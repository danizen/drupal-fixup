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

$main_tid = key(taxonomy_get_term_by_name('NLM Main Pages', 'section'));

$techbull_tid = key(taxonomy_get_term_by_name('Technical Bulletin', 'section'));

print "NLM Main Pages tid = $main_tid\n";
print "Technical Bulletin tid = $techbull_tid\n";

$result = db_query("select nid from node where nid not in (Select distinct workbench_access_node.nid from workbench_access_node) order by nid");
while ($obj = $result->fetch()) {
  $node = node_load($obj->nid);
  $tid = $main_tid;
  if (isset($node->path) && isset($node->path['alias'])) {
    $path = $node->path['alias'];
    if (preg_match('/^pubs\/techbull/', $path) == 1) {
      $tid = $techbull_tid;
    }
  }

  $data = array( 'nid' => $obj->nid, 'access_id' => $tid, 'access_scheme' => 'taxonomy');
  db_insert('workbench_access_node')->fields($data)->execute();

  print "node ".$obj->nid." => tid $tid\n";
}

