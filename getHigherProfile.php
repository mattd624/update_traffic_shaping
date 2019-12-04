<?php

include '/usr/local/bin/commonPHP/write_exec_log.php';
write_exec_log(__FILE__);

//Example input
/*
$profiles = [ 
  [ '6' , '6Mbps' ],
  [ '7' , '7Mbps' ],
  [ '8' , '8Mbps' ],
  [ '10' , '10Mbps' ],
  [ '20' , '20Mbps' ],
  [ '50' , '50Mbps' ]
];
*/
/*
$profiles = [
  ['22' , 'MattD_Test'],
  ['50' , 'Test-Arbor']
];
*/

function getHigherMoName($array, $nr) {

//echo "\n\nBEGIN:\n";
  $first_idx = $array[0][0];
  $last_idx = end($array)[0];
//echo "\n\nLast Index: $last_idx \n";
  if ($nr > $last_idx){ 
    return 'Enterprise';
  } 
  foreach($array as $idx => $num){
    if ($nr <= $first_idx) {
      return $array[0][1];
    } else if ( 0 < $idx ) {
      if (($array[($idx - 1)][0] < $nr) and ($nr <= $array[$idx][0])) {
        return $array[$idx][1];
      break;
      }
    }  
  }
  return end($array)[1]; 
}



$profiles = [
  '5' => '1',
  '7' => '2',
  '9' => '3',
  '11' => '4',
  '13' => '5',
  '15' => '6',
  '' => '7',
  '' => '8',
  '' => '9',
];

function getHigherProfile($array, $nr) {
  
}



/*
print_r($mo_names);
print_r(getHigherMoName($profiles, -1 ));
print_r(getHigherMoName($profiles, 0 ));
print_r(getHigherMoName($profiles, 2 ));
print_r(getHigherMoName($profiles, 23 ));
print_r(getHigherMoName($profiles, 223 ));
*/
