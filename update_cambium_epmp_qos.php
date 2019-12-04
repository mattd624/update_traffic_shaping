<?php
/////////////////////////////////////////// Includes //////////////////////////////////////////////


ini_set("soap.wsdl_cache_enabled", "0");  // clean WSDL for develop
ini_set("allow_url_fopen", true);
date_default_timezone_set('America/Los_Angeles');
require realpath(__DIR__ . '/../commonDirLocation.php');
require (COMMON_PHP_DIR . '/toolkit/soapclient/SforceEnterpriseClient.php');
require (COMMON_PHP_DIR . '/partial.userAuth.php'); //this contains the credentials used to log into our org when the API is called
require realpath(COMMON_PHP_DIR . '/creds.php');
require realpath(COMMON_PHP_DIR . '/SlackMessagePost.php');
require realpath(COMMON_PHP_DIR . '/checkOrgID.php');
require realpath(COMMON_PHP_DIR . '/respond.php');
require realpath(COMMON_PHP_DIR . '/snmp.php');
require realpath(COMMON_PHP_DIR . '/parseNotification.php');
require realpath(__DIR__ . '/sf_update.php');
require realpath(__DIR__ . '/ping_port.php');
include realpath(COMMON_PHP_DIR . '/deleteOldLogs.php');


///////////////////////////////////////////////// GLOBALS /////////////////////////////////////////////

$logging_on = 1;
$file_name = 'update_cambium_pmp_qos.php';
$log_dir = __DIR__ . '/log_cambium_pmp/';
$keep_logs_days_old = 90;
$sf_url = 'https://na131.salesforce.com/';

///////////////////////////////////////////////  FUNCTIONS  ///////////////////////////////////////////



function writelog($log) {
    global $log_dir;
    global $logging_on;
    if ($logging_on) {
      file_put_contents($log_dir . @date('Y-m-d') . '.log', print_r($log, true), FILE_APPEND);
    }
}


function log_time() {
  /////////////..........depends on writelog()
  $tmstmp = date('D, \d\a\y d \o\f F, G:i:s');
  writelog("\n" . $tmstmp . "\n\n\n");
}


function send_reboot_cmd($ip) {
  $success = 0;
  $success1 = set_snmp_val($ip, '.1.3.6.1.4.1.17713.21.4.3.0', $value = 1); ///save
  $success2 = set_snmp_val($ip, '.1.3.6.1.4.1.17713.21.4.1.0', $value = 1); ///reboot
  if ($success1 + $success2 == 2) $success = 1;
  return $success;
}


function determine_ovrd(array $snmp_data) {
  global $max_profile_num;
  if ($snmp_data['profile_num'] == $max_profile_num) {
    return 1;
  }
  return 0;
}


function data_same($sf_data, $remote_data) {
  if (($sf_data['profile_num'] == $remote_data['profile_num']) and
      ($sf_data['ovrd'] == $remote_data['ovrd'])) {
    return 1;
  } else {
    return 0;
  }
}


