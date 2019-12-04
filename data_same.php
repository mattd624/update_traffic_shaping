<?php

function data_same($sf_data, $remote_data) {
  $sf_ovrd = $sf_data->sf_ovrd;
  $sf_dn = $sf_data->sf_dn;
  $sf_up = $sf_data->sf_up;
  $remote_ovrd = $remote_data['ovrd'];
  $remote_dn = $remote_data['down']['rate'];
  $remote_up = $remote_data['up']['rate'];

  if (($sf_ovrd == $remote_ovrd) and
      ($sf_dn == $remote_dn) and
      ($sf_up == $remote_up)) {
    return 1;
  } else {
    return 0;
  }
}

