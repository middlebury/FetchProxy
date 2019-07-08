<?php

require_once(dirname(__FILE__).'/vendor/autoload.php');

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\TooManyRedirectsException;

/**
 * Answer a configured HTTP client.
 *
 * @return \GuzzleHttp\Client
 */
function http_client() {
	static $client;
	if (!$client) {
		if (!defined('USER_AGENT')) {
			define('USER_AGENT', "FetchProxy");
		}
		if (!defined('FETCH_CONNECT_TIMEOUT')) {
			define('FETCH_CONNECT_TIMEOUT', 60);
		}
		if (!defined('FETCH_TIMEOUT')) {
			define('FETCH_TIMEOUT', 120);
		}
		if (!defined('FETCH_MAX_REDIRECTS')) {
			define('FETCH_MAX_REDIRECTS', 10);
		}
		$options = [
			'connect_timeout' => FETCH_CONNECT_TIMEOUT,
			'timeout' => FETCH_TIMEOUT,
			'allow_redirects' => [
				'max' => FETCH_MAX_REDIRECTS,
				'track_redirects' => true,
			],
			'headers' => [
				'User-agent' => USER_AGENT,
			],
		];
		$client = new Client($options);
	}
	return $client;
}

/**
 * Fetch and cache a URL
 *
 * @param string $id
 * @param string $url
 * @return void
 */
function fetch_url ($id, $url) {
	try {
		$res = http_client()->get($url);

		if ($res->getStatusCode() == 200) {
			$headers = $res->getHeaders();
			$data = $res->getBody()->getContents();

			$headerStrings = array();
			foreach ($headers as $name => $value) {
				if (is_array($value)) {
					foreach ($value as $v) {
						$headerStrings[] = $name.': '.$v;
					}
				} else {
					$headerStrings[] = $name.': '.$value;
				}
			}

			$operation = store_feed($id, $url, implode("\n", $headerStrings), $data, 200, 'OK');
			if ($operation == 'INSERT')
				log_event('add', 'New feed fetched.', $id, $url, $res->getHeaderLine('X-Guzzle-Redirect-History'), 0);
			else
				log_event('update', 'Feed fetched.', $id, $url, $res->getHeaderLine('X-Guzzle-Redirect-History'), 0);

		}
		// Record errors
		else {
			fetch_error($id, $url, $res->getHeaderLine('X-Guzzle-Redirect-History'), 'Error response '.$res->getStatusCode().' received.', $res->getStatusCode(), $res->getReasonPhrase());
		}
	}
	catch (TooManyRedirectsException $e) {
		fetch_error($id, $url, $res->getHeaderLine('X-Guzzle-Redirect-History'), 'Redirect limit of ' . FETCH_MAX_REDIRECTS . ' exceeded. ' . $res->getHeaderLine('X-Guzzle-Redirect-History'), 552, 'Too Many Redirects');
	}
	catch (Exception $e) {
		fetch_error($id, $url, NULL, get_class($e).': '.$e->getMessage(), 550, 'HTTP Error');
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
