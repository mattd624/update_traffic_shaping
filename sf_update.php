<?php


function sf_update($id,$dn,$up,$ovrd) {
/*
id = salesforce opportunity id; 
dn = download in mbps; 
up = upload in mbps; 
ovrd = value to put in Radio_MIR_Override__c
requires USERNAME and PASSWORD to be set and SforceEnterpriseClient.php to be loaded from the php Salesforce toolkit
*/
  $sf_dn = ($dn / 1024);
  $sf_up = ($up / 1024);
  $wsdl = COMMON_PHP_DIR . '/wsdl/production.enterprise.wsdl.xml';
  $mySforceConnection = new SforceEnterpriseClient();
  $mySoapClient = $mySforceConnection->createConnection($wsdl);
  $mylogin = $mySforceConnection->login(SF_USER,SF_PW);
  //$options = new QueryOptions(300);  //Set query to return results in chunks
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


