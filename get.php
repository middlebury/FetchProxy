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

$stmt = $db->prepare('SELECT * FROM feeds WHERE id = ?');
$stmt->execute(array($id));
$row = $stmt->fetchObject();
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