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

//==============================================================================

class output {
  static $enabled;  // Echo enable flag
  static $marcEnabled;  // Echo marc enable flag
  private function __construct() {}  // Prevents instantiation
  
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

class serviceDatabase {
  private $oci;  // Database instance

  function __construct($authentication) {
    try {
      $this->oci = new Oci($authentication);
      $this->oci->connect();
    } catch (ociException $e) {
      output::die_log($e);
    }
  }
  
  function query() {
    try {
      $sql = "select id, danbibid, bibliotek, data from poster where rownum < 6";
      $this->oci->set_query($sql);
    } catch (ociException $e) {
      output::die_log($e);
    }
  }
  
  function fetch() {
    return $this->oci->fetch_into_assoc();
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

  private function __construct() {}  // Prevents instantiation
  
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
  private $fieldTab;    // Holds translation table for fields/subfields
  private $danbibDb;    // Object holding danbib database
  private $openxidurl;  // URL to the Open Xid Webservice

  function __construct($config, $verbose) {
    output::enable(array_key_exists('verbose', $verbose));
    output::marcEnable(array_key_exists('marc', $verbose) and array_key_exists('verbose', $verbose));
    openXidWrapper::enable(!array_key_exists('silent', $verbose));
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
    $this->openxidurl = $config->get_value('openxidurl', 'setup');
  }

  function execute($howmuch) {
    if (empty($howmuch)) {  // $howmuch contains either one of the strings 'full' or 'inc' - or an array of id's
      output::die_log('No identifiers specified');
    }

    //while (true) {
    //  $sql ='select id from services where serviceno = 3';
    //  $this->oci->getxxx($sql);

    //  while ($idno = $this->oci->fetch_row($xxx)) {



    if (is_array($howmuch)) {
      $where = "where id in ('" . implode("','", $howmuch) . "')";
    } else if ($howmuch == 'inc') {
      output::die_log('Incremental harvest is not yet implemented');
    } else {  // $howmuch == 'full'
      $where = '';  // No where clause finds all records!
    }
    $this->danbibDb->query($where);
    while ($num = $this->danbibDb->fetch()) {
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
        } else {  // $field is NOT '000', and there is nothing to find here, However we can display something if needed
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
        $time = 1;
        
        // Fetch the data record an get the data
        
        // Update OpenXId
        
        $sql = "delete from services where serviceno = 3 and id = $idno";
    //    $this->oci->update($sql);
    //    $this->oci->commit();
    //  }
      
      $time *= $time;
      if ($time > 256) $time = 256;
      sleep($time);
      
    //}

  }
}

?>