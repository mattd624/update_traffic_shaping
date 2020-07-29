<?php
date_default_timezone_set('America/Los_Angeles');
ini_set("allow_url_fopen", true);
ini_set("soap.wsdl_cache_enabled", "0");  // clean WSDL for develop
require_once (__DIR__ . '/../commonDirLocation.php');
require_once (COMMON_PHP_DIR . '/SlackMessagePost.php');
require_once (COMMON_PHP_DIR . '/checkOrgID.php');
require_once (COMMON_PHP_DIR . '/parseNotification.php');
require_once (COMMON_PHP_DIR . '/writelog.php');
require_once (COMMON_PHP_DIR . '/logTime.php');
require_once (COMMON_PHP_DIR . '/respond.php');
require_once (COMMON_PHP_DIR . '/deleteOldLogs.php');
require_once (COMMON_PHP_DIR . '/toolkit/soapclient/SforceEnterpriseClient.php');
require_once (COMMON_PHP_DIR . '/snmp.php');
require_once (COMMON_PHP_DIR . '/creds.php');
require_once (COMMON_PHP_DIR . '/busy.php');
require_once  (__DIR__ . '/sf_update.php');
require_once  (__DIR__ . '/data_same.php');
require_once (__DIR__ . '/ping_port.php');

///////////////////////////////////////////////// GLOBALS /////////////////////////////////////////////
$logging_on = 1;
$log_dir = __DIR__ . '/log/';
$f_name = pathinfo(__FILE__)['basename'];
$rel_path = substr(__FILE__, strlen($_SERVER['DOCUMENT_ROOT']));
$keep_logs_days_old = 90;
$sf_url = 'https://na131.salesforce.com';
$heavy_logging = 1;
//////////////////////////////////////////////// TEST ARRAY ///////////////////////////////////////////
function test_array() {
  $requestArray = array(
    'OrganizationId' => '00DU0000000IjIFMA0',
    'SessionId' => '0',
    'EnterpriseUrl' => 'https://unwired--eIP.salesforce.com',
    'MapsRecords' => array(
      0 => array(
          'Id' => '5000x00000BpCfaAAF',              //Case Id
          'Radio_Model__c' => 'Ubiquiti Rocket M5',              //Case Id
          'Opportunity__c' => '0060x000008T08oAAC',
          'SU_IP_Address__c' => '10.7.1.46',
          'New_MIR_Down__c' => '11',
          'New_MIR_Up__c' => '3.75',
          'Override_Radio__c' => 'false',
          'LastModifiedById' => '005U0000000IucGIAS'
      ),
/*
      1 => array(
          'Id' => '5000x00000Bpjkhsdl',              //Case Id
          'Radio_Model__c' => 'Ubiquiti Rocket M5',              //Case Id
          'Opportunity__c' => '0060x000008T08oAAC',
          'SU_IP_Address__c' => '10.7.1.46',
          'New_MIR_Down__c' => '10',
          'New_MIR_Up__c' => '6',
          'Override_Radio__c' => 'false',
          'LastModifiedById' => '005U0000000IucGIAS'
      )
*/
    ),
    'sObject' => '0'
  );
  return $requestArray;
}

/////////////////////////////// START EXECUTION CODE ///////////////////////////////////////////

$start_time = time('now');
$sf_opp_radio_mir_arr = [];
$sf_case_comment_arr = [];
$sf_case_update_arr = [];

ob_start();
$busy = intval(check_busy());
$times = intval(check_times());
if ($busy) {
  if($times <= 2) {
    $times++;
    set_times($times);
    respond('false');
    exit;
  } else {
    set_times(0);
  }
} else {
  ob_clean();
  respond('true');
  header('Connection: close');
  //do not put spaces around period before ob_get_length or XML error
  header('Content-Length: '.ob_get_length());
  ob_end_flush();
  ob_flush();
  flush();
  set_busy(1);

}

//////////////////  DO NOT USE writelog() OR heavylog() BEFORE THIS LINE  /////////////////////

                                                                                        writelog(
                                                                                          "\n\n\n________________________________________________________________________\n" .
                                                                                          "________________________________________________________________________\n"
                                                                                        );
