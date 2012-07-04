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
require_once("$startdir/OLS_class_lib/timer_class.php");

define('VOXB_SERVICE_NUMBER', 7);               // Service number for the Voxb Service
define('VOXB_HARVEST_POLL_TIME', 5);            // Time given in minutes
define('PROGRESS_INDICATOR_LINE_LENGTH', 100);  // Number of periods in a progression indicator line
define('PROGRESS_INDICATOR_CHUNK', 100);        // Size of chunks to process for each period to be echoed
define('TIME_MEASUREMENT', true);               // Determines whether time will be measured and logged

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

  function trace($text) {
    if (self::$enabled) {
      echo $text . "\n";
    }
    self::log(TRACE, $text);
  }

  function marcTrace($text) {
    if (self::$marcEnabled) {
      echo $text . "\n";
    }
    self::log(TRACE, $text);
  }

  function error($error) {
    if (self::$enabled) {
      echo $error . "\n";
    }
    self::log(ERROR, $error);
  }

  function die_log($text) {
    echo $text . "\n";
    self::log(FATAL, $text);
    exit;
  }
}

//==============================================================================

class stopWatchTimer {
  static $stop_watch_timer;
  static $enabled;

  static function init($flag=true) {
    self::$enabled = $flag;
    self::$stop_watch_timer = new stopwatch();
  }

  static function start() {
    if (!isset(self::$stop_watch_timer)) return;
    $backtrace = debug_backtrace();
    self::$stop_watch_timer->start($backtrace[1]['class'] . '::' . $backtrace[1]['function']);
  }
  
  static function stop() {
    if (!isset(self::$stop_watch_timer)) return;
    $backtrace = debug_backtrace();
    self::$stop_watch_timer->stop($backtrace[1]['class'] . '::' . $backtrace[1]['function']);
  }
  
  static function result() {
    self::$stop_watch_timer->format('screen');
    if (self::$enabled) {
      output::trace(self::$stop_watch_timer->dump());
    }
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
    if ($this->counter==0) return;
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
      output::error($e->getMessage());
      throw new Exception('Service Database could not be opened');
    }
  }

  function queryServices() {
    stopWatchTimer::start();
    try {
      $this->oci->set_query('select rowid, id from services where service = ' . VOXB_SERVICE_NUMBER);
    } catch (ociException $e) {
      output::error($e->getMessage());
      stopWatchTimer::stop();
      throw new Exception('Service Database could not be queried');
    }
    stopWatchTimer::stop();
  }

  function fetchId() {
    stopWatchTimer::start();
    try {
      $ret = $this->oci->fetch_into_assoc();
      stopWatchTimer::stop();
      return $ret;
    } catch (ociException $e) {
      output::error($e->getMessage());
      stopWatchTimer::stop();
      throw new Exception('Could not fetch ID from Service Database');
    }
    stopWatchTimer::stop();
  }

  function removeService($rowid, $idno) {
    stopWatchTimer::start();
    try {
      $this->ociDelete->set_query("delete from services where rowid = '$rowid'");
      $this->ociDelete->commit();
    } catch (ociException $e) {
      output::error($e->getMessage());
      stopWatchTimer::stop();
      throw new Exception('Could not remove ID from Service Database: ' . $idno);
    }
    stopWatchTimer::stop();
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
      output::error($e->getMessage());
      throw new Exception('Danbib Database could not be opened');
    }
  }

  private function _queryOverflow($id) {
    try {
      $sql = "select id, lbnr, data, length(data) length from poster_overflow where id = $id order by lbnr";
      $this->ociOverflow->set_query($sql);
    } catch (ociException $e) {
      output::error($e->getMessage());
      throw new Exception('Danbib Database could not be queried for overflow data');
    }
  }

  private function _fetchOverflow() {
    try {
      return $this->ociOverflow->fetch_into_assoc();
    } catch (ociException $e) {
      output::error($e->getMessage());
      throw new Exception('Could not fetch overflow data from Danbib Database');
    }
  }

  function query($where) {
    stopWatchTimer::start();
    try {
      $sql = "select id, danbibid, bibliotek, data, length(data) length from poster";
      if (!empty($where)) {
         $sql .= " $where";
      }
      $this->oci->set_query($sql);
    } catch (ociException $e) {
      output::error($e->getMessage());
      stopWatchTimer::stop();
      throw new Exception('Danbib Database could not be queried');
    }
    stopWatchTimer::stop();
  }

  function fetch() {
    stopWatchTimer::start();
    try {
      $data = $this->oci->fetch_into_assoc();
      if ($data['LENGTH'] >= 4000) {
        $this->_queryOverflow($data['ID']);
        do {
          $overflow = $this->_fetchOverflow();
          $data['DATA'] .= $overflow['DATA'];
          $data['LENGTH'] += $overflow['LENGTH'];
        } while ($overflow['LENGTH'] >= 4000);
      }
      stopWatchTimer::stop();
      return $data;
    } catch (ociException $e) {
      output::error($e->getMessage());
      stopWatchTimer::stop();
      throw new Exception('Could not fetch data from Danbib Database');
    }
    stopWatchTimer::stop();
  }
}

