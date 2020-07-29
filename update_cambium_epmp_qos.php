<?php
/////////////////////////////////////////// Includes //////////////////////////////////////////////

require_once (__DIR__ . '/maestro_api.php');
require_once (__DIR__ . '/epmp_profiles.php');

///////////////////////////////////////////////// GLOBALS /////////////////////////////////////////////



///////////////////////////////////////////////  FUNCTIONS  ///////////////////////////////////////////


function determine_ovrd($snmp_data) {
  if (($snmp_data['up']['rate'] + $snmp_data['down']['rate']) > 1000000) return 1;
    else return 0;
}

/*
function data_same($sf_data, $remote_data) {
  if (($sf_data->profile->num == $remote_data->profile->num) and
      ($sf_data->ovrd == $remote_data->ovrd)) {
    return 1;
  } else {
    return 0;
  }
}
*/
/*
function get_profile($num) {
  global $profiles;
  foreach ($profiles as $type) {
    if (isset($type[$num])) return $type[$num];
  }
  return 0;
}
*/
/*
function determine_profile($sf_data) {
  global $profiles;

  if ($sf_data->ovrd == 1) return $profiles['default'][0];

  $type = ($sf_data->sf_dn === $sf_data->sf_up) ? 'symm' : 'asym';

  foreach ($profiles[$type] as $i => $profile) {
    $previous = isset($profiles[$type][$i -1]) ? $profiles[$type][$i -1]->dn : 0;
    $current = $profile->dn;
    if (($previous < $sf_data->sf_dn) and ($sf_data->sf_dn <= $current)) return $profile;
  }
  return $profiles['default'][0];
}
*/

