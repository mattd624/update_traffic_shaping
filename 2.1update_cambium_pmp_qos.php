<?php
/////////////////////////////////////////// Includes //////////////////////////////////////////////


ini_set("soap.wsdl_cache_enabled", "0");  // clean WSDL for develop
ini_set("allow_url_fopen", true);
date_default_timezone_set('America/Los_Angeles');
require realpath(__DIR__ . '/../commonDirLocation.php');
require (COMMON_PHP_DIR . '/toolkit/soapclient/SforceEnterpriseClient.php');
require (COMMON_PHP_DIR . '/eip.userAuth.php'); //this contains the credentials used to log into our org when the API is called
require realpath(COMMON_PHP_DIR . '/creds.php');
require realpath(COMMON_PHP_DIR . '/SlackMessagePost.php');
require realpath(COMMON_PHP_DIR . '/checkOrgID.php');
require realpath(COMMON_PHP_DIR . '/respond.php');
require realpath(COMMON_PHP_DIR . '/snmp.php');
require realpath(COMMON_PHP_DIR . '/parseNotification.php');
include realpath(COMMON_PHP_DIR . '/writelog.php');
include realpath(COMMON_PHP_DIR . '/deleteOldLogs.php');
include realpath(COMMON_PHP_DIR . '/logTime.php');
require realpath(__DIR__ . '/2sf_update.php');
require realpath(__DIR__ . '/ping_port.php');
require realpath(__DIR__ . '/data_same.php');


///////////////////////////////////////////////// GLOBALS /////////////////////////////////////////////

$logging_on = 1;
$f_name = pathinfo(__FILE__)['basename'];
$f_dir = pathinfo(__FILE__)['dirname'];
$log_dir = '/2log_cambium_pmp/';
$keep_logs_days_old = 90;
$sf_url = 'https://na131.salesforce.com';

///////////////////////////////////////////////  FUNCTIONS  ///////////////////////////////////////////


function send_reboot_cmd($ip) {
  global $snmp_community_str;
  $success = set_snmp_val($ip, '.1.3.6.1.4.1.161.19.3.3.3.4.0', $value = 1);
  return $success;
}


function determine_ovrd(array $snmp_data) {
  global $snmp_max;
  if (($snmp_data['down']['rate'] + $snmp_data['up']['rate']) >= ($snmp_max - 2000)) {
    return 1;
  }
  return 0;
}


///////////////////////////////////////////////// START EXECUTION CODE ///////////////////////////////////////////


                                                                                        writelog(
                                                                                          "\n\n\n________________________________________________________________________\n" .
                                                                                          "________________________________________________________________________\n"
                                                                                        );
                                                                                        writelog("START");
                                                                                        log_time();

ob_start();

$req = file_get_contents('php://input');
if (empty($req)) {
                                                                                        writelog("\n\nRequest is empty. Responding true and exiting...");
  respond('true');
  exit;
}

//                                                                                      writelog("\n\nREQ:\n\n");
//                                                                                      writelog($req);
$xml = new DOMDocument();
$xml->loadXML($req);
$requestArray = parseNotification($xml);
//                                                                                      writelog("\n\nREQ ARRAY:\n\n");
//                                                                                      writelog($requestArray);

/*
//Test Array:
$requestArray = array(
  'OrganizationId' => '00DU0000000IjIFMA0',
  'MapsRecords' => array(
    0 => array(
        'Id' => '5000x00000BpCfaAAF',              //Case Id
        'Opportunity__c' => '0060x000008T08o',
        'SU_IP_Address__c' => '10.11.205.4',
        'MIR_Down__c' => '50.000',
        'MIR_Up__c' => '50.000',
        'Override_Radio__c' => 'false'
    ),

    1 => array(
        'Id' => '5000x00000BpCfa',              //Case Id
        'Opportunity__c' => '0060x000008T08o',
        'SU_IP_Address__c' => '',
        'MIR_Down__c' => '5.000',
        'MIR_Up__c' => '2.536',
        'Override_Radio__c' => 'false'
    )

  ),
  'sObject' => '0'
);
*/

$org_id = $requestArray['OrganizationId'];
$org_id_success = checkOrgID($org_id);
if (!$org_id_success) {
                                                                                        writelog("\nOrg ID check failed. Exiting.");
                                                                                        slack($f_name . ": Org ID check failed",'mattd');
  respond('true');
  exit;
}

//  respond('true');
//  exit;


