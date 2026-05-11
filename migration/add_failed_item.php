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
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => 'CURL_ERROR', 'error_description' => $err];
    return json_decode($response, true);
}

// Item details
$fullName = "Dave";
$phone = "+4917674864224";
$email = "dave83915@gmail.com";
$sourceInfo = "EDITION | ENG";
$messengerInfo = "whatsapp";

echo "Adding item: $fullName ($phone)...\n";

$batch = [
    'contact_add' => 'crm.contact.add?' . http_build_query([
        'fields' => [
            'NAME' => $fullName,
            'PHONE' => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']],
            'EMAIL' => [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']],
            'ASSIGNED_BY_ID' => 1
        ]
    ]),
    'deal_add' => 'crm.deal.add?' . http_build_query([
        'fields' => [
            'TITLE' => $fullName . ' - Deal',
            'CONTACT_ID' => '$result[contact_add]',
            'SOURCE_ID' => 'SSM',
            'UF_CRM_1778243605' => $sourceInfo,
            'UF_CRM_DEAL_1777620403795' => $messengerInfo,
            'ASSIGNED_BY_ID' => 1
        ]
    ])
];

$result = callB24('batch', ['halt' => 0, 'cmd' => $batch]);

if (isset($result['result']['result']['deal_add'])) {
    echo "Success: Deal ID " . $result['result']['result']['deal_add'] . "\n";
} else {
    echo "FAILED: " . print_r($result, true) . "\n";
}
