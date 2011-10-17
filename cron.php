<?php

require_once('config.php');
require_once('lib.php');
$db = new PDO(DB_DSN, DB_USER, DB_PASS);

$stmt = $db->prepare(
	'SELECT id, url
	FROM feeds 
	WHERE 
		(custom_ttl IS NULL AND last_fetch < DATE_SUB(NOW(), INTERVAL '.DEFAULT_TTL.' MINUTE))
		OR (custom_ttl IS NOT NULL AND last_fetch < DATE_SUB(NOW(), INTERVAL custom_ttl MINUTE))
	');
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_OBJ);

foreach ($rows as $row) {
	fetch_url($row->id, $row->url);
}