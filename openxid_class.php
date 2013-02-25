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


require_once("OLS_class_lib/webServiceServer_class.php");
require_once("OLS_class_lib/oci_class.php");
require_once("OLS_class_lib/pg_database_class.php");
require_once("OLS_class_lib/material_id_class.php");

class openXId extends webServiceServer {

  protected $db;
  protected $idTypeTable;

  function __construct($inifile, $silent=false) {
    parent::__construct($inifile);
    if ($silent) verbose::open('/dev/null');  // When instantiated by the harvester - the harvester sets up the common logfile, so all logging will then go there
    try {
      $this->db=new pg_database($this->config->get_value("oxid_credentials", "setup"));
      $this->db->open();
      $this->_prepare_postgres('GetClusterDataByTypeValuePairQuery', 'select (select name from oxid_id_types where id=ids.idtype) as idtype, idvalue from oxid_ids ids where clusterid in (select clusterid from oxid_ids where idvalue=$2 and idtype=(select id from oxid_id_types where name=lower($1)))');
      $this->_prepare_postgres('RemoveRecordsByRecordIdQuery', 'delete from oxid_ids where recordid = $1');
      $this->_prepare_postgres('PutIdTypeValueQuery', 'insert into oxid_ids (idtype,idvalue,recordid,clusterid) values ($1,$2,$3,$4)');
    } catch(Exception $e) {
      verbose::log(ERROR, "openxid:: Couldn't open database: " . $e->__toString());
      unset($this->db);
    }
    // get conversion table between binary idType id's and textual idTypes
    if (isset($this->db)) {
      $this->idTypeTable = $this->_getIdTypeTable();
      if (is_string($this->idTypeTable)) {
        verbose::log(ERROR, "openxid:: Couldn't open database: " . $this->idTypeTable);
        unset($this->db);
      }
    }
    verbose::log(TRACE, "openxid:: openxid initialized");
  }

  function __destruct() {
    if (isset($this->db)) {
      $this->db->close();
    }
    parent::__destruct();
  }

 /** \brief _prepare_postgres
  * @param string $name Name of the prepared statement
  * @param string $sql SQL string to prepare
  */
  private function _prepare_postgres($name, $sql) {
    try {
      @$this->db->prepare($name, $sql);
    } catch(fetException $e) {
      // Do nothing - the statement has already been prepared, just continue
    }
  }

 /** \brief _getClusterDataByTypeValuePair
  *
  */
  protected function _getClusterDataByTypeValuePair($type, $value) {
    verbose::log(DEBUG, "openxid:: _getClusterDataByTypeValuePair($type, $value);");
//    $sql = "select (select name from oxid_id_types where id=ids.idtype) as idtype, idvalue from oxid_ids ids where clusterid in (select clusterid from oxid_ids where idvalue=':idvalue' and idtype=(select id from oxid_id_types where name=lower(':idtype')))";
//    verbose::log(DEBUG, "openxid::  -> SQL: $sql");
    /*
    The following SQL has already been prepared in the constructor...
    $sql is constructed like this:
      <select 1> finds idtype as a number with $type as input:
        <select 1> = "select id from oxid_id_types where name=lower('$type')"
      
      <select 2> finds clusterid for the post, with the material given by id: $type, $value (eg. EAN, 1234567890123)
        <select 2> = "select clusterid from oxid_ids where idvalue='$value' and idtype=(<select 1>)"
                   = "select clusterid from oxid_ids where idvalue='$value' and idtype=(select id from oxid_id_types where name=lower('$type'))"
      
      <select 3> finds all material in the cluster for the found material:
        <select 3> = "select idtype, idvalue from oxid_ids where clusterid in (<select 2>)"
                   = "select idtype, idvalue from oxid_ids where clusterid in (select clusterid from oxid_ids where idvalue='$value' and idtype=(select id from oxid_id_types where name=lower('$type')))"
      
      <select 4> finds the string for idtype with the given number as input
        <select 4> = "select name from oxid_id_types where id=$num"
      
      <select 5> is in principle the same as <select 3> - but now fetched idtype as a string:
        <select 5> = "select <select 4>, idvalue from oxid_ids where clusterid in (select clusterid from oxid_ids where idvalue='$value' and idtype=(select id from oxid_id_types where name=lower('$type')))"
                   = "select (select name from oxid_id_types where id=ids.idtype), idvalue from oxid_ids ids where clusterid in (select clusterid from oxid_ids where idvalue='$value' and idtype=(select id from oxid_id_types where name=lower('$type')))"
    */
    try {
      $this->db->bind('idtype', $type);    // $1
      $this->db->bind('idvalue', $value);  // $2
      $this->db->execute('GetClusterDataByTypeValuePairQuery');
      while( $row = $this->db->get_row() ) { 
        $result[] = $row;
      }
    } catch(Exception $e) {
      verbose::log(ERROR, "openxid:: Couldn't get cluster data: " . $e->__toString());
      return "could not reach database";
    }
    return $result;
  }


