<?php 

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . '/../simpletest');
require_once('autorun.php'); 

class TestOfTwoPlusTwo extends UnitTestCase {
  function test_two_plus_two() {
    $this->assertEqual(2+2, 4);
  }
  function test_two_plus_two_plus_two() {
    $this->assertEqual(2+2+2, 6);
  }
} 

?>
