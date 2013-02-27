#!/usr/bin/php
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

    $startdir = dirname(realpath($argv[0]));
    require_once("$startdir/OLS_class_lib/inifile_class.php");
    require_once("$startdir/OLS_class_lib/pg_database_class.php");

    /**
    * This function is used to write out the parameters which can be used for the program
    * @param str  The text string to be displayed in case of any errors
    */
    function usage($str='') {
        global $argv, $inifile, $config;

        echo "$str\n\n";
        echo "Usage: \n\$ php $argv[0]\n";
        echo "\t-p <initfile> (default: \"$inifile\") \n";
        echo "\t-h\thelp (shows this message) \n";
        exit;
    }
    $inifile = $startdir . "/../" . "openxid.ini";

    $deleteTables = false;
    $options = getopt('p:hC:');
    if (array_key_exists('h', $options)) usage(); 
    if (array_key_exists('p', $options)) $inifile = $options['p'];
    if (array_key_exists('C',$options)) { 
        if ( $options[C] == 'YES') $deleteTables = true;
    }
    $config = new inifile($inifile);
    if ($config->error) usage($config->error);


    try {
        $db=new pg_database($config->get_value("oxid_credentials", "setup"));
        $db->open();

        $tablename1 = 'oxid_id_types';
        $tablename2 = 'oxid_ids';
        // test whether the table $tabelname exsist
        $sql = "select tablename from pg_tables where tablename = $1";
        $arr = $db->fetch($sql,array($tablename1));
        if ( $arr ) {
            if ($deleteTables) {
                $sql = "drop table $tablename2\n";
                $db->exe($sql);
                echo"TRACE, table droped:$sql";
                $sql = "drop table $tablename1\n";
                $db->exe($sql);
                echo"TRACE, table droped:$sql";
            }
            else {
                echo "table: $tablename1 or $tablename2 allready exsist, if you want to delete them, please call this program with parameter '-C YES' \n";
                exit;
            }
        }
        $sql = "
        CREATE TABLE $tablename1 (
        id   integer PRIMARY KEY,
        name varchar(10)
        )
        \n";
        $db->exe($sql);
        echo"TRACE, table created:$sql";

        $sql  = "
        CREATE TABLE $tablename2 (
        id        serial PRIMARY KEY,
        idtype    integer REFERENCES oxid_id_types(id),
        idvalue   varchar(32),
        recordid  integer,
        clusterid integer
        )
        \n";  
        $db->exe($sql);
        echo"TRACE, table created:$sql";

        $sql = "
        INSERT INTO oxid_id_types (id, name) VALUES (0, 'faust');
        INSERT INTO oxid_id_types (id, name) VALUES (1, 'ean');
        INSERT INTO oxid_id_types (id, name) VALUES (2, 'issn');
        INSERT INTO oxid_id_types (id, name) VALUES (3, 'local');
        \n";
        $db->exe($sql);
        echo"TRACE, table created:$sql";

        $sql = "
        CREATE INDEX oxid_id_types_id_idx ON oxid_id_types (id);
        CREATE INDEX oxid_ids_id_idx ON oxid_ids (id);
        CREATE INDEX oxid_ids_recordid_idx ON oxid_ids (recordid);
        CREATE INDEX oxid_ids_clusterid_idx ON oxid_ids (clusterid);
        \n";
        $db->exe($sql);
        echo"TRACE, index's created:$sql";

    } 
    catch(Exception $e) {
        echo "ERROR," . $e->__toString();
        exit(1);
    }

?>
