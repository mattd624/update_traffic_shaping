<?php


function sf_opp_update($id,$dn,$up,$ovrd) {
/*
id = salesforce opportunity id;
dn = download in mbps;
up = upload in mbps;
ovrd = value to put in Radio_MIR_Override__c
requires SF_USER and SF_PW to be set and SforceEnterpriseClient.php to be loaded from the php Salesforce toolkit
*/
  $sf_dn = ($dn / 1024);
  $sf_up = ($up / 1024);
  $mySforceConnection = new SforceEnterpriseClient();
  $mySoapClient = $mySforceConnection->createConnection(WSDL);
  $mylogin = $mySforceConnection->login(SF_USER,SF_PW);

  $obj = 'Opportunity'; // salesforce object to update
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
  $createResponse = $mySforceConnection->update(array($sObject), $obj);
  return $createResponse[0]->success;
}


function sf_create_case_comment($case_id,$msg) {
/*
case_id = Case id;
msg = message to put in comment
requires SF_USER and SF_PW to be set and SforceEnterpriseClient.php to be loaded from the php Salesforce toolkit
*/
  $mySforceConnection = new SforceEnterpriseClient();
  $mySoapClient = $mySforceConnection->createConnection(WSDL);
  $mylogin = $mySforceConnection->login(SF_USER,SF_PW);
  $sObject = new stdClass();
  $sObject->ParentId = $case_id;
  $sObject->CommentBody = $msg;
  $createResponse = $mySforceConnection->create(array($sObject), 'CaseComment');
  return $createResponse;//[0]->success;
}

