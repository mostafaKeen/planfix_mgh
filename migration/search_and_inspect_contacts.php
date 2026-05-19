<?php
/**
 * Script to search for and inspect all CRM contacts associated with User ID 43 (Mostafa Osama)
 * in Bitrix24. Analyzes why names are wrong and if phone numbers are present.
 */

$config = require __DIR__ . '/config.php';

$userId = 43;
$reportFile = __DIR__ . '/contact_inspection_report.json';
$lastRequestTime = 0;

/**
 * Standard Bitrix API call with simple rate limiting and retry handling
 */
function callBitrix($method, $params = [], $retries = 3) {
    global $config, $lastRequestTime;
    
    // Strict rate limiting (approx 2 requests/sec)
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
            echo "HTTP request failed. Retrying...\n";
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
 * Fetch all contacts for a specific filter
 */
function fetchContacts($filter) {
    $contacts = [];
    $start = 0;
    
    while (true) {
        $response = callBitrix('crm.contact.list', [
            'filter' => $filter,
            'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE', 'ASSIGNED_BY_ID', 'CREATED_BY_ID', 'DATE_CREATE'],
            'start' => $start
        ]);

        if (!$response || !isset($response['result'])) {
            echo "Error fetching contacts. Response: " . print_r($response, true) . "\n";
            break;
        }

        $items = $response['result'];
        if (empty($items)) {
            break;
        }

        foreach ($items as $item) {
            $contacts[$item['ID']] = $item;
        }

        echo "  Retrieved " . count($contacts) . " contacts so far...\r";

        if (isset($response['next'])) {
            $start = $response['next'];
        } else {
            break;
        }
    }
    echo "\n";
    return $contacts;
}

echo "--- STEP 1: FETCHING CONTACTS ASSOCIATED WITH USER 43 ---\n";

echo "Fetching contacts ASSIGNED to Mostafa Osama (User 43)...\n";
$assignedContacts = fetchContacts(['ASSIGNED_BY_ID' => $userId]);
echo "Found " . count($assignedContacts) . " contacts assigned to User 43.\n\n";

echo "Fetching contacts CREATED by Mostafa Osama (User 43)...\n";
$createdContacts = fetchContacts(['CREATED_BY_ID' => $userId]);
echo "Found " . count($createdContacts) . " contacts created by User 43.\n\n";

// Merge contacts to prevent duplicates
$contacts = $assignedContacts + $createdContacts;
$totalContacts = count($contacts);
echo "Total unique contacts to inspect: $totalContacts\n\n";

if ($totalContacts === 0) {
    echo "No contacts found for User 43. Exiting.\n";
    exit;
}

echo "--- STEP 2: ANALYZING CONTACTS ---\n";

$problematicContacts = [];
$stats = [
    'total_inspected' => $totalContacts,
    'perfect' => 0,
    'total_wrong_names' => 0,
    'total_missing_phone' => 0,
    'both_wrong' => 0,
    'categories' => [
        'empty_name' => 0,
        'numeric_or_phone_name' => 0,
        'email_name' => 0,
        'placeholder_name' => 0,
        'missing_phone' => 0,
        'invalid_phone' => 0
    ]
];