//==============================================================================

class guessId {
  private function _normalize($idType, $idValue) {
    switch ($idType) {
      case 'ean':
        // Check if number is an EAN number
        $ean = materialId::validateEAN(materialId::normalizeEAN($idValue));
        if ($ean) return $ean;  // Yes it was...
        // Check if number is an ISBN number
        $idValue = materialId::validateISBN(materialId::normalizeISBN($idValue));
        if ($idValue === 0) return 0;  // No - it was neither a EAN nor an ISBN number
        return materialId::convertISBNToEAN($idValue);  // It was an ISBN number - convert to EAN
      case 'issn':
        return materialId::validateISSN(materialId::normalizeISSN($idValue));
      case 'faust':
        return materialId::validateFaust(materialId::normalizeFaust($idValue));
      case 'local':
        return $idValue;
      default:
        return 0;
    }
  }

  function guess($par) {
    stopWatchTimer::start();
    // First, determine either Faust of Local id number from Identifier of Bibliographic Record
    $recordid = $par['recordid'][0];  // Only one instance of this par is expected, therefore the first is taken
    $libraryid = $par['libraryid'][0];  // Only one instance of this par is expected, therefore the first is taken
    
    if (empty($recordid)) throw new Exception('An empty RecordId was found - this should not be possible');
    if (empty($libraryid)) throw new Exception('An empty LibraryId was found - this should not be possible');
    
    $rtype = 'local';  // If nothing else is found, then this is a local number
    // First check, if recordid is a faust number
    if (self::_normalize('faust', $recordid)) {
      if ($recordid[0] != '9') {  // If the first digit is NOT '9' - then we know that this is a faust number
        $rtype = 'faust';
      }
    }
    if (self::_normalize('issn', $recordid)) {
      $rtype = 'issn';
    }
    if (self::_normalize('ean', $recordid)) {
      $rtype = 'ean';
    }
    if ($rtype == 'local') {
      $ret[] = array('type' => $rtype, 'id' => $libraryid . ':' . $recordid);
      output::trace(" Found identification: $rtype($libraryid:$recordid) - Reason: Trial validations says, that $recordid is of type: '$rtype'");
    } else {
      $ret[] = array('type' => $rtype, 'id' => $recordid);
      output::trace(" Found identification: $rtype($recordid) - Reason: Trial validations says, that $recordid is of type: '$rtype'");
    }

    // Now, determine if one of the "previous" faust/local numbers exist
    @ $previousfaustid = $par['previousfaustid'][0];  // Only one instance of this par is expected, therefore the first is taken
    @ $previouslibraryid = $par['previouslibraryid'][0];  // Only one instance of this par is expected, therefore the first is taken
    @ $previousrecordid = $par['previousrecordid'];  // Please note, that here we can have multiple (well - two) previous record id's - so this is an array
    if (!empty($previousfaustid)) {  // If previousfaustid exists, then it can either be a faust number or a local number
      $validfaust = self::_normalize('faust', $previousfaustid);
      if ($validfaust and ($validfaust[0] != '9')) {  // If previousfaustid is a valid faust, AND the first digit is NOT '9' - then we know that this is a faust number
        $ret[] = array('type' => 'faust', 'id' => $previousfaustid);
        output::trace(" Found identification: faust($previousfaustid) - Reason: [previousfaustid] exists and is a valid faust number and the first digit is not '9'");
      } else if (!empty($previouslibraryid)) {  // Now we know, that this is not a faust number - if previouslibraryid exist, we can form a local number
        $ret[] = array('type' => 'local', 'id' => $previouslibraryid . ':' . $previousfaustid);
        if ($validfaust) {
          output::trace(" Found identification: local($previouslibraryid:$previousfaustid) - Reason: [previousfaustid] exists and is a valid faust number, but the first digit is '9'");
        } else {
          output::trace(" Found identification: local($previouslibraryid:$previousfaustid) - Reason: [previousfaustid] exists but is not a valid faust number");
        }
      }
    }

    // If any of the two previousrecordid's were present, we can form local id's from these, if previouslibraryid exist
    if (is_array($previousrecordid)) {
      foreach ($previousrecordid as $recid) {
        if (!empty($recid)) {
          if (!empty($previouslibraryid)) {
            $ret[] = array('type' => 'local', 'id' => $previouslibraryid . ':' . $recid);
            output::trace(" Found identification: local($previouslibraryid:$recid) - Reason: [previousrecordid] exists and so does [previouslibraryid]");
          }
          // However - we would also suspect $previousrecordid to contain isbn or issn numbers - if this might be true, then do catch them
          if (self::_normalize('ean', $recid)) {
            $ret[] = array('type' => 'ean', 'id' => $recid);
            output::trace(" Found identification: ean($recid) - Reason: [previousrecordid] exists and found to be a local id, but it is also a valid ean number");
          }
          if (self::_normalize('issn', $recid)) {
            $ret[] = array('type' => 'issn', 'id' => $recid);
            output::trace(" Found identification: issn($recid) - Reason: [previousrecordid] exists and found to be a local id, but it is also a valid issn number");
          }
        }
      }
    }

    // Run through all parameters, and check for the remaining 'ean', 'issn' and 'materialid'
    if (is_array($par)) foreach ($par as $type => $ids) {
      $type = strtolower($type);
      // If type is either 'ean' or 'issn', then these are used directly
      if (($type == 'ean') or ($type == 'issn')) {
        if (is_array($ids)) foreach ($ids as $id) {
          if ($type == 'ean') {
            if (self::_normalize('ean', $id)) {
              $ret[] = array('type' => $type, 'id' => $id);
              output::trace(" Found identification: $type($id) - Reason: [$type] is found");
            } else {
              output::error(" Validation error: id=$id is an illegal $type id");
            }
          } else {  // $type == 'issn'
            if (self::_normalize('issn', $id)) {
              $ret[] = array('type' => $type, 'id' => $id);
              output::trace(" Found identification: $type($id) - Reason: [$type] is found");
            } else {
              output::error(" Validation error: id=$id is an illegal $type id");
            }
          }
        }
      }
      // If type is materialid, these contains EAN numbers - if they are valid
      if (($type == 'materialid')) {
        if (is_array($ids)) foreach ($ids as $id) {
          if (self::_normalize('ean', $id)) {  // If id is a valid EAN number - then just go ahead
            $ret[] = array('type' => 'ean', 'id' => $id);
            output::trace(" Found identification: ean($id) - Reason: [materialid] is found, and is a valid EAN number");
          }
        }
      }
    }
    stopWatchTimer::stop();
    return $ret;
  }
}

