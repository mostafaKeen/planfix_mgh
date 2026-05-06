<?php
$config = require __DIR__ . '/config.php';

// Simple args parsing
$args = array_slice($argv, 1);
$isDryRun = in_array('--dry-run', $args);
$isResume = in_array('--resume', $args);
$phase = null;
foreach ($args as $arg) {
    if (strpos($arg, '--phase=') === 0) {
        $phase = explode('=', $arg)[1];
    }
}

// 1. Lock File / Process Killing Mechanism
function handleLocking($lockPath) {
    if (file_exists($lockPath)) {
        $oldPid = trim(file_get_contents($lockPath));
        if (!empty($oldPid) && is_numeric($oldPid)) {
            echo "Old instance detected (PID: $oldPid). Attempting to kill...\n";
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                exec("taskkill /F /PID $oldPid 2>&1");
            } else {
                exec("kill -9 $oldPid 2>&1");
            }
            usleep(500000); // Wait for termination
        }
    }
    file_put_contents($lockPath, getmypid());
}

handleLocking($config['statePaths']['lock']);

// State management
$progress = [];
$idMapping = [];

function loadState() {
    global $config, $progress, $idMapping;
    if (file_exists($config['statePaths']['progress'])) {
        $progress = json_decode(file_get_contents($config['statePaths']['progress']), true) ?? [];
    }
    if (file_exists($config['statePaths']['idMapping'])) {
        $idMapping = json_decode(file_get_contents($config['statePaths']['idMapping']), true) ?? [];
    }
}

function saveState() {
    global $config, $progress, $idMapping;
    file_put_contents($config['statePaths']['progress'], json_encode($progress, JSON_PRETTY_PRINT));
    file_put_contents($config['statePaths']['idMapping'], json_encode($idMapping, JSON_PRETTY_PRINT));
}

function logError($msg, $err = null) {
    global $config;
    $timestamp = date('c');
    $errStr = is_object($err) ? $err->getMessage() : (is_string($err) ? $err : print_r($err, true));
    $logMsg = "[$timestamp] $msg | $errStr\n";
    file_put_contents($config['statePaths']['errorsLog'], $logMsg, FILE_APPEND);
    echo "ERROR: $logMsg";
}

// 2. Batch Core with Rate Limiting & Retry Logic
$lastBatchTime = 0;