foreach ($contacts as $id => $contact) {
    $firstName = trim($contact['NAME'] ?? '');
    $lastName = trim($contact['LAST_NAME'] ?? '');
    $fullName = trim($firstName . ' ' . $lastName);
    
    $phones = $contact['PHONE'] ?? [];
    $hasPhone = !empty($phones);
    
    $nameErrors = [];
    $phoneErrors = [];
    
    // 1. Name Analysis
    if (empty($fullName)) {
        $nameErrors[] = 'empty_name';
        $stats['categories']['empty_name']++;
    } else {
        // Check if name is numeric or a phone number (e.g. "+97150...", "971...")
        // Matches string consisting only of digits, +, -, (, ), and spaces
        if (preg_match('/^[+\d\s\-\(\)]+$/', $fullName) && strlen(preg_replace('/\D/', '', $fullName)) >= 6) {
            $nameErrors[] = 'numeric_or_phone_name';
            $stats['categories']['numeric_or_phone_name']++;
        }
        
        // Check if name is an email address
        if (strpos($fullName, '@') !== false && filter_var($fullName, FILTER_VALIDATE_EMAIL)) {
            $nameErrors[] = 'email_name';
            $stats['categories']['email_name']++;
        }
        
        // Check for placeholder/test names
        $lowerName = mb_strtolower($fullName, 'UTF-8');
        $placeholders = ['тест', 'test', 'новий', 'новый', 'co ', 'company', 'proj', 'project', 'lead', 'deal', 'assistant', 'контрагент'];
        foreach ($placeholders as $p) {
            if (strpos($lowerName, $p) !== false) {
                $nameErrors[] = 'placeholder_name';
                $stats['categories']['placeholder_name']++;
                break;
            }
        }
    }
    
    // 2. Phone Analysis
    if (!$hasPhone) {
        $phoneErrors[] = 'missing_phone';
        $stats['categories']['missing_phone']++;
    } else {
        $validPhoneCount = 0;
        foreach ($phones as $p) {
            $val = trim($p['VALUE'] ?? '');
            $digits = preg_replace('/\D/', '', $val);
            if (strlen($digits) >= 7) {
                $validPhoneCount++;
            }
        }
        if ($validPhoneCount === 0) {
            $phoneErrors[] = 'invalid_phone';
            $stats['categories']['invalid_phone']++;
        }
    }
    
    $isWrongName = !empty($nameErrors);
    $isWrongPhone = !empty($phoneErrors);
    
    if ($isWrongName || $isWrongPhone) {
        if ($isWrongName) $stats['total_wrong_names']++;
        if ($isWrongPhone) $stats['total_missing_phone']++;
        if ($isWrongName && $isWrongPhone) $stats['both_wrong']++;
        
        // Detailed phone listing for report
        $phoneList = [];
        foreach ($phones as $p) {
            $phoneList[] = $p['VALUE'] ?? '';
        }
        
        $problematicContacts[] = [
            'id' => $id,
            'name_in_crm' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $fullName
            ],
            'phones_in_crm' => $phoneList,
            'assigned_by_id' => $contact['ASSIGNED_BY_ID'] ?? null,
            'created_by_id' => $contact['CREATED_BY_ID'] ?? null,
            'date_create' => $contact['DATE_CREATE'] ?? null,
            'reasons' => array_merge($nameErrors, $phoneErrors)
        ];
    } else {
        $stats['perfect']++;
    }
}

echo "Analysis completed. Writing detailed log to json...\n";
$report = [
    'run_date' => date('c'),
    'statistics' => $stats,
    'problematic_contacts' => $problematicContacts
];
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n--- INSPECTION REPORT FOR USER ID 43 (Mostafa Osama) ---\n";
echo "Total Inspected Contacts : {$stats['total_inspected']}\n";
echo "Valid/Perfect Contacts    : {$stats['perfect']}\n";
echo "Contacts with Wrong Name  : {$stats['total_wrong_names']}\n";
echo "Contacts with Wrong Phone : {$stats['total_missing_phone']}\n";
echo "Contacts with BOTH Wrong  : {$stats['both_wrong']}\n";
echo "\nBreakdown of Issues Found:\n";
echo "  - Empty Names               : {$stats['categories']['empty_name']}\n";
echo "  - Phone Number used as Name : {$stats['categories']['numeric_or_phone_name']}\n";
echo "  - Email Address used as Name: {$stats['categories']['email_name']}\n";
echo "  - Placeholder/Test Names    : {$stats['categories']['placeholder_name']}\n";
echo "  - Missing Phone Numbers     : {$stats['categories']['missing_phone']}\n";
echo "  - Invalid/Short Phone Nums  : {$stats['categories']['invalid_phone']}\n";
echo "\nDetailed diagnostic saved to:\n";
echo "  " . realpath($reportFile) . "\n";
echo "--------------------------------------------------------\n";
