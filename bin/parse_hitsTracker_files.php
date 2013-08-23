<?php
/*
 * Created on Feb 6, 2008
 *
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
require_once(dirname(__FILE__) .'/../lib/hitsTracker.class.php');

$ht = new hitsTracker;
$ht->process_all_files();

?>
