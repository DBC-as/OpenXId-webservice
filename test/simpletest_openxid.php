<?php 

set_include_path(get_include_path() . PATH_SEPARATOR . 
                 __DIR__ . '/../simpletest' . PATH_SEPARATOR . 
                 __DIR__ . '/..');
require_once('autorun.php'); 
require_once('openxid_class.php');

/**
 * The class under test
 * Is extended for enabling Unit Test:
 *  - Exposing protected information to the unit test
 *  - Enabling simpler handling of WS object data (NS not needed)
 */
class utOpenXId extends openXId {
  function __construct($inifile, $silent = false) {
    parent::__construct($inifile, $silent);
    $this->aaa = new utAaa();
  }
  
  /**
   * Determines whether the aaa class returns failure
   * @param boolean $authentication Determines whether the aaa class returns failure
   */
  function _setAuthenticationResult($authentication) {
    $this->aaa->authentication = $authentication;
  }
  
  /**
   * Add namespace to object structure
   * @param string $namespace Namespace to be added
   * @param object $obj The object to add namespace
   * @return object The namespace enriched object
   */
  private function _addNS($namespace, $obj) {
    switch (gettype($obj)) {
      case 'object':
        $res = (object)null;
        foreach ($obj as $i => $o) {
          if (is_array($o)) {
            $res->$i = $this->_addNS($namespace, $o);
          } else {
            $res->$i->_namespace = $namespace;
            $res->$i->_value = $this->_addNS($namespace, $o);
          }
        }
        return $res;
      case 'array':
        $res = array();
        foreach ($obj as $o) {
          $res[] = (object) array('_namespace'=>$namespace, '_value'=>$this->_addNS($namespace, $o));
        }
        return $res;
      default:
        return $obj;
    }
  }

  /**
   * Remove namespace from object structure
   * @param object $obj The object from which namespaces are removed
   * @return object The simplified object without namespaces
   */
  private function _removeNS($obj) {
    switch (gettype($obj)) {
      case 'object':
        if (property_exists($obj, '_value')) {
          return $this->_removeNS($obj->_value);
        } else {
          $res = (object)null;
          foreach ($obj as $i => $o) {
            if ($i != '_namespace') {
              $res->$i = $this->_removeNS($o);
            }
          }
          return $res;
        }
      case 'array':
        $res = array();
        foreach ($obj as $o) {
          $res[] = $this->_removeNS($o);
        }
        return $res;
      case null:
        return null;
      default:
        return $obj;
    }
  }

  /**
   * Fetch the Id Type Table (the instance member
   * @return array The Id Type Table
   */
  function getIdTypeTable() {
    $ret = $this->idTypeTable;
    return $ret;
  }
  function utGetClusterDataByTypeValuePair($type, $value) {return $this->_getClusterDataByTypeValuePair($type, $value);}
  function utRemoveRecordsByRecordId($recordId) {return $this->_removeRecordsByRecordId($recordId);}
  function utPutIdTypeValue($recordId, $clusterId, $idType, $idValue) {return $this->_putIdTypeValue($recordId, $clusterId, $idType, $idValue);}
  function utNormalize($idType, $idValue) {return $this->_normalize($idType, $idValue);}
  function utRemoveDuplicates($data) {return $this->_removeDuplicates($data);}

  /**
   * This method enriches the $param data with namespaces and calls the getIdsRequest method
   * @param object $param Simplified param block without namespaces
   * @return object Simplified return data block without namespaces
   */
  function utGetIdsRequest($param) {
    return $this->_removeNS($this->getIdsRequest($this->_addNS('http://oss.dbc.dk/ns/openxid', $param)));
  }
  
  /**
   * This method enriches the $param data with namespaces and calls the updateIdRequest method
   * @param object $param Simplified param block without namespaces
   * @return object Simplified return data block without namespaces
   */
  function utUpdateIdRequest($param) {
    return $this->_removeNS($this->updateIdRequest($this->_addNS('http://oss.dbc.dk/ns/openxid', $param)));
  } 
}

//------------------------------------------------------------------------------

/**
 * Necessary stub class
 */
class utAaa {
  public $authentication = true;
  function has_right($p1, $p2) {
    return $this->authentication;
  }
}

//------------------------------------------------------------------------------

/**
 * Test class for testing openXId Class
 */
class Test_OpenXid extends UnitTestCase {
  private $temp_files;
  private $ini_success = array("[setup]", "oxid_credentials = host=pgtest dbname=openxidtest user=openxidtest password=ogemudf");

  function __construct($label = false) {
    parent::__construct($label);
    $this->temp_files = array();
  }
  
