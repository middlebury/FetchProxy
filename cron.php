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
if (!defined('DEFAULT_TTL'))
	throw new Exception('DEFAULT_TTL must be defined.');
if (!defined('BATCH_FREQUENCY'))
	throw new Exception('BATCH_FREQUENCY must be defined.');
if (!defined('SLOW_FEED_THRESHOLD'))
	define('SLOW_FEED_THRESHOLD', 45);


// Verify that there aren't other processes.
// We don't want to overlap and duplicate effort.
$stmt = $db->prepare('SELECT * FROM processes');
$clear = $db->prepare('DELETE FROM processes WHERE pid = ?');
$stmt->execute();
$processes = $stmt->fetchAll(PDO::FETCH_OBJ);
if (count($processes)) {
	// Verify that the other process is still running
	foreach ($processes as $process) {
		if (isRunning($process->pid)) {
			$min = round((time() - strtotime($process->tstamp)) / 60, 2);
			print date('c')."\tFetch process ".$process->pid." has been going for ".$min." minutes and is still running. Not starting another fetch.\n";
			exit(1);
		}
		// Clear out the entry for the dead process.
		else {
			print date('c')."\tFetch process ".$process->pid." has died, clearing.\n";
			$clear->execute(array($process->pid));
		}
	}
}
// If there are no other processes, mark ours and start.
$stmt = $db->prepare('INSERT INTO processes (pid) VALUES (?)');
$stmt->execute(array(getmypid()));


// Set a batch limit to help distribute the fetches throughout the hour.
$stmt = $db->prepare('SELECT COUNT(id) FROM feeds');
$stmt->execute();
$batchLimit = ceil(intval($stmt->fetchColumn()) / (DEFAULT_TTL / BATCH_FREQUENCY));

$stmt = $db->prepare(
	'SELECT id, url
	FROM feeds 
	WHERE 
		(custom_ttl IS NULL AND UNIX_TIMESTAMP(last_fetch) < UNIX_TIMESTAMP() - '.DEFAULT_TTL.' * 60)
		OR (custom_ttl IS NOT NULL AND UNIX_TIMESTAMP(last_fetch) < UNIX_TIMESTAMP() - custom_ttl * 60)
	ORDER BY last_fetch ASC
	LIMIT '.$batchLimit);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_OBJ);
$fetched = count($rows);

foreach ($rows as $row) {
	$feedStart = microtime(true);
	if ($verbose) {
		ob_start();
		print date('c');
		print "\t".getmypid();
		print "\t".$row->id;
		print "\t".$row->url;
		print "\t";
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
	
	$fetchTime = round(microtime(true) - $feedStart, 3);
	if ($verbose) {
		print "\t".$fetchTime.'s';
		print "\n";
		fwrite(STDERR, ob_get_clean());
	}
	if ($fetchTime > SLOW_FEED_THRESHOLD) {
		ob_start();
		print date('c');
		print "\t".getmypid();
		print "\t".$row->id;
		print "\t".$row->url;
		print "\tSlow fetch";
		print "\t".$fetchTime.'s';
		print "\n";
		fwrite(STDERR, ob_get_clean());
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
$message = date('c').' '
	.str_pad($fetched, 5, " ", STR_PAD_LEFT).' feeds fetched in '
	.str_pad(sprintf('%.2f', microtime(true) - $start, 2), 7, " ", STR_PAD_LEFT).'s. '
	.str_pad($succeeded, 5, " ", STR_PAD_LEFT).' succeeded, '
	.str_pad($failed, 5, " ", STR_PAD_LEFT).' failed. '
	.str_pad($deleted, 5, " ", STR_PAD_LEFT).' not accessed in '.MAX_LIFE_WITHOUT_ACCESS.' and deleted.'."\n"; 

if (in_array('--stats', $argv))
	print $message;

// Clear out our proccess entry so that other processes can start.
$clear->execute(array(getmypid()));

/**
 * Check if a process is running.
 * From: http://stackoverflow.com/a/45966/15872
 * 
 * @param int $pid
 * @return boolean
 */
function isRunning($pid){
	try{
		$result = shell_exec(sprintf("ps %d", $pid));
		if( count(preg_split("/\n/", $result)) > 2){
			return true;
		}
	}catch(Exception $e){}
	
	return false;
}