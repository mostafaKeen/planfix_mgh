<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$config = require __DIR__ . '/config.php';
$webhookUrl = $config['webhookUrl'];

// Paths for state management
$progressFile = __DIR__ . '/progress_deals.json';
$failedFile = __DIR__ . '/failed_deals.json';

// Load progress
$progress = file_exists($progressFile) ? json_decode(file_get_contents($progressFile), true) : ['lastIndex' => 0];
$failedRows = file_exists($failedFile) ? json_decode(file_get_contents($failedFile), true) : [];

function saveState($index) {
    global $progressFile, $progress;
    $progress['lastIndex'] = $index;
    file_put_contents($progressFile, json_encode($progress));
}

function logFailure($row, $error) {
    global $failedFile, $failedRows;
    $failedRows[] = ['row' => $row, 'error' => $error, 'timestamp' => date('c')];
    file_put_contents($failedFile, json_encode($failedRows, JSON_PRETTY_PRINT));
}

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

// 1. Ensure Source "SSM" exists
echo "Ensuring source 'SSM' exists...\n";
$sourceResult = callB24('crm.status.add', [
    'fields' => [
        'ENTITY_ID' => 'SOURCE',
        'STATUS_ID' => 'SSM',
        'NAME' => 'SSM',
        'SORT' => 120
    ]
]);
if (isset($sourceResult['error']) && $sourceResult['error'] !== 'Duplicate STATUS_ID') {
    echo "Warning adding source: " . ($sourceResult['error_description'] ?? $sourceResult['error']) . "\n";
} else {
    echo "Source 'SSM' ready.\n";
}

// 2. Load Excel
$file = __DIR__ . '/../MHG Leads Merged.xlsx';
echo "Loading Excel file: $file\n";
$spreadsheet = IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray();
$headers = array_shift($rows); // Remove header row

$total = count($rows);
$limit = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)explode('=', $arg)[1];
    }
}

if ($limit > 0) {
    $rows = array_slice($rows, 0, $limit);
    $total = count($rows);
    echo "Limit set to: $total\n";
}

echo "Total rows to process: $total\n";
echo "Starting from index: " . $progress['lastIndex'] . "\n";

// Mappings based on inspection:
// [0] created_time
// [1] source information -> UF_CRM_1778243605
// [2] please_select_a_messenger_to_receive_full_information -> UF_CRM_DEAL_1777620403795
// [3] full name
// [4] phone
// [5] work_phone_number
// [6] email
// [7] source

for ($i = $progress['lastIndex']; $i < $total; $i++) {
    $row = $rows[$i];
    $fullName = $row[3] ?? 'Unnamed';
    $phone = trim($row[4] ?? '');
    $email = trim($row[6] ?? '');
    $sourceInfo = $row[1] ?? '';
    $messengerInfo = $row[2] ?? '';

    // Format phone
    if (!empty($phone) && strpos($phone, '+') !== 0) {
        $phone = '+' . $phone;
    }

    echo "[$i/$total] Processing: $fullName ($phone)...\n";

    // Batch request: Create Contact and then Deal
    $batch = [
        'contact_add' => 'crm.contact.add?' . http_build_query([
            'fields' => [
                'NAME' => $fullName,
                'PHONE' => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']],
                'EMAIL' => [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']]
            ]
        ]),
        'deal_add' => 'crm.deal.add?' . http_build_query([
            'fields' => [
                'TITLE' => $fullName . ' - Deal',
                'CONTACT_ID' => '$result[contact_add]',
                'SOURCE_ID' => 'SSM',
                'UF_CRM_1778243605' => $sourceInfo,
                'UF_CRM_DEAL_1777620403795' => $messengerInfo
            ]
        ])
    ];

    $result = callB24('batch', ['halt' => 0, 'cmd' => $batch]);

    if (isset($result['result']['result']['deal_add'])) {
        echo "   Success: Deal ID " . $result['result']['result']['deal_add'] . "\n";
        saveState($i + 1);
    } else {
        $err = $result['result']['result_error']['deal_add'] ?? ($result['error_description'] ?? 'Unknown error');
        echo "   FAILED: $err\n";
        logFailure($row, $err);
        // We still increment progress to avoid getting stuck, but log failure
        saveState($i + 1);
    }

    // Rate limit delay: 0.4s
    usleep(400000);
}

echo "\nDone. Processed " . ($i - $progress['lastIndex']) . " rows.\n";
if (!empty($failedRows)) {
    echo "Check failed_deals.json for " . count($failedRows) . " errors.\n";
}
