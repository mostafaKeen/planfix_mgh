<?php
$config = require __DIR__ . '/config.php';

// Simple args parsing
$args = array_slice($argv, 1);
$isDryRun = in_array('--dry-run', $args);
$isResume = in_array('--resume', $args);
$deleteDuplicates = true;

$stateFile = __DIR__ . '/progress_contacts.json';
$failFile = __DIR__ . '/failed_contacts.json';
$cacheFile = __DIR__ . '/contacts_cache.json';

$state = [
    'fetchStart' => 0,
    'phase' => 'fetch',
    'updateIndex' => 0,
    'deleteIndex' => 0
];

if ($isResume && file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true) ?? $state;
}

function saveState() {
    global $stateFile, $state;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
}

function logFailure($id, $action, $error) {
    global $failFile;
    $log = [
        'timestamp' => date('c'),
        'id' => $id,
        'action' => $action,
        'error' => $error
    ];
    file_put_contents($failFile, json_encode($log) . "\n", FILE_APPEND);
}

// 1. Batch Core with Rate Limiting
$lastBatchTime = 0;

function callBitrixBatch($commands, $retries = 3) {
    global $config, $isDryRun, $lastBatchTime;
    if ($isDryRun && empty($commands)) return ['result' => ['result' => [], 'result_error' => []]];

    $currentTime = microtime(true);
    $timeSinceLast = $currentTime - $lastBatchTime;
    if ($timeSinceLast < 0.55) {
        usleep((0.55 - $timeSinceLast) * 1000000);
    }
    $lastBatchTime = microtime(true);

    try {
        $url = $config['webhookUrl'] . 'batch';
        $payload = ['halt' => 0, 'cmd' => $commands];
        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($payload),
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if ($result === false) throw new Exception("HTTP Batch request failed");
        $data = json_decode($result, true);
        if (isset($data['error']) && (strpos($data['error'], 'LIMIT') !== false || $data['error'] == '503')) {
            if ($retries > 0) {
                $wait = pow(2, (3 - $retries)) * 2;
                echo "Rate limit hit. Waiting {$wait}s before retry...\n";
                sleep($wait);
                return callBitrixBatch($commands, $retries - 1);
            }
        }
        return $data;
    } catch (Exception $err) {
        if ($retries > 0) {
            usleep(1000000);
            return callBitrixBatch($commands, $retries - 1);
        }
        throw $err;
    }
}

function callBitrixSingle($method, $params, $retries = 3) {
    global $config, $isDryRun;
    if ($isDryRun && strpos($method, 'list') === false && strpos($method, 'get') === false) {
        return ['result' => true];
    }
    $url = $config['webhookUrl'] . $method;
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($params),
            'ignore_errors' => true
        ]
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === false) return null;
    $data = json_decode($result, true);
    
    if (isset($data['error']) && (strpos($data['error'], 'LIMIT') !== false || $data['error'] == '503')) {
        if ($retries > 0) {
            sleep(2);
            return callBitrixSingle($method, $params, $retries - 1);
        }
    }
    return $data;
}

function normalizePhoneForComparison($phone) {
    return preg_replace('/\D/', '', $phone);
}

// Phase 1: Fetching
$contacts = [];
if ($isResume && file_exists($cacheFile)) {
    $contacts = json_decode(file_get_contents($cacheFile), true) ?? [];
}

if ($state['phase'] === 'fetch') {
    echo "Fetching contacts (Start: {$state['fetchStart']})...\n";
    while (true) {
        $res = callBitrixSingle('crm.contact.list', [
            'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE'],
            'start' => $state['fetchStart']
        ]);
        if (empty($res['result'])) break;
        foreach ($res['result'] as $contact) {
            $contacts[$contact['ID']] = $contact;
        }
        echo "Fetched " . count($contacts) . " contacts...\r";
        file_put_contents($cacheFile, json_encode($contacts));
        
        if (isset($res['next'])) {
            $state['fetchStart'] = $res['next'];
            saveState();
        } else {
            $state['phase'] = 'analyze';
            saveState();
            break;
        }
    }
    echo "\nTotal contacts: " . count($contacts) . "\n";
}

