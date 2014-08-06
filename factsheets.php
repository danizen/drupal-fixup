#!/usr/bin/env php
<?php
/** 
 * @file
 *
 * Reads two CSV files that map a factsheet relative URI, e.g. /pubs/factsheets/*, to a category.
 *
 * One CSV gives the alphabetical category from http://www.nlm.nih.gov/pubs/factsheets/factsheets.html
 *
 * The other CSV gives the subject category from http://www.nlm.nih.gov/pubs/factsheets/factsubj.html
 *
 * These are built into a data structure mapping FactSheet relative URI to its categories, and then the factsheet
 * is updated in the Drupal database to give correct values for 'field_alphabetical_view' and 'field_subject_view'.
 * 
 * Errors are printed before any database update about any of the following problems:
 *     - The relative URI from either CSV cannot be mapped to a node id (nid)
 *     - The category term name cannot be mapped to a term id (tid)
 *
 * If errors are printed from the script, the Drupal database is not updated.
 * 
 * Otherwise, updates are made to all the relative paths if the tids result in any changes, and 
 * a new revision is created for each node modified in this way.
 */

// Bootstrap start
define('DRUPAL_ROOT', '/usr/nlm/apps/cmseval/drupal7');
$_SERVER['REMOTE_ADDR'] = "localhost"; // Necessary if running from command line
require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
// Bootstrap end


define(ALPHA_FIELD, 'field_alphabetical_view');
define(SUBJ_FIELD, 'field_subject_view');

/**
 * Print out debug output
 */
function debugme($message) {
  if (isset($GLOBALS['v'])) {
    print $message."\n";
  }
}

/**
 * Permute our uniq array of tids to an array of tids suitable for the term reference field values
 */
function to_tid_array(&$tid_hash) {
  $tid_array = array();
  foreach ($tid_hash as $tid => $tidstring) {
    array_push($tid_array, array('tid' => $tidstring));
  }
  return $tid_array;
}

/** 
 * Check a node's body for links to html
 */
function fixup_factsheet_node (&$node, &$values) {

  // Some nodes will not be altered by this
  $changed = false;

  if ($alpha_array = $values[ALPHA_FIELD]) {
    $tidlist = to_tid_array($alpha_array);

    if (($curvalue = $node->field_alphabetical_view['und'])) {
      if ($tidlist != $curvalue) {
        $node->field_alphabetical_view['und'] = $tidlist;
        $changed = true;
      }
    } else {
      $node->field_alphabetical_view['und'] = $tidlist;
      $changed = true;
    }
  }
   
  if ($subject_array = $values[SUBJ_FIELD]) {
    $tidlist = to_tid_array($subject_array);

    if (($curvalue = $node->field_subject_view['und'])) {
      if ($tidlist != $curvalue) {
        $node->field_subject_view['und'] = $tidlist;
        $changed = true;
      }
    } else {
      $node->field_subject_view['und'] = $tidlist;
      $changed = true;
    }
  }

  // we had to modify it to make it work
  if ($changed) {
    $node->revision = 1;
    $node->path['pathauto'] = 0;
    $node->log = "Updated automatically to fix to modify views fields";
    node_save($node);
    print 'Modified node '.$node->nid.' at path '.$node->path."\n";
  } else {
    debugme('No need for changes on node '.$node->nid.' at path '.$node->path);
  }
}


/**
 * Parse a CSV, checking that each vocabulary term can be found in the given vocabulary
 */