//==============================================================================


class openXidWrapper {
  static $enabled;
  static $openxid_class;  // If not empty the wrapper uses direct database access - by using the class in this variable
  static $openxid_url;    // If not empty the wrapper uses the OpenXid webservice - by using the URL in this variable
  
  private function __construct($webservice) {
    self::$webservice = $webservice;
  }

  private static function _curl_execute($url, $xml) {
    $curl = new cURL();
    $curl->set_timeout(10);
    $curl->set_post_xml($xml);
    $res = $curl->get($url);
    if ($err = $curl->has_error()) throw new Exception('cURL could not communicate with OpenXid: ' . $err);
    $curl->close();
    @ $res_php = unserialize($res);
    if (!is_object($res_php)) throw new Exception('cURL could not decode status from OpenXid');
    return $res_php;
  }

  private function _buildRequest($openxid, $clusterid, $matches) {
    $requestDom = new DOMDocument('1.0', 'UTF-8');
    $requestDom->formatOutput = true;
    $soapEnvelope = $requestDom->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Envelope');
    $soapEnvelope->setAttribute('xmlns:xid', 'http://oss.dbc.dk/ns/openxid');
    $requestDom->appendChild($soapEnvelope);
    $soapBody = $soapEnvelope->appendChild($requestDom->createElement('soapenv:Body'));
    $updateIdRequest = $soapBody->appendChild($requestDom->createElement('xid:updateIdRequest'));
    $updateIdRequest->appendChild($requestDom->createElement('xid:recordId', $openxid));
    $updateIdRequest->appendChild($requestDom->createElement('xid:clusterId', $clusterid));
    if (is_array($matches)) foreach ($matches as $match) {
      $id = $updateIdRequest->appendChild($requestDom->createElement('xid:id'));
      $id->appendChild($requestDom->createElement('xid:idType', $match['type']));
      $id->appendChild($requestDom->createElement('xid:idValue', $match['id']));
    }
    $updateIdRequest->appendChild($requestDom->createElement('xid:outputType', 'php'));
    return $requestDom->saveXML();
  }

