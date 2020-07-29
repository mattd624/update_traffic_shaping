<?php
/////////////////////////////////////////// Includes //////////////////////////////////////////////

require_once (__DIR__ . '/maestro_api.php');
require_once (__DIR__ . '/epmp_profiles.php');
require_once (__DIR__ . '/data_same.php');

///////////////////////////////////////////////// GLOBALS /////////////////////////////////////////////

$general_radio_model_str = 'ePMP'; ///// this is for the api for building the name of the configuration template

$supported_models = '/F300/'; ////strings that must be found in the SNMP MIB of the device when polling the model_oids.
$model_oids = ['.1.3.6.1.4.1.17713.21.3.8.2.2.0'];


///////////////////////////////////////////////  FUNCTIONS  ///////////////////////////////////////////

function determine_ovrd(array $snmp_data) {
  $down = $snmp_data['down']['rate'];
  $up = $snmp_data['up']['rate'];
                                                                                        heavylog("\nDOWN: $down");
                                                                                        heavylog("\nUP: $up");
  if ($down + $up >= 1000000) {
    $ovrd = 1; 
  } else {
    $ovrd = 0;
  }
}


function do_ePMP($sf_data) {
  global $general_radio_model_str;
  global $supported_models;
  global $model_oids;
  global $individual_success;
  global $sf_case_comment_arr;
  global $sf_opp_radio_mir_arr;
  global $rel_path;
  global $sf_url;

  $id = $sf_data->Id;
  $ip = $sf_data->SU_IP_Address__c;
  $last_mod_id = $sf_data->LastModifiedById;
  try {
    $mac_oid             =  '.1.3.6.1.2.1.2.2.1.6.3'; //MAC in all caps with dashes -- does not apply to ePMPs
    $qos_dn_read_oid     =  '.1.3.6.1.4.1.17713.21.3.8.4.1.1.4.1';
    $qos_up_read_oid     =  '.1.3.6.1.4.1.17713.21.3.8.4.1.1.5.1';
    
                                                                                        heavylog("\nGETTING RADIO MODEL STRING");
    $radio_model_str = '';
    error_reporting(E_ALL & ~(E_NOTICE|E_WARNING));
    foreach ($model_oids as $oid) {
      if ($radio_model_str = get_snmp_data($ip, $oid)) break;
    }
    error_reporting(E_ALL);
                                                                                        writelog("\nradio_model_str: $radio_model_str");
                                                                                        heavylog("\nCHECKING RADIO MODEL STRING");
    if (!preg_match($supported_models, $radio_model_str, $match)) {
      $msg = "Radio model: $radio_model_str not supported.";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
      return 0;
    }
                                                                                        heavylog("\nSETTING SNMP_DATA ARRAY");
    $snmp_data = [];
                                                                                        heavylog("\nGETTING SNMP_DATA");
    error_reporting(E_ALL & ~(E_NOTICE|E_WARNING));
    $snmp_data['mac'] = str_replace([' ','-'],":",trim(get_snmp_data($ip,$mac_oid)));
    $snmp_data['down']['rate'] = get_snmp_data($ip,$qos_dn_read_oid);
    $snmp_data['up']['rate'] = get_snmp_data($ip,$qos_up_read_oid);
    error_reporting(E_ALL);
                                                                                        heavylog("\nDETERMINING RADIO OVERRIDE VALUE");
    $snmp_data['ovrd'] = determine_ovrd($snmp_data);
                                                                                        heavylog("\nSETTING SF_DN AND SF_UP");
    $sf_data->sf_dn = round($sf_data->New_MIR_Down__c * 1000);
    $sf_data->sf_up = round($sf_data->New_MIR_Up__c * 1000);
                                                                                        heavylog("\nSETTING OVERRIDE_RADIO");
    if ($sf_data->Override_Radio__c == 'true') {
      $sf_data->sf_ovrd = 1;
    } else {
      $sf_data->sf_ovrd = 0;
    }
    
    if ($sf_data->sf_ovrd == 1) {
      $sf_data->sf_dn = 1000000;
      $sf_data->sf_up = 1000000;
    }
                                                                                        heavylog("\nsnmp_data:");
                                                                                        heavylog($snmp_data);


                                                                                        heavylog("\nCHECKING IF SF_DATA AND SNMP_DATA ARE THE SAME");
    if (data_same($sf_data,$snmp_data)) {
                                                                                        heavylog("\nDATA IS THE SAME");
      $msg = "$rel_path: $sf_url/$id - $ip - Data is the same; no need to update";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$msg);
                                                                                        heavylog("\nINCREMENTING INDIVIDUAL SUCCESS");
      $individual_success['successful']++;
    } else {

      
                                                                                        heavylog("\nCLEARING OUTPUT BUFFER AND RESPONDING \"TRUE\" TO SF");
      ob_clean();
      respond('true');
      header('Connection: close');
      header('Content-Length: '.ob_get_length());
      ob_end_flush();
      ob_flush();
      flush();

                                                                                        heavylog("\nREQUESTING TOKEN FROM API ");
      $api_token_str = maestro_get_api_token(MAESTRO_CLIENT_ID, MAESTRO_CLIENT_SEC);

                                                                                        heavylog("\nSETTING MAC");
      $mac = $snmp_data['mac'];
                                                                                        heavylog("\nGETTING A MATCHING PROFILE BASED ON SPEEDS");
      $matched_profile = epmp_get_higher_profile($sf_data->sf_dn, $sf_data->sf_up);
                                                                                        heavylog("\nCREATING ARRAY FOR PUT DATA");
      $put_arr = ["template" => $general_radio_model_str . 
               '__D' . str_replace(".", "_", strval($matched_profile->dn)) . 
               '__U' . str_replace("." ,"_", strval($matched_profile->up))];
                                                                                        heavylog("\nSETTING JSON FOR API PUT");
      $put_json = json_encode($put_arr);

                                                                                        heavylog("\nput_json: \n$put_json");
      $maestro_result = maestro_api_update('PUT', $api_token_str, $mac, $put_json);
                                                                                        heavylog("\nmaestro_result => $maestro_result");
      if (!$maestro_result == 1) {
        $msg = "$rel_path: maestro update error: $maestro_result";
                                                                                        writelog("\n$msg");
                                                                                        slack($msg, 'mattd');
        return 0;
      } else {
        $decoded_maestro_result = json_decode($maestro_result);
        if (($decoded_maestro_result->error) and (!empty($decoded_maestro_result->error))) { 
          $msg = "Error reported from API: " . $decoded_maestro_result->error->message;
											writelog("\n$rel_path: $sf_url/$id - $ip - $msg");  
        } else {
          sleep(50); //waiting for radio to reboot 

                                                                                        writelog("\nWAITING FOR RADIO TO REBOOT...");
                                                                                        heavylog("\nIGNORING NOTICE AND WARNING MESSAGES WHILE WAITING");
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
      $snmp_followup_read_data['down']['rate'] = get_snmp_data($ip,$qos_dn_read_oid);  
      $snmp_followup_read_data['up']['rate'] = get_snmp_data($ip,$qos_up_read_oid);
      $snmp_followup_read_data['ovrd'] = determine_ovrd($snmp_followup_read_data);
                                                                                        heavylog("\nsnmp_followup_read_data:\n");
                                                                                        heavylog($snmp_followup_read_data);
      //////////check new data against sf data and if good, callback to sf and update values
                                                                                        heavylog("\nCHECKING IF SF_DATA AND SNMP_FOLLOWUP_READ_DATA ARE THE SAME");
      if (data_same($sf_data,$snmp_followup_read_data)) {
                                                                                        heavylog("\nTHEY ARE THE SAME");
                                                                                        heavylog("\nADDING NEW RADIO MIR VALUES TO SF RADIO MIR ARRAY");
        $sf_opp_radio_mir_arr[] = sf_1000_radio_mir($opp_id,$snmp_followup_read_data['down']['rate'],$snmp_followup_read_data['up']['rate'],$snmp_followup_read_data['ovrd']);

        if ($snmp_followup_read_data['ovrd']) $qos_status = 'disabled';
        else $qos_status = 'enabled';
                                                                                        heavylog("\nCONVERTING BPS VALUES TO MBPS");
        $old_ssh_dn_Mbps = round(($snmp_data['down']['rate'] / 1000),2);
        $old_ssh_up_Mbps = round(($snmp_data['up']['rate'] / 1000),2);
        $new_ssh_dn_Mbps = round(($snmp_followup_read_data['down']['rate'] / 1000),2);
        $new_ssh_up_Mbps = round(($snmp_followup_read_data['up']['rate'] / 1000),2);
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
                                                                                        heavylog("\nSETTING ERROR REPORTING BACK TO \"ALL\"");
      error_reporting(E_ALL);
    }

  } catch (exception $e) {
    $catch_msg = "$rel_path: $sf_url/$id - $ip - Caught exception: $e";
                                                                                        writelog("\nCAUGHT EXCEPTION");
                                                                                        slack($catch_msg, 'mattd');
                                                                                        $sf_case_comment_arr[] = sf_case_comment($id,$catch_msg);
                                                                                        heavylog("\nSETTING INDIVUDUAL SUCCESS BACK TO 0");
    $individual_success['successful'] = 0;
  }
}