function read_csv(&$path_hash, $csv_path, $field) {

  if (!field_info_instance('node', $field, 'nlm_factsheet')) {
    fprintf(STDERR, "Content type 'nlm_factsheet' lacks field '$field'\n");
    $errors = true;
    return false;
  }
  elseif (!($field_info = field_info_field($field))) {
    fprintf(STDERR, "Drupal installation lacks field '$field'\n");
    $errors = true;
    return false;
  }
  elseif ($field_info['type'] != 'taxonomy_term_reference') {
    fprintf(STDERR, "field '$field' is not a taxonomy_term_reference\n");
    $errors = true;
    return false;
  }
  elseif (! isset($field_info['settings']['allowed_values'])) {
    fprintf(STDERR, "couldn't find allowed vocabularies of field '$field'\n");
    $errors = true;
    return false;
  }
  $vocabulary = $field_info['settings']['allowed_values'][0]['vocabulary'];
  debugme("field '$field' has vocabulary '$vocabulary'");

  if (($file = fopen($csv_path, "r")) == false) {
    fprintf(STDERR, "Couldn't open $csv_path for reading\n");
    return false;
  }

  // parse headers of CSV

  $headers = fgetcsv($file);
  if (($ncol = count($headers)) < 2) {
    fprintf(STDERR, "Expected at least two columns in csv $csv_path\n");
    fclose($file);
    return false;
  }
  debugme("Got headers from $csv_path: ".var_export($headers, true));
  $factsheet_col = false;
  $category_col = false;
  for ($i = 0; $i < $ncol; $i++) {
    if ($headers[$i] == 'Factsheet') {
      $factsheet_col = $i;
    }
    elseif ($headers[$i] == 'Category') {
      $category_col = $i;
    }
  }
  if ($factsheet_col === false || $category_col === false) {
    fprintf(STDERR, "Couldn't find the 'Factesheet' and 'Category' columns\n");
    fclose($file);
    return false;
  }

  // read each row of the CSV

  $anyerrors = false;
  $lineno = 0;


  while (! feof($file)) {
    $lineno++;
    if (!is_array($list = fgetcsv($file))) {
      continue;
    }

    if (count($list) != $ncol) {
      fprintf(STDERR, "Line $lineno of $csv_path does not have $ncol columns, skipping\n");
      $anyerrors = true;
      continue;
    }

    $factsheet = $list[$factsheet_col];
    $category = $list[$category_col];

    // validate that this category is a valid term in the given vocabulary

    $term = taxonomy_get_term_by_name($category, $vocabulary);
    if (!is_array($term) || count($term) != 1) {
      fprintf(STDERR, "Couldn't find term '$category' (".bin2hex($category).") in vocabulary '$vocabulary' at line $lineno of $csv_path\n");
      $anyerrors = true;
    }
    $term = array_shift($term);

    // update the path hash
    is_array($path_hash[$factsheet]) || ($path_hash[$factsheet] = array());
    $path_hash[$factsheet][$field][$term->tid] = $term->tid;
  }
  fclose($file);

  return $anyerrors;
}

/**
 * Find a factsheet node by its alias
 */
function load_factsheet_node_by_alias($alias) {
  // strip leading path
  if (strpos($alias, '/') == 0) {
    $alias = substr($alias, 1);
  }

  // lookup an alias to get a path like '/node/44'
  if (!($path = drupal_get_normal_path($alias))) {
    fprintf(STDERR, "URI $alias couldn't be resolved to a normal path\n");
    return false;
  }

  // get that object as a node
  if (!($node = menu_get_object('node', 1, $path))) {
    fprintf(STDERR, "normal path $path for alias $alias is not a node\n");
    return false;
  }

  // check if that node is the right content type
  if ($node->type != 'nlm_factsheet') {
    fprintf(STDERR, "node ".$node->nid." for alias $alias is not a factsheet\n");
    return false;
  }

  return $node;
}
 

/**
 * Lookup each relative URI (key) in the path_hash and make sure it resolves to a node that is a factsheet
 *
 * Also double-checks that the factsheet content type has the needed fields for this exercise.
 */
function check_paths($path_hash) {

  $errors = false;

  foreach ($path_hash as $alias => $values) {
    if (load_factsheet_node_by_alias($alias) == false) {
      $errors = true;
    }
  }

  return $errors;
}

/** 
 * Make the the changes to each factsheet node that are needed.
 */
function fixup_factsheet_nodes($path_hash) {

  $errors = false;

  foreach ($path_hash as $alias => $values) {
     $node = load_factsheet_node_by_alias($alias);
     if (fixup_factsheet_node($node, $values)) {
       $errors = true;
     }
  }

  return $errors;
}

/**
 * Parse both CSVs into an in-memory database
 */
function fixup_factsheets_from_csv($alpha_path, $subject_path) {
  $path_hash = array();

  $anyerrors = false;
  if (read_csv($path_hash, $alpha_path, ALPHA_FIELD)) {
    $anyerrors = true;
  }
  if (read_csv($path_hash, $subject_path, SUBJ_FIELD)) {
    $anyerrors = true;
  }
  if (check_paths($path_hash)) {
    $anyerrors = true;
  }
  if ($anyerrors) {
    return false;
  } else {
    return fixup_factsheet_nodes($path_hash);
  }
}

/** 
 * Usage for this program
 */
function usage($progname) {
  print "Usage: $progname [-v] {-a alpha_csv_path} {-s subject_csv_path}\n";
  exit(1);
}

$options = getopt("va:s:");

// Output verbose output
if (isset($options['v'])) {
  $GLOBALS['v'] = true;
}

// process all nodes
if (!(isset($options['a']) && isset($options['s']))) {
  fprintf(STDERR, "Both -a alpha_csv_path and -s subject_csv_path are required\n");
  exit(1);
}

if (fixup_factsheets_from_csv($options['a'], $options['s'])) {
  exit(1);
}
exit(0);
