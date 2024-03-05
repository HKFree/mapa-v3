<?php
require_once('vendor/autoload.php');
require_once('config.php');


use GuzzleHttp\Client;

$client = new Client([
  'base_uri' => 'https://userdb.hkfree.org/userdb/api/',
  'auth' => [$apiuser, $apikey]
]);

$response = $client->request('GET', 'areas');
$body = $response->getBody();

header('Content-Type: application/json; charset=utf-8');

print($body->getContents());