log_time();
if ($heavy_logging) {
  $msg = "Heavy logging is turned on.";
                                                                                        slack("$rel_path: $msg", 'mattd');
}


$req = file_get_contents('php://input');
if (!empty($req)) {
                                                                                        heavylog("\n\nreq:\n\n");
                                                                                        heavylog($req);
  $xml = new DOMDocument();
  $xml->loadXML($req);
  $requestArray = parseNotification($xml);
} else { 
                                                                                        heavylog("\n\nRequest is empty. Using test array\n\n");
  $requestArray = test_array();
}
                                                                                        heavylog("\n\nrequestArray:\n\n");
                                                                                        heavylog($requestArray);
if (preg_match('+(https://.*salesforce\.com)+', $requestArray['EnterpriseUrl'], $match)) $sf_url = $match[1];
$session_id = $requestArray['SessionId'];
$location  =  $requestArray['EnterpriseUrl'];
$location_prefix = strstr(str_replace('https://','',$location),'.', TRUE);
define('WSDL', COMMON_PHP_DIR . '/wsdl/' . $location_prefix . '.enterprise.wsdl.xml');
                                                                                        heavylog("\n\nWSDL:\n\n");
                                                                                        heavylog(WSDL);
$org_id = $requestArray['OrganizationId'];
$org_id_success = checkOrgID($org_id);
if (!$org_id_success) {
                                                                                        writelog("\nOrg ID check failed. Exiting.");
                                                                                        slack("$rel_path: Org ID check failed",'mattd');
  respond('true');
  exit;
}
                                                                                        heavylog("\nBEGINNING TRY BLOCK");
