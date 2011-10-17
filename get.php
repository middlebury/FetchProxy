<?php

if (empty($_GET['url']))
	throw new InvalidArgumentException('No url provided.');
if (!parse_url($_GET['url']))
	throw new InvalidArgumentException('Invalid url provided');

$url = $_GET['url'];
$id = md5($url);

require_once('config.php');
require_once('lib.php');

global $db;
$db = new PDO(DB_DSN, DB_USER, DB_PASS);

// Verify that the client is allowed.
$allowed = FALSE;
if (!empty($allowedClients) && in_array($_SERVER['REMOTE_ADDR'], $allowedClients))
	$allowed = TRUE;
if (!empty($allowedProxyChains) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
	foreach ($allowedProxyChains as $regex) {
		if (preg_match($regex, $_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$allowed = TRUE;
			break;
		}
	}
}
if (!$allowed) {
	header('HTTP/1.1 403 Forbidden');
	header('Content-Type: text/plain');
	print "403 Forbidden\n";
	$message = $_SERVER['REMOTE_ADDR']." is not in the allowed-client list.";
	log_event('access_denied', $message, $id, $url);
	print $message;
	exit;
}

// Look up our data
$stmt = $db->prepare('SELECT * FROM feeds WHERE id = ?');
$stmt->execute(array($id));
$row = $stmt->fetchObject();
$stmt->closeCursor();
if (empty($row)) {
	try {
		fetch_url($id, $url);
	} catch (Exception $e) {
		header('HTTP/1.1 500 Internal Server Error');
		header('Content-Type: text/plain');
		print $e->getMessage();
		exit;
	}
	$stmt = $db->prepare('SELECT * FROM feeds WHERE id = ?');
	$stmt->execute(array($id));
	$row = $stmt->fetchObject();
	$stmt->closeCursor();
}

if ($row) {
	$stmt = $db->prepare('UPDATE feeds SET last_access = NOW(), num_access = :num_access WHERE id = :id');
	$stmt->execute(array(
		':id' => $id,
		':num_access' => $row->num_access + 1,
	));
}

// If headers and data are null, then return an error
if (!$row || is_null($row->headers) && is_null($row->data)) {
	header('HTTP/1.1 500 Internal Server Error');
	header('Content-Type: text/plain');
	print 'Errors have occurred while trying to fetch the feed.';
	exit;
}
// Otherwise, return our content.
else {
	foreach (explode("\n", $row->headers) as $header) {
		// To-do: Filter out some cache-control and expires header here if needed.
		header($header);
	}
	print $row->data;
}
exit;