function callBitrixBatch($commands, $retries = null) {
    global $config, $isDryRun, $lastBatchTime;
    if ($retries === null) $retries = $config['rateLimitConfig']['maxRetries'];

    if ($isDryRun) {
        echo "[DRY RUN] BATCH with " . count($commands) . " commands\n";
        $mockResults = [];
        foreach ($commands as $key => $cmd) { $mockResults[$key] = 'dry_run_id_' . rand(1000, 9999); }
        return ['result' => ['result' => $mockResults, 'result_error' => []]];
    }

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

        // Check for global rate limit errors (503 or specific error code)
        if (isset($data['error']) && (strpos($data['error'], 'LIMIT') !== false || $data['error'] == '503')) {
            if ($retries > 0) {
                $wait = pow(2, (3 - $retries)) * 2; // Exponential backoff
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

// 3. Entity Processing wrapper using batches of 50
function processBatchEntities($items, $stateKey, $processItemToCommandFunc, $onBatchSuccessFunc) {
    global $progress, $isResume, $isDryRun, $config;
    
    if (!isset($progress[$stateKey])) $progress[$stateKey] = ['lastIndex' => 0];
    $startIndex = $isResume ? $progress[$stateKey]['lastIndex'] : 0;
    $batchSize = $config['rateLimitConfig']['batchSize'];

    for ($i = $startIndex; $i < count($items); $i += $batchSize) {
        $slice = array_slice($items, $i, $batchSize);
        $commands = [];
        $tempItems = [];

        foreach ($slice as $idx => $item) {
            $cmd = $processItemToCommandFunc($item, $i + $idx);
            if ($cmd) {
                $key = "item_" . ($i + $idx);
                $commands[$key] = $cmd;
                $tempItems[$key] = $item;
            }
        }

        if (empty($commands)) {
            $progress[$stateKey]['lastIndex'] = $i + count($slice);
            saveState();
            continue;
        }

        try {
            $batchRes = callBitrixBatch($commands);
            if (isset($batchRes['result'])) {
                $results = $batchRes['result']['result'] ?? [];
                $errors = $batchRes['result']['result_error'] ?? [];
                
                $onBatchSuccessFunc($results, $errors, $tempItems);
                
                // Only increment progress if the batch was executed
                if (!$isDryRun) {
                    $progress[$stateKey]['lastIndex'] = $i + count($slice);
                    saveState();
                }
            }
        } catch (Exception $e) {
            logError("Batch processing failed for $stateKey at index $i", $e);
            break; // Stop phase on critical error
        }
    }
}

function loadData($key) {
    global $config;
    if (file_exists($config['dataPaths'][$key])) {
        return json_decode(file_get_contents($config['dataPaths'][$key]), true) ?? [];
    }
    return [];
}

// --- PHASE IMPLEMENTATIONS ---

function phaseUsers() {
    global $idMapping;
    $users = loadData('users');
    if (!isset($idMapping['users'])) $idMapping['users'] = [];

    processBatchEntities($users, 'users', function($user, $idx) {
        if (empty($user['email']) || isset($idMapping['users'][$user['id']])) return null;
        // user.get by email is hard to batch without knowing results first, 
        // but for migration we assume we add or update.
        // For efficiency, we just user.add and handle "exists" as a per-item error if needed, 
        // or just use user.get in a separate non-batch check if volume is low.
        return "user.add?" . http_build_query([
            'EMAIL' => $user['email'],
            'NAME' => $user['name'] ?? '',
            'LAST_NAME' => $user['lastName'] ?? '',
            'EXTENSION' => 'users'
        ]);
    }, function($results, $errors, $items) use (&$idMapping) {
        foreach ($results as $key => $bitrixId) {
            $pfId = $items[$key]['id'];
            $idMapping['users'][$pfId] = $bitrixId;
        }
        foreach ($errors as $key => $err) {
            logError("User " . $items[$key]['id'] . " failed", $err);
        }
    });
}

function phaseHandbooks() {
    global $idMapping, $progress, $isResume;
    if ($isResume && ($progress['handbooks']['completed'] ?? false)) return;
    
    $handbooks = loadData('handbooks');
    $handbooksData = loadData('handbooksData');
    if (!isset($idMapping['handbooks'])) $idMapping['handbooks'] = [];

    foreach ($handbooks as $hb) {
        $fieldName = "UF_CRM_HB_{$hb['id']}";
        $fieldDef = [
            'FIELD_NAME' => $fieldName,
            'EDIT_FORM_LABEL' => $hb['name'],
            'USER_TYPE_ID' => 'string', // Modern Task standard: fallback to string for reliability
            'XML_ID' => "PLANFIX_HB_{$hb['id']}"
        ];

        try {
            // CRM Fields support enumeration
            $crmDef = $fieldDef;
            $crmDef['USER_TYPE_ID'] = 'enumeration';
            $listData = array_filter($handbooksData, function($d) use ($hb) { return ($d['handbook']['id'] ?? 0) === $hb['id']; });
            $crmDef['LIST'] = array_map(function($d) { return ['VALUE' => $d['data'][0]['value'] ?? $d['id']]; }, $listData);
            
            callBitrixBatch([
                'lead' => "crm.lead.userfield.add?" . http_build_query(['fields' => $crmDef]),
                'deal' => "crm.deal.userfield.add?" . http_build_query(['fields' => $crmDef]),
                'task' => "task.item.userfield.add?" . http_build_query(['fields' => $fieldDef])
            ]);
            $idMapping['handbooks'][$hb['id']] = $fieldName;
        } catch (Exception $e) { logError("Handbook {$hb['id']} failed", $e); }
    }
    $progress['handbooks']['completed'] = true;
    saveState();
}

function phaseCRM() {
    global $idMapping;
    $contacts = loadData('contacts');
    $companies = array_values(array_filter($contacts, function($c) { return !empty($c['isCompany']); }));
    $people = array_values(array_filter($contacts, function($c) { return empty($c['isCompany']); }));

    // Companies
    processBatchEntities($companies, 'companies', function($item, $idx) {
        if (isset($idMapping['companies'][$item['id']])) return null;
        $fields = ['TITLE' => $item['name'] ?? "Co {$item['id']}", 'UF_PLANFIX_ID' => (string)$item['id']];
        return "crm.company.add?" . http_build_query(['fields' => $fields]);
    }, function($res, $errs, $items) use (&$idMapping) {
        foreach ($res as $k => $id) { $idMapping['companies'][$items[$k]['id']] = $id; }
    });

    // Contacts
    processBatchEntities($people, 'contacts', function($item, $idx) use ($idMapping) {
        if (isset($idMapping['contacts'][$item['id']])) return null;
        $fields = ['NAME' => $item['name'] ?? '', 'LAST_NAME' => $item['lastName'] ?? '', 'UF_PLANFIX_ID' => (string)$item['id']];
        if (!empty($item['companies'][0]['id']) && isset($idMapping['companies'][$item['companies'][0]['id']])) {
            $fields['COMPANY_ID'] = $idMapping['companies'][$item['companies'][0]['id']];
        }
        return "crm.contact.add?" . http_build_query(['fields' => $fields]);
    }, function($res, $errs, $items) use (&$idMapping) {
        foreach ($res as $k => $id) { $idMapping['contacts'][$items[$k]['id']] = $id; }
    });
}

function phaseWorkgroups() {
    global $idMapping;
    $projects = loadData('projects');
    processBatchEntities($projects, 'projects', function($proj, $idx) use ($idMapping) {
        if (isset($idMapping['projects'][$proj['id']])) return null;
        return "sonet_group.create?" . http_build_query([
            'NAME' => $proj['title'] ?? "Proj {$proj['id']}",
            'VISIBLE' => 'N', 'OPENED' => 'N',
            'OWNER_ID' => $idMapping['users'][$proj['owner']['id'] ?? 0] ?? 1
        ]);
    }, function($res, $errs, $items) use (&$idMapping) {
        foreach ($res as $k => $id) { $idMapping['projects'][$items[$k]['id']] = $id; }
    });
}

function phaseTasks() {
    global $idMapping;
    $tasks = loadData('tasks');
    processBatchEntities($tasks, 'tasks', function($task, $idx) use ($idMapping) {
        if (isset($idMapping['tasks'][$task['id']]) || isset($idMapping['leads'][$task['id']]) || isset($idMapping['deals'][$task['id']])) return null;
        
        $isSales = ($task['project']['id'] ?? 0) === 46897;
        $ownerId = $idMapping['users'][$task['owner']['id'] ?? 0] ?? 1;
        
        if ($isSales) {
            $method = ($task['status'] ?? '') === 'Новая' ? 'crm.lead.add' : 'crm.deal.add';
            return "$method?" . http_build_query(['fields' => ['TITLE' => $task['title'], 'ASSIGNED_BY_ID' => $ownerId, 'UF_PLANFIX_ID' => (string)$task['id']]]);
        } else {
            $fields = ['TITLE' => $task['title'], 'RESPONSIBLE_ID' => $ownerId, 'UF_TASK_PF_ID' => (string)$task['id']];
            if (isset($idMapping['projects'][$task['project']['id'] ?? 0])) $fields['GROUP_ID'] = $idMapping['projects'][$task['project']['id']];
            return "tasks.task.add?" . http_build_query(['fields' => $fields]);
        }
    }, function($res, $errs, $items) use (&$idMapping) {
        foreach ($res as $k => $id) {
            $pfId = $items[$k]['id'];
            $isSales = ($items[$k]['project']['id'] ?? 0) === 46897;
            if ($isSales) {
                if (($items[$k]['status'] ?? '') === 'Новая') $idMapping['leads'][$pfId] = $id;
                else $idMapping['deals'][$pfId] = $id;
            } else {
                $idMapping['tasks'][$pfId] = is_array($id) ? ($id['task']['id'] ?? $id) : $id;
            }
        }
    });
}

function phaseHistory() {
    global $idMapping;
    $actions = loadData('actions');
    processBatchEntities($actions, 'actions', function($act, $idx) use ($idMapping) {
        $taskId = $act['task']['id'] ?? 0;
        $comment = "[Planfix " . ($act['dateTime'] ?? '') . "] " . strip_tags($act['description'] ?? '');
        $userId = $idMapping['users'][$act['owner']['id'] ?? 0] ?? 1;

        if (isset($idMapping['leads'][$taskId])) return "crm.timeline.comment.add?" . http_build_query(['fields' => ['ENTITY_ID' => $idMapping['leads'][$taskId], 'ENTITY_TYPE' => 'lead', 'COMMENT' => $comment, 'AUTHOR_ID' => $userId]]);
        if (isset($idMapping['deals'][$taskId])) return "crm.timeline.comment.add?" . http_build_query(['fields' => ['ENTITY_ID' => $idMapping['deals'][$taskId], 'ENTITY_TYPE' => 'deal', 'COMMENT' => $comment, 'AUTHOR_ID' => $userId]]);
        if (isset($idMapping['tasks'][$taskId])) return "tasks.task.chat.message.send?" . http_build_query(['taskId' => $idMapping['tasks'][$taskId], 'message' => $comment]);
        return null;
    }, function($res, $errs, $items) {});
}

function phaseAnalytics() {
    global $idMapping;
    // 7.1 Calls
    $callsIncoming = loadData('analiticsData_7027');
    $callsGeneral = loadData('analiticsData_7025');
    $allCalls = array_merge($callsIncoming, $callsGeneral);

    processBatchEntities($allCalls, 'calls', function($call, $idx) use ($idMapping) {
        // Find associated contact or lead
        $pfContactId = $call['fields']['41639']['id'] ?? $call['fields']['41627']['id'] ?? null;
        if (!$pfContactId) return null;

        $fields = [
            'TYPE_ID' => 'CALL',
            'SUBJECT' => 'Planfix Call Migration',
            'COMPLETED' => 'Y',
            'START_TIME' => $call['fields']['41635'] ?? $call['fields']['41617'] ?? null,
            'DIRECTION' => ($call['fields']['41637'] ?? $call['fields']['41621'] ?? '') === 'Incoming' ? 1 : 2
        ];

        if (isset($idMapping['contacts'][$pfContactId])) {
            $fields['OWNER_TYPE_ID'] = 3;
            $fields['OWNER_ID'] = $idMapping['contacts'][$pfContactId];
        }

        return "crm.activity.add?" . http_build_query(['fields' => $fields]);
    }, function($res, $errs, $items) {});

    // 7.2 Time Tracking
    $timeData = loadData('analiticsData_6773');
    processBatchEntities($timeData, 'time_tracking', function($entry, $idx) use ($idMapping) {
        $pfTaskId = $entry['task']['id'] ?? 0;
        if (!isset($idMapping['tasks'][$pfTaskId])) return null;

        $userId = $idMapping['users'][$entry['fields']['40283']['id'] ?? 0] ?? 1;
        $minutes = $entry['fields']['40277'] ?? 0;

        return "task.elapseditem.add?" . http_build_query([
            '0' => $idMapping['tasks'][$pfTaskId],
            '1' => [
                'SECONDS' => $minutes * 60,
                'COMMENT_TEXT' => 'Migrated from Planfix',
                'USER_ID' => $userId
            ]
        ]);
    }, function($res, $errs, $items) {});
}

// MAIN RUN
loadState();
if (!$phase || $phase === 'users') phaseUsers();
if (!$phase || $phase === 'handbooks') phaseHandbooks();
if (!$phase || $phase === 'crm') phaseCRM();
if (!$phase || $phase === 'workgroups') phaseWorkgroups();
if (!$phase || $phase === 'tasks') phaseTasks();
if (!$phase || $phase === 'history') phaseHistory();
if (!$phase || $phase === 'analytics') phaseAnalytics();

unlink($config['statePaths']['lock']);
echo "Migration phase completed.\n";
