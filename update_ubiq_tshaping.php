<?php
/////////////////////////////////////////// Includes //////////////////////////////////////////////
//////// This file depends on 
require_once (COMMON_PHP_DIR . '/vendor/autoload.php');
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('phpseclib\\', realpath(COMMON_PHP_DIR . '/vendor/phpseclib/phpseclib/phpseclib'));
$loader->register();
use phpseclib\Crypt\RSA;
use phpseclib\Net\SSH2;
use phpseclib\Net\SCP;

/////////////////////////////////////////// GLOBAL VARS ///////////////////////////////////////////////

$supported_models = '/( M5| 5AC)/'; ////strings that must be found in the SNMP MIB of the device when polling the model_oids.

$model_oids = ['.1.2.840.10036.3.1.2.1.3.5',
               '.1.2.840.10036.3.1.2.1.3.10',
               '.1.2.840.10036.3.1.2.1.3.15',
               '.1.2.840.10036.3.1.2.1.3.7',
               '.1.2.840.10036.3.1.2.1.3.12'];
///////////////////////////////////////////////  FUNCTIONS  ///////////////////////////////////////////

function ssh_cmd($ip,$cmd,$wait = 1.5,$ping_wait = 1) {
// ping_wait is unused at the moment
// return traffic shaping data
// requires phpseclib\SSH
  try {
     
    global $ubiq_su_user;
    global $ubiq_su_pws;
    $data = '';
    if(!empty($ip)){
      //if (ping_port($ip, $port = 22, $ping_wait)) {
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
											writelog("\n" . __FUNCTION__ . "ERROR - ssh session failed");
          return 0;
        }
      //} else {
											//writelog("\nERROR - $ip - missed ping");
        //return 0;
      //}
    } else {
											writelog("\n" . __FUNCTION__ . ": ERROR - ip is empty");
      return 0;
    }
  } catch (exception $e) {
											writelog("\n" . __FUNCTION__ . ": $e->faultstring");
  }
}



function ssh_get_data($ip, $file,$wait = 2, $ping_wait = 1) {
  $cmd[] = "grep -E '(tshaper|netconf)' /tmp/" . $file . ".cfg";
  if ($result = ssh_cmd($ip,$cmd,$wait,$ping_wait)) {
    $data = array_map('trim',explode(PHP_EOL,$result));
    sort($data);
    return $data;
  } elseif (($wait = 10) and ($result = ssh_cmd($ip,$cmd,$wait = 10,$ping_wait))) { //waits longer the second time if first fails
											slack("\n" . __FUNCTION__ . "failed 1st attempt. Increasing wait time to $wait seconds",'mattd');
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
    if (preg_match('/netconf\.(\d+)\.devname=([ea]th0)$/',$item,$match)) {
      $conf_num = $match[1];
      $int_name = $match[2];
      $result3['int_names'][] = $int_name;
      if (preg_match('/eth0/', $int_name)) {
        $int_arr = 'down';
      } elseif (preg_match('/ath0/', $int_name)) {
        $int_arr = 'up';
      }
      $result3[$int_arr]['conf_num'] = $conf_num;
      $result3[$int_arr]['int_name'] = $int_name;
      $result3['missing']++;
    }
  }
  if (!isset($result3['down']) or !isset($result3['up'])) {
                                                                                        writelog("\n" . __FUNCTION__ . " ERROR - at least one interface was not found");
    return 0;
  }
  if (preg_grep('/tshaper(\.\d+)?(\.output)?\.status=disabled/',$result2)) {
    $ssh_ovrd = 1;
  } else {
    $ssh_ovrd = 0;
  }
  $result3['ovrd'] = $ssh_ovrd;
  foreach($result2 as $item) {
    if (preg_match("/tshaper\.(\d+)\.devname=eth0$/",$item,$match)) {
      $tshaper_down_int_num = $match[1];
      $result3['down']['tshaper_num'] = $tshaper_down_int_num;
      $result3['missing']--;
    } elseif (preg_match("/tshaper\.(\d+)\.devname=ath0$/",$item,$match)) {
      $tshaper_up_int_num = $match[1];
      $result3['up']['tshaper_num'] = $tshaper_up_int_num;
      $result3['missing']--;
    }
  }
                                                                                        heavylog("\n" . __FUNCTION__ . " result3:");
                                                                                        heavylog($result3);
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
                                                                                        heavylog("\n" . __FUNCTION__ . " vals:");
                                                                                        heavylog($vals);
////////
  $tshaper_str = "\n";
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
//////// do config backups on device and make htb_devices.txt file
  $dn_name = $ssh_data['down']['int_name'];
  $up_name = $ssh_data['up']['int_name'];
  $cmds[] = '[ -f /tmp/system.cfg.backup ] || cp /tmp/system.cfg /tmp/system.cfg.backup; cp /tmp/system.cfg /tmp/system.cfg.b4_update.bak; echo ' . 
    $dn_name . ' > /tmp/htb_devices.txt; echo ' . $up_name . ' >> /tmp/htb_devices.txt ';
//////// remove all tshaper values and add new ones, then save
  $cmds[] = "sed -i -e '/tshaper.*/d' -e '/^$/d' /tmp/system.cfg; echo '" . $tshaper_str . "' >> /tmp/system.cfg";
  $cmds[] = "sed -i '/^$/d' /tmp/system.cfg; /usr/etc/rc.d/rc.softrestart save";
                                                                                        heavylog("\n" . __FUNCTION__ . " cmds:");
                                                                                        heavylog($cmds);
  if ($result = ssh_cmd($ip,$cmds)) {
                                                                                        heavylog("\n" . __FUNCTION__ . " ssh_cmd result:\n");
                                                                                        heavylog("\n$result\n");
    return 1;
  } elseif (($wait = 10) and ($result = ssh_cmd($ip, $cmds, $wait))) {
											heavylog("\n" . __FUNCTION__ . " ssh_cmd result:\n");
											heavylog("\n$result\n");
											slack("\n" . __FUNCTION__ . "failed 1st attempt. Increasing wait time to $wait seconds",'mattd');
    return 1;
  } else { 
    return 0;
  }
}

