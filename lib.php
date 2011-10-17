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
	global $db;
	
	// If we aren't following a redirect, fetch the original url
	if (is_null($fetchUrl))
		$fetchUrl = $origUrl;
	
	// Check for redirect loops
	if ($numRedirects > 4)
		fetch_error($id, $origUrl, $fetchUrl, 'Redirect limit of 4 exceeded.');
	
	$r = new HttpRequest($fetchUrl);
	try {
		$r->send();
		if ($r->getResponseCode() == 200) {
			$headers = $r->getResponseHeader();
			$data = $r->getResponseBody();
			
			$headerStrings = array();
			foreach ($headers as $name => $value) {
				$headerStrings[] = $name.': '.$value;
			}
			
			$stmt = $db->prepare('SELECT COUNT(*) FROM feeds WHERE id = ?');
			$stmt->execute(array($id));
			$exists = intval($stmt->fetchColumn());
			if ($exists) {
				$stmt = $db->prepare('UPDATE feeds SET headers = :headers, data = :data, last_fetch = NOW(), num_errors = 0 WHERE id = :id');
				$stmt->execute(array(
						':id' => $id,
						':headers' => implode("\n", $headerStrings),
						':data' => $data,
					));
				
				// Log the update
				$stmt = $db->prepare('INSERT INTO log (event_type, feed_id, feed_url, fetch_url, message, num_errors) VALUES (:event_type, :feed_id, :feed_url, :fetch_url, :message, 0)');
				$stmt->execute(array(
						':event_type' => 'update',
						':feed_id' => $id,
						':feed_url' => $origUrl,
						':fetch_url' => $fetchUrl,
						':message' => 'Feed fetched.',
					));
			} else {
				$stmt = $db->prepare('INSERT INTO feeds (id, url, headers, data, last_fetch, num_errors) VALUES (:id, :url, :headers, :data, NOW(), 0)');
				$stmt->execute(array(
						':id' => $id,
						':url' => $origUrl,
						':headers' => implode("\n", $headerStrings),
						':data' => $data,
					));
				
				// Log the insert
				$stmt = $db->prepare('INSERT INTO log (event_type, feed_id, feed_url, fetch_url, message, num_errors) VALUES (:event_type, :feed_id, :feed_url, :fetch_url, :message, 0)');
				$stmt->execute(array(
						':event_type' => 'add',
						':feed_id' => $id,
						':feed_url' => $origUrl,
						':fetch_url' => $fetchUrl,
						':message' => 'New feed fetched.',
					));
			}
			
		}
		// Follow redirects
		else if (in_array($r->getResponseCode(), array(301, 302))) {
			$location = $r->getResponseHeader('Location');
			if (empty($location))
				fetch_error($id, $origUrl, $fetchUrl, 'No Location header found.');
			else
				fetch_url($id, $origUrl, $location, $numRedirects++);
		}
		// Record errors
		else {
			fetch_error($id, $origUrl, $fetchUrl, 'Error response '.$r->getResponseCode().' recieved.');
		}
	} catch (HttpException $e) {
		fetch_error($id, $origUrl, $fetchUrl, get_class($e).': '.$e->getMessage());
	}
}

/**
 * Record an error state and throw an exception.
 * 
 * @param string $origUrl
 * @param string $fetchUrl
 * @param string $message
 * @return void
 */
function fetch_error ($id, $origUrl, $fetchUrl, $message) {
	global $db;
	
	$stmt = $db->prepare('SELECT num_errors FROM feeds WHERE id = ?');
	$stmt->execute(array($id));
	$numErrors = $stmt->fetchColumn();
	$numErrors++;
	
	// Increment the counter in the db row.
	$stmt = $db->prepare('UPDATE feeds SET last_fetch = NOW(), num_errors = :num_errors WHERE id = :id');
	$stmt->execute(array(
			':id' => $id,
			':num_errors' => $numErrors,
		));
	
	// Log the error
	$stmt = $db->prepare('INSERT INTO log (event_type, feed_id, feed_url, fetch_url, message, num_errors) VALUES (:event_type, :feed_id, :feed_url, :fetch_url, :message, :num_errors)');
	$stmt->execute(array(
			':event_type' => 'error',
			':feed_id' => $id,
			':feed_url' => $origUrl,
			':fetch_url' => $fetchUrl,
			':message' => $message,
			':num_errors' => $numErrors,
		));
	
	// If we have been fetching for a long time and are still getting errors,
	// clear out our data to indicate that the client should be given an error
	// response.
	if ($numErrors > MAX_NUM_ERRORS) {
		$stmt = $db->prepare('UPDATE feeds SET headers = NULL, data = NULL WHERE id = :id');
		$stmt->execute(array(
				':id' => $id,
			));
	}
		
}