<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$files = [
    __DIR__ . '/../MHG Leads 15.07-05.03.xlsx',
    __DIR__ . '/../MHG Leads 15.07-19.12.xlsx'
];

$leadsMap = [];
$headers = [];

function cleanPhone($phone) {
    // Remove p:, +, and any non-numeric characters
    return preg_replace('/[^0-9]/', '', (string)$phone);
}

foreach ($files as $file) {
    echo "Processing $file...\n";
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(NULL, TRUE, FALSE, TRUE);
    
    if (empty($headers)) {
        $headers = array_shift($rows);
        // Rename form_name to source information
        foreach ($headers as $key => $val) {
            if ($val === 'form_name') {
                $headers[$key] = 'source information';
            }
        }
        // Add source column
        $headers[] = 'source';
    } else {
        array_shift($rows); // Skip header row for subsequent files
    }

    foreach ($rows as $row) {
        // Find phone index (it's E in the headers we saw, which is index 4 if 0-based)
        // But to be safe, let's find it by value in headers
        $phoneValue = $row['E'] ?? ''; // Based on the print_r output [4] => phone
        $cleanedPhone = cleanPhone($phoneValue);
        
        if (empty($cleanedPhone)) {
            // If no phone, we can't deduplicate effectively. 
            // For now, let's just use a unique key or skip. 
            // Usually in leads, no phone means no contact.
            continue; 
        }

        $createdTime = strtotime($row['A'] ?? '1970-01-01'); // [0] => created_time

        if (!isset($leadsMap[$cleanedPhone]) || $createdTime > $leadsMap[$cleanedPhone]['timestamp']) {
            $row['E'] = $cleanedPhone; // Update phone to cleaned version
            $row['source'] = 'SSM'; // Add source value
            $leadsMap[$cleanedPhone] = [
                'timestamp' => $createdTime,
                'data' => $row
            ];
        }
    }
}

echo "Merging completed. Total unique leads: " . count($leadsMap) . "\n";

$newSpreadsheet = new Spreadsheet();
$newSheet = $newSpreadsheet->getActiveSheet();

// Write headers
$col = 'A';
foreach ($headers as $header) {
    if (!empty($header)) {
        $newSheet->setCellValue($col . '1', $header);
        $col++;
    }
}

// Write data
$rowIndex = 2;
foreach ($leadsMap as $lead) {
    $data = $lead['data'];
    $col = 'A';
    // Mapping keys A, B, C, D, E, F, G to columns
    $keys = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    foreach ($keys as $key) {
        $val = $data[$key] ?? '';
        
        // Special handling for created_time (column A)
        if ($key === 'A' && is_numeric($val) && $val > 1000) {
            $val = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($val)->format('d/m/Y');
        } elseif ($key === 'A' && !empty($val)) {
            // Try to parse string date just in case
            $ts = strtotime($val);
            if ($ts) $val = date('d/m/Y', $ts);
        }

        $newSheet->setCellValue($col . $rowIndex, $val);
        $col++;
    }
    // Add source
    $newSheet->setCellValue($col . $rowIndex, $data['source']);
    $rowIndex++;
}

// Set Date format for column A
$newSheet->getStyle('A2:A' . $rowIndex)->getNumberFormat()->setFormatCode('dd/mm/yyyy');

$writer = new Xlsx($newSpreadsheet);
$outputFile = __DIR__ . '/../MHG Leads Merged.xlsx';
$writer->save($outputFile);

echo "Saved to $outputFile\n";
