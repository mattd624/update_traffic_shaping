<?php


function ping_error() {
  writelog("\nping_error\n");
}



function ping_port($host, $port = 22, $wait_secs = 1) {
  set_error_handler("ping_error");
  $fp = fsockopen($host,$port,$errCode,$errStr,$wait_secs);
  restore_error_handler();
  if ($fp) {
    return 1;
  } else {
    return 0;
  }
  fclose($fp);
}

