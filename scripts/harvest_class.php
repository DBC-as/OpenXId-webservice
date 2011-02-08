<?php
/**
 *
 * This file is part of openLibrary.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * openLibrary is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * openLibrary is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with openLibrary.  If not, see <http://www.gnu.org/licenses/>.
*/

require_once("$startdir/OLS_class_lib/marc_class.php");
require_once "$startdir/OLS_class_lib/verbose_class.php";
require_once("$startdir/OLS_class_lib/oci_class.php");
require_once("$startdir/OLS_class_lib/pg_database_class.php");

require_once("$startdir/OLS_class_lib/material_id_class.php");
require_once("$startdir/OLS_class_lib/curl_class.php");

define('VOXB_SERVICE_NUMBER', 4);               // Service number for the Voxb Service
define('VOXB_HARVEST_POLL_TIME', 5);            // Time given in minutes
define('PROGRESS_INDICATOR_LINE_LENGTH', 100);  // Number of periods in a progression indicator line
define('PROGRESS_INDICATOR_CHUNK', 100);        // Size of chunks to process for each period to be echoed 

//==============================================================================

class output {
  static $enabled;  // Echo enable flag
  static $marcEnabled;  // Echo marc enable flag
  private function __construct() {}
  
  function enable($flag) {
    self::$enabled = $flag;
  }
  
  function marcEnable($flag) {
    self::$marcEnabled = $flag;
  }
  
  function open($logfile, $mask) {
    verbose::open($logfile, $mask);
  }

  function log($verboselevel, $text) {
    verbose::log($verboselevel, $text);
  }
  
  function display($text) {
    if (self::$enabled) {
      echo $text . "\n";
    }
    self::log(TRACE, $text);
  }
  
  function marcDisplay($text) {
    if (self::$marcEnabled) {
      echo $text . "\n";
    }
    self::log(TRACE, $text);
  }
  
  function die_log($text) {
    echo $text . "\n";
    self::log(FATAL, $text);
    exit;
  }
}

//==============================================================================

class progressIndicator {
  private $enabled;  // Echo enable flag
  private $counter;  // Counting number of ticks

  function __construct($enable=false) {
    $this->enabled = $enable;
    $this->counter = 0;
  }

  function __destruct() {
    if (!$this->enabled) return;
    echo "  (" . strval($this->counter+1) . ")\n";
  }

  function enable($flag) {
    $this->enabled = $flag;
  }

  function tick($count=1) {
    for (; $count>0; $count--, $this->counter++) {
      if (!$this->enabled) continue;
      if (($this->counter%PROGRESS_INDICATOR_CHUNK)==(PROGRESS_INDICATOR_CHUNK-1)) {
        echo '.';
      }
      if (($this->counter % (PROGRESS_INDICATOR_LINE_LENGTH*PROGRESS_INDICATOR_CHUNK)) == ((PROGRESS_INDICATOR_LINE_LENGTH*PROGRESS_INDICATOR_CHUNK)-1)) {
        echo "  (" . strval($this->counter+1) . ")\n";
      }
    }

  }
}

//==============================================================================

class serviceDatabase {
  private $oci;         // Database instance
  private $ociDelete;   // Database instance

  function __construct($authentication) {
    try {
      $this->oci = new Oci($authentication);
      $this->oci->connect();
      $this->ociDelete = new Oci($authentication);
      $this->ociDelete->connect();
    } catch (ociException $e) {
      output::die_log($e);
    }
  }
  
  function queryServices() {
    try {
      $this->oci->set_query('select id from services where service = ' . VOXB_SERVICE_NUMBER);
    } catch (ociException $e) {
      output::die_log($e);
    }
  }
  
  function fetchId() {
    try {
      $ret = $this->oci->fetch_into_assoc();
    } catch (ociException $e) {
      output::die_log($e);
    }
    return $ret['ID'];
  }

  function removeService($idno) {
    try {
      $this->ociDelete->set_query("delete from services where service = " . VOXB_SERVICE_NUMBER . " and id = $idno");
      $this->ociDelete->commit();
    } catch (ociException $e) {
      output::die_log($e);
    }
  }
}

//==============================================================================

class danbibDatabase {
  private $oci;           // Database instance
  private $ociOverflow;   // Extra Database instance for overflow

  function __construct($authentication) {
    try {
      $this->oci = new Oci($authentication);
      $this->oci->connect();
      $this->ociOverflow = new Oci($authentication);
      $this->ociOverflow->connect();
    } catch (ociException $e) {
      output::die_log($e);
    }
  }
  