// Phase 2: Analysis
$phoneMap = []; // normalized -> [ids]
$updates = []; // id -> new_phone_array
$idsToDelete = [];

echo "Analyzing data...\n";
foreach ($contacts as $id => $contact) {
    $phones = $contact['PHONE'] ?? [];
    $needsUpdate = false;
    $newPhones = [];
    foreach ($phones as $p) {
        $val = $p['VALUE'];
        $normalized = normalizePhoneForComparison($val);
        if (!empty($normalized)) {
            $phoneMap[$normalized][] = $id;
        }
        $fixedVal = $val;
        if (substr($val, 0, 1) !== '+') {
            $fixedVal = '+' . $val;
            $needsUpdate = true;
        }
        $newPhones[] = ['ID' => $p['ID'], 'VALUE' => $fixedVal];
    }
    if ($needsUpdate) $updates[$id] = $newPhones;
}

$duplicatePhones = array_filter($phoneMap, function($ids) {
    return count(array_unique($ids)) > 1;
});

foreach ($duplicatePhones as $phone => $ids) {
    $ids = array_unique($ids);
    rsort($ids); // Keep latest
    array_shift($ids); // Remove latest from delete list
    foreach ($ids as $did) $idsToDelete[] = $did;
}
$idsToDelete = array_unique($idsToDelete);

echo "Found " . count($updates) . " contacts needing phone fix.\n";
echo "Found " . count($idsToDelete) . " duplicate contacts to delete.\n";

if ($state['phase'] === 'analyze') {
    $state['phase'] = 'update';
    saveState();
}

// Phase 3: Updates
if ($state['phase'] === 'update') {
    echo "Applying phone fixes (Start: {$state['updateIndex']})...\n";
    $updateIds = array_keys($updates);
    for ($i = $state['updateIndex']; $i < count($updateIds); $i += 50) {
        $batch = [];
        $slice = array_slice($updateIds, $i, 50);
        foreach ($slice as $id) {
            $batch["upd_$id"] = "crm.contact.update?" . http_build_query(['ID' => $id, 'fields' => ['PHONE' => $updates[$id]]]);
        }
        $res = callBitrixBatch($batch);
        $errors = $res['result']['result_error'] ?? [];
        foreach ($errors as $k => $err) {
            $cid = str_replace('upd_', '', $k);
            logFailure($cid, 'update', $err);
        }
        $state['updateIndex'] = $i + 50;
        saveState();
        echo "Processed " . min($state['updateIndex'], count($updateIds)) . "/" . count($updateIds) . " updates...\r";
    }
    echo "\nUpdate phase completed.\n";
    $state['phase'] = 'delete';
    saveState();
}

// Phase 4: Deletions
if ($state['phase'] === 'delete' && $deleteDuplicates) {
    echo "Processing deletions (Start: {$state['deleteIndex']})...\n";
    for ($i = $state['deleteIndex']; $i < count($idsToDelete); $i += 50) {
        $batch = [];
        $slice = array_slice($idsToDelete, $i, 50);
        foreach ($slice as $id) {
            $batch["del_$id"] = "crm.item.delete?" . http_build_query(['entityTypeId' => 3, 'id' => $id]);
        }
        $res = callBitrixBatch($batch);
        $errors = $res['result']['result_error'] ?? [];
        foreach ($errors as $k => $err) {
            $cid = str_replace('del_', '', $k);
            logFailure($cid, 'delete', $err);
        }
        $state['deleteIndex'] = $i + 50;
        saveState();
        echo "Processed " . min($state['deleteIndex'], count($idsToDelete)) . "/" . count($idsToDelete) . " deletions...\r";
    }
    echo "\nDeletion phase completed.\n";
    $state['phase'] = 'done';
    saveState();
}

if ($state['phase'] === 'done') {
    echo "Task finished successfully.\n";
    @unlink($cacheFile);
}

