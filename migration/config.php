<?php
return [
    'webhookUrl' => 'https://mhg.bitrix24.ae/rest/43/1ii1joal14eai2ln/',
    'rateLimitConfig' => [
        'maxRequestsPerSecond' => 2,
        'batchSize' => 50,
        'maxRetries' => 3,
        'retryDelayMs' => 1000
    ],
    'dataPaths' => [
        'users' => __DIR__ . '/../users.json',
        'contacts' => __DIR__ . '/../contacts.json',
        'projects' => __DIR__ . '/../projects.json',
        'tasks' => __DIR__ . '/../tasks.json',
        'actions' => __DIR__ . '/../actions.json',
        'handbooks' => __DIR__ . '/../handbooks.json',
        'handbooksData' => __DIR__ . '/../handbooksData.json',
        'analiticsData_4' => __DIR__ . '/../analiticsData_4.json',
        'analiticsData_6771' => __DIR__ . '/../analiticsData_6771.json',
        'analiticsData_6773' => __DIR__ . '/../analiticsData_6773.json',
        'analiticsData_6775' => __DIR__ . '/../analiticsData_6775.json',
        'analiticsData_6777' => __DIR__ . '/../analiticsData_6777.json',
        'analiticsData_6779' => __DIR__ . '/../analiticsData_6779.json',
        'analiticsData_6781' => __DIR__ . '/../analiticsData_6781.json',
        'analiticsData_6783' => __DIR__ . '/../analiticsData_6783.json',
        'analiticsData_7025' => __DIR__ . '/../analiticsData_7025.json',
        'analiticsData_7027' => __DIR__ . '/../analiticsData_7027.json'
    ],
    'statePaths' => [
        'progress' => __DIR__ . '/progress.json',
        'idMapping' => __DIR__ . '/id_mapping.json',
        'errorsLog' => __DIR__ . '/errors.log',
        'lock' => __DIR__ . '/migrate.lock'
    ]
];