 /** \brief _removeRecordsByRecordId
  *
  */
  protected function _removeRecordsByRecordId($recordId) {
    verbose::log(DEBUG, "openxid:: _removeRecordsByRecordId($recordId);");
    if (empty($recordId)) return false;
    try {
      $this->db->bind('recordid', $recordId);  // $1
      $this->db->execute('RemoveRecordsByRecordIdQuery');
    } catch(Exception $e) {
      verbose::log(ERROR, "openxid:: Couldn't remove records: " . $e->__toString());
      return "could not reach database";
    }
    return false;
  }


 /** \brief _getIdTypeTable
  *
  */
  protected function _getIdTypeTable() {
    verbose::log(DEBUG, "openxid:: _getIdTypeTable();");
    try {
      $sql = "select id, name from oxid_id_types";
      verbose::log(DEBUG, "openxid::  -> SQL: $sql");
      $this->db->set_query($sql);
      $this->db->execute();
      while( $row = $this->db->get_row() ) {
        $result[intval($row['id'])] = $row['name'];
        $result[$row['name']] = intval($row['id']);  // Both ways
      }
    } catch(Exception $e) {
      verbose::log(ERROR, "openxid:: Couldn't get ID type table: " . $e->__toString());
      return "could not read ID Types";
    }
    return $result;
  }

 /** \brief _putIdTypeValue
  *
  */
  protected function _putIdTypeValue($recordId, $clusterId, $idType, $idValue) {
    verbose::log(DEBUG, "openxid:: _putIdTypeValue($recordId, $clusterId, $idType, $idValue);");
    $recordId = strip_tags($recordId);
    $clusterId = strip_tags($clusterId);
    $idType = strtolower(strip_tags($idType));
    if (!array_key_exists($idType, $this->idTypeTable)) return "invalid idType";
    $idType = $this->idTypeTable[$idType];
    $idValue = strip_tags($idValue);
    try {
      $this->db->bind('idtype', $idType);        // $1
      $this->db->bind('idvalue', $idValue);      // $2
      $this->db->bind('recordid', $recordId);    // $3
      $this->db->bind('clusterid', $clusterId);  // $4
      $this->db->execute('PutIdTypeValueQuery');
    } catch(Exception $e) {
      verbose::log(ERROR, "openxid:: Couldn't put type value pair: " . $e->__toString());
      return "could not reach database";
    }
    return false;
  }


