<?php

///for Enterprise WSDL, not Partner

function sf_radio_mir($id,$dn,$up,$ovrd) {
/*
id = salesforce opportunity id;
dn = download in mbps;
up = upload in mbps;
ovrd = value to put in Radio_MIR_Override__c
requires SF_USER and SF_PW to be set and SforceEnterpriseClient.php to be loaded from the php Salesforce toolkit
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


function sf_case_comment($case_id,$msg) {
/*
case_id = Case id;
msg = message to put in comment
requires SF_USER and SF_PW to be set and SforceEnterpriseClient.php to be loaded from the php Salesforce toolkit
*/

  $sObject = new stdClass();
  $sObject->ParentId = $case_id;
  $sObject->CommentBody = $msg;
  return $sObject;
}