  private function _queryOverflow($id) {
    try {
      $sql = "select id, lbnr, data, length(data) length from poster_overflow where id = $id order by lbnr";
      $this->ociOverflow->set_query($sql);
    } catch (ociException $e) {
      output::die_log($e);
    }
    
  }

  private function _fetchOverflow() {
    $data = $this->ociOverflow->fetch_into_assoc();
    return $data;
  }

  function query($where) {
    try {
//      $sql = "select id, danbibid, bibliotek, data from poster where rownum < 200";
// danbibid in ('4363271','4876910', '17703600')
      $sql = "select id, danbibid, bibliotek, data, length(data) length from poster";
      if (!empty($where)) {
         $sql .= " $where";
      }
      $this->oci->set_query($sql);
    } catch (ociException $e) {
      output::die_log($e);
    }
  }
  
  function fetch() {
    $data = $this->oci->fetch_into_assoc();
    if ($data['LENGTH'] >= 4000) {
      $this->_queryOverflow($data['ID']);
      do {
        $overflow = $this->_fetchOverflow();
        $data['DATA'] .= $overflow['DATA'];
        $data['LENGTH'] += $overflow['LENGTH'];
        $tjek = strlen($data['DATA']);
      } while ($overflow['LENGTH'] >= 4000);
    }
    return $data;
  }
}

//==============================================================================

class guessId {
  function guess($par) {
    // First, determine either Faust of Local id number from Identifier of Bibliographic Record
    $recordidtype = strtolower($par['recordidtype'][0]);  // Only one instance of this par is expected, therefore the first is taken
    $recordid = $par['recordid'][0];  // Only one instance of this par is expected, therefore the first is taken
    $libraryid = $par['libraryid'][0];  // Only one instance of this par is expected, therefore the first is taken
    if (!empty($recordid)) {  // If recordid exists, then it can either be a faust number or a local number
      if (($recordidtype == 'faust') and ($recordid[0] != '9')) {  // If recordidtype says faust, AND the first digit is NOT '9' - then we know that this is a faust number
        $ret[] = array('type' => 'faust', 'id' => $recordid);
        output::display(" Found identification: faust($recordid) - Reason: [recordid] exist, [recordidtype] says 'faust', and first digit is not '9'");
      } else {
        if (!empty($libraryid)) {  // Now we know, that this is not a faust number - if libraryid exist, then we can form a local number
          $ret[] = array('type' => 'local', 'id' => $libraryid . ':' . $recordid);
          if ($recordidtype == 'faust') {
            output::display(" Found identification: local($libraryid:$recordid) - Reason: [recordid] exists, [recordidtype] says 'faust', but first digit is '9'");
          } else {
            output::display(" Found identification: local($libraryid:$recordid) - Reason: [recordid] exists and [recordidtype] says '$recordidtype'");
          }
        }
        // However - we would also suspect recordid to contain an isbn or issn number - if this might be true, then do catch it
        if (materialId::validateIsbn(materialId::normalizeIsbn($recordid))) {
          $ret[] = array('type' => 'ean', 'id' => $recordid);
          output::display(" Found identification: ean($recordid) - Reason: [recordid] exists and found to be a local id, but it is also a valid ean number");
        }
        if (materialId::validateIssn(materialId::normalizeIssn($recordid))) {
          $ret[] = array('type' => 'issn', 'id' => $recordid);
          output::display(" Found identification: issn($recordid) - Reason: [recordid] exists and found to be a local id, but it is also a valid issn number");
        }
      }
    }
    // Now, determine if one of the "previous" faust/local numbers exist
    $previousfaustid = $par['previousfaustid'][0];  // Only one instance of this par is expected, therefore the first is taken
    $previouslibraryid = $par['previouslibraryid'][0];  // Only one instance of this par is expected, therefore the first is taken
    $previousrecordid = $par['previousrecordid'];  // Please note, that here we can have multiple (well - two) previous record id's - so this is an array
    if (!empty($previousfaustid)) {  // If previousfaustid exists, then it can either be a faust number or a local number
      $validfaust = materialId::validateFaust(materialId::normalizeFaust($previousfaustid));
      if ($validfaust and ($previousfaustid[0] != '9')) {  // If previousfaustid is a valid faust, AND the first digit is NOT '9' - then we know that this is a faust number
        $ret[] = array('type' => 'faust', 'id' => $previousfaustid);
        output::display(" Found identification: faust($previousfaustid) - Reason: [previousfaustid] exists and is a valid faust number and the first digit is not '9'");
      } else if (!empty($previouslibraryid)) {  // Now we know, that this is not a faust number - if previouslibraryid exist, we can form a local number
        $ret[] = array('type' => 'local', 'id' => $previouslibraryid . ':' . $previousfaustid);
        if ($validfaust) {
          output::display(" Found identification: local($previouslibraryid:$previousfaustid) - Reason: [previousfaustid] exists and is a valid faust number, but the first digit is '9'");
        } else {
          output::display(" Found identification: local($previouslibraryid:$previousfaustid) - Reason: [previousfaustid] exists but is not a valid faust number");
        }
      }
    }
    // If any of the two previousrecordid's were present, we can form local id's from these, if previouslibraryid exist
    if (is_array($previousrecordid)) {
      foreach ($previousrecordid as $recid) {
        if (!empty($recid)) {
          if (!empty($previouslibraryid)) {
            $ret[] = array('type' => 'local', 'id' => $previouslibraryid . ':' . $recid);
            output::display(" Found identification: local($previouslibraryid:$recid) - Reason: [previousrecordid] exists and so does [previouslibraryid]");
          }
          // However - we would also suspect $previousrecordid to contain isbn or issn numbers - if this might be true, then do catch them
          if (materialId::validateIsbn(materialId::normalizeIsbn($recid))) {
            $ret[] = array('type' => 'ean', 'id' => $recid);
            output::display(" Found identification: ean($recid) - Reason: [previousrecordid] exists and found to be a local id, but it is also a valid ean number");
          }
          if (materialId::validateIssn(materialId::normalizeIssn($recid))) {
            $ret[] = array('type' => 'issn', 'id' => $recid);
            output::display(" Found identification: issn($recid) - Reason: [previousrecordid] exists and found to be a local id, but it is also a valid issn number");
          }
        }
      }
    }
    // Run through all parameters, and check for the remaining 'ean', 'issn' and 'materialid'
    foreach ($par as $type => $ids) {
      $type = strtolower($type);
      // If type is either 'ean' or 'issn', then these are used directly
      if (($type == 'ean') or ($type == 'issn')) {
        foreach ($ids as $id) {
          $ret[] = array('type' => $type, 'id' => $id);
          output::display(" Found identification: $type($id) - Reason: [$type] is found");
        }
      }
      // If type is materialid, these contains EAN numbers - if they are valid
      if (($type == 'materialid')) {
        foreach ($ids as $id) {
          if (materialId::validateEAN(materialId::normalizeEAN($id))) {  // If id is a valid EAN number - then just go ahead
            $ret[] = array('type' => 'ean', 'id' => $id);
            output::display(" Found identification: ean($id) - Reason: [materialid] is found, and is a valid EAN number");
          }
        }
      }
    }
    return $ret;
  }
}