 /** \brief _normalize
  *
  */
  protected function _normalize($idType, $idValue) {
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


 /** \brief _removeDuplicates
  *
  */
  protected function _removeDuplicates($data) {
    if (!is_array($data)) return null;
    foreach($data as $item) {
      $acc[$item['idtype']][$item['idvalue']]++;  // Make new array with data as keys, hereby duplicates are overwritten
    }
    foreach ($acc as $type => $valueArray) {
      foreach($valueArray as $value => $count) {
        $res[] = array('idtype' => $type, 'idvalue' => $value);  // Re-establish array
      }
    }
    return $res;
  }

// =============================================================================


 /** \brief getIdsRequest
  *
  */
  function getIdsRequest($param) {
    verbose::log(DEBUG, "openxid:: getIdsRequest(...);");
    $xid_getIdsResponse = &$ret->getIdsResponse;
    $xid_getIdsResponse->_namespace = $this->xmlns['xid'];

    if (!$this->aaa->has_right("openxidget", 560)) {
      $xid_error = &$xid_getIdsResponse->_value->error;
      $xid_error->_value = "authentication error";
      $xid_error->_namespace = $this->xmlns['xid'];
      return $ret;
    }

    if (!isset($this->db)) {
      $xid_error = &$xid_getIdsResponse->_value->error;
      $xid_error->_value = "could not reach database";
      $xid_error->_namespace = $this->xmlns['xid'];
      return $ret;
    }

    $paramId = is_array($param->id) ? $param->id : array($param->id);
    if (empty($paramId)) {
      $xid_error = &$xid_getIdsResponse->_value->error;
      $xid_error->_value = "invalid id";
      $xid_error->_namespace = $this->xmlns['xid'];
      return $ret;
    }

    $clusterData = array();
    foreach ($paramId as $id) {
      $item = array();
      $item['idType'] = strtolower(strip_tags($id->_value->idType->_value));
      $item['idValue'] = strip_tags($id->_value->idValue->_value);
      // check if idType is supported
      if (!array_key_exists($item['idType'], $this->idTypeTable)) {
        $item['error'] = "invalid idType";
        $clusterData[] = $item;
        continue;  // Next iteration
      }
      // Normalize
      $idValue = self::_normalize($item['idType'], $item['idValue']);
      if ($idValue == 0) {
        $item['error'] = "invalid id"; 
        $clusterData[] = $item;
        continue;  // Next iteration
      }
      // Get data from db
      $newClusterData = $this->_getClusterDataByTypeValuePair($item['idType'], $idValue);
      if (is_string($newClusterData)) {
        $item['error'] = "could not reach database"; 
        $clusterData[] = $item;
        continue;  // Next iteration
      }
      if (!is_array($newClusterData) or (count($newClusterData)==0)) {
        $item['error'] = "no results found for requested id"; 
        $clusterData[] = $item;
        continue;  // Next iteration
      }
      $item['ids'] = self::_removeDuplicates($newClusterData);
      $clusterData[] = $item;
    }

    // Format output xml
    $xid_getIdResult = &$xid_getIdsResponse->_value->getIdResult;
    foreach ($clusterData as $item) {
      unset($xid_get_item);
      $xid_get_item->_namespace = $this->xmlns['xid'];
      $xid_requestedId = &$xid_get_item->_value->requestedId;
      $xid_requestedId->_namespace = $this->xmlns['xid'];
      $xid_requestedIdType = &$xid_requestedId->_value->idType;
      $xid_requestedIdType->_namespace = $this->xmlns['xid'];
      $xid_requestedIdType->_value = $item['idType'];
      $xid_requestedIdValue = &$xid_requestedId->_value->idValue;
      $xid_requestedIdValue->_namespace  = $this->xmlns['xid'];
      $xid_requestedIdValue->_value = $item['idValue'];
      // If error
      if (!isset($item['error'])) {
        $xid_ids = &$xid_get_item->_value->ids;
        $xid_ids->_namespace = $this->xmlns['xid'];
        $xid_id = &$xid_ids->_value->id;
        foreach ($item['ids'] as $item_id) {
          unset($xid_get_id);
          $xid_get_id->_namespace = $this->xmlns['xid'];
          $xid_idType = &$xid_get_id->_value->idType;
          $xid_idType->_namespace  = $this->xmlns['xid'];
          $xid_idType->_value = $item_id['idtype'];
          $xid_idValue = &$xid_get_id->_value->idValue;
          $xid_idValue->_namespace  = $this->xmlns['xid'];
          $xid_idValue->_value = $item_id['idvalue'];
          $xid_id[] = $xid_get_id;
        }
      } else {
        $xid_ids_error = &$xid_get_item->_value->error;
        $xid_ids_error->_namespace = $this->xmlns['xid'];
        $xid_ids_error->_value = $item['error'];
        $xid_getIdResult[] = $xid_get_item;
        continue;
      }
      $xid_getIdResult[] = $xid_get_item;
    }
    return $ret;
  }


 /** \brief updateIdRequest
  *
  */
  function updateIdRequest($param) {
    verbose::log(DEBUG, "openxid:: updateIdRequest(...);");
    $xid_updateIdResponse = &$ret->updateIdResponse;
    $xid_updateIdResponse->_namespace = $this->xmlns['xid'];

    if (!$this->aaa->has_right("openxidupdate", 500)) {
      $xid_error = &$xid_updateIdResponse->_value->error;
      $xid_error->_value = "authentication error";
      $xid_error->_namespace = $this->xmlns['xid'];
      return $ret;
    }

    if (!isset($this->db)) {
      $xid_error = &$xid_updateIdResponse->_value->error;
      $xid_error->_value = "could not reach database";
      $xid_error->_namespace = $this->xmlns['xid'];
      return $ret;
    }

    $recordId = strip_tags($param->recordId->_value);
    $clusterId = strip_tags($param->clusterId->_value);
    if (isset($param->id)) {
      if (!is_array($param->id)) $param->id = array($param->id);  // Assure, that this is an array
      $id = array();
      foreach ($param->id as $item) {
        $idType = strip_tags($item->_value->idType->_value);
        $idValue = strip_tags($item->_value->idValue->_value);
        $normalizedValue = self::_normalize($idType, $idValue);
        if (!is_string($idType) or !array_key_exists($idType, $this->idTypeTable)) {  // Error: Invalid idType
          $id["$idType:$normalizedValue"] = array('idType' => $idType, 'idValue' => $idValue, 'error' => 'invalid idType');  // Use type:value in order to filter out duplicates
        } else {
          if (empty($normalizedValue)) {  // If normalizing returns a zero, it means that the id was invalid
            $id["$idType:$normalizedValue"] = array('idType' => $idType, 'idValue' => $idValue, 'error' => 'invalid id');  // Use type:value in order to filter out duplicates
          } else {
            $id["$idType:$normalizedValue"] = array('idType' => $idType, 'idValue' => $normalizedValue);  // Use type:value in order to filter out duplicates
          }
        }
      }
    }

    // First - delete the existing records with recordId as given in input
    if ($result = $this->_removeRecordsByRecordId($recordId)) {
      $xid_error = &$xid_updateIdResponse->_value->error;
      $xid_error->_value = "could not reach database";
      $xid_error->_namespace = $this->xmlns['xid'];
      return $ret;
    }

    // Then - add all listed materials in <id> tag
    if (is_array($id)) {
      foreach ($id as $key=>$item) {
        if (!isset($item['error'])) {
          $id[$key]['error'] = $this->_putIdTypeValue($recordId, $clusterId, $item['idType'], $item['idValue']);
        }
      }
    }

    // Format output xml
    if (!is_array($id) or empty($id)) {
      $xid_updateIdStatus = &$xid_updateIdResponse->_value->updateIdStatus;
      $xid_updateIdOk = &$status_item->_value->updateIdOk;
      $xid_updateIdOk->_namespace = $this->xmlns['xid'];
      $xid_updateIdStatus = $status_item;
      $xid_updateIdStatus->_namespace = $this->xmlns['xid'];
    } else {  // $id IS an array
      foreach ($id as $item) {
        $xid_updateIdStatus = &$xid_updateIdResponse->_value->updateIdStatus;
        unset($status_item);
        $status_item->_namespace = $this->xmlns['xid'];;
        $xid_id = &$status_item->_value->id;
        $xid_id->_namespace = $this->xmlns['xid'];
        $xid_idType = &$xid_id->_value->idType;
        $xid_idType->_namespace = $this->xmlns['xid'];
        $xid_idType->_value = $item['idType'];
        $xid_idValue = &$xid_id->_value->idValue;
        $xid_idValue->_namespace = $this->xmlns['xid'];
        $xid_idValue->_value = $item['idValue'];
        if (is_string($item['error'])) {
          $xid_error = &$status_item->_value->error;
          $xid_error->_namespace = $this->xmlns['xid'];
          $xid_error->_value = $item['error'];
        } else {
          $xid_updateIdOk = &$status_item->_value->updateIdOk;
          $xid_updateIdOk->_namespace = $this->xmlns['xid'];
        }
        $xid_updateIdStatus[] = $status_item;
      }
    }
    return $ret;
  }

}

?>