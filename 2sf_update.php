<?php

///for Enterprise WSDL, not Partner
function sf_update_single_case_comment($case_id,$msg) {
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


function sf_1024_radio_mir($id,$dn,$up,$ovrd) {
/*
id = salesforce opportunity id;
dn = download in mbps;
up = upload in mbps;
ovrd = value to put in Radio_MIR_Override__c
*/
  $sf_dn = ($dn / 1024);
  $sf_up = ($up / 1024);
  $sObject = new stdClass();
  $sObject->Id = $id;
  $sObject->Radio_MIR_Down__c = $sf_dn;
  $sObject->Radio_MIR_Up__c = $sf_up;
  $sObject->Radio_MIR_Override__c = $ovrd;
  return $sObject;
}



function sf_1000_radio_mir($id,$dn,$up,$ovrd) {
/*
id = salesforce opportunity id;
dn = download in mbps;
up = upload in mbps;
ovrd = value to put in Radio_MIR_Override__c
*/
  $sf_dn = ($dn / 1000);
  $sf_up = ($up / 1000);
  $sObject = new stdClass();
  $sObject->Id = $id;
  $sObject->Radio_MIR_Down__c = $sf_dn;
  $sObject->Radio_MIR_Up__c = $sf_up;
  $sObject->Radio_MIR_Override__c = $ovrd;
  return $sObject;
}


function sf_case_comment($case_id,$msg) {
/*
Creates case comment object for addition to an array
case_id = Salesforce Case Id;
msg = message to put in comment
*/

  $sObject = new stdClass();
  $sObject->ParentId = $case_id;
  $sObject->CommentBody = $msg;
  return $sObject;
}

function sf_uncheck($id,$field){
/*
id = a Salesforce object instance Id
field = checkbox (field) in the object instance
*/

  $sObject_arr = [];
  $sObject_arr['Id'] = $id;
  $sObject_arr[$field] = 'false';
  $sObject = (object) $sObject_arr;
  return $sObject;
}

