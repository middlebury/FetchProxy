<?php

/**
 * Fetch and cache a URL
 * 
 * @param string $id
 * @param string $origUrl
 * @param optional string $fetchUrl  An alternate URL to fetch when following redirects.
 * @param optional int $numRedirects A counter to prevent infinite redirect loops.
 * @return void
 */
function fetch_url ($id, $origUrl, $fetchUrl = null, $numRedirects = 0) {
	// If we aren't following a redirect, fetch the original url
	if (is_null($fetchUrl))
		$fetchUrl = $origUrl;
	
	// Check for redirect loops
	if ($numRedirects > 4)
		fetch_error($id, $origUrl, $fetchUrl, 'Redirect limit of 4 exceeded.', 552, 'Too Many Redirects');
	
	$r = new HttpRequest($fetchUrl);
	
	if (!defined('USER_AGENT'))
		define('USER_AGENT', "FetchProxy");
	
	$r->addHeaders(array(
		'User-agent' => USER_AGENT
	));
	try {
		$r->send();
		if ($r->getResponseCode() == 200) {
			$headers = $r->getResponseHeader();
			$data = $r->getResponseBody();
			
			$headerStrings = array();
			foreach ($headers as $name => $value) {
				$headerStrings[] = $name.': '.$value;
			}
			
			$operation = store_feed($id, $origUrl, implode("\n", $headerStrings), $data, 200, 'OK');
			if ($operation == 'INSERT')
				log_event('add', 'New feed fetched.', $id, $origUrl, $fetchUrl, 0);
			else
				log_event('update', 'Feed fetched.', $id, $origUrl, $fetchUrl, 0);
			
		}
		// Follow redirects
		else if (in_array($r->getResponseCode(), array(301, 302))) {
			$location = $r->getResponseHeader('Location');
			if (empty($location))
				fetch_error($id, $origUrl, $fetchUrl, 'No Location header found.', 551, 'HTTP Location missing');
			else
				fetch_url($id, $origUrl, $location, $numRedirects++);
		}
		// Record errors
		else {
			fetch_error($id, $origUrl, $fetchUrl, 'Error response '.$r->getResponseCode().' recieved.', $r->getResponseCode(), $r->getResponseStatus());
		}
	} catch (HttpException $e) {
		fetch_error($id, $origUrl, $fetchUrl, get_class($e).': '.$e->getMessage(), 550, 'HTTP Error');
	}
}

/**
 * Store a feed, return the type of operation 'INSERT' or 'UPDATE'
 * 
 * @param string $id The id of the feed.
 * @param string $url The URL of the feed.
 * @param string $headers
 * @param string $data
 * @param int $statusCode
 * @param string $statusMsg
 * @return string 'INSERT' or 'UPDATE'
 */
function store_feed ($id, $url, $headers, $data, $statusCode, $statusMsg) {
	global $db;
	
	$stmt = $db->prepare('SELECT COUNT(*) FROM feeds WHERE id = ?');
	$stmt->execute(array($id));
	$exists = intval($stmt->fetchColumn());
	$stmt->closeCursor();
	if ($exists) {
		$stmt = $db->prepare('UPDATE feeds SET headers = :headers, data = :data, status_code = :status_code, status_msg = :status_msg, last_fetch = NOW(), num_errors = 0 WHERE id = :id');
		$stmt->execute(array(
				':id' => $id,
				':headers' => $headers,
				':data' => $data,
				':status_code' => $statusCode,
				':status_msg' => $statusMsg,
			));
		
		return 'UPDATE';
	} else {
		$stmt = $db->prepare('INSERT INTO feeds (id, url, headers, data, status_code, status_msg, last_fetch, last_access, num_errors) VALUES (:id, :url, :headers, :data, :status_code, :status_msg, NOW(), NOW(), 0)');
		$stmt->execute(array(
				':id' => $id,
				':url' => $url,
				':headers' => $headers,
				':data' => $data,
				':status_code' => $statusCode,
				':status_msg' => $statusMsg,
			));
		
		return 'INSERT';
	}
}

/**
 * Record an error state and throw an exception.
 * 
 * @param string $origUrl
 * @param string $fetchUrl
 * @param string $message
 * @param int $statusCode
 * @param string $statusMsg
 * @return void
 */
function fetch_error ($id, $origUrl, $fetchUrl, $message, $statusCode, $statusMsg) {
	global $db;
	
	// Store an error response so that we can return quickly in the future
	$stmt = $db->prepare('SELECT COUNT(*) FROM feeds WHERE id = ?');
	$stmt->execute(array($id));
	$exists = intval($stmt->fetchColumn());
	$stmt->closeCursor();
	if (!$exists) {
		store_feed($id, $origUrl, null, null, $statusCode, $statusMsg);
	}
	
	// Get the number of errors.
	$stmt = $db->prepare('SELECT num_errors FROM feeds WHERE id = ?');
	$stmt->execute(array($id));
	$numErrors = $stmt->fetchColumn();
	$stmt->closeCursor();
	$numErrors++;
	
	// Increment the counter in the db row.
	$stmt = $db->prepare('UPDATE feeds SET last_fetch = NOW(), num_errors = :num_errors WHERE id = :id');
	$stmt->execute(array(
			':id' => $id,
			':num_errors' => $numErrors,
		));
	
	log_event('error', $message, $id, $origUrl, $fetchUrl, $numErrors);
	
	// If we have been fetching for a long time and are still getting errors,
	// clear out our data to indicate that the client should be given an error
	// response.
	if ($numErrors > MAX_NUM_ERRORS) {
		store_feed($id, $origUrl, null, null, $statusCode, $statusMsg);
	}
	
	$exception = new FetchProxyException($message, $statusCode);
	$exception->setStatusMessage($statusMsg);
	throw $exception;
}

/**
 * Log an event
 * 
 * @param string $type
 * @param string $message
 * @param string $id
 * @param string $origUrl
 * @param optional string $fetchUrl
 * @param optional int $numErrors
 * @param string 
 * @return void
 */
function log_event ($type, $message, $id = null, $origUrl = null, $fetchUrl = null, $numErrors = null) {
	global $db, $skipLoggingFetchesToDb;
	
	if (!empty($skipLoggingFetchesToDb) && in_array($type, array('update', 'error')))
		return;
	
	$stmt = $db->prepare('INSERT INTO log (event_type, feed_id, feed_url, fetch_url, message, num_errors) VALUES (:event_type, :feed_id, :feed_url, :fetch_url, :message, :num_errors)');
	$stmt->execute(array(
			':event_type' => $type,
			':feed_id' => $id,
			':feed_url' => $origUrl,
			':fetch_url' => $fetchUrl,
			':message' => $message,
			':num_errors' => $numErrors,
		));
}

/**
 * A class for FetchProxy Exceptions, includes a status message.
 */
class FetchProxyException
	extends RuntimeException
{
	private $statusMsg = '';
	
	/**
	 * Set the status message.
	 * 
	 * @param string $statusMsg
	 * @return void
	 */
	public function setStatusMessage ($statusMsg) {
		$this->statusMsg = $statusMsg;
	}
	/**
	 * Answer our status message
	 * 
	 * @return string
	 */
	public function getStatusMessage () {
		return $this->statusMsg;
	}
	
	
}