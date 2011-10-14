<?php

/**
 * Fetch and cache a URL
 * 
 * @param string $url
 * @return void
 */
function fetch_url ($url) {
	$r = new HttpRequest($url);
	try {
		$r->send();
		if ($r->getResponseCode() == 200) {
			$headers = $r->getResponseHeader();
			$data = $r->getResponseData();
			// Insert/Update the database.
		}
		// Follow redirects
		else if (in_array($r->getResponseCode(), array(301, 302))) {
			// to-do.
		}
		// Record errors
		else {
			// Increment the error counter.
		}
	} catch (HttpException $e) {
		// Increment the error counter.
	}
}