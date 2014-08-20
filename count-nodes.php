#!/usr/bin/env php
<?php
/** 
 * @file
 * For each of a list of paths, find the node for that path, and then edit the node's body so that all absolute links
 * to www.nlm.nih.gov.*\.html are made local, and the trailing \.html is removed.   Any relative links that point to \.html
 * are also modified to remove the \.html.
 * 
 * A new revision is created for each node modified in this way.
 */

// Turn on error reporting
error_reporting(E_ALL);
ini_set('display_errors', TRUE);
ini_set('display_startup_errors', TRUE);

// Bootstrap start
define('DRUPAL_ROOT', '/usr/nlm/apps/cmseval/drupal7');
$_SERVER['REMOTE_ADDR'] = "localhost"; // Necessary if running from command line
require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
// Bootstrap end

$result = db_select('node', 'n')->countQuery()->execute();
while ($obj = $result->fetchAssoc()) {
  print "Found ".$obj['expression']." nodes\n";
}

