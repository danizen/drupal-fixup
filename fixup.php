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

// Bootstrap start
define('DRUPAL_ROOT', '/usr/nlm/apps/cmseval/drupal7');
$_SERVER['REMOTE_ADDR'] = "localhost"; // Necessary if running from command line
require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
// Bootstrap end

include_once('simple_html_dom.php');

/**
 * Print out debug output
 */
function debugme($message) {
  if (isset($GLOBALS['v'])) {
    print $message."\n";
  }
}

/** 
 * Parse some HTML text and check content for links to html
 *
 * Returns false if the HTML text was fine, and true if the HTML text was modified
 */
function fixup_check_html (&$htmltext) {

  $changed = false;

  /* parse that text as as HTML */
  $html = str_get_html($htmltext);

  // for each link
  foreach ($html->find('a') as $link) {

    debugme("found link to ".$link->href);
    $parts = parse_url($link->href);

    // if it is absolute and it points here
    if (isset($parts['scheme']) && $parts['scheme'] = 'http' && isset($parts['host']) && $parts['host'] = 'www.nlm.nih.gov') {

      // if it is an html file; 
      if (isset($parts['path']) && preg_match('/\.html$/', $parts['path'])==1)  {

        debugme(" -> it is an absolute link to .html");

        // keep just the path and the anchor and point it here
        $new_href = preg_replace('/\.html$/', '', $parts['path']);
        if (isset($parts['fragment'])) {
          $new_href .= '#'.$parts['fragment'];
        }
        debugme(" -> new href = $new_href");
        $link->href = $new_href;
        $changed = true;
      }

    } 
    // if it is relative
    elseif (!isset($parts['host']) && !isset($parts['scheme'])) {

      // if it is an html file; 
      if (isset($parts['path']) && preg_match('/\.html$/', $parts['path'])==1)  {

        debugme(" -> it is an absolute link to .html");

        // keep just the path and the anchor and point it here
        $new_href = preg_replace('/\.html$/', '', $parts['path']);
        if (isset($parts['fragment'])) {
          $new_href .= '#'.$parts['fragment'];
        }
        debugme(" -> new href = $new_href");
        $link->href = $new_href;
        $changed = true;
      }
    }
  }

  if ($changed) {
    $htmltext = $html;
  }
  return $changed;
}


/** 
 * Check a node's body for links to html
 */
function fixup_check_node ($node) {

  // Some nodes will not be altered by this
  $changed = false;

  // Must nodes have a body, but not all
  if (field_info_instance('node', 'body', $node->type)) {
    debugme('node '.$node->nid.' has a body');
    $htmltext = $node->body[$node->language][0]['value'];
    if (fixup_check_html($htmltext)) {
      $node->body[$node->language][0]['value'] = $htmltext;
      $changed = true;
    }
  }

  // In our schema, some nodes have a secondary long text area
  if (field_info_instance('node', 'field_sidebar', $node->type)) {
    debugme('node '.$node->nid.' has a sidebar');
    $htmltext = $node->field_sidebar[$node->language][0]['value'];
    if (fixup_check_html($htmltext)) {
      $node->field_sidebar[$node->language][0]['value'] = $htmltext;
      $changed = true;
    }
  }

  // we had to modify it to make it work
  if ($changed) {
    $node->revision = 1;
    $node->path['pathauto'] = 0;
    $node->log = "Updated automatically to fix to absolute links and links to .html";
    node_save($node);
    print 'Modified node '.$node->nid.' at path '.$node->path."\n";
  } else {
    debugme('No need for changes on node '.$node->nid.' at path '.$node->path);
  }

}

/** 
 * For each node, check the body for links to html
 */
function fixup_check_all_nodes() {
  $result = db_select('node', 'n')->fields('nid');
  while ($obj = db_fetch_object ($result)) {

    // Load the node
    $node = node_load($obj->nid);
    debugme("visiting node ".$node->nid." with path ".$node->path."\n");

    // Check the node body
    fixup_check_node($node);
  }
}

/**
 * For an alias path, lookup the node, and check its body
 */
function fixup_check_node_by_alias($alias) {

  // strip off one leading slash
  if (strpos($alias, '/') == 0) {
    $alias = substr($alias, 1);
  }

  // Lookup an alias to get a path like '/node/44'
  if ($path = drupal_get_normal_path($alias)) {
    debugme("got normal path $path for $alias");

    // Load that node 
    if ($node = menu_get_object('node', 1, $path)) {

      debugme("got node ".$node->nid." for normal path");

      // check the node body
      fixup_check_node($node);
    }
  }
  // the 'alias' might already by like '/node/44'
  elseif ($node = menu_get_object('node', 1, $alias)) {

    debugme("got node ".$node->nid." for $alias");

    // check the node body
    fixup_check_node($node);
  }
}

/**
 * For each of a number of alias paths, lookup the node, and check its body
 */
function fixup_check_nodes_by_aliases($aliases) {
  if (is_array($aliases)) {
    foreach ($aliases as $an_alias) {
      fixup_check_node_by_alias($an_alias);
    }
  }
  else {
    $an_alias = $aliases;
    fixup_check_node_by_alias($an_alias);
  }
}

/** 
 * Usage for this program
 */
function usage($progname) {
  print "Usage: $progname [-v] {-a|--all} | {-p|--path path }\n";
  exit(1);
}

$did_something = false;

$options = getopt("vap:", array( "all", "path::" ));
if (!is_array($options)) {
  usage($argv[0]);
}

// Output verbose output
if (isset($options['v'])) {
  $GLOBALS['v'] = true;
}

// process all nodes
if (isset($options['a']) || isset($options['all'])) {
   fixup_check_all_nodes();
   $did_something = true;
}

// process path
if (isset($options['p'])) {
   fixup_check_nodes_by_aliases($options['p']);
   $did_something = true;
}

// process path 
if (isset($options['path'])) {
   fixup_check_nodes_by_aliases($options['path']);
   $did_something = true;
}

// another usage problem
if ($did_something == false) {
  print "Nothing to do.\n";
  usage($argv[0]);
}

