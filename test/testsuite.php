<?php 

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . '/../simpletest');
require_once 'unit_tester.php';
require_once 'collector.php';
require_once 'extensions/junit_xml_reporter.php';

class AllTests extends TestSuite {
  function __construct() {
    parent::__construct();
    $this->TestSuite('All tests');
    $this->collect(dirname(__FILE__), new SimplePatternCollector('/simpletest.*.php/'));  // Test alle php filer, der starter med teksten "simpletest"
  }
}

$testRun = new AllTests();
$testRun->run(new JUnitXMLReporter());

?>