try {
  $sf_obj_arr = [];
  foreach ($requestArray['MapsRecords'] as $r) {
    $id = $r['Id'];
    $sf_obj = (object) $r;
    $sf_obj->modify_flag = 0;
    if (
        !isset($sf_obj->SU_IP_Address__c) ||
        !isset($sf_obj->MIR_Down__c) ||
        !isset($sf_obj->MIR_Up__c) ||
        !isset($sf_obj->Override_Radio__c)
       ) {
      $msg = "$sf_url$id has a required value MISSING:
      \nsf_obj->Opportunity__c: $sf_obj->Opportunity__c
      \nsf_obj->SU_IP_Address__c: $sf_obj->SU_IP_Address__c
      \nsf_obj->MIR_Down__c: $sf_obj->MIR_Down__c
      \nsf_obj->MIR_Up__c:  $sf_obj->MIR_Up__c
      \nsf_obj->Override_Radio__c: $sf_obj->Override_Radio__c
      \n\nHere is the object:\n";
                                                                                        writelog($msg);
                                                                                        writelog($sf_obj);
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$sf_obj);
    } else {
      
      $sf_obj_arr[$id] = $sf_obj;
      $sf_obj_arr[$id]->sf_dn = round($sf_obj->MIR_Down__c * 1024);
      $sf_obj_arr[$id]->sf_up = round($sf_obj->MIR_Up__c * 1024);
      if ($sf_obj->Override_Radio__c == 'true') {
        $sf_obj_arr[$id]->sf_ovrd = 1;
      } else {
        $sf_obj_arr[$id]->sf_ovrd = 0;
      }
    }
  }

writelog($sf_obj_arr);

  $individual_success = [];
  $individual_success['total'] = count($requestArray['MapsRecords']);
  $individual_success['successful'] = 0;
  foreach ($sf_obj_arr as $sf_data) {
    $id = $sf_data->Id;
    $opp_id = $sf_data->Opportunity__c;
    $ip = $sf_data->SU_IP_Address__c;

writelog("\nsf_data:\n");
writelog($sf_data);
    if (preg_match('/.*?([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}).*/',$sf_data->SU_IP_Address__c, $match)) {
      $ip = $match[1];
                                               writelog("\n________________________________________________________________________\n\nWorking IP: $ip\nid: $id");
      $ip_is_valid = filter_var($ip, FILTER_VALIDATE_IP);
    } else {
      $ip_is_valid = 0;
    }
    if (!$ip_is_valid) {
      $msg = "$f_name: $sf_url$id - Invalid IP address: \"$ip\"";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg,'mattd');
      $msg = "Invalid IP address: \"$ip\"";
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      continue;
    }

                                                                                        writelog("\nsf_ovrd: $sf_data->sf_ovrd");
                                                                                        writelog("\nsf_dn: $sf_data->sf_dn");
                                                                                        writelog("\nsf_up: $sf_data->sf_up");