//==============================================================================


class openXidWrapper {
  static $enabled;

  private function __construct() {}
  
  private function _buildRequest($openxid, $clusterid, $matches) {
    $requestDom = new DOMDocument('1.0', 'UTF-8');
    $requestDom->formatOutput = true;
    $soapEnvelope = $requestDom->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Envelope');
    $soapEnvelope->setAttribute('xmlns:oxid', 'http://oss.dbc.dk/ns/openxid');
    $requestDom->appendChild($soapEnvelope);
    $soapBody = $soapEnvelope->appendChild($requestDom->createElement('soapenv:Body'));
    $updateIdRequest = $soapBody->appendChild($requestDom->createElement('oxid:updateIdRequest'));
    $updateIdRequest->appendChild($requestDom->createElement('oxid:openXId', $openxid));
    $updateIdRequest->appendChild($requestDom->createElement('oxid:clusterId', $clusterid));
    if (is_array($matches)) foreach ($matches as $match) {
      $id = $updateIdRequest->appendChild($requestDom->createElement('oxid:id'));
      $id->appendChild($requestDom->createElement('oxid:idType', $match['type']));
      $id->appendChild($requestDom->createElement('oxid:idValue', $match['id']));
    }
    return $requestDom->saveXML();
  }

  function enable($flag) {
    self::$enabled = $flag;
  }

  function sendupdateIdRequest($url, $openxid, $clusterid, $matches) {
    if (!self::$enabled) return;
    $curl = new cURL();
    $curl->set_timeout(10);
    $curl->set_post_xml(self::_buildRequest($openxid, $clusterid, $matches));
    $res = $curl->get($url);
    $curl->close();
  }
  
}



