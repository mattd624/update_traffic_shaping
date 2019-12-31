<?php
/////////////////////////////////////////// Includes //////////////////////////////////////////////
ini_set("soap.wsdl_cache_enabled", "0");  // clean WSDL for develop
ini_set("allow_url_fopen", true);
date_default_timezone_set('America/Los_Angeles');
include realpath(__DIR__ . '/../commonDirLocation.php');
require_once (COMMON_PHP_DIR . '/toolkit/soapclient/SforceEnterpriseClient.php');
require_once (COMMON_PHP_DIR . '/eip.userAuth.php'); //this contains the credentials used to log into our org when the API is called
require realpath(COMMON_PHP_DIR . '/creds.php');
include realpath(COMMON_PHP_DIR . '/vendor/autoload.php');
include realpath(COMMON_PHP_DIR . '/SlackMessagePost.php');
include realpath(COMMON_PHP_DIR . '/checkOrgID.php');
include realpath(COMMON_PHP_DIR . '/respond.php');
include realpath(COMMON_PHP_DIR . '/parseNotification.php');
include realpath(COMMON_PHP_DIR . '/deleteOldLogs.php');
include realpath(COMMON_PHP_DIR . '/writelog.php');
include realpath(COMMON_PHP_DIR . '/logTime.php');
include realpath(__DIR__ . '/2sf_update.php');
include realpath(__DIR__ . '/ping_port.php');
include realpath(__DIR__ . '/data_same.php');
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('phpseclib\\', realpath(COMMON_PHP_DIR . '/vendor/phpseclib/phpseclib/phpseclib'));
$loader->register();
use phpseclib\Crypt\RSA;
use phpseclib\Net\SSH2;

///////////////////////////////////////////////// GLOBALS /////////////////////////////////////////////
$logging_on = 1;
$f_name = pathinfo(__FILE__)['basename'];
$log_dir = __DIR__ . '/1log_ubiq/';
$keep_logs_days_old = 90;
$sf_url = 'https://na131.salesforce.com/';
$rel_path = substr(__FILE__, strlen($_SERVER['DOCUMENT_ROOT']));


///////////////////////////////////////////////  FUNCTIONS  ///////////////////////////////////////////

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


function ssh_update($ip,$sf_data, $ssh_data, $tshaper_str) {
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
//  writelog("\nssh_cmd result:\n");
//  writelog("\n$result\n");
    return 1;
  } else {
    return 0;
  }
}

/*
case_id = Case id;
msg = message to put in comment
requires USERNAME and PASSWORD to be set and SforceEnterpriseClient.php to be loaded from the php Salesforce toolkit

  $mySforceConnection = new SforceEnterpriseClient();
  $mySoapClient = $mySforceConnection->createConnection(WSDL);
  $mylogin = $mySforceConnection->login(SF_USER,SF_PW);
  $sObject = new stdClass();
  $sObject->ParentId = $case_id;
  $sObject->CommentBody = $msg;
//writelog($sObject);
  $createResponse = $mySforceConnection->create(array($sObject), 'CaseComment');
  return $createResponse;//[0]->success;
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
        'Id' => '5000x00000BpCfaAAF',              //Case Id
        'Opportunity__c' => '0060x000008T08oAAC',
        'SU_IP_Address__c' => '192.168.2.47',
        'MIR_Down__c' => '7.000',
        'MIR_Up__c' => '7.536',
        'Override_Radio__c' => 'false'
    ),

    1 => array(
        'Id' => '5000x00000Bpjkhsdl',              //Case Id
        'Opportunity__c' => '0060x000008T08oAAC',
        'SU_IP_Address__c' => '192.168.2.46',
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
                                                                                        slack("$rel_path: Org ID check failed",'mattd');
  respond('true');
  exit;
} 
//  respond('true');
//  exit;
try {
  $sf_obj_arr = [];
  $individual_success = [];
  $individual_success['total'] = count($requestArray['MapsRecords']);
  $individual_success['successful'] = 0;
  foreach ($requestArray['MapsRecords'] as $r) {
writelog("\n");
writelog($r);
    $opp_id = $r['Opportunity__c'];
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
      \nsf_obj->SU_IP_Address__c: $sf_obj->SU_IP_Address__c
      \nsf_obj->MIR_Down__c: $sf_obj->MIR_Down__c
      \nsf_obj->MIR_Up__c:  $sf_obj->MIR_Up__c
      \nsf_obj->Override_Radio__c: $sf_obj->Override_Radio__c
      \n\nHere is the object:\n";
                                                                                        writelog($msg);
                                                                                        writelog($sf_obj);
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$sf_obj);
    continue;
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


  foreach ($sf_obj_arr as $sf_data) {
    $id = $sf_data->Id;
    preg_match('/.*?([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}).*/',$sf_data->SU_IP_Address__c, $match);
    $ip = $match[1];
    
                                               writelog("\n________________________________________________________________________\n\nWorking IP: $ip\nid: $id");