  function __destruct() {
    foreach($this->temp_files as $file) {
      unlink($file);
    }
  }

  /**
   * Create a temp file, and put name in $this->temp_files (is deleted by destructor)
   * @param string $content Data to put into temp file
   * @return string File name
   */
  private function _temp_inifile($content) {
    $inifilename = tempnam('/tmp', 'openxid_unittest_');
    $this->temp_files[] = $inifilename;
    file_put_contents($inifilename, implode("\n", $content) . "\n");
    return $inifilename;
  }
  
  /**
   * Create an empty database with correct openxid tables
   * Clears any data in any existing openxid tables
   * @return string The last line from the result of the command (as returned by the PHP exec command)
   */
  private function _create_empty_database() {
    $inifile = $this->_temp_inifile($this->ini_success);
    $res = exec("php ../scripts/create_tables.php -p $inifile -C YES", $output);
    return $res;
    }

  /**
   * Instantiate the openXId class with a successful ini file
   * @return object openXId object
   */
  private function _instantiate_oxid() {
    $inifilename = $this->_temp_inifile($this->ini_success);
    return new utOpenXId($inifilename);
  }

  //============================================================================

  /**
   * Test instantiation of the openXId class
   */
  function test_instantiation() {
    $id_types = array('faust', 'ean', 'issn', 'local');
  
    echo $this->_create_empty_database();
    
    $oxid = $this->_instantiate_oxid();
    $this->assertIsA($oxid, 'openXId');
    
    $type_table = $oxid->getIdTypeTable();
    $exptected_type_table = array();
    $i = 0;
    foreach($id_types as $id) {
      $exptected_type_table[$i] = $id;
      $exptected_type_table[$id] = $i++;
    }
    $this->assertIdentical($type_table, $exptected_type_table);
    unset($oxid);
  }

  //============================================================================
  
  /**
   * Test the normalize method in the openXId class
   */
  function test_normalize() {
    $test_data = array(
      //     idType             idValue         expected
      // Key      0                   1                2
      array('dummy',                  0 ,              0 ),
      array('local',                  0 ,              0 ),
      array('local',                123 ,            123 ),
      array('local',               '123',           '123'),
      array('faust',                  0 ,              0 ),
      array('faust',                123 ,              0 ),
      array('faust',               '123',              0 ),
      array('faust',           '1234567',              0 ), // Wrong checksum
      array('faust',           '1234560',      '01234560'), // Correct checksum
      array('faust',         '12 345-60',      '01234560'), // Correct checksum, with whitespace and dash
      array('faust',          '12345678',              0 ), // Correct checksum
      array('faust',        '1-23456 74',      '12345674'), // Correct checksum, with whitespace and dash
      array( 'issn',                  0 ,              0 ),
      array( 'issn',                123 ,              0 ),
      array( 'issn',               '123',              0 ),
      array( 'issn',           '1234567',              0 ), // Incorrect count of digits
      array( 'issn',          '12345678',              0 ), // Wrong checksum
      array( 'issn',          '12345679',      '12345679'), // Correct checksum
      array(  'ean',                  0 ,              0 ),
      array(  'ean',                123 ,              0 ),
      array(  'ean',               '123',              0 ),
      array(  'ean',           '1234567',              0 ), // Incorrect count of digits
      array(  'ean',        '1234567890',              0 ), // Wrong checksum
      array(  'ean',        '1933988274', '9781933988276'), // Correct checksum ISBN10
      array(  'ean',     '1-933988-27-4', '9781933988276'), // Correct checksum ISBN10 with dashes
      array(  'ean',     '9781933988276', '9781933988276'), // Correct checksum ISBN13
      array(  'ean', '978-1-933988-27-6', '9781933988276'), // Correct checksum ISBN13 with dashes
    );
    
    $oxid = $this->_instantiate_oxid();
    foreach ($test_data as $d) {
      $actual_result = $oxid->utNormalize($d[0], $d[1]);
      $expected_result = $d[2];
      $this->assertIdentical($expected_result, $actual_result);
    }
    unset($oxid);
  }
  
  //============================================================================
  