//==============================================================================

class harvest {
  private $progress;    // Progress indicator
  private $fieldTab;    // Holds translation table for fields/subfields
  private $danbibDb;    // Object holding danbib database
  private $serviceDb;   // Object holding service database
  private $openxidurl;  // URL to the Open Xid Webservice
  private $noupdate;    // Boolean to tell, whether to send data to OpenXId - if false, no data is sent

  function __construct($config, $verbose) {
    $this->progress = new progressIndicator(!(array_key_exists('marc', $verbose) or array_key_exists('verbose', $verbose)));
    output::enable(array_key_exists('verbose', $verbose));
    output::marcEnable(array_key_exists('marc', $verbose) and array_key_exists('verbose', $verbose));
    $this->noupdate = array_key_exists('noupdate', $verbose);
    openXidWrapper::enable(!$this->noupdate);
    output::open($config->get_value("logfile", "setup"), $config->get_value("verbose", "setup"));
    // Construct the fieldTab table - translating field/subfield to identifier types
    $this->fieldTab = array();
    $fieldFromIni = $config->get_value('field', 'marc');
    foreach ($fieldFromIni as $key => $values) {
      if (is_array($values))
        foreach ($values as $val) {
          list($field, $subfield) = explode('*', $val, 2);
          $this->fieldTab[$field][$subfield] = $key;
        }
    }
    $this->danbibDb = new danbibDatabase($config->get_value('ocilogon', 'setup'));
    $this->serviceDb = new serviceDatabase($config->get_value('servicelogon', 'setup'));
    $this->openxidurl = $config->get_value('openxidurl', 'setup');
  }


  private function _processMarcRecord($num) {
    $marcclass = new marc();
    $marcclass->fromString($num['DATA']);
    output::display("Marc record({$num['LENGTH']}): library={$num['BIBLIOTEK']}, id={$num['ID']}, danbibid={$num['DANBIBID']}");
    foreach ($marcclass as $marcItem) {
      $field = $marcItem['field'];
      if ($field != '000') {
        output::marcDisplay("   $field {$marcItem['indicator']}");
        if (is_array($marcItem['subfield'])) {
          foreach ($marcItem['subfield'] as $subfield) {
            $subfieldCode = $subfield[0];
            $subfield = substr($subfield, 1);
            output::marcDisplay("     $subfieldCode $subfield");
            if (isset($this->fieldTab[$field][$subfieldCode])) {
              $match[strtolower($this->fieldTab[$field][$subfieldCode])][] = $subfield;
            }
          }
        }
      } else {  // $field IS '000', and there is nothing to find here, However we can display something if verbose enabled
        output::marcDisplay("   $field {$marcItem['indicator']}");
        if (is_array($marcItem['subfield'])) {
          foreach ($marcItem['subfield'] as $subfield) {
            output::marcDisplay("     $subfield");
          }
        }
      }
    }
    openXidWrapper::sendupdateIdRequest($this->openxidurl, $num['ID'], $num['DANBIBID'], guessId::guess($match));
    unset($marcclass);
    unset($match);
  }


  private function _processDanbibData($where) {
    $this->danbibDb->query($where);
    while ($num = $this->danbibDb->fetch()) {
      $this->progress->tick();
      $this->_processMarcRecord($num);
    }
  }


  function execute($howmuch) {
    if (empty($howmuch)) {  // $howmuch contains either one of the strings 'full' or 'inc' - or an array of id's
      output::die_log('No identifiers specified');
    }
    if (is_array($howmuch)) {  // In this case, $howmuch contains an array of id's
      $this->_processDanbibData("where id in ('" . implode("','", $howmuch) . "')");
    } else if ($howmuch == 'full') {
      $this->_processDanbibData("");  // The where clause is empty - meaning all id's will be found
    } else {  // $howmuch == 'inc'
      $time = 1;
      while (true) {
        $this->serviceDb->queryServices();
        while ($id = $this->serviceDb->fetchId()) {
          $time = 1;
          $this->_processDanbibData("where id = '$id'");
          if (!$this->noupdate) {  // Only remove entry from service table if update is done
            $this->serviceDb->removeService($id);
          }
        }
        output::display("Delaying: $time seconds");
        sleep($time);
        $time = min(2*$time, 60*VOXB_HARVEST_POLL_TIME);  // Double the time value (with a ceiling value)
      }
    }
  }

}

?>