<?php

require_once('config.php');
require_once('lib.php');
global $db, $skipLoggingFetchesToDb;
$db = new PDO(DB_DSN, DB_USER, DB_PASS);

if (php_sapi_name() != 'cli') {
	header('HTTP/1.1 403 Forbidden');
	header('Content-Type: text/plain');
	print "403 Forbidden\n";
	$message = "cron.php can only be invoked from the command-line.";
	log_event('access_denied', $message);
	print $message;
	exit;
}

// Validate our arguments
$valid_args = array($argv[0], '-h', '-v', '--stats', '--no-log');
if (in_array('-h', $argv) || count(array_diff($argv, $valid_args))) {
	print 'Usage:
'.$argv[0].' [-h] [--stats] [--no-log] [-v]
     -h         This help message.
     --stats    Print out update stats.
     --no-log   Do not log fetch updates/errors to the database.
     -v         Verbose, print out the id and URL of each feed as it is updated.
 ';
 	exit;
}
$verbose = in_array('-v', $argv);
$skipLoggingFetchesToDb = in_array('--no-log', $argv);

// Set up our counters
$start = microtime(true);
$succeeded = 0;
$failed = 0;

// Update our feeds
$stmt = $db->prepare(
	'SELECT id, url
	FROM feeds 
	WHERE 
		(custom_ttl IS NULL AND last_fetch < DATE_SUB(NOW(), INTERVAL '.DEFAULT_TTL.' MINUTE))
		OR (custom_ttl IS NOT NULL AND last_fetch < DATE_SUB(NOW(), INTERVAL custom_ttl MINUTE))
	');
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_OBJ);
$fetched = count($rows);

foreach ($rows as $row) {
	if ($verbose) {
		print $row->id."\t".$row->url."\t";
		$feedStart = microtime(true);
	}
	
	try {
		fetch_url($row->id, $row->url);
		$succeeded++;
		if ($verbose)
			print "success";
	} catch (Exception $e) {
// 		print $e->getMessage()."\n";
		$failed++;
		if ($verbose)
			print "failed";
	}
	
	if ($verbose) {
		print "\t".round(microtime(true) - $feedStart, 3).'s';
		print "\n";
	}
}


// Delete feeds that haven't been accessed in a very long time so we don't waste
// effort continuing to fetch them.
$stmt = $db->prepare(
	'SELECT id, url
	FROM feeds 
	WHERE 
		(last_access < DATE_SUB(NOW(), INTERVAL '.MAX_LIFE_WITHOUT_ACCESS.'))
	');
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_OBJ);
$deleted = count($rows);

$deleteStmt = $db->prepare('DELETE FROM feeds WHERE id = ?');
foreach ($rows as $row) {
	$db->beginTransaction();
	log_event('delete', 'Feed not accessed in '.MAX_LIFE_WITHOUT_ACCESS.', deleting.', $row->id, $row->url);
	$deleteStmt->execute(array($row->id));
	$db->commit();
}

// Print out statistics
$message = str_pad($fetched, 5, " ", STR_PAD_LEFT).' feeds fetched in '
	.str_pad(sprintf('%.2f', microtime(true) - $start, 2), 7, " ", STR_PAD_LEFT).'s. '
	.str_pad($succeeded, 5, " ", STR_PAD_LEFT).' succeeded, '
	.str_pad($failed, 5, " ", STR_PAD_LEFT).' failed. '
	.str_pad($deleted, 5, " ", STR_PAD_LEFT).' not accessed in '.MAX_LIFE_WITHOUT_ACCESS.' and deleted.'."\n"; 

if (in_array('--stats', $argv))
	print $message;
	