<?php
/**
 * Script to delete all leads from Bitrix24
 * Usage: php delete_all_leads.php [--dry-run]
 */

$config = require __DIR__ . '/config.php';

// Parse arguments
$args = array_slice($argv, 1);
$isDryRun = in_array('--dry-run', $args);

if ($isDryRun) {
    echo "--- DRY RUN MODE ENABLED ---\n";
} else {
    echo "--- WARNING: ACTUAL DELETION MODE ---\n";
    echo "You have 5 seconds to cancel (Ctrl+C)...\n";
    sleep(5);
}

$lastRequestTime = 0;

/**
 * Standard Bitrix API call
 */
function callBitrix($method, $params = []) {
    global $config, $lastRequestTime;
    
    // Simple rate limiting (approx 2 requests per second)
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
    
    // Batch rate limiting
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

echo "Fetching leads...\n";

$allLeadIds = [];
$start = 0;

while (true) {
    $response = callBitrix('crm.lead.list', [
        'select' => ['ID'],
        'start' => $start
    ]);

    if (!$response || !isset($response['result'])) {
        echo "Error fetching leads. Response: " . print_r($response, true) . "\n";
        break;
    }

    foreach ($response['result'] as $lead) {
        $allLeadIds[] = $lead['ID'];
    }

    echo "Collected " . count($allLeadIds) . " leads...\n";

    if (isset($response['next'])) {
        $start = $response['next'];
    } else {
        break;
    }
}

$totalLeads = count($allLeadIds);
echo "Total leads to delete: $totalLeads\n";

if ($totalLeads === 0) {
    echo "No leads found. Exiting.\n";
    exit;
}

if ($isDryRun) {
    echo "[DRY RUN] Would delete $totalLeads leads.\n";
    exit;
}

// Deleting in batches of 50
$batchSize = 50;
for ($i = 0; $i < $totalLeads; $i += $batchSize) {
    $slice = array_slice($allLeadIds, $i, $batchSize);
    $commands = [];
    foreach ($slice as $id) {
        $commands["del_$id"] = "crm.lead.delete?id=$id";
    }

    echo "Deleting batch " . ($i / $batchSize + 1) . "/" . ceil($totalLeads / $batchSize) . "...\n";
    
    $batchRes = callBitrixBatch($commands);
    if (!$batchRes || !isset($batchRes['result'])) {
        echo "Error in batch deletion: " . print_r($batchRes, true) . "\n";
    } else {
        $successCount = count($batchRes['result']['result'] ?? []);
        $errorCount = count($batchRes['result']['result_error'] ?? []);
        echo "Batch results: $successCount successes, $errorCount errors.\n";
    }
}

echo "\nDeletion process completed.\n";
