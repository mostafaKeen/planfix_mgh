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

$fields = callB24('crm.deal.userfield.list', ['filter' => ['LANG' => 'ru']]);
if (isset($fields['result'])) {
    foreach ($fields['result'] as $field) {
        if (stripos($field['EDIT_FORM_LABEL'] ?? '', 'Source') !== false || stripos($field['EDIT_FORM_LABEL'] ?? '', 'Источник') !== false) {
            echo "Field: " . $field['FIELD_NAME'] . " | Label: " . $field['EDIT_FORM_LABEL'] . "\n";
        }
    }
}