function determine_profile_num($sf_data) {
  $profile_table = [
    '' => '1',
    '' => '2',
    '' => '3',
    '' => '4',
    '' => '5',
    '' => '6',
    '' => '7',
    '' => '8',
    '' => '9',
    '' => '10',
    '' => '11',
    '' => '12',
    '' => '13',
    '' => '14',
    '' => '15'
  ];  
  return $profile_table[$sf_data->Plan__c];
}
///////////////////////////////////////////////// START EXECUTION CODE ///////////////////////////////////////////


                                                                                        writelog(
                                                                                          "\n\n\n________________________________________________________________________\n" .
                                                                                          "________________________________________________________________________\n"
                                                                                        );

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
        'Id' => '000kjh00000000023423',
        'Name' => 'A-S01040799',
        'SU_IP_Address__c' => '10.11.205.4',
        'AP_Standard_Name__c' => 'flbk-su-test_1',
        'MIR_Down_Mbps__c' => '57.000',
        'MIR_Up_Mbps__c' => '57.000',
        'Remove_PSM_Rate_Limiting__c' => 'false'
    ),

    1 => array(
        'Id' => '000kjh00000000023424',
        'Name' => 'A-S01040800',
        'SU_IP_Address__c' => '192.168.2.46',
        'AP_Standard_Name__c' => 'flbk-su-test_2',
        'MIR_Down_Mbps__c' => '7.3',
        'MIR_Up_Mbps__c' => '4.0',
        'Remove_PSM_Rate_Limiting__c' => 'true'
    ),

    2 => array(
        'Id' => '000kjh00000000023425',
        'Name' => 'A-S01040801',
        'SU_IP_Address__c' => '192.168.2.47',
        'AP_Standard_Name__c' => 'flbk-su-test_3',
        'MIR_Down_Mbps__c' => '20.000',
        'MIR_Up_Mbps__c' => '6.50284809',
        'Remove_PSM_Rate_Limiting__c' => 'false'
    ),

    3 => array(
        'Id' => '000kjh00000000023426',
        'Name' => 'A-S01040802',
        'SU_IP_Address__c' => '192.168.2.48',
        'AP_Standard_Name__c' => 'flbk-su-test_4',
        'MIR_Down_Mbps__c' => '24.000',
        'MIR_Up_Mbps__c' => '6.50',
        'Remove_PSM_Rate_Limiting__c' => 'false'
    )

  ),
  'sObject' => '0'
);
*/

$org_id = $requestArray['OrganizationId'];
$org_id_success = checkOrgID($org_id);
if (!$org_id_success) {
                                                                                        writelog("\nOrg ID check failed. Exiting.");
                                                                                        slack($file_name . ": Org ID check failed",'mattd');
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
        !isset($sf_obj->MIR_Down_Mbps__c) ||
        !isset($sf_obj->MIR_Up_Mbps__c) ||
        !isset($sf_obj->Remove_PSM_Rate_Limiting__c)
       ) {
                                                                                        slack("\n$sf_url$id has a required value MISSING", 'mattd');
                                                                                        writelog("\n$sf_url$id has a required value MISSING:");
                                                                                        writelog("\nsf_obj->SU_IP_Address__c: " . $sf_obj->SU_IP_Address__c);
                                                                                        writelog("\nsf_obj->MIR_Down_Mbps__c: " . $sf_obj->MIR_Down_Mbps__c );
                                                                                        writelog("\nsf_obj->MIR_Up_Mbps__c: " . $sf_obj->MIR_Up_Mbps__c );
                                                                                        writelog("\nHere is the object:\n");
                                                                                        writelog($sf_obj);
    } else {
      $sf_obj_arr[$id] = $sf_obj;
      $sf_obj_arr[$id]->sf_dn = round($sf_obj->MIR_Down_Mbps__c * 1000);
      $sf_obj_arr[$id]->sf_up = round($sf_obj->MIR_Up_Mbps__c * 1000);
      if ($sf_obj->Remove_PSM_Rate_Limiting__c == 'true') {
        $sf_obj_arr[$id]->sf_ovrd = 1;
      } else {
        $sf_obj_arr[$id]->sf_ovrd = 0;
      }
    }
  }

  $individual_success = [];
  $individual_success['total'] = count($requestArray['MapsRecords']);
  $individual_success['successful'] = 0;
  foreach ($sf_obj_arr as $sf_data) {
    $id = $sf_data->Id;
    $ip = $sf_data->SU_IP_Address__c;
                                               writelog("\n________________________________________________________________________\n\nWorking IP: $ip\nid: $id");
    $ip_is_valid = filter_var($ip, FILTER_VALIDATE_IP);
    if (!$ip_is_valid) {
      $msg = $file_name . ": " . $sf_url . $id . " - " . $ip . " is not a valid IP address";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg,'mattd');
      continue;
    }

                                                                                        writelog("\nsf_ovrd: $sf_data->sf_ovrd");
                                                                                        writelog("\nsf_dn: $sf_data->sf_dn");
                                                                                        writelog("\nsf_up: $sf_data->sf_up");
