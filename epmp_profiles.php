<?php

function epmp_get_higher_profile($dn_input,$up_input) {
  $profiles = [
    'default' => [
      0 => (object) [
         'num' => 0,
         'dn'   => 1000000,
         'up'   => 1000000
      ]
    ],
    'asym' => [
      1 => (object) [
         'num' => 1,
         'dn'   => 5120,
         'up'   => 1280
      ],
      2 => (object) [
         'num' => 2,
         'dn'   => 9216,
         'up'   => 2304
      ],
      3 => (object) [
         'num' => 3,
         'dn'   => 15360,
         'up'   => 3840
      ],
      4 => (object) [
         'num' => 4,
         'dn'   => 21504,
         'up'   => 5376
      ],
      5 => (object) [
         'num' => 5,
         'dn'   => 29696,
         'up'   => 7424
      ]
    ],
    'symm' => [
      6 => (object) [
         'num' => 6,
         'dn'   => 4096,
         'up'   => 4096
      ],
      7 => (object) [
         'num' => 7,
         'dn'   => 6144,
         'up'   => 6144
      ],
      8 => (object) [
         'num' => 8,
         'dn'   => 8196,
         'up'   => 8196
      ],
      9 => (object) [
         'num' => 9,
         'dn'   => 10240,
         'up'   => 10240
      ],
      10 => (object) [
         'num' => 10,
         'dn'   => 20480,
         'up'   => 20480
      ]
    ]
  ];
/*
  foreach ($profiles as $type => $profile_arr) {
    print_r("\n\n$type: ");
    foreach ($profile_arr as $key => $obj) {
      print_r("\n  name: ePMP__D" . $obj->dn . "__U" . $obj->up);
      foreach($obj as $prop => $val) {
        print_r("\n    $prop: $val");
      }
    }
  }
*/  

  if ($dn_input + $up_input >= 10000000) return $profiles['default'][0];

  if ($dn_input == $up_input) $p_type = 'symm';
    else $p_type = 'asym';
  $prev_p = -1;
  $curr_p = 0; 
  foreach ($profiles[$p_type] as $p) {
    $curr_p = $p->dn;
    //print_r("\nprev_p: $prev_p  dn_input: $dn_input  curr_p: $curr_p");
    if (($prev_p < $dn_input) and ($dn_input <= $curr_p)) {
      return $p; // return the profile number of the first profile where the download speed is higher than the input download speed
    }
    $prev_p = $curr_p;
  }
  return $profiles['default'][0]; // return default profile number if none match criteria

}

/*
$down = 0;
$up = 0;

$p = epmp_get_higher_profile($down, $up);
print_r($p);

$down = 3000;
$up = 3000;

$p_num = epmp_get_higher_profile($down, $up);
print_r($p);

$down = 13000;
$up = 13000;

$p_num = epmp_get_higher_profile($down, $up);
print_r($p);

$down = 29697;
$up = 29697;

$p_num = epmp_get_higher_profile($down, $up);
print_r($p);
*/
