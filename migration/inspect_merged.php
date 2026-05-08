<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = __DIR__ . '/../MHG Leads Merged.xlsx';
$spreadsheet = IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet();
$data = $sheet->rangeToArray('A1:Z2', NULL, TRUE, FALSE);
print_r($data);