  function enable($flag) {
    self::$enabled = $flag;
  }

  function openxidUrl($url) {
    self::$openxid_url = $url;
  }
  
  function openxidClass($class) {
    self::$openxid_class = $class;
  }
  
  function sendupdateIdRequestWeb($openxid, $clusterid, $matches) {
    try {
      $response = self::_curl_execute(self::$openxid_url, self::_buildRequest($openxid, $clusterid, $matches));
    } catch (Exception $e) {
      output::error($e->getMessage());
      throw new Exception('ID(s) could not be sent to OpenXid - Cluster ID: ' . $clusterid . ', ID\'s=' . implode(', ', $all_ids));
    }
    return $response;
  }
  
  function sendupdateIdRequestDirect($openxid, $clusterid, $matches) {
    // Setup aaa authentication for allowing access to direct call
    self::$openxid_class->initOpenXid();
    // Build php object for representing the XML request
    unset($updateRequest);
    $updateRequest->recordId->_value = $openxid;
    $updateRequest->clusterId->_value = $clusterid;
    foreach ($matches as $m) {
      $item->idType->_value = $m['type'];
      $item->idValue->_value = $m['id'];
      $updateRequest->id[]->_value = $item;
      unset($item);
    }
    // 'Send' the request to the OpenXId object
    return self::$openxid_class->updateIdRequest($updateRequest);
  }

