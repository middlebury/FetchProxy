<?php

require_once('config.php');
require_once('lib.php');
$db = new PDO(DB_DSN, DB_USER, DB_PASS);

$urls = $db->query(
	'SELECT url 
	FROM feeds 
	WHERE 
		(custom_ttl IS NULL AND last_fetch < DATE_SUB(NOW(), INTERVAL :default_ttl))
		OR (custom_ttl IS NOT NULL AND last_fetch < DATE_SUB(NOW(), INTERVAL custom_ttl))
	', array(
		':default_ttl' => DEFAULT_TTL,
	))->fetchColumn();

foreach ($urls as $url) {
	fetch_url($url);
}