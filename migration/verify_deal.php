<?php
require 'vendor/autoload.php';

$config = require __DIR__ . '/config.php';
$webhookUrl = $config['webhookUrl'];

function callB24($method, $params = []) {
    global $webhookUrl;
    $url = $webhookUrl . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

$deal = callB24('crm.deal.get', ['id' => 6449]);
print_r($deal);