////  Get SNMP Data
    $pmp_radio_model_oid =  '.1.3.6.1.4.1.161.19.3.3.1.266.0';
    $qos_max_oid         =  '.1.3.6.1.4.1.161.19.3.3.1.108.0';
    $qos_dn_read_oid     =  '.1.3.6.1.4.1.161.19.3.2.2.99.0'; 
    $qos_up_read_oid     =  '.1.3.6.1.4.1.161.19.3.2.2.97.0';
    $qos_dn_write_oid    =  '.1.3.6.1.4.1.161.19.3.2.1.64.0';
    $qos_up_write_oid    =  '.1.3.6.1.4.1.161.19.3.2.1.62.0';

    
    $ping_check = get_snmp_data($ip, '.1.3.6.1.2.1.1.1.0'); 
    if (!$ping_check) {
      $msg = "$f_name: $sf_url$id - $ip - Ping check failed";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      continue;
    }
    $radio_model_str = get_snmp_data($ip, $pmp_radio_model_oid);
                                                                                        writelog("\nradio_model_str: $radio_model_str");
    if (!preg_match('/PMP 450.*/', $radio_model_str, $match)) {
      $msg = "$radio_model_str is not the correct type of radio for this script.";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      continue;
    }
    $snmp_max = get_snmp_data($ip, $qos_max_oid);
    $snmp_data = [];
    $snmp_data['down']['rate'] = get_snmp_data($ip,$qos_dn_write_oid);
    $snmp_data['up']['rate'] = get_snmp_data($ip,$qos_up_write_oid);
    $snmp_data['ovrd'] = determine_ovrd($snmp_data);
    if ($sf_data->sf_ovrd == 1) {
      $sf_data->sf_dn = ($snmp_max * 0.75);
      $sf_data->sf_up = ($snmp_max * 0.25);
    }

    if (data_same($sf_data,$snmp_data)) {
      $msg = "$f_name: $sf_url$id - $ip - Data is the same; no need to update";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      $individual_success['successful']++;
    } else { 
      set_snmp_val($ip, $qos_dn_write_oid , $sf_data->sf_dn);
      set_snmp_val($ip, $qos_up_write_oid , $sf_data->sf_up);
      $snmp_followup_write_data = []; 
      $snmp_followup_write_data['down']['rate'] = get_snmp_data($ip,$qos_dn_write_oid);
      $snmp_followup_write_data['up']['rate'] = get_snmp_data($ip,$qos_up_write_oid);
      $snmp_followup_write_data['ovrd'] = determine_ovrd($snmp_followup_write_data);

      error_reporting(E_ALL & ~(E_NOTICE|E_WARNING));
      if (data_same($sf_data, $snmp_followup_write_data)) {
        ob_clean();
        respond('true');
        header('Connection: close');
        header('Content-Length: '.ob_get_length());
        ob_end_flush();
        ob_flush();
        flush();
        send_reboot_cmd($ip);
        sleep(110); //waiting for radio to reboot
        $followup_ping = get_snmp_data($ip, '.1.3.6.1.2.1.1.1.0');
        while (!$followup_ping) {
          $followup_ping = get_snmp_data($ip, '.1.3.6.1.2.1.1.1.0'); 
          sleep(5);
        }
        $snmp_followup_read_data['down']['rate'] = get_snmp_data($ip,$qos_dn_read_oid);
        $snmp_followup_read_data['up']['rate'] = get_snmp_data($ip,$qos_up_read_oid);
        $snmp_followup_read_data['ovrd'] = determine_ovrd($snmp_followup_read_data);

        //////////check new data against sf data and if good, callback to sf and update values
        $sf_opp_radio_mir_arr = [];
        $sf_case_comment_arr = [];
        if (data_same($sf_data,$snmp_followup_read_data)) {

          $sf_opp_radio_mir_arr[] = sf_radio_mir($opp_id,$snmp_followup_read_data['down']['rate'],$snmp_followup_read_data['up']['rate'],$snmp_followup_read_data['ovrd']);

          if ($snmp_followup_read_data['ovrd']) $friendly_ovrd = 'active';
          else $friendly_ovrd = 'not active';
          $new_ssh_dn_Mbps = ($snmp_followup_read_data['down']['rate'] / 1024);
          $new_ssh_up_Mbps = ($snmp_followup_read_data['up']['rate'] / 1024);
          $msg = "Download updated from " . $snmp_data['down']['rate'] . " to $new_ssh_dn_Mbps Mbps. Upload updated from " . 
              $snmp_data['up']['rate'] . " to $new_ssh_up_Mbps Mbps. Radio override is $friendly_ovrd.";
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
          $individual_success['successful']++;
        } else {
          $msg = "$f_name: $sf_url$id - $ip - update failed. Data is not as intended after update";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
        }
      } else {
        $msg = "$f_name: $sf_url$id - $ip - update failed. Data is not as intended after reboot";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      }
      error_reporting(E_ALL);
    }

  }//end of for loop: ($sf_obj_arr as $sf_data)

} catch (exception $e) {
  $catch_msg = "$f_name: $sf_url$id - $ip - Caught exception: $e";
                                                                                        slack($catch_msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$catch_msg);
  $individual_success['successful'] = 0;
}
                                                                                        writelog("\n\n Success: ");
                                                                                        writelog((string) $individual_success['successful'] .
                                                                                        " of " . (string) $individual_success['total'] . "\n");

if ($individual_success['successful'] == $individual_success['total']) {

} else {
  $msg = "$f_name: $sf_url$id - $ip - failed";
                                                                                        writelog($msg);
                                                                                        slack($msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
}

try {
  $mySforceConnection = new SforceEnterpriseClient();
  $mySoapClient = $mySforceConnection->createConnection(WSDL);
  $mylogin = $mySforceConnection->login(SF_USER,SF_PW);
  $createResponse = $mySforceConnection->update($sf_opp_radio_mir_arr, 'Opportunity');
  $createResponse = $mySforceConnection->create($sf_case_comment_arr, 'CaseComment');
} catch (Exception $e) {
  $msg = "Error creating/updating SF Object(s): $e->faultstring";
                                                                                        writelog("\n$msg");
                                                                                        slack("$rel_path - $msg",'mattd');
}


respond('true');
                                                                                        log_time();
                                                                                        writelog("\nEND");
deleteOldLogs($log_dir, $keep_logs_days_old)

?>