  function sendupdateIdRequest($openxid, $clusterid, $matches) {
    if (!self::$enabled) return;
    stopWatchTimer::start();
    $all_ids = array();
    if (is_array($matches)) foreach ($matches as $m) $all_ids[] = "{$m['type']}({$m['id']})";
    if (isset(self::$openxid_class)) {
      $response = self::sendupdateIdRequestDirect($openxid, $clusterid, $matches);
    } elseif (isset(self::$openxid_url)) {
      $response = self::sendupdateIdRequestWeb($openxid, $clusterid, $matches);
    }
    if (!isset($response->updateIdResponse->_value->updateIdStatus) or !is_array($response->updateIdResponse->_value->updateIdStatus)) {
      output::error('Could not decode answer from OpenXid');
      throw new Exception('ID(s) could not be sent to OpenXid - Cluster ID: ' . $clusterid . ', ID\'s=' . implode(', ', $all_ids));
    }
    $statuses = $response->updateIdResponse->_value->updateIdStatus;
    $faulty_ids = array();
    if (is_array($statuses)) foreach ($statuses as $s) {
      if (isset($s->_value->error)) {
        $faulty_ids[] = "{$s->_value->id->_value->idType->_value}({$s->_value->id->_value->idValue->_value})[{$s->_value->error->_value}]";
      }
    }
    if (count($faulty_ids) > 0) {
      output::error('Warning: Could not send data to OpenXid - Cluster ID: ' . $clusterid . ', ID\'s=' . implode(', ', $faulty_ids));
    }
    stopWatchTimer::stop();
  }
}



//==============================================================================

class harvest {
  private $progress;    // Progress indicator
  private $loop;        // Loop mode or not
  private $webservice;  // Boolean to tell, if webservice call shall be used to send data to OpenXid
  private $fieldTab;    // Holds translation table for fields/subfields
  private $danbibDb;    // Object holding danbib database
  private $serviceDb;   // Object holding service database
  private $noupdate;    // Boolean to tell, whether to send data to OpenXId - if false, no data is sent
  private $timing;      // Boolean to tell, whether timing figures are to be displayed at the end
  
  function __construct($config, $verbose, $loop, $webservice) {
    if (isset($_SERVER['REMOTE_ADDR'])) {
      $server_ip = $_SERVER['REMOTE_ADDR'];
    } else {
      $server_ip = gethostbyname(gethostname());
    }
    $this->loop = $loop;
    $this->webservice = $webservice;
    if ($this->webservice) {
      openXidWrapper::openxidUrl($config->get_value('openxidurl', 'setup'));
    } else {
      require_once($config->get_value('openxidpath', 'setup') . '/openxid_class.php');  // Include the Openxid classes
      // Now define a subclass of OpenXId - cannot be done before runtime, because placement of parent openXId class is not known before now
      eval("class directOpenXId extends openXId { function initOpenXid() { \$this->aaa->init_rights(null, null, null, '$server_ip'); } }");
      openXidWrapper::openxidClass(new directOpenXId($config->get_value('openxidpath', 'setup') . '/openxid.ini'));
    }
    $this->progress = new progressIndicator(!(array_key_exists('marc', $verbose) or array_key_exists('verbose', $verbose)));
    output::enable(array_key_exists('verbose', $verbose));
    output::marcEnable(array_key_exists('marc', $verbose) and array_key_exists('verbose', $verbose));
    $this->noupdate = array_key_exists('noupdate', $verbose);
    $this->timing = array_key_exists('timing', $verbose);
    if ($this->timing) {
      stopWatchTimer::init();
    }
    openXidWrapper::enable(!$this->noupdate);
    output::open($config->get_value("logfile", "setup"), $config->get_value("verbose", "setup"));
    // Construct the fieldTab table - translating field/subfield to identifier types
    $this->fieldTab = array();
    $fieldFromIni = $config->get_value('field', 'marc');
    if (is_array($fieldFromIni)) foreach ($fieldFromIni as $key => $values) {
      if (is_array($values))
        foreach ($values as $val) {
          list($field, $subfield) = explode('*', $val, 2);
          $this->fieldTab[$field][$subfield] = $key;
        }
    }
    try {
      $this->danbibDb = new danbibDatabase($config->get_value('ocilogon', 'setup'));
      $this->serviceDb = new serviceDatabase($config->get_value('servicelogon', 'setup'));
    } catch (Exception $e) {
      output::error($e->getMessage());
      throw new Exception('A database error prevented the harvest');
    }
  }

