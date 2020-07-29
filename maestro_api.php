<?php


function maestro_get_api_token($client_id, $client_secret, $z = null) {
  $content_type = 'application/x-www-form-urlencoded';
  $host = '64.203.113.34';
  $api_path = 'api/v1';
  $resource = 'access/token';
  $z['post'] = "grant_type=client_credentials&client_id=$client_id&client_secret=$client_secret";
  $curl = curl_init();
  $useragent = isset($z['useragent']) ? $z['useragent'] : 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:10.0.2) Gecko/20100101 Firefox/10.0.2';
  $url = "https://$host/$api_path/$resource";
//print_r($url);
  curl_setopt_array($curl, array(
    CURLOPT_URL => $url,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_SSL_VERIFYPEER => 0,
    CURLOPT_AUTOREFERER => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC | CURLAUTH_ANY,
    CURLOPT_POST => isset($z['post']),
    CURLOPT_HTTPHEADER => array(
      "Accept: */*",
      "Content-Type: $content_type"
    ),
  ));
  if( isset($z['post']) )         curl_setopt( $curl, CURLOPT_POSTFIELDS, $z['post'] );
  if( isset($z['refer']) )        curl_setopt( $curl, CURLOPT_REFERER, $z['refer'] );
  curl_setopt( $curl, CURLOPT_USERAGENT, $useragent );
  curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT, ( isset($z['timeout']) ? $z['timeout'] : 5 ) );

  $response = curl_exec($curl);
  $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);   //get status code
  if ( $httpCode != 200 ){
    $output = $httpCode . curl_error($curl);
  } else {
    $output = $response;
  }
  curl_close($curl);
  $token_array = json_decode($output);
//print_r($token_array);
  $token = $token_array->access_token;
  return $token;

}




function maestro_api_update( $method, $token, $mac, $data) {
  $content_type = 'application/json';
  $host = '64.203.113.34';
  $api_path = 'api/v1';
  $resource = 'devices';
  $curl = curl_init();
  $url = "https://$host/$api_path/$resource/$mac";

  switch ($method){
    case "POST":
      curl_setopt($curl, CURLOPT_POST, 1);
      if ($data)
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
      break;
    case "PUT":
      curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
      if ($data)
        curl_setopt($curl, CURLOPT_POSTFIELDS,$data);
      break;
    case "GET":
      curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
      $url = $url . "?fields=" . implode(",", $data);
      //$url = $url . "/operations";
      break;
    default:
      if ($data)
        $url = sprintf("%s?%s", $url, http_build_query($data));
  }
  // OPTIONS:
  curl_setopt($curl, CURLOPT_URL, $url);
  curl_setopt($curl, CURLOPT_HTTPHEADER, array(
     "Accept: application/json",
     "Content-Type: $content_type",
     "Authorization: Bearer $token"
  ));
//  curl_setopt($curl, CURLOPT_VERBOSE, true);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($curl, CURLOPT_TIMEOUT, 0);
  curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
  curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
  // EXECUTE:
  $response = curl_exec($curl);
                                                                                        heavylog("\nresponse:");
                                                                                        heavylog($response);
  $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);   //get status code
  if ($httpCode != 200 ){
    $result = $httpCode . curl_error($curl);
  } else {
    if ($method === 'GET') $result = $response;
    else $result = 1;
  }
  curl_close($curl);
  return $result;
}
