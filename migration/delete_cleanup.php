<?php
/**
 * Script to delete deals, contacts, and tasks created by a specific user on a specific date.
 * Created by User ID: 43
 * Date: 2026-05-06
 */

$config = require __DIR__ . '/config.php';

$userId = 43;
$targetDateStart = '2026-05-06T00:00:00';
$targetDateEnd = '2026-05-07T00:00:00';

$lastRequestTime = 0;

/**
 * Standard Bitrix API call
 */
function callBitrix($method, $params = []) {
    global $config, $lastRequestTime;
    
    // Simple rate limiting
    $currentTime = microtime(true);
    $timeSinceLast = $currentTime - $lastRequestTime;
    if ($timeSinceLast < 0.5) {
        usleep((0.5 - $timeSinceLast) * 1000000);
    }
    $lastRequestTime = microtime(true);

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
    return json_decode($result, true);
}

/**
 * Batch Bitrix API call
 */
function callBitrixBatch($commands) {
    global $config, $lastRequestTime;
    
    $currentTime = microtime(true);
    $timeSinceLast = $currentTime - $lastRequestTime;
    if ($timeSinceLast < 0.6) {
        usleep((0.6 - $timeSinceLast) * 1000000);
    }
    $lastRequestTime = microtime(true);

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
    
    if ($result === false) return null;
    return json_decode($result, true);
}

function fetchAll($method, $filter, $entityKey = null) {
    $allIds = [];
    $start = 0;
    echo "Fetching items for $method...\n";

    while (true) {
        $response = callBitrix($method, [
            'filter' => $filter,
            'select' => ['ID'],
            'start' => $start
        ]);

        if (!$response || !isset($response['result'])) {
            echo "Error fetching from $method. Response: " . print_r($response, true) . "\n";
            break;
        }

        $items = ($entityKey && isset($response['result'][$entityKey])) ? $response['result'][$entityKey] : $response['result'];
        
        foreach ($items as $item) {
            $allIds[] = $item['ID'] ?? $item['id'];
        }

        echo "Collected " . count($allIds) . " items...\n";

        if (isset($response['next'])) {
            $start = $response['next'];
        } else {
            break;
        }
    }
    return $allIds;
}

$entities = [
    'deal' => [
        'list' => 'crm.deal.list',
        'delete' => 'crm.deal.delete',
        'filter' => [
            'CREATED_BY_ID' => $userId,
            '>=DATE_CREATE' => $targetDateStart,
            '<DATE_CREATE' => $targetDateEnd,
        ]
    ],
    'contact' => [
        'list' => 'crm.contact.list',
        'delete' => 'crm.contact.delete',
        'filter' => [
            'CREATED_BY_ID' => $userId,
            '>=DATE_CREATE' => $targetDateStart,
            '<DATE_CREATE' => $targetDateEnd,
        ]
    ],
    'task' => [
        'list' => 'tasks.task.list',
        'delete' => 'tasks.task.delete',
        'entityKey' => 'tasks',
        'filter' => [
            'CREATED_BY' => $userId,
            '>=CREATED_DATE' => $targetDateStart,
            '<CREATED_DATE' => $targetDateEnd,
        ]
    ]
];

$toDelete = [];
$totalCount = 0;

echo "--- STEP 1: COUNTING ITEMS ---\n";
foreach ($entities as $type => $info) {
    $ids = fetchAll($info['list'], $info['filter'], $info['entityKey'] ?? null);
    $toDelete[$type] = $ids;
    $totalCount += count($ids);
}

echo "\nSummary of items to delete:\n";
foreach ($toDelete as $type => $ids) {
    echo "- $type: " . count($ids) . "\n";
}
echo "Total: $totalCount items.\n\n";

if ($totalCount === 0) {
    echo "Nothing to delete. Exiting.\n";
    exit;
}

echo "Starting deletion in 5 seconds... Press Ctrl+C to cancel.\n";
sleep(5);

echo "--- STEP 2: DELETING ITEMS ---\n";
foreach ($toDelete as $type => $ids) {
    $deleteMethod = $entities[$type]['delete'];
    $count = count($ids);
    if ($count === 0) continue;

    echo "Deleting $type ($count items)...\n";
    
    // Batch deletion
    $batchSize = 50;
    for ($i = 0; $i < $count; $i += $batchSize) {
        $slice = array_slice($ids, $i, $batchSize);
        $commands = [];
        foreach ($slice as $id) {
            $paramName = ($type === 'task') ? 'taskId' : 'id';
            $commands["del_$id"] = "$deleteMethod?$paramName=$id";
        }

        echo "  Batch " . ($i / $batchSize + 1) . "/" . ceil($count / $batchSize) . "...\n";
        
        $batchRes = callBitrixBatch($commands);
        if (!$batchRes || !isset($batchRes['result'])) {
            echo "    Error in batch: " . print_r($batchRes, true) . "\n";
        } else {
            $successCount = count($batchRes['result']['result'] ?? []);
            $errorCount = count($batchRes['result']['result_error'] ?? []);
            echo "    Result: $successCount success, $errorCount error.\n";
        }
    }
}

echo "\nProcess completed.\n";
