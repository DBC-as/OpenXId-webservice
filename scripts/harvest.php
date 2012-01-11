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
require_once("harvest_class.php");
require_once("$startdir/OLS_class_lib/inifile_class.php");

/**
 * This function is used to write out the parameters which can be used for the program
 * @param str  The text string to be displayed in case of any errors
 */
function usage($str='') {
  global $argv, $inifile, $config;

  if ($str != '') {
    echo "$str\n\n";
  } else {
    $version_text = isset($config) ? " - version {$config->get_value("version", "setup")}" : "";
    echo "OpenXId Harvest$version_text\n\n";
  }
  echo "Usage: \n\$ $argv[0] [-p <initfile>] [-f] [-i <id>] [-l] [-n] [-v] [-m] [-h]\n";
  echo "\t-p <initfile> (default: \"$inifile\") \n";
  echo "\t-f\tfull harvest (if not specified, an incremental harvest is done) \n";
  echo "\t-i <id>\tharvests only the danbib record with identifier <id>. Please note,\n\t\tthat this disables full/incremental harvest.\n\t\tMultiple identifiers may be specified. \n";
  echo "\t-l\tloop mode - when doing an incremental harvest, start over when one harvest is done \n";
  echo "\t-n\tno update - no requests are sent to OpenXid\n\t\tPlease note, that in this case, incremental harvest does not remove the entry in the service table \n";
  echo "\t-v\tverbose display \n";
  echo "\t-m\tin verbose, do also output each marc record processed \n";
  echo "\t-h\thelp (shows this message) \n";
  exit;
}

$inifile = $startdir . "/" . "harvest.ini";
$config = new inifile($inifile);
if ($config->error) usage($config->error);

$options = getopt('?p:fi:nlvmh');
if (array_key_exists('h', $options)) usage();
if (array_key_exists('p', $options)) $inifile = $options['p'];

if (array_key_exists('i', $options)) {
  $howmuch = is_array($options['i']) ? $options['i'] : array($options['i']);  // Make sure, that it is an array
  foreach ($howmuch as &$i) $i = intval($i);
} else if (array_key_exists('f', $options)) {
  $howmuch = 'full';
} else {
  $howmuch = 'inc';
}
$verbose = array();
if (isset($options['n'])) $verbose['noupdate'] = true;
if (isset($options['v'])) $verbose['verbose'] = true;
if (isset($options['m'])) $verbose['marc'] = true;
$loop = isset($options['l']);
try {
  $harvest = new harvest($config, $verbose, $loop);
  $harvest->execute($howmuch);
} catch (Exception $e) {
  echo 'Harvest Error: ' . $e->getMessage() . "\n";
};

?>