/////// check IP address validity
    $ip_is_valid = filter_var($ip, FILTER_VALIDATE_IP);
    if (!$ip_is_valid) {
      $msg = "$rel_path: $sf_url$id - Invalid IP address: \"$ip\"";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg,'mattd');
      $msg = "Invalid IP address: \"$ip\"";
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
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
      unset($ssh_data);
                                                                                        writelog("\n   ssh_ovrd: " . $ssh_parsed_data['ovrd']);
                                                                                        writelog("\n   ssh_dn: " .   $ssh_parsed_data['down']['rate']);
                                                                                        writelog("\n   ssh_up: " .   $ssh_parsed_data['up']['rate']);
      if ((!data_same($sf_data, $ssh_parsed_data)) or ($ssh_parsed_data['missing'] != 0))  {
                                                                                        writelog("\n$sf_url/$id : $ip : Updating through ssh...");
        $tshaper_str = generate_tsh_str($sf_data, $ssh_parsed_data);
        $ssh_update_success = ssh_update($ip,$sf_data, $ssh_parsed_data, $tshaper_str);
        if ($ssh_update_success) {
          $ssh_followup_data = ssh_get_data($ip, 'running');
          if ($ssh_followup_data) {
                                                                                        writelog("success");
          } else {
                                                                                        writelog("\n\nNO FOLLOWUP DATA");
            $msg = "$rel_path: " . $sf_url . $id . " - NO FOLLOWUP DATA";
                                                                                        slack($msg, 'mattd');
            continue;
          }
          $ssh_parsed_followup_data = parse_ssh_data($ssh_followup_data);
          unset($ssh_followup_data);
          $new_ssh_ovrd = $ssh_parsed_followup_data['ovrd'];
          $new_ssh_dn = trim($ssh_parsed_followup_data['down']['rate']);
          $new_ssh_up = trim($ssh_parsed_followup_data['up']['rate']);
                                                                                        writelog("\n  new_ssh_ovrd: $new_ssh_ovrd");
                                                                                        writelog("\n  new_ssh_dn: $new_ssh_dn");
                                                                                        writelog("\n  new_ssh_up: $new_ssh_up");
        } else {
                                                                                        writelog("\nSSH UPDATE FAILED");
          $msg = "$rel_path: " . $sf_url . $id . " - SSH UPDATE FAILED";
                                                                                        slack($msg, 'mattd'); 
          continue;
        }
        //////////check new data against sf data and if good, callback to sf and update values
        $sf_opp_radio_mir_arr = [];
        $sf_case_comment_arr = [];
        if (data_same($sf_data, $ssh_parsed_followup_data)) {
          
          $sf_opp_radio_mir_arr[] = sf_radio_mir($opp_id,$new_ssh_dn,$new_ssh_up,$new_ssh_ovrd);
          
          if ($new_ssh_ovrd) $friendly_ovrd = 'active';
          else $friendly_ovrd = 'not active';
          
          $old_ssh_dn_Mbps = round(($ssh_parsed_data['down']['rate'] / 1024),2);
          $old_ssh_up_Mbps = round(($ssh_parsed_data['up']['rate'] / 1024),2);
          $new_ssh_dn_Mbps = round(($new_ssh_dn / 1024),2);
          $new_ssh_up_Mbps = round(($new_ssh_up / 1024),2);
          $msg = "Download updated from $old_ssh_dn_Mbps to $new_ssh_dn_Mbps Mbps.\n".
                 "Upload updated from $old_ssh_up_Mbps to $new_ssh_up_Mbps Mbps.\n".
                 "Radio override is $friendly_ovrd.";
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
          $individual_success['successful']++;
        } else {
          $msg = "$rel_path: " . $sf_url . $id . " - SU UPDATE FAILED - data is not the same after update";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg,'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
          continue;
        }
      } else {
        $msg = "\n$sf_url/$id : $ip : Data is the same. Nothing to change.";
                                                                                        writelog("\n$msg");
        $msg = "Data is the same. Nothing to change.";
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
        $individual_success['successful']++;
      } 
    } else {
      $msg = "$sf_url/$id : $ip : unable to get ssh data";
                                                                                        writelog("\n$msg");
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      continue;  
    }
  }//end of for loop: ($sf_obj_arr as $sf_data)
} catch (exception $e) {
  $catch_msg = "$rel_path: " . $sf_url . $id . " - Caught exception: $e";
                                                                                        slack($catch_msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$catch_msg);
  $individual_success['successful'] = 0; 
}
                                                                                        writelog("\n\n Success: ");
                                                                                        writelog((string) $individual_success['successful'] . 
                                                                                        " of " . (string) $individual_success['total'] . 
                                                                                        "\n");
ob_get_clean();
if (!($individual_success['successful'] == $individual_success['total'])) {
  $msg = "$rel_path: " . $sf_url . $id . " - failed";
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
deleteOldLogs($log_dir, $keep_logs_days_old)
?>