function do_ubiq($sf_data) {
  global $supported_models;
  global $model_oids;
  global $individual_success;
  global $sf_case_comment_arr;
  global $sf_opp_radio_mir_arr;
  global $rel_path;
  global $sf_url;
  try {
                                                                                        heavylog("\nLOOP ITERATION BEGIN");
                                                                                        heavylog("\nSETTING VARS FROM SF_DATA");
    $id = $sf_data->Id;
    $opp_id = $sf_data->Opportunity__c;
    $ip = $sf_data->SU_IP_Address__c;
    $last_mod_id = $sf_data->LastModifiedById;  // identifies the user that ran the automation
                                                                                        heavylog("\nSETTING SF_DN AND SF_UP");
    $sf_data->sf_dn = round($sf_data->New_MIR_Down__c * 1024);
    $sf_data->sf_up = round($sf_data->New_MIR_Up__c * 1024);
                                                                                        heavylog("\nSETTING OVERRIDE_RADIO");
    if ($sf_data->Override_Radio__c == 'true') {
      $sf_data->sf_ovrd = 1;
    } else {
      $sf_data->sf_ovrd = 0;
    }

                                                                                        heavylog("\nid: $id");
                                                                                        heavylog("\nopp_id: $opp_id");
                                                                                        heavylog("\nip: $ip");
                                                                                        heavylog("\nlast_mod_id: $last_mod_id");
                                                                                        writelog("\nsf_ovrd: $sf_data->sf_ovrd");
                                                                                        writelog("\nsf_dn: $sf_data->sf_dn");
                                                                                        writelog("\nsf_up: $sf_data->sf_up");
                                                                                        heavylog("\nGETTING MODEL BY SNMP");
                                                                                        heavylog("\nIGNORING NOTICE AND WARNING MESSAGES WHILE POLLING");
    error_reporting(E_ALL & ~(E_NOTICE|E_WARNING));
    $model = '';
    foreach ($model_oids as $oid) {
      if ($model = get_snmp_data($ip, $oid, 1)) break;
    }
                                                                                        heavylog("\nSETTING WARNING MESSAGES BACK TO 'ALL'");
    error_reporting(E_ALL);
                                                                                        heavylog("\nmodel: $model");
    if(empty($model)) {
      $msg = "Radio model was not detected. Please try once more. If it fails more than once with this same error, send to engineering.";
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      $msg = "Radio model was not detected by SNMP.";
                                                                                        writelog("\n$msg");
                                                                                        slack("$rel_path: $sf_url/$id - $msg", 'mattd');
      return 0;
    } elseif (!preg_match($supported_models, $model)){
      $msg = "Radio model is not supported.";
                                                                                        writelog("\n$msg");
                                                                                        slack("$rel_path: $sf_url/$id - $msg", 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      return 0;
    }
                                                                                        heavylog("\nGETTING SSH DATA RUNNING CONFIG");
    $ssh_data = ssh_get_data($ip, 'running');
                                                                                        heavylog("\nTESTING IF THERE IS DATA");
    if ($ssh_data) {
                                                                                        heavylog("\nDATA FOUND");
                                                                                        heavylog("\nssh_data:");
                                                                                        heavylog($ssh_data);
                                                                                        heavylog("\nPARSING SSH DATA");
      $ssh_parsed_data = parse_ssh_data($ssh_data); // data from a single radio (/tmp/running.cfg)
      unset($ssh_data);
                                                                                        heavylog("\nssh_parsed_data:");
                                                                                        heavylog($ssh_parsed_data);
                                                                                        writelog("\n   ssh_ovrd: " . $ssh_parsed_data['ovrd']);
                                                                                        writelog("\n   ssh_dn: " .   $ssh_parsed_data['down']['rate']);
                                                                                        writelog("\n   ssh_up: " .   $ssh_parsed_data['up']['rate']);

                                                                                        heavylog("\nCHECKING IF SF_DATA IS THE SAME AS SSH_PARSED_DATA");
      if ((!data_same($sf_data, $ssh_parsed_data)) or ($ssh_parsed_data['missing'] != 0))  {
        $msg = "$sf_url/$id : $ip : UPDATING RADIO BY SSH...";
                                                                                        writelog("\n$msg");
                                                                                        heavylog("\nGENERATING TSHAPER STRING");
        $tshaper_str = generate_tsh_str($sf_data, $ssh_parsed_data);
                                                                                        heavylog("\ntshaper_str:");
                                                                                        heavylog($tshaper_str);
                                                                                        heavylog("\nEXECUTING UPDATE BY SSH");
        $ssh_update_success = ssh_update($ip,$sf_data, $ssh_parsed_data, $tshaper_str);
                                                                                        heavylog("\nCHECKING IF SSH UPDATE SUCCESS");
        if ($ssh_update_success) {
                                                                                        heavylog("\nSSH UPDATE IS SUCCESSFUL");
          $wait_sec = 2;
                                                                                        heavylog("\nWAITING $wait_sec SECONDS...");
          sleep($wait_sec);
                                                                                        heavylog("\nGETTING FOLLOWUP SSH DATA");
          $followup_start_time = time('now');
											heavylog("\nfollowup_start_time: $followup_start_time");
          $ssh_followup_data = ssh_get_data($ip, 'running');
          $followup_end_time = time('now');
                                                                                        heavylog("\nfollowup_end_time: $followup_end_time");
          $followup_duration = ($followup_end_time - $followup_start_time);
											heavylog("\nfollowup_duration: $followup_duration");
                                                                                        heavylog("\nCHECKING IF THERE IS FOLLOWUP DATA");
          if ($ssh_followup_data) {
                                                                                        heavylog("\nTHERE IS FOLLOWUP DATA\n");
                                                                                        writelog("success");
          } else {
            $msg = "$rel_path: $sf_url/$id - NO FOLLOWUP DATA";
                                                                                        slack($msg, 'mattd');
                                                                                        writelog("\n$msg");
                                                                                        heavylog("\nCONTINUE");
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
            return 0;
          }
          //unset($ssh_parsed_data);
                                                                                        heavylog("\nPARSING FOLLOWUP DATA");
          $ssh_parsed_followup_data = parse_ssh_data($ssh_followup_data);
                                                                                        heavylog("\nssh_parsed_followup_data:");
                                                                                        heavylog($ssh_parsed_followup_data);

                                                                                        heavylog("\nUNSETTING SSH_FOLLOWUP_DATA");
          unset($ssh_followup_data);
                                                                                        heavylog("\nSETTING NEW_SSH_OVRD");
          $new_ssh_ovrd = $ssh_parsed_followup_data['ovrd'];
                                                                                        heavylog("\nSETTING NEW_SSH_DN");
          $new_ssh_dn = trim($ssh_parsed_followup_data['down']['rate']);
                                                                                        heavylog("\nSETTING_NEW_SSH_UP");
          $new_ssh_up = trim($ssh_parsed_followup_data['up']['rate']);
                                                                                        writelog("\n  new_ssh_ovrd: $new_ssh_ovrd");
                                                                                        writelog("\n  new_ssh_dn: $new_ssh_dn");
                                                                                        writelog("\n  new_ssh_up: $new_ssh_up");
        } else {
          $msg = "$rel_path: $sf_url/$id - SSH UPDATE FAILED";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
                                                                                        heavylog("\nCONTINUE");
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
          return 0;
        }
        //////////check new data against sf data and if good, sf update values
                                                                                        heavylog("\nCHECKING IF SF_DATA IS THE SAME AS SSH_PARSED_FOLLOWUP_DATA");
        if (data_same($sf_data, $ssh_parsed_followup_data)) {
                                                                                        heavylog("\nSF_DATA IS THE SAME AS SSH_PARSED_FOLLOWUP_DATA");
                                                                                        heavylog("\nSETTING SF RADIO MIR VALUES TO UPDATE");
          $sf_opp_radio_mir_arr[] = sf_1024_radio_mir($opp_id,$new_ssh_dn,$new_ssh_up,$new_ssh_ovrd);
                                                                                        heavylog("\nDETERMINING FRIENDLY_OVRD");
          if ($new_ssh_ovrd) $tshaping = 'disabled';
          else $tshaping = 'active';
                                                                                        heavylog("\nSETTING CASE LOG MESSAGE VARIABLES");
          $old_ssh_dn_Mbps = round(($ssh_parsed_data['down']['rate'] / 1024),2);
          $old_ssh_up_Mbps = round(($ssh_parsed_data['up']['rate'] / 1024),2);
          $new_ssh_dn_Mbps = round(($new_ssh_dn / 1024),2);
          $new_ssh_up_Mbps = round(($new_ssh_up / 1024),2);
          $msg = "Download updated from $old_ssh_dn_Mbps to $new_ssh_dn_Mbps Mbps.\n".
                 "Upload updated from $old_ssh_up_Mbps to $new_ssh_up_Mbps Mbps.\n".
                 "Radio traffic shaping is $tshaping.\n".
                 "User ID: $last_mod_id";

                                                                                        heavylog("\n$msg");
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
                                                                                        heavylog("\nINCREMENTING INDIVIDUAL SUCCESS");
          $individual_success['successful']++;
        } else {



          $msg = "$rel_path: $sf_url/$id - SU UPDATE FAILED - data is not the same after update";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg,'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
        }
      } else {
        $msg = "\n$sf_url/$id : $ip : Data is the same. Nothing to change.";
                                                                                        writelog("\n$msg");
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
        $individual_success['successful']++;
                                                                                        heavylog("\nINCREMENTING INDIVIDUAL SUCCESS");
      } 
    } else {
        $msg = "$sf_url/$id : $ip : unable to get ssh data";
                                                                                        writelog("\n$msg");
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
    }
  } catch (exception $e) {
                                                                                        heavylog("\nCATCH EXCEPTION");
    $catch_msg = "$rel_path: $sf_url/$id - Caught exception: $e";
                                                                                        slack($catch_msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$catch_msg);
                                                                                        heavylog("\nSETTING INDIVIDUAL SUCCESS TO 0");
    $individual_success['successful'] = 0; 
  }
}