  /**
   * Test the putIdTypeValue and getClusterDataByTypeValuePair methods in the openXId class
   */
  function test_get_and_put() {
    $test_data = array(
      //    recordId, clusterId,    idType, idValue  fail status
      // Key       0          1          2        3  4
      array(     551,        23,   'dummy',   13232, 'invalid idType' ),
      array(      41,        23,         0,     232, 'could not reach database' ),
      array(      11,        23,   'faust',    3232, false ),
      array(      12,       233,    'issn',    1234, false ),
      array(      13,        23,     'ean',    3456, false ),
      array(      14,       323,   'local',    4567, false ),
      array(     111,       323,   'faust',    5678, false ),
      array(    1111,       323,     'ean',    6789, false ),
    );

    $oxid = $this->_instantiate_oxid();
    foreach ($test_data as $d) {
      $res = $oxid->utPutIdTypeValue($d[0], $d[1], $d[2], $d[3]);
      $this->assertIdentical($res, $d[4]);
    }
// Her indføres en test af den nye get_by_typeValue() når den implementeres

    $cluster_data = array();  // An array indexed by clusterId, containing all elements in that cluster
    foreach ($test_data as $d) {
      if ($d[4] !== false) continue;
      $cluster_data[$d[1]][] = array('idtype' => $d[2], 'idvalue' => $d[3]);
    }
    foreach ($test_data as $d) {
      if ($d[4] !== false) continue;
      $actual_content = $oxid->utGetClusterDataByTypeValuePair($d[2], $d[3]);
      $expected_content = $cluster_data[$d[1]];
      $this->assertEqual($actual_content, $expected_content);
    }
    unset($oxid);
  }
  
  //============================================================================
  
  /**
   * Test the removeRecordsByRecordId method in the openXId class
   */
  function test_removeRecordsByRecordId() {
    $oxid = $this->_instantiate_oxid();
    $content_before = $oxid->utGetClusterDataByTypeValuePair('ean', 6789);
    $expected_content_before = array(
      array('idtype'=>'local', 'idvalue'=>'4567'), 
      array('idtype'=>'faust', 'idvalue'=>'5678'), 
      array('idtype'=>'ean', 'idvalue'=>'6789'), 
    );
    $this->assertIdentical($expected_content_before, $content_before);
    
    $this->assertFalse($oxid->utRemoveRecordsByRecordId(111));  // Returns false meaning no error

    $content_after = $oxid->utGetClusterDataByTypeValuePair('ean', 6789);
    $expected_content_after = array(
      array('idtype'=>'local', 'idvalue'=>'4567'), 
      array('idtype'=>'ean', 'idvalue'=>'6789'), 
    );
    $this->assertIdentical($expected_content_after, $content_after);
    
    unset($oxid);
  }
  
  //============================================================================
  
  /**
   * Test the removeDuplicates method in the openXId class
   */
  function test_removeDuplicates() {
    $test_data = array(
      array(
        'in' => null,
        'out' => null,
      ),
      array(
        'in' => array(
          array('idtype'=>'ean', 'idvalue'=>'1234'),
          array('idtype'=>'ean', 'idvalue'=>'1234'),
        ),
        'out' => array(
          array('idtype'=>'ean', 'idvalue'=>'1234'),
        ),
      ),
      array(
        'in' => array(
          array('idtype'=>'ean', 'idvalue'=>'1234'),
          array('idtype'=>'local', 'idvalue'=>'1234'),
          array('idtype'=>'ean', 'idvalue'=>'1234'),
        ),
        'out' => array(
          array('idtype'=>'ean', 'idvalue'=>'1234'),
          array('idtype'=>'local', 'idvalue'=>'1234'),
        ),
      ),
    );
    
    $oxid = $this->_instantiate_oxid();

    foreach ($test_data as $data) {
      $actual_result = $oxid->utRemoveDuplicates($data['in']);
      $this->assertEqual($actual_result, $data['out']);
    }
    
    unset($oxid);
  }
  
  //============================================================================

  /**
   * Test the public getIdsRequest method in the openXId class
   */
  function test_getIdsRequest() {
    $test_data = array(
      
      array(
        'test' => 'Test empty list of id´s',
        'in' => (object) array(
          'id' => array(),
        ),
        'out' => (object) array(
          'getIdsResponse' => (object) array(
            'error' => 'invalid id',
          ),
        ),
      ),
      
      array(
        'test' => 'Test with an entry, that couldn´t be found',
        'in' => (object) array(
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-0-321-60191-9'),
          ),
        ),
        'out' => (object) array(
          'getIdsResponse' => (object) array(
            'getIdResult' => array(
              (object) array(
                'requestedId' => (object) array('idType'=>'ean', 'idValue'=>'978-0-321-60191-9'),
                'error' => 'no results found for requested id',
              ),
            ),
          ),
        ),
      ),
      
      array(
        'test' => 'Test with an invalid idtype',
        'in' => (object) array(
          'id' => array(
            (object) array('idType' => 'eanx', 'idValue' => '978-1-933988-27-6'),
          ),
        ),
        'out' => (object) array(
          'getIdsResponse' => (object) array(
            'getIdResult' => array(
              (object) array(
                'requestedId' => (object) array('idType'=>'eanx', 'idValue'=>'978-1-933988-27-6'),
                'error' => 'invalid idType',
              ),
            ),
          ),
        ),
      ),
      
      array(
        'test' => 'Test with an invalid idvalue',
        'in' => (object) array(
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-123'),
          ),
        ),
        'out' => (object) array(
          'getIdsResponse' => (object) array(
            'getIdResult' => array(
              (object) array(
                'requestedId' => (object) array('idType'=>'ean', 'idValue'=>'978-123'),
                'error' => 'invalid id',
              ),
            ),
          ),
        ),
      ),
      
