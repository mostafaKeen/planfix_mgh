<?php
$config = require __DIR__ . '/config.php';

// Simple args parsing
$args = array_slice($argv, 1);
$isDryRun = in_array('--dry-run', $args);
$deleteDuplicates = true; // User explicitly asked to delete duplications

// 1. Batch Core with Rate Limiting
$lastBatchTime = 0;

function callBitrixBatch($commands, $retries = 3) {
    global $config, $isDryRun, $lastBatchTime;

    if ($isDryRun && empty($commands)) return ['result' => ['result' => [], 'result_error' => []]];

    // Strict 0.55s delay between batches (approx 1.8 batches/sec)
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
        echo "[DRY RUN] Would call $method with " . json_encode($params) . "\n";
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
    return json_decode($result, true);
}

// Helper to normalize phone for comparison (digits only)
function normalizePhoneForComparison($phone) {
    return preg_replace('/\D/', '', $phone);
}

// 2. Fetch all contacts
echo "Fetching contacts...\n";
$contacts = [];
$start = 0;
while (true) {
    $res = callBitrixSingle('crm.contact.list', [
        'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE'],
        'start' => $start
    ]);
    
    if (empty($res['result'])) break;
    
    foreach ($res['result'] as $contact) {
        $contacts[$contact['ID']] = $contact;
    }
    
    echo "Fetched " . count($contacts) . " contacts...\r";
    
    if (isset($res['next'])) {
        $start = $res['next'];
    } else {
        break;
    }
}
echo "\nTotal contacts: " . count($contacts) . "\n";

// 3. Analyze phones and identify updates/duplicates
$phoneMap = []; // normalized -> [ids]
$updates = []; // id -> new_phone_array
$duplicatesToDelete = [];

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
        
        $newPhones[] = [
            'ID' => $p['ID'],
            'VALUE' => $fixedVal
        ];
    }
    
    if ($needsUpdate) {
        $updates[$id] = $newPhones;
    }
}

// Identify duplicates
$duplicatePhones = array_filter($phoneMap, function($ids) {
    return count(array_unique($ids)) > 1;
});

echo "Found " . count($updates) . " contacts needing phone fix (+).\n";
echo "Found " . count($duplicatePhones) . " phone numbers with duplicates.\n";

// 4. Execute Updates
if (!empty($updates)) {
    echo "Applying phone fixes...\n";
    $batch = [];
    $i = 0;
    foreach ($updates as $id => $newPhones) {
        $batch["upd_$id"] = "crm.contact.update?" . http_build_query([
            'ID' => $id,
            'fields' => ['PHONE' => $newPhones]
        ]);
        
        if (count($batch) >= 50) {
            callBitrixBatch($batch);
            $batch = [];
            echo "Updated " . ($i + 50) . " contacts...\r";
        }
        $i++;
    }
    if (!empty($batch)) callBitrixBatch($batch);
    echo "\nPhone fixes applied.\n";
}

// 5. Execute Deletions
if ($deleteDuplicates && !empty($duplicatePhones)) {
    echo "Processing duplicate deletions (Keeping latest ID)...\n";
    $idsToDelete = [];
    foreach ($duplicatePhones as $phone => $ids) {
        $ids = array_unique($ids);
        rsort($ids); // Keep the highest ID (latest)
        $primary = array_shift($ids);
        foreach ($ids as $duplicateId) {
            $idsToDelete[] = $duplicateId;
        }
    }
    
    $idsToDelete = array_unique($idsToDelete);
    echo "Will delete " . count($idsToDelete) . " duplicate contacts.\n";
    
    $batch = [];
    $i = 0;
    foreach ($idsToDelete as $id) {
        // Using crm.item.delete for contacts (entityTypeId 3)
        $batch["del_$id"] = "crm.item.delete?" . http_build_query([
            'entityTypeId' => 3,
            'id' => $id
        ]);
        
        if (count($batch) >= 50) {
            callBitrixBatch($batch);
            $batch = [];
            echo "Deleted " . ($i + 50) . " duplicates...\r";
        }
        $i++;
    }
    if (!empty($batch)) callBitrixBatch($batch);
    echo "\nDeletions completed.\n";
}

if ($isDryRun) {
    echo "\n[DRY RUN] No changes were actually made to Bitrix24.\n";
}

echo "Done.\n";
