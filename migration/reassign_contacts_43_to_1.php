<?php
/**
 * CLI script to search for all contacts with responsible person (ASSIGNED_BY_ID) = 43 (Mostafa Osama)
 * and update their ASSIGNED_BY_ID to 1 (Administrator).
 *
 * Usage:
 *   Dry run (safe simulation): php migration/reassign_contacts_43_to_1.php --dry-run
 *   Live execution:            php migration/reassign_contacts_43_to_1.php
 */

$config = require __DIR__ . '/config.php';

// Parse command arguments
$args = array_slice($argv, 1);
$isDryRun = in_array('--dry-run', $args);

$fromUserId = 43;
$toUserId = 1;
$errorLogFile = __DIR__ . '/reassign_errors.log';
$lastRequestTime = 0;

/**
 * Standard Bitrix API call with rate limiting
 */
function callBitrix($method, $params = [], $retries = 3) {
    global $config, $lastRequestTime;
    
    // Strict rate limiting
    $currentTime = microtime(true);
    $timeSinceLast = $currentTime - $lastRequestTime;
    if ($timeSinceLast < 0.55) {
        usleep((0.55 - $timeSinceLast) * 1000000);
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
    
    if ($result === false) {
        if ($retries > 0) {
            echo "HTTP single request failed. Retrying in 1s...\n";
            sleep(1);
            return callBitrix($method, $params, $retries - 1);
        }
        return null;
    }
    
    $data = json_decode($result, true);
    if (isset($data['error']) && (strpos($data['error'], 'LIMIT') !== false || $data['error'] == '503')) {
        if ($retries > 0) {
            $wait = pow(2, (3 - $retries)) * 2;
            echo "Rate limit hit. Waiting {$wait}s before retry...\n";
            sleep($wait);
            return callBitrix($method, $params, $retries - 1);
        }
    }
    return $data;
}

/**
 * Batch Bitrix API call with rate limiting and exponential backoff
 */
function callBitrixBatch($commands, $retries = 3) {
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
    
    if ($result === false) {
        if ($retries > 0) {
            echo "HTTP Batch request failed. Retrying in 1s...\n";
            sleep(1);
            return callBitrixBatch($commands, $retries - 1);
        }
        return null;
    }
    
    $data = json_decode($result, true);
    if (isset($data['error']) && (strpos($data['error'], 'LIMIT') !== false || $data['error'] == '503')) {
        if ($retries > 0) {
            $wait = pow(2, (3 - $retries)) * 2;
            echo "Rate limit hit on batch. Waiting {$wait}s before retry...\n";
            sleep($wait);
            return callBitrixBatch($commands, $retries - 1);
        }
    }
    return $data;
}

// Log error helper
function logError($msg) {
    global $errorLogFile;
    $timestamp = date('c');
    file_put_contents($errorLogFile, "[$timestamp] $msg\n", FILE_APPEND);
    echo "ERROR: $msg\n";
}

echo "=== CRM CONTACT REASSIGNMENT SYSTEM ===\n";
echo "From User ID : $fromUserId (Mostafa Osama)\n";
echo "To User ID   : $toUserId (Administrator)\n";
echo "Mode         : " . ($isDryRun ? "DRY RUN (Simulation Only)" : "LIVE EXECUTION (Database Mutating)") . "\n";
echo "========================================\n\n";

echo "--- STEP 1: FETCHING TARGET CONTACTS ---\n";
$targetContactIds = [];
$start = 0;

while (true) {
    $res = callBitrix('crm.contact.list', [
        'filter' => ['ASSIGNED_BY_ID' => $fromUserId],
        'select' => ['ID', 'NAME', 'LAST_NAME'],
        'start' => $start
    ]);

    if (!$res || !isset($res['result'])) {
        echo "Failed to retrieve contacts from CRM.\n";
        exit(1);
    }

    $items = $res['result'];
    if (empty($items)) {
        break;
    }

    foreach ($items as $item) {
        $targetContactIds[] = $item['ID'];
    }

    echo "  Fetched " . count($targetContactIds) . " contacts so far...\r";

    if (isset($res['next'])) {
        $start = $res['next'];
    } else {
        break;
    }
}
echo "\n";

$totalContacts = count($targetContactIds);
echo "Found $totalContacts contacts currently assigned to User $fromUserId.\n\n";

if ($totalContacts === 0) {
    echo "No contacts to reassign. Exiting.\n";
    exit(0);
}

echo "--- STEP 2: PROCESSING REASSIGNMENT ---\n";

if ($isDryRun) {
    echo "[DRY RUN] Simulating reassignment of $totalContacts contacts to User $toUserId...\n";
    echo "[DRY RUN] No updates will be made to Bitrix24.\n";
    
    // Print a few sample contact IDs to show what would be done
    $samples = array_slice($targetContactIds, 0, 10);
    echo "[DRY RUN] Sample target IDs: " . implode(', ', $samples) . (count($targetContactIds) > 10 ? "..." : "") . "\n";
    echo "[DRY RUN] Simulation complete. Run without --dry-run flag to apply changes.\n";
    exit(0);
}

// Live batch execution
echo "Starting live reassignment of $totalContacts contacts...\n";
$batchSize = 50;
$successCount = 0;
$failCount = 0;

for ($i = 0; $i < $totalContacts; $i += $batchSize) {
    $slice = array_slice($targetContactIds, $i, $batchSize);
    $commands = [];
    
    foreach ($slice as $id) {
        $commands["reassign_$id"] = "crm.contact.update?" . http_build_query([
            'ID' => $id,
            'fields' => ['ASSIGNED_BY_ID' => $toUserId]
        ]);
    }
    
    echo "  Processing batch " . ($i / $batchSize + 1) . "/" . ceil($totalContacts / $batchSize) . " (IDs " . ($i + 1) . " to " . min($i + $batchSize, $totalContacts) . ")...\n";
    
    $batchRes = callBitrixBatch($commands);
    
    if (!$batchRes || !isset($batchRes['result'])) {
        logError("Batch request failed completely for indices $i to " . ($i + count($slice) - 1));
        $failCount += count($slice);
        continue;
    }
    
    $results = $batchRes['result']['result'] ?? [];
    $errors = $batchRes['result']['result_error'] ?? [];
    
    $batchSuccess = count($results);
    $batchFail = count($errors);
    
    $successCount += $batchSuccess;
    $failCount += $batchFail;
    
    if ($batchFail > 0) {
        foreach ($errors as $cmdKey => $err) {
            $contactId = str_replace('reassign_', '', $cmdKey);
            logError("Contact ID $contactId failed to reassign: " . (is_string($err) ? $err : json_encode($err)));
        }
    }
}

echo "\n========================================\n";
echo "REASSIGNMENT COMPLETED SUMMARY\n";
echo "Total Target Contacts : $totalContacts\n";
echo "Successfully Updated  : $successCount\n";
echo "Failed to Update      : $failCount\n";
echo "========================================\n";
if ($failCount > 0) {
    echo "Check error log at: " . realpath($errorLogFile) . "\n";
}