      array(
        'test' => 'Test with success - two matches found for one id',
        'in' => (object) array(
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-1-933988-27-6'),
          ),
        ),
        'out' => (object) array(
          'getIdsResponse' => (object) array(
            'getIdResult' => array(
              (object) array(
                'requestedId' => (object) array('idType'=>'ean', 'idValue'=>'978-1-933988-27-6'),
                'ids' => (object) array(
                  'id' => array(
                    (object) array('idType'=>'ean', 'idValue'=>'9781933988276'),
                    (object) array('idType'=>'ean', 'idValue'=>'1933988274'),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
      
      array(
        'test' => 'Test with authentication error',
        'aaa_error' => true,
        'in' => (object) array(
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-1-933988-27-6'),
          ),
        ),
        'out' => (object) array(
          'getIdsResponse' => (object) array(
            'error' => 'authentication error',
          ),
        ),
      ),
      
      array(
        'test' => 'Test with success - two id´s => 2 and 3 matches found',
        'in' => (object) array(
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-1-933988-27-6'),
            (object) array('idType' => 'ean', 'idValue' => '9788770537568'),
          ),
        ),
        'out' => (object) array(
          'getIdsResponse' => (object) array(
            'getIdResult' => array(
              (object) array(
                'requestedId' => (object) array('idType'=>'ean', 'idValue'=>'978-1-933988-27-6'),
                'ids' => (object) array(
                  'id' => array(
                    (object) array('idType'=>'ean', 'idValue'=>'9781933988276'),
                    (object) array('idType'=>'ean', 'idValue'=>'1933988274'),
                  ),
                ),
              ),
              (object) array(
                'requestedId' => (object) array('idType'=>'ean', 'idValue'=>'9788770537568'),
                'ids' => (object) array(
                  'id' => array(
                    (object) array('idType'=>'ean', 'idValue'=>'9788770537568'),
                    (object) array('idType'=>'faust', 'idValue'=>'29315183'),
                    (object) array('idType'=>'local', 'idValue'=>'12345678'),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
      
    );
    
    $oxid = $this->_instantiate_oxid();
    $oxid->utPutIdTypeValue(1001, 1001, 'ean', '9781933988276');  // Roy Osherove: The art of Unit Testing
    $oxid->utPutIdTypeValue(1002, 1001, 'ean', '1933988274');     // Roy Osherove: The art of Unit Testing
    $oxid->utPutIdTypeValue(1003, 1002, 'ean', '9788770537568');  // Hådan Nesser: Himlen over London
    $oxid->utPutIdTypeValue(1004, 1002, 'faust', '29315183');     // Hådan Nesser: Himlen over London
    $oxid->utPutIdTypeValue(1005, 1002, 'local', '12345678');     // Hådan Nesser: Himlen over London

    foreach ($test_data as $data) {
      $oxid->_setAuthenticationResult(!$data['aaa_error']);  // Set the behavior of the utAaa stub
      $actual_output = $oxid->utGetIdsRequest($data['in']);
      $this->assertEqual($actual_output, $data['out']);
    }
    
    unset($oxid);
  }

  //============================================================================

  /**
   * Test the public updateIdRequest method in the openXId class
   */
  function test_updateIdRequest() {
    
    $test_data = array(
      
      array(
        'test' => 'Test with authentication error',
        'aaa_error' => true,
        'updateIdRequest in' => (object) array(
          'recordId' => '2001',
          'clusterId' => '2001',
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-87-567-9906-5'),  // Jussi Adler-Olsen: Marco effekten
            (object) array('idType' => 'faust', 'idValue' => '2 970 511 9'),
          ),
        ),
        'updateIdRequest out' => (object) array(
          'updateIdResponse' => (object) array(
            'error' => 'authentication error',
          ),
        ),
        'getIdsRequest in' => (object) array(
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-87-567-9906-5'),
          ),
        ),
        'getIdsRequest out' => (object) array(
          'getIdsResponse' => (object) array(
            'getIdResult' => array(
              (object) array(
                'requestedId' => (object) array('idType'=>'ean', 'idValue'=>'978-87-567-9906-5'),
                'error' => 'no results found for requested id',
              ),
            ),
          ),
        ),
      ),

      array(
        'test' => 'Test with success - add one record with two matches',
        'updateIdRequest in' => (object) array(
          'recordId' => '2001',
          'clusterId' => '2001',
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-87-567-9906-5'),  // Jussi Adler-Olsen: Marco effekten
            (object) array('idType' => 'faust', 'idValue' => '2 970 511 9'),
          ),
        ),
        'updateIdRequest out' => (object) array(
          'updateIdResponse' => (object) array(
            'updateIdStatus' => array(
              (object) array(
                'id' => (object) array('idType'=>'ean', 'idValue'=>'9788756799065'),
                'updateIdOk' => (object)null,
              ),
              (object) array(
                'id' => (object) array('idType'=>'faust', 'idValue'=>'29705119'),
                'updateIdOk' => (object)null,
              ),
            ),
          ),
        ),
        'getIdsRequest in' => (object) array(
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-87-567-9906-5'),
          ),
        ),
        'getIdsRequest out' => (object) array(
          'getIdsResponse' => (object) array(
            'getIdResult' => array(
              (object) array(
                'requestedId' => (object) array('idType'=>'ean', 'idValue'=>'978-87-567-9906-5'),
                'ids' => (object) array(
                  'id' => array(
                    (object) array('idType'=>'ean', 'idValue'=>'9788756799065'),
                    (object) array('idType'=>'faust', 'idValue'=>'29705119'),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
      
      array(
        'test' => 'Test with success - add one record without matches (a record with no id´s) => delete existing records',
        'updateIdRequest in' => (object) array(
          'recordId' => '2001',
          'clusterId' => '2001',
          'id' => array(),
        ),
        'updateIdRequest out' => (object) array(
          'updateIdResponse' => (object) array(
            'updateIdStatus' => (object) array(
              'updateIdOk' => (object) array(),
            ),
          ),
        ),
        'getIdsRequest in' => (object) array(
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-87-567-9906-5'),
          ),
        ),
        'getIdsRequest out' => (object) array(
          'getIdsResponse' => (object) array(
            'getIdResult' => array(
              (object) array(
                'requestedId' => (object) array('idType'=>'ean', 'idValue'=>'978-87-567-9906-5'),
                'error' => 'no results found for requested id',
              ),
            ),
          ),
        ),
      ),
      
      array(
        'test' => 'Test with idType failure',
        'updateIdRequest in' => (object) array(
          'recordId' => '2001',
          'clusterId' => '2001',
          'id' => array(
            (object) array('idType' => 'sean', 'idValue' => '978-87-567-9906-5'),
          ),
        ),
        'updateIdRequest out' => (object) array(
          'updateIdResponse' => (object) array(
            'updateIdStatus' => array(
              (object) array(
                'id' => (object) array('idType'=>'sean', 'idValue'=>'978-87-567-9906-5'),
                'error' => 'invalid idType',
              ),
            ),
          ),
        ),
      ),
      
      array(
        'test' => 'Test with idValue failure',
        'updateIdRequest in' => (object) array(
          'recordId' => '2001',
          'clusterId' => '2001',
          'id' => array(
            (object) array('idType' => 'ean', 'idValue' => '978-87-567-9906'),
          ),
        ),
        'updateIdRequest out' => (object) array(
          'updateIdResponse' => (object) array(
            'updateIdStatus' => array(
              (object) array(
                'id' => (object) array('idType'=>'ean', 'idValue'=>'978-87-567-9906'),
                'error' => 'invalid id',
              ),
            ),
          ),
        ),
      ),
      
    );

    $oxid = $this->_instantiate_oxid();
    
    foreach ($test_data as $data) {
      $oxid->_setAuthenticationResult(!$data['aaa_error']);  // Set the behavior of the utAaa stub
      $actual_update_output = $oxid->utUpdateIdRequest($data['updateIdRequest in']);
      $this->assertEqual($actual_update_output, $data['updateIdRequest out']);
      $oxid->_setAuthenticationResult(true);  // Set the behavior of the utAaa stub back to normal
      if (array_key_exists('getIdsRequest in', $data)) {
        $actual_get_output = $oxid->utGetIdsRequest($data['getIdsRequest in']);
        $this->assertEqual($actual_get_output, $data['getIdsRequest out']);
      }
    }

    unset($oxid);
  }

}

?>
