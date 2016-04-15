<?php

try {
	if (empty($_GET['url']))
		throw new InvalidArgumentException('No url provided.');
	if (!parse_url($_GET['url']))
		throw new InvalidArgumentException('Invalid url provided');
} catch (InvalidArgumentException $e) {
	header('HTTP/1.1 400 Bad Request');
	print "400 Bad Request\n".$e->getMessage();
	exit;
}

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
	if (!empty($allowedProxyChains) && !empty($_SERVER['HTTP_X_FORWARDED_FOR']))
		$message .= "\n".$_SERVER['HTTP_X_FORWARDED_FOR'].' is not in the allowed-proxy-chains list.';
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
	} catch (FetchProxyException $e) {
		header('HTTP/1.1 '.$e->getCode().' '.$e->getStatusMessage());
		header('Content-Type: text/plain');
		print $e->getMessage();
		exit;
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
	if (!empty($row->status_code))
		$code = $row->status_code;
	else
		$code = 500;
	if (!empty($row->status_msg))
		$status = $row->status_msg;
	else
		$status = 'Internal Server Error';
	
	header('HTTP/1.1 '.$code.' '.$status);
	header('Content-Type: text/plain');
	print $code.' '.$status."\n";
	print 'Errors have occurred while trying to fetch the feed.';
	exit;
}
// Otherwise, return our content.
else {
	$headersToIgnore = array(
		'cache-control',
		'expires',
		'etag',
		'pragma',
		'set-cookie',
		'connection',
	);
	$hasViaHeader = false;
	foreach (explode("\n", $row->headers) as $header) {
		if (preg_match('/^([^:]+):(.+)/', $header, $matches)) {
			$name = trim($matches[1]);
			$value = trim($matches[2]);
			
			// Filter out some cache control headers and others that shouldn't be propagated
			if (in_array(strtolower($name), $headersToIgnore)) {
				continue;
			}
			// Add a FetchProxy entry to the Via line.
			if (strtolower($name) == 'via') {
				$value .= ', 1.1 FetchProxy';
				$hasViaHeader = true;
			}
			header($name.': '.$value);
		}
	}
	if (!$hasViaHeader) {
		header('Via: 1.1 FetchProxy');
	}
	print $row->data;
}
exit;