/*
    $qos_max_oid    =   '.1.3.6.1.4.1.161.19.3.3.1.108.0';
    $qos_dn_read_oid =  '.1.3.6.1.4.1.161.19.3.2.2.99.0'; 
    $qos_up_read_oid =  '.1.3.6.1.4.1.161.19.3.2.2.97.0';
    $qos_dn_write_oid = '.1.3.6.1.4.1.161.19.3.2.1.64.0';
    $qos_up_write_oid = '.1.3.6.1.4.1.161.19.3.2.1.62.0';
*/

    $qos_profile_oid =  '.1.3.6.1.4.1.17713.21.3.8.2.30.0'; 
    
    
    $ping_check = get_snmp_data($ip, '.1.3.6.1.2.1.1.1.0'); 
    if (!$ping_check) {
      $msg = $file_name . ": " . $sf_url . $id . " - " . $ip . "Ping check failed";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
      continue;
    }
    $snmp_data = [];
    $snmp_data['profile_num'] = get_snmp_data($ip,$qos_profile_oid);
    $snmp_data['ovrd'] = determine_ovrd($snmp_data);
    if (data_same($sf_data,$snmp_data)) {
      $msg = $file_name . ": " . $sf_url . $id . " - " . $ip . " - Data is the same; no need to update";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
      $individual_success['successful']++;
    } else { 
      set_snmp_val($ip, $qos_dn_write_oid , $sf_data->sf_dn);
      set_snmp_val($ip, $qos_up_write_oid , $sf_data->sf_up);
      $snmp_followup_data = []; 
      $snmp_followup_data['profile_num'] = get_snmp_data($ip,$qos_profile_oid);
      $snmp_followup_data['ovrd'] = determine_ovrd($snmp_followup_data);
      error_reporting(E_ALL & ~(E_NOTICE|E_WARNING));
      if (data_same($sf_data, $snmp_followup_data)) {
        ob_get_clean();
                                                                                        respond('true');
        send_reboot_cmd($ip);

        sleep(110); //waiting for radio to reboot
        $followup_ping = get_snmp_data($ip, '.1.3.6.1.2.1.1.1.0');
        while (!$followup_ping) {
          $followup_ping = get_snmp_data($ip, '.1.3.6.1.2.1.1.1.0'); 
          sleep(5);
        }
        $snmp_followup_data = []; 
        $snmp_followup_data['profile_num'] = get_snmp_data($ip,$qos_profile_oid);
        $snmp_followup_data['ovrd'] = determine_ovrd($snmp_followup_data);
        print_r($snmp_followup_data);
        if (data_same($sf_data,$snmp_followup_data)) {
          $individual_success['successful']++;
        } else {
          $msg = $file_name . ": " . $sf_url . $id . " - " . $ip . " - update failed. Data is not as intended after reboot";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
        }
      } else {
        $msg = $file_name . ": " . $sf_url . $id . " - " . $ip . " - update failed. Data is not as intended after update";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
      }
      error_reporting(E_ALL);
    }



  }//end of for loop: ($sf_obj_arr as $sf_data)

} catch (exception $e) {
  $catch_msg = $file_name . ": " . $sf_url . $id . " - Caught exception: $e";
                                                                                        slack($catch_msg, 'mattd');
  $individual_success['successful'] = 0;
}
                                                                                        writelog("\n\n Success: ");
                                                                                        writelog((string) $individual_success['successful'] .
                                                                                        " of " . (string) $individual_success['total'] . "\n");

if ($individual_success['successful'] == $individual_success['total']) {

} else {
  $msg = $file_name . ": " . $sf_url . $id . " - failed";
                                                                                        writelog($msg);
                                                                                        slack($msg, 'mattd');
}
log_time();
deleteOldLogs($log_dir, $keep_logs_days_old)

?>