  private function _processMarcRecord($rec) {
    stopWatchTimer::start();
    $marcclass = new marc();
    $marcclass->fromIso($rec['DATA']);
    output::trace("Marc record({$rec['LENGTH']}): library={$rec['BIBLIOTEK']}, id={$rec['ID']}, danbibid={$rec['DANBIBID']}");
    foreach ($marcclass as $marcItem) {
      $field = $marcItem['field'];
      if ($field != '000') {
        output::marcTrace("   $field {$marcItem['indicator']}");
        if (is_array($marcItem['subfield'])) {
          foreach ($marcItem['subfield'] as $subfield) {
            $subfieldCode = $subfield[0];
            $subfield = substr($subfield, 1);
            output::marcTrace("     $subfieldCode $subfield");
            if (isset($this->fieldTab[$field][$subfieldCode])) {
              $match[strtolower($this->fieldTab[$field][$subfieldCode])][] = $subfield;
            }
          }
        }
      } else {  // $field IS '000', and there is nothing to find here, However we can display something if verbose enabled
        output::marcTrace("   $field {$marcItem['indicator']}");
        if (is_array($marcItem['subfield'])) {
          foreach ($marcItem['subfield'] as $subfield) {
            output::marcTrace("     $subfield");
          }
        }
      }
    }
    stopWatchTimer::stop();
    openXidWrapper::sendupdateIdRequest($rec['ID'], $rec['DANBIBID'], guessId::guess($match));
    unset($marcclass);
    unset($match);
  }


  private function _processDanbibData($where) {
    $this->danbibDb->query($where);
    while ($rec = $this->danbibDb->fetch()) {
      $this->progress->tick();
      $this->_processMarcRecord($rec);
    }
  }


  function execute($howmuch) {
    if (empty($howmuch)) {  // $howmuch contains either one of the strings 'full' or 'inc' - or an array of id's
      throw new Exception('No identifiers specified');
    }
    if (is_array($howmuch)) {  // In this case, $howmuch contains an array of id's
      stopWatchTimer::start();
      $this->_processDanbibData('where id in (' . implode(',', $howmuch) . ')');
      stopWatchTimer::stop();
      stopWatchTimer::result();
    } else if ($howmuch == 'full') {
      stopWatchTimer::start();
      $this->_processDanbibData('');  // The where clause is empty - meaning all id's will be found
      stopWatchTimer::stop();
      stopWatchTimer::result();
    } else {  // $howmuch == 'inc'
      $time = 1;
            
      while (true) {
        stopWatchTimer::start();
        try {
          $this->serviceDb->queryServices();
        } catch (Exception $e) {
          stopWatchTimer::stop();
          stopWatchTimer::result();
          throw new Exception('Service database could not be queried');
        }
        while ($ids = $this->serviceDb->fetchId()) {
          $time = 1;
          try {
            $this->_processDanbibData("where id = " . $ids['ID']);
            if (!$this->noupdate) {  // Only remove entry from service table if update is done
              $this->serviceDb->removeService($ids['ROWID'], $ids['ID']);
            }
          } catch (Exception $e) {
            output::error('Error while processing Danbib data - ' . $e->getMessage());
          }
        }        
        stopWatchTimer::stop();
        stopWatchTimer::result();
        if (!$this->loop) break;

        output::trace("Delaying: $time seconds");
        sleep($time);
        $time = min(2*$time, 60*VOXB_HARVEST_POLL_TIME);  // Double the time value (with a ceiling value)
      }
    }
  }

}

?>