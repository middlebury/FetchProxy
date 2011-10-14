<?php

if (empty($_GET['url']))
	throw new InvalidArgumentException('No url provided.');
if (!parse_url($_GET['url']))
	throw new InvalidArgumentException('Invalid url provided');

$url = $_GET['url'];

require_once('config.php');
require_once('lib.php');
$db = new PDO(DB_DSN, DB_USER, DB_PASS);

$row = $db->query('SELECT * FROM feeds WHERE url = ?', array($url))->fetch();
if (empty($row)) {
	fetch_url($url);
	$row = $db->query('SELECT * FROM feeds WHERE url = ?', array($url))->fetch();
}

foreach (explode("\n", $row->headers) as $header) {
	// To-do Filter out some cache-control and expires header here if needed.
	header($header);
}
print $row->data;
exit;