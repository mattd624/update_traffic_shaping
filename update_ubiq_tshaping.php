<?php
/////////////////////////////////////////// Includes //////////////////////////////////////////////
ini_set("soap.wsdl_cache_enabled", "0");  // clean WSDL for develop
ini_set("allow_url_fopen", true);
date_default_timezone_set('America/Los_Angeles');
include realpath(__DIR__ . '/../commonDirLocation.php');
require_once (COMMON_PHP_DIR . '/toolkit/soapclient/SforceEnterpriseClient.php');
require_once (COMMON_PHP_DIR . '/partial.userAuth.php'); //this contains the credentials used to log into our org when the API is called
require realpath(COMMON_PHP_DIR . '/creds.php');
include realpath(COMMON_PHP_DIR . '/vendor/autoload.php');
include realpath(COMMON_PHP_DIR . '/SlackMessagePost.php');
include realpath(COMMON_PHP_DIR . '/checkOrgID.php');
include realpath(COMMON_PHP_DIR . '/respond.php');
include realpath(COMMON_PHP_DIR . '/parseNotification.php');
include realpath(COMMON_PHP_DIR . '/deleteOldLogs.php');
include realpath(__DIR__ . '/sf_update.php');
include realpath(__DIR__ . '/ping_port.php');
include realpath(__DIR__ . '/data_same.php');
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('phpseclib\\', realpath(COMMON_PHP_DIR . '/vendor/phpseclib/phpseclib/phpseclib'));
$loader->register();
use phpseclib\Crypt\RSA;
use phpseclib\Net\SSH2;
///////////////////////////////////////////////// GLOBALS /////////////////////////////////////////////
$logging_on = 1;
///////////////////////////////////////////////  FUNCTIONS  ///////////////////////////////////////////
function writelog($log) {
    global $logging_on;
    if ($logging_on) {
      file_put_contents(__DIR__ . '/log_ubiq/' . @date('Y-m-d') . '.log', print_r($log, true), FILE_APPEND);
    }
}
function log_time() {
  /////////////..........depends on writelog()
  $tmstmp = date('D, \d\a\y d \o\f F, G:i:s');
  writelog("\n" . $tmstmp . "\n\n\n");
}
function ssh_cmd($ip,$cmd,$wait = 1) {
// return traffic shaping data
// requires phpseclib\SSH
  try {
    global $ubiq_su_user;
    global $ubiq_su_pws;
    $data = '';
    if(!empty($ip)){
      if (ping_port($ip, $port = 22, $wait_secs = 0.5)) {
        $ssh = new SSH2($ip);
        $ssh->timeout = $wait;
        
        foreach ($ubiq_su_pws as $pw) {
          if ($ssh->login($ubiq_su_user,$pw)) {
            if (is_array($cmd)) {
              foreach ($cmd as $c) {
                $ssh->timeout = $wait;
                if (preg_match('/.* save/',$c)) {
                  $ssh->timeout = 10;
                }
                $ssh->write("$c\r");
                $data = $data . $ssh->read();
  //writelog($data);
              }
            } else {
              $ssh->timeout = $wait;
              if (preg_match('/.* save/',$cmd)) {
                $ssh->timeout = 10;
              }
              $ssh->write("$cmd\r");
              $data = $ssh->read(); 
  //writelog($data);
            }
            $ssh->disconnect();
            unset($ssh);
            return $data;
          }
          return 0;
        }
      } else {
        writelog("ERROR - $ip - missed ping");
        return 0;
      }
    } else {
      return 0;
    }
  } catch (exception $e) {
    writelog($e);
  }
}
function ssh_get_data($ip, $file) {
  $cmd[] = "grep -E '(tshaper|netconf)' /tmp/" . $file . ".cfg";
  if ($result = ssh_cmd($ip,$cmd)) {
    $data = array_map('trim',explode(PHP_EOL,$result));
    sort($data);
    return $data;
  } else { 
    return 0;
  }
}
function parse_ssh_data($data) {
  $result2 = preg_grep('/^(tshaper|netconf).*$/',$data);
  unset($result1);
  sort($result2);
 
  $result3 = [];
  $result3['down']['rate'] = 0;
  $result3['up']['rate'] = 0;
  $result3['int_names'] = [];
  $result3['missing'] = 0;
  foreach ($result2 as $item) {
    if (preg_match('/netconf\.(\d+)\.devname=([ea]th(\d+))$/',$item,$match)) {
      $conf_num = $match[1];
      $int_name = $match[2];
      $int_num = $match[3];
      $result3['int_names'][] = $int_name;
      if (preg_match('/eth.*/', $int_name)) {
        $int_arr = 'down';
      } elseif (preg_match('/ath.*/', $int_name)) {
        $int_arr = 'up';
      }
      $result3[$int_arr]['conf_num'] = $conf_num;
      $result3[$int_arr]['int_name'] = $int_name;
      $result3[$int_arr]['int_num'] = $int_num;
      $result3['missing']++;
    }
  }
  if (!isset($result3['down']) or !isset($result3['up'])) {
                                                                                        writelog("ERROR - at least one interface was not found");
    return 0;
  }
  if (preg_grep('/tshaper(\.\d+)?(\.output)?\.status=disabled/',$result2)) {
    $ssh_ovrd = 1;
  } else {
    $ssh_ovrd = 0;
  }
  $result3['ovrd'] = $ssh_ovrd;
  foreach($result2 as $item) {
    if (preg_match("/tshaper\.(\d+)\.devname=eth\d+$/",$item,$match)) {
      $tshaper_down_int_num = $match[1];
      $result3['down']['tshaper_num'] = $tshaper_down_int_num;
      $result3['missing']--;
    } elseif (preg_match("/tshaper\.(\d+)\.devname=ath\d+$/",$item,$match)) {
      $tshaper_up_int_num = $match[1];
      $result3['up']['tshaper_num'] = $tshaper_up_int_num;
      $result3['missing']--;
    }
  }
                                                                                        //writelog($result3);
  if ($result3['missing'] == 0) {
    foreach ($result2 as $item) {
      if (preg_match("/tshaper\." . $result3['down']['tshaper_num'] . "\.output.rate=(\d+)/",$item,$match)) {
        $result3['down']['rate'] = $match[1];
      } elseif (preg_match("/tshaper\." . $result3['up']['tshaper_num'] . "\.output.rate=(\d+)/",$item,$match)) {
        $result3['up']['rate'] = $match[1];
      }
    }
  } 
  return $result3;
}
function generate_tsh_str($sf_data, $ssh_data) {
//helper function for parse_ssh_data()
//used if there are ANY missing tshapers
  if ($sf_data->sf_ovrd) {
    $tshaper_output_status = 'disabled';
  } else {
    $tshaper_output_status = 'enabled';
  }
////////
  $vals = [
            1 => [
                   $ssh_data['down']['int_name'],
                   $sf_data->sf_dn
                 ],    
            2 => [ 
                   $ssh_data['up']['int_name'], 
                   $sf_data->sf_up, 
                 ],
          ];
                                                                                        writelog("vals:");
                                                                                        writelog($vals);
////////
  $tshaper_str = '';
  $tshaper_str .= 'tshaper.status=enabled' . "\n";
  foreach($vals as $k => $v) {
    if ($k > 1) $tshaper_str .= "\n"; //adding a newline between additional lines in string
      $tshaper_str .= 'tshaper.' . $k . '.devname=' . $vals[$k][0] . "\n" .
                      'tshaper.' . $k . '.status=enabled' . "\n" .
                      'tshaper.' . $k . '.input.burst=0' . "\n" .
                      'tshaper.' . $k . '.input.rate=21000' . "\n" .
                      'tshaper.' . $k . '.input.status=disabled' . "\n" .
                      'tshaper.' . $k . '.output.burst=0' . "\n" .
                      'tshaper.' . $k . '.output.rate=' . $vals[$k][1] .  "\n" .
                      'tshaper.' . $k . '.output.status=' . $tshaper_output_status;
  }
  return $tshaper_str;
}
/*
function data_same($sf_data, $remote_data) {
  $sf_ovrd = $sf_data->sf_ovrd;
  $sf_dn = $sf_data->sf_dn;
  $sf_up = $sf_data->sf_up;
  $remote_ovrd = $remote_data['ovrd'];
  $remote_dn = $remote_data['down']['rate'];
  $remote_up = $remote_data['up']['rate'];
  
  if (($sf_ovrd == $remote_ovrd) and 
      ($sf_dn == $remote_dn) and 
      ($sf_up == $remote_up)) {
    return 1;
  } else { 
    return 0;
  }
}
*/
function ssh_update($sf_data, $ssh_data, $tshaper_str) {
  $ip = $sf_data->SU_IP_Address__c;
  $cmds = [];
//////// do config backups on device
  $cmds[] = '[ -f /tmp/system.cfg.backup ] || cp /tmp/system.cfg /tmp/system.cfg.backup';
  $cmds[] = "cp /tmp/system.cfg /tmp/system.cfg.b4_update.bak";
//////// make htb_devices.txt file
  $dn_name = $ssh_data['down']['int_name'];
  $up_name = $ssh_data['up']['int_name'];
  $cmds[] = 'grep ' . $dn_name . ' /tmp/htb_devices.txt || echo ' . $dn_name . ' >> /tmp/htb_devices.txt';
  $cmds[] = 'grep ' . $up_name . ' /tmp/htb_devices.txt || echo ' . $up_name . ' >> /tmp/htb_devices.txt';
//////// remove all tshaper values 
  $cmds[] = "sed -i -e '/tshaper.*/d' -e '/^$/d' /tmp/system.cfg";
//////// add new tshaper values
  $cmds[] = "echo '" . $tshaper_str . "' >> /tmp/system.cfg";
  $cmds[] = "/usr/etc/rc.d/rc.softrestart save";
  //writelog("\n");
  //writelog("$cmds");
  if ($result = ssh_cmd($ip,$cmds)) {
  writelog("\nssh_cmd result:\n");
  writelog("\n$result\n");
    return 1;
  } else {
    return 0;
  }
}
/*
function sf_update($id,$dn,$up,$ovrd) {
  global $USERNAME;
  global $PASSWORD;
  $sf_dn = ($dn / 1024);
  $sf_up = ($up / 1024);
  $wsdl = COMMON_PHP_DIR . '/wsdl/production.enterprise.wsdl.xml';
  $mySforceConnection = new SforceEnterpriseClient();
  $mySoapClient = $mySforceConnection->createConnection($wsdl);
  $mylogin = $mySforceConnection->login($USERNAME, $PASSWORD);
  $options = new QueryOptions(300);  //Set query to return results in chunks
  $mySforceConnection->setQueryOptions($options);
  $obj = 'Opportunity'; // salesforce object to query
  $data = [$id,$sf_dn,$sf_up,$ovrd]; //the number of data and number of fields must match
  $fields = 'Id,Radio_MIR_Down__c,Radio_MIR_Up__c,Radio_MIR_Override__c'; 
  $fields_arr = explode(",",$fields);
  $fields_ct = count($fields_arr);
  $obj_arr = [];
  for ($i=0;$i<$fields_ct;$i++) {
    $obj_arr[$fields_arr[$i]] = $data[$i];
  }
  $sObject = (object) $obj_arr;
                                                                                        //writelog("\n");
                                                                                        //writelog($sObject);
  $createResponse = $mySforceConnection->update(array($sObject), 'Opportunity');
  return $createResponse[0]->success;
}
*/
/////////////////////////////// START EXECUTION CODE ///////////////////////////////////////////
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
//Test Array:
/*$requestArray = array(
  'OrganizationId' => '00DU0000000IjIFMA0',
  'MapsRecords' => array(
    0 => array(
        'Id' => '000kjh00000000023423',
        'Name' => 'A-S01040799',
        'SU_IP_Address__c' => '192.168.2.45',
        'AP_Standard_Name__c' => 'flbk-su-test_1',
        'MIR_Down_Mbps__c' => '7.000',
        'MIR_Up_Mbps__c' => '7.536',
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
        'MIR_Up_Mbps__c' => '6.50284809',
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
                                                                                        slack('update_ubiq_tshaping.php: Org ID check failed','mattd');
  respond('true');
  exit;
} 
//  respond('true');
//  exit;
$sf_url = 'https://na131.salesforce.com/';
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
                                                                                        writelog("\n$sf_url$id has a required value MISSING:");
                                                                                        writelog("\nsf_obj->SU_IP_Address__c: " . $sf_obj->SU_IP_Address__c);
                                                                                        writelog("\nsf_obj->MIR_Down_Mbps__c: " . $sf_obj->MIR_Down_Mbps__c );
                                                                                        writelog("\nsf_obj->MIR_Up_Mbps__c: " . $sf_obj->MIR_Up_Mbps__c );
                                                                                        writelog("\nHere is the object:\n");
                                                                                        writelog($sf_obj);
    } else {
      $sf_obj_arr[$id] = $sf_obj;
      $sf_obj_arr[$id]->sf_dn = round($sf_obj->MIR_Down_Mbps__c * 1024);
      $sf_obj_arr[$id]->sf_up = round($sf_obj->MIR_Up_Mbps__c * 1024);
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
/////// check IP address validity
    $ip_is_valid = filter_var($ip, FILTER_VALIDATE_IP);
    if (!$ip_is_valid) {
      $msg = "update_ubiq_tshaping.php: " . $sf_url . $id . " - " . $ip . " is not a valid IP address";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg,'mattd');
      continue;
    }
//////
                                                                                        writelog("\nsf_ovrd: $sf_data->sf_ovrd");
                                                                                        writelog("\nsf_dn: $sf_data->sf_dn");
                                                                                        writelog("\nsf_up: $sf_data->sf_up");
/////// Get SSH data
    $ssh_data = ssh_get_data($ip, 'running');
    if ($ssh_data) {
      $ssh_parsed_data = parse_ssh_data($ssh_data); // data from a single radio (/tmp/running.cfg)
writelog($ssh_parsed_data);
      unset($ssh_data);
                                                                                        writelog("\n   ssh_ovrd: " . $ssh_parsed_data['ovrd']);
                                                                                        writelog("\n   ssh_dn: " .   $ssh_parsed_data['down']['rate']);
                                                                                        writelog("\n   ssh_up: " .   $ssh_parsed_data['up']['rate']);
      if ((!data_same($sf_data, $ssh_parsed_data)) or ($ssh_parsed_data['missing'] != 0))  {
                                                                                        writelog("\n$sf_url/$id : $ip : Updating through ssh...");
        $tshaper_str = generate_tsh_str($sf_data, $ssh_parsed_data);
        $ssh_update_success = ssh_update($sf_data, $ssh_parsed_data, $tshaper_str);
        if ($ssh_update_success) {
          $ssh_followup_data = ssh_get_data($ip, 'running');
          if ($ssh_followup_data) {
                                                                                        writelog("success");
          } else {
                                                                                        writelog("\n\nNO FOLLOWUP DATA");
            $msg = "update_ubiq_tshaping.php: " . $sf_url . $id . " - NO FOLLOWUP DATA";
                                                                                        slack($msg, 'mattd');
            continue;
          }
          unset($ssh_parsed_data);
          $ssh_parsed_followup_data = parse_ssh_data($ssh_followup_data);
          unset($ssh_followup_data);
          $new_ssh_ovrd = $ssh_parsed_followup_data['ovrd'];
          $new_ssh_dn = trim($ssh_parsed_followup_data['down']['rate']);
          $new_ssh_up = trim($ssh_parsed_followup_data['up']['rate']);
                                                                                        writelog("\n  new_ssh_ovrd: $new_ssh_ovrd");
                                                                                        writelog("\n  new_ssh_dn: $new_ssh_dn");
                                                                                        writelog("\n  new_ssh_up: $new_ssh_up");
        } else {
writelog($ssh_parsed_followup_data);
                                                                                        writelog("SSH UPDATE FAILED");
          $msg = "update_ubiq_tshaping.php: " . $sf_url . $id . " - SSH UPDATE FAILED";
                                                                                        slack($msg, 'mattd'); 
          continue;
        }
        //////////check new data against sf data and if good, callback to sf and update values
        if (data_same($sf_data, $ssh_parsed_followup_data)) {
          $sf_update_success = sf_update($id,$new_ssh_dn,$new_ssh_up,$new_ssh_ovrd);
          if ($sf_update_success) {
            $individual_success['successful']++;
                                                                                        writelog("\nsf update success");
          } else {
                                                                                        writelog("\nSALESFORCE UPDATE FAILED");
            $msg = "update_ubiq_tshaping.php: " . $sf_url . $id . " - SALESFORCE UPDATE FAILED";
                                                                                        slack($msg, 'mattd');
          }
        } else {
          $msg = "update_ubiq_tshaping.php: " . $sf_url . $id . " - SU UPDATE FAILED - data is not the same after update";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg,'mattd');
        }
      } else {
                                                                                        writelog("\n$sf_url/$id : $ip : Data is the same. Nothing to change.");
        $individual_success['successful']++;
      } 
    } else {
                                                                                        writelog("\n$sf_url/$id : $ip : unable to get ssh data");
    }
  }//end of for loop: ($sf_obj_arr as $sf_data)
} catch (exception $e) {
  $catch_msg = "update_ubiq_tshaping.php: " . $sf_url . $id . " - Caught exception: $e";
                                                                                        slack($catch_msg, 'mattd');
  $individual_success['successful'] = 0; 
}
                                                                                        writelog("\n\n Success: ");
                                                                                        writelog((string) $individual_success['successful'] . 
                                                                                        " of " . (string) $individual_success['total'] . 
                                                                                        "\n");
ob_get_clean();
if ($individual_success['successful'] == $individual_success['total']) {
} else {
  $msg = "update_ubiq_tshaping.php: " . $sf_url . $id . " - failed";
                                                                                        writelog($msg);
                                                                                        slack($msg, 'mattd');
}
respond('true');
log_time();
?>