try {
                                                                                        heavylog("\nSETTING INDIVIDUAL SUCCESS VARIABLES");
  $individual_success = [];
  $individual_success['total'] = count($requestArray['MapsRecords']);
  $individual_success['successful'] = 0;
                                                                                        heavylog("\nSETTING SF OBJECT ARRAY");

  $sf_obj_arr = [];
                                                                                        heavylog("\nBEGINNING FOREACH ON REQUEST ARRAY");
  foreach ($requestArray['MapsRecords'] as $r) {
                                                                                        heavylog("\nLOOP ITERATION BEGIN");
                                                                                        heavylog("\nSETTING CASE ID");
    $id = $r['Id'];
                                                                                        heavylog("\nREMOVING Trigger_Automation__c CHECK FROM CHECKBOX");
    $sf_case_update_arr[] = sf_uncheck($id,'Trigger_Automation__c');
                                                                                        heavylog("\nCONVERTING OBJECT");
    $sf_obj = (object) $r;
    $sf_obj->modify_flag = 0;

                                                                                        heavylog("\nCHECKING OBJECT FOR ALL REQUIRED PROPERTIES");
/*
    if (
        !isset($sf_obj->Opportunity__c) ||
        !isset($sf_obj->SU_IP_Address__c) ||
        !isset($sf_obj->New_MIR_Down__c) ||
        !isset($sf_obj->New_MIR_Up__c) ||
        !isset($sf_obj->Override_Radio__c)
       ) {
      $msg = "$sf_url/$id has a required value MISSING:
      \nsf_obj->Opportunity__c: $sf_obj->Opportunity__c
      \nsf_obj->SU_IP_Address__c: $sf_obj->SU_IP_Address__c
      \nsf_obj->New_MIR_Down__c: $sf_obj->New_MIR_Down__c
      \nsf_obj->New_MIR_Up__c:  $sf_obj->New_MIR_Up__c
      \nsf_obj->Override_Radio__c: $sf_obj->Override_Radio__c\n";
*/

    $check_missing_arr = ['Opportunity__c','SU_IP_Address__c','New_MIR_Down__c','New_MIR_Up__c','Override_Radio__c'];
    $missing_arr = [];
    foreach ($check_missing_arr as $param) {
      if (!isset($sf_obj->{$param})) {
        $missing_arr[] = $param;
      }
    }
    if (count($missing_arr) > 0) {
      $msg = "$sf_url/$id: ERROR - MISSING " . count($missing_arr) . " required parameter(s): " . implode(", ", $missing_arr);
                                                                                        writelog("\n$msg");
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      $msg = "\n\nHere is the object:\n";
                                                                                        writelog($msg);
                                                                                        writelog($sf_obj);

                                                                                        heavylog("\nCONTINUE");
      continue;
    } else {
                                                                                        heavylog("\nSETTING SF_OBJ");
      $sf_obj_arr[$id] = $sf_obj;
    }
                                                                                        heavylog("\nCHECKING VALIDITY OF IP ADDRESS");
    $ip = str_replace(['https://','http://'],'',$sf_obj->SU_IP_Address__c);
    $ip_is_valid = filter_var($ip, FILTER_VALIDATE_IP);
    if (!$ip_is_valid) {
                                                                                        writelog("\nERROR - IP IS INVALID");
      $msg = "Invalid IP address: \"$ip\"";
                                                                                        slack("$rel_path: $sf_url/$id - $msg",'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      continue;
    } else {
      $sf_obj_arr[$id]->SU_IP_Address__c = $ip;
    }                                                                                    heavylog("\nLOOP ITERATION END");
  }
                                                                                        heavylog("\nEND FOREACH LOOP ON REQUEST ARRAY");

  if (count($sf_obj_arr) < 1) {
                                                                                        heavylog("\nCOUNT OF SF OBJECTS IS LESS THAN 1");
    $msg = "There are no valid objects to modify. This is probably due to missing data.";
                                                                                        writelog("\n$msg");
                                                                                        slack("$rel_path: $msg",'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
  }
                                                                                        heavylog("\nsf_obj_arr: \n");
                                                                                        heavylog($sf_obj_arr);
                                                                                        heavylog("\nBEGINNING FOREACH LOOP ON SF_OBJ_ARR");
  foreach ($sf_obj_arr as $obj) {
                                                                                        heavylog("\nLOOP ITERATION BEGIN");
                                                                                        heavylog("\nGETTING MODEL BY SNMP");
    $model_oid = '.1.3.6.1.2.1.1.1.0';
    $sys_obj_id_oid = '.1.3.6.1.2.1.1.2.0';
    $ip = $obj->SU_IP_Address__c;
    if (!($model = get_snmp_data($ip, $model_oid, 1))) {
      $model = get_snmp_data($ip, $model_oid, 2);
    }
                                                                                        heavylog("\nmodel: $model");
    switch ($model) {
      case (preg_match('/Linux 2\.6\.32.*/', $model) ? true : false) :
                                                                                        heavylog("\nLOADING UPDATE UBIQ MODULE");
        require_once realpath(__DIR__ . '/update_ubiq_tshaping.php');
                                                                                        heavylog("\nEXECUTING do_ubiq()");
        do_ubiq($obj);
        break;
      case (preg_match('/CANOPY [0-9\.]+.* SM/', $model) ? true : false):
                                                                                        heavylog("\nLOADING UPDATE CAMBIUM MODULE");
        require_once realpath(__DIR__ . '/update_cambium_pmp_450_qos.php');
                                                                                        heavylog("\nEXECUTING do_450()");
        do_450($obj);
        break;
      case (preg_match('/Linux [-_,A-Za-z0-9]/', $model)) :
        $sys_obj_id = get_snmp_data($ip, $sys_obj_id_oid, 1);
        if (preg_match('/\.1\.3\.6\.1\.4\.1\.17713\./', $sys_obj_id)) {
          $msg = "$ip appears to be an Elevate model"; 
          $msg2 = " Please send to engineering.";
                                                                                        writelog("\n$msg");
											$sf_case_comment_arr[] = sf_case_comment($id,$msg.$msg2);
        }
        break;
      default:
        $msg = "$ip model not recognized.";
        $msg2 = " Please send to engineering.";
                                                                                        writelog("\n$msg");
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg.$msg2);
        break;
    }
  
  }
} catch (exception $e) {
                                                                                        heavylog("\nCATCH EXCEPTION");
  $catch_msg = "$rel_path: $sf_url/$id - Caught exception: $e";
                                                                                        slack($catch_msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$catch_msg);
                                                                                        heavylog("\nSETTING INDIVIDUAL SUCCESS TO 0");
  $individual_success['successful'] = 0;
}

                                                                                        heavylog("\nCHECKING HOW MANY SUCCESSFUL");
$msg = "                       Successful: " .
  (string) $individual_success['successful'] .
  " of " .
  (string) $individual_success['total'] . "\n";
                                                                                        writelog("\n\n$msg");
if (!($individual_success['successful'] == $individual_success['total'])) {
  $msg = "$rel_path: $sf_url/$id - ONE OR MORE FAILURES OCCURRED";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
}

try {
                                                                                        heavylog("\nCREATING NEW SALESFORCE CONNECTION");
  $mySforceConnection = new SforceEnterpriseClient();
  $mySoapClient = $mySforceConnection->createConnection(WSDL);
  $mySession = $mySforceConnection->setEndpoint($location);
  $myLocation = $mySforceConnection->setSessionHeader($session_id);

                                                                                        heavylog("\nCHECKING FOR VALUES TO UPDATE IN CASES");
  if (count($sf_case_update_arr) > 0) {
                                                                                        heavylog("\nTHERE ARE VALUES TO UPDATE IN CASES");
                                                                                        heavylog("\nsf_case_update_arr:");
                                                                                        heavylog($sf_case_update_arr);
                                                                                        heavylog("\nUPDATING CASE");
    $createResponse = $mySforceConnection->update($sf_case_update_arr, 'Case');
                                                                                        heavylog("\ncreateResponse:");
                                                                                        heavylog($createResponse);
  }
                                                                                        heavylog("\nCHECKING FOR MIR VALUES TO UPDATE IN ANY OPPORTUNITIES");
  if (count($sf_opp_radio_mir_arr) > 0) {
                                                                                        heavylog("\nTHERE ARE VALUES TO UPDATE IN OPPORTUNITIES");
                                                                                        heavylog("\nsf_opp_radio_mir_arr:");
                                                                                        heavylog($sf_opp_radio_mir_arr);
                                                                                        heavylog("\nUPDATING OPPORTUNITY");
    $createResponse = $mySforceConnection->update($sf_opp_radio_mir_arr, 'Opportunity');
                                                                                        heavylog("\ncreateResponse:");
                                                                                        heavylog($createResponse);
  }
                                                                                        heavylog("\nCHECKING FOR CASE COMMENTS TO UPDATE IN ANY CASES");
  if (count($sf_case_comment_arr) > 0) {
                                                                                        heavylog("\nTHERE ARE CASE COMMENTS TO UPDATE IN CASES");
                                                                                        heavylog("\nsf_case_comment_arr");
                                                                                        heavylog($sf_case_comment_arr);
                                                                                        heavylog("\nUPDATING CASES");
    $createResponse = $mySforceConnection->create($sf_case_comment_arr, 'CaseComment');
                                                                                        heavylog("\ncreateResponse:");
                                                                                        heavylog($createResponse);
  }
                                                                                        heavylog("\nDONE UPDATING SF");
} catch (Exception $e) {
                                                                                        heavylog("\nCATCH EXCEPTION");
  $msg = "Error creating/updating SF Object(s): $e->faultstring";
                                                                                        writelog("\n$msg");
                                                                                        slack("$rel_path - $msg",'mattd');
}
                                                                                        heavylog("\nDELETING OLD LOGS");
deleteOldLogs($log_dir, $keep_logs_days_old);

                                                                                        heavylog("\nLOGGING TIME");
                                                                                        log_time();
$end_time = time('now');
$elapsed_time = $end_time - $start_time;
$msg = "Time elapsed: $elapsed_time";
                                                                                        writelog("\n$msg");
set_busy(0);
set_times(0);