function do_ePMP($sf_data) {
  global $max_profile_num;
  global $individual_success;
  global $sf_case_comment_arr;
  global $sf_opp_radio_mir_arr;
  global $rel_path;
  global $sf_url;
  global $product_type;
  $product_type = 'ePMP';
  $max_profile_num = 0;

  $id = $sf_data->Id;
  $opp_id = $sf_data->Opportunity__c;
  $ip = $sf_data->SU_IP_Address__c;
  $last_mod_id = $sf_data->LastModifiedById;
  try {
                                                                                        heavylog("GETTING SNMP_DATA");
    //$snmp_data = new stdClass();
    $snmp_data = [];
    $mac_oid             =  '.1.3.6.1.2.1.2.2.1.6.3'; //MAC in all caps with dashes
    $qos_profile_oid = '.1.3.6.1.4.1.17713.21.3.8.2.30.0';
    $snmp_dn_oid = '.1.3.6.1.4.1.17713.21.3.8.4.1.1.4.1';
    $snmp_up_oid = '.1.3.6.1.4.1.17713.21.3.8.4.1.1.5.1';
    //error_reporting(E_ALL & ~(E_NOTICE|E_WARNING));
    //$snmp_data->mac = strtoupper(fix_mac_addr_missing_zeroes(str_replace([' ','-'],":",trim(get_snmp_data($ip,$mac_oid)))));
    $snmp_data['mac'] = strtoupper(fix_mac_addr_missing_zeroes(str_replace([' ','-'],":",trim(get_snmp_data($ip,$mac_oid)))));
    $snmp_data['down']['rate'] = get_snmp_data($ip, $snmp_dn_oid);
    $snmp_data['up']['rate'] = get_snmp_data($ip, $snmp_up_oid);
    //$snmp_data['profile'] = get_profile(get_snmp_data($ip,$qos_profile_oid)); // gets profile number by snmp and then determines profile
    error_reporting(E_ALL);
    $snmp_data['ovrd'] = determine_ovrd($snmp_data);
											heavylog("GETTING SF_DATA");
    $sf_data->sf_dn = round($sf_data->New_MIR_Down__c * 1024);
    $sf_data->sf_up = round($sf_data->New_MIR_Up__c * 1024);
    $sf_data->sf_ovrd = ($sf_data->Override_Radio__c == 'true') ? 1 : 0;
    $sf_determined_profile = epmp_get_higher_profile($sf_data->sf_dn, $sf_data->sf_up);
    if ($sf_data->sf_ovrd) {
      $sf_data->sf_dn = 1000000;
      $sf_data->sf_up = 1000000;
    } else {
      $sf_data->sf_dn = $sf_determined_profile->dn;
      $sf_data->sf_up = $sf_determined_profile->up;
    }
    
    if (data_same($sf_data, $snmp_data)) {
      $msg = "Data is the same. No need to update";
											writelog($msg);
											$sf_case_comment_arr[] = sf_case_comment($id,$msg);
      $individual_success['successful']++;
      respond_early();
      return 1;
    }
     
      

                                                                                        heavylog("REQUESTING TOKEN FROM API ");
      $api_token_str = maestro_get_api_token(MAESTRO_CLIENT_ID, MAESTRO_CLIENT_SEC);
                                                                                        heavylog("CREATING ARRAY FOR GET REQUEST");
      $get_arr = ["product"];
      $maestro_result = maestro_api_update('GET', $api_token_str, $snmp_data['mac'], $get_arr);
                                                                                        heavylog("maestro_result => $maestro_result");
      $decoded_maestro_result = json_decode($maestro_result);
                                                                                        heavylog("decoded_maestro_result: ");
                                                                                        heavylog($decoded_maestro_result);
      if (!preg_match("+$product_type+", $decoded_maestro_result->data[0]->product)) {
	$msg = "The product: " . $decoded_maestro_result->data[0]->product . " is not compatible with this script. Expecting $product_type product";
											writelog("$msg");
        return 0;
      }


      $put_arr = ["template" => $product_type.
                   '__D'.str_replace(".", "_", strval($sf_data->sf_dn)).
                   '__U'.str_replace("." ,"_", strval($sf_data->sf_up))];
                                                                                        heavylog("put_arr: ");
                                                                                        heavylog($put_arr);
                                                                                        heavylog("SETTING JSON FOR API PUT");
      $put_json = json_encode($put_arr);

      $maestro_result = maestro_api_update('PUT', $api_token_str, $snmp_data['mac'], $put_json);

                                                                                        heavylog("maestro_result => $maestro_result");
      if (!$maestro_result) {
        $msg = "$rel_path: maestro update error: $maestro_result";
											writelog("$msg");
                                                                                        slack($msg, 'mattd');
        return 0;
      } else {
        $decoded_maestro_result = json_decode($maestro_result);
                                                                                        heavylog("decoded_maestro_result: ");
                                                                                        heavylog($decoded_maestro_result);
        if (($decoded_maestro_result->error) and (!empty($decoded_maestro_result->error))) { 
          $msg = "Error reported from API: " . $decoded_maestro_result->error->message;
											writelog("$rel_path: $sf_url/$id - $ip - $msg");  
        } else {
          respond_early();
          sleep(50); //waiting for radio to reboot 

                                                                                        writelog("WAITING FOR RADIO TO REBOOT...");
                                                                                        heavylog("IGNORING NOTICE AND WARNING MESSAGES WHILE WAITING");
          error_reporting(E_ALL & ~(E_NOTICE|E_WARNING));
        }
      }
      while (!$followup_ping) {
                                                                                        writelog(".");
        $followup_ping = get_snmp_data($ip, '.1.3.6.1.2.1.1.1.0'); 
        sleep(2);
      }
                                                                                        heavylog("DONE WAITING");
                                                                                        heavylog("\nGETTING SNMP_FOLLOWUP_READ_DATA");
      $snmp_followup = [];
      error_reporting(E_ALL & ~(E_NOTICE|E_WARNING));
      $snmp_followup['down']['rate'] = get_snmp_data($ip,$snmp_dn_oid);
      $snmp_followup['up']['rate'] = get_snmp_data($ip,$snmp_up_oid);
      //$snmp_followup['profile'] = get_profile(get_snmp_data($ip,$qos_profile_oid));
      $snmp_followup['ovrd'] = determine_ovrd($snmp_followup);
      error_reporting(E_ALL); 
      //////////check new data against sf data and if good, callback to sf and update values
                                                                                        heavylog("\nCHECKING IF SF_DATA AND SNMP_FOLLOWUP_READ_DATA ARE THE SAME");
      if (data_same($sf_data,$snmp_followup)) {
                                                                                        heavylog("\nTHEY ARE THE SAME");
                                                                                        heavylog("\nADDING NEW RADIO MIR VALUES TO SF RADIO MIR ARRAY");
        $sf_opp_radio_mir_arr[] = sf_1024_radio_mir($opp_id,$sf_data->sf_dn,$sf_data->sf_up,$snmp_followup['ovrd']);
        $qos_status = ($snmp_followup['ovrd']) ? 'disabled' : 'enabled';
                                                                                        heavylog("\nCONVERTING BPS VALUES TO MBPS");
        $old_ssh_dn_Mbps = isset($snmp_data['down']['rate']) ? round(($snmp_data['down']['rate'] / 1024),2) : "undetermined";
        $old_ssh_up_Mbps = isset($snmp_data['up']['rate']) ? round(($snmp_data['up']['rate'] / 1024),2) : "undetermined";
        $new_ssh_dn_Mbps = isset($snmp_followup['down']['rate']) ? round(($snmp_followup['down']['rate'] / 1024),2) : "undetermined";
        $new_ssh_up_Mbps = isset($snmp_followup['up']['rate']) ? round(($snmp_followup['up']['rate'] / 1024),2) : "undetermined";
                                                                                        heavylog("\nCREATING MESSAGE TO ADD TO CASE COMMENT ARRAY");
        $msg = "Download updated from $old_ssh_dn_Mbps to $new_ssh_dn_Mbps Mbps.\n".   
               "Upload updated from $old_ssh_up_Mbps to $new_ssh_up_Mbps Mbps.\n".  
               "Radio traffic shaping is $qos_status.\n".
               "User ID: $last_mod_id";
                                                                                        heavylog("\nADDING MESSAGE TO CASE COMMENT ARRAY");
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
                                                                                        heavylog("\nINCREMENTING INDIVIDUAL SUCCESS");
        $individual_success['successful']++;
      } else {
        $msg = "$rel_path: $sf_url/$id - $ip - update failed. Data is not as intended after update";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      }
//    }

  } catch (exception $e) {
    $catch_msg = "$rel_path: $sf_url/$id - $ip - Caught exception: $e";
                                                                                        writelog("\nCAUGHT EXCEPTION");
                                                                                        slack($catch_msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$catch_msg);
                                                                                        heavylog("\nSETTING INDIVUDUAL SUCCESS BACK TO 0");
    $individual_success['successful'] = 0;
  }
}
