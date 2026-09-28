<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Generates and serves a sample Excel file matching the default column layout.
chdir('../../');
require('./include/auth.php');
require_once(__DIR__ . '/lib/license_check.php');

if (!api_user_realm_auth('cereus_datasync.php')) {
    http_response_code(403);
    exit;
}

if (!cereus_datasync_license_ok()) {
    http_response_code(403);
    exit;
}

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    print 'PhpSpreadsheet not available';
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Sheet1');

// ── Column layout (matches default profile column mapping) ────────────────
// A=hostname  B=ip  E=snmp_community  K=country  M=device_function
// O=region    P=site
// Columns C,D,F,G,H,I,J,L,N are left blank (other data the customer may have)

// ── Row 1: document title ─────────────────────────────────────────────────
$sheet->mergeCells('A1:P1');
$sheet->setCellValue('A1', 'Network Device Inventory — Sample File');
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1e3a5f']],
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(24);

// ── Row 2: column group labels ────────────────────────────────────────────
$sheet->setCellValue('A2', 'Device');
$sheet->setCellValue('E2', 'SNMP');
$sheet->setCellValue('K2', 'Location');
$sheet->getStyle('A2:P2')->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2e6da4']],
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
]);
$sheet->getRowDimension(2)->setRowHeight(18);

// ── Row 3: column headers ─────────────────────────────────────────────────
$headers = [
    'A' => 'Hostname',       // mapped: hostname
    'B' => 'IP Address',     // mapped: ip
    'C' => '(other)',
    'D' => '(other)',
    'E' => 'SNMP Community', // mapped: snmp_community
    'F' => '(other)',
    'G' => '(other)',
    'H' => '(other)',
    'I' => '(other)',
    'J' => '(other)',
    'K' => 'Country',        // mapped: country
    'L' => '(other)',
    'M' => 'Device Function',// mapped: device_function
    'N' => '(other)',
    'O' => 'Region',         // mapped: region
    'P' => 'Site',           // mapped: site
];
foreach ($headers as $col => $label) {
    $sheet->setCellValue($col . '3', $label);
}
$sheet->getStyle('A3:P3')->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'd0e4f7']],
    'font' => ['bold' => true],
]);
$sheet->getRowDimension(3)->setRowHeight(16);

// Highlight the mapped columns so the user knows which ones matter
$mappedCols = ['A', 'B', 'E', 'K', 'M', 'O', 'P'];
foreach ($mappedCols as $col) {
    $sheet->getStyle($col . '3')->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'ffd966']],
        'font' => ['bold' => true],
    ]);
}

// ── Sample data (rows 4+) ─────────────────────────────────────────────────
$devices = [
    // hostname,               ip,              community, country,         function,          region,          site
    ['eu-gblon-wan-rtr01', '10.1.10.1',  'public',   'United Kingdom', 'Router',           'Europe',        'London - HQ Office'],
    ['eu-gblon-wan-sw01',  '10.1.10.2',  'public',   'United Kingdom', 'Switch IOS L3',    'Europe',        'London - HQ Office'],
    ['eu-gblon-acc-sw01',  '10.1.10.3',  'public',   'United Kingdom', 'Switch IOS L2',    'Europe',        'London - HQ Office'],
    ['eu-gblon-fw01',      '10.1.10.4',  'public',   'United Kingdom', 'Firewall',         'Europe',        'London - HQ Office'],
    ['eu-deber-wan-rtr01', '10.2.10.1',  'public',   'Germany',        'Router',           'Europe',        'Berlin - DC1'],
    ['eu-deber-wan-rtr02', '10.2.10.2',  'public',   'Germany',        'Router',           'Europe',        'Berlin - DC1'],
    ['eu-deber-acc-sw01',  '10.2.10.3',  'public',   'Germany',        'Switch IOS L2',    'Europe',        'Berlin - DC1'],
    ['eu-frpar-wan-rtr01', '10.3.10.1',  'public',   'France',         'Router',           'Europe',        'Paris - Office'],
    ['eu-frpar-wan-sw01',  '10.3.10.2',  'public',   'France',         'Switch IOS L3',    'Europe',        'Paris - Office'],
    ['na-usnyc-wan-rtr01', '10.10.10.1', 'public',   'USA',            'Router',           'North America', 'New York - Trading Floor'],
    ['na-usnyc-wan-rtr02', '10.10.10.2', 'public',   'USA',            'Router',           'North America', 'New York - Trading Floor'],
    ['na-usnyc-fw01',      '10.10.10.3', 'public',   'USA',            'Firewall-FTD',     'North America', 'New York - Trading Floor'],
    ['na-usnyc-acc-sw01',  '10.10.10.4', 'public',   'USA',            'Switch IOS L2',    'North America', 'New York - Trading Floor'],
    ['na-usnyc-acc-sw02',  '10.10.10.5', 'public',   'USA',            'Switch IOS L2',    'North America', 'New York - Trading Floor'],
    ['na-caott-wan-rtr01', '10.11.10.1', 'public',   'Canada',         'Router',           'North America', 'Ottawa - Office'],
    ['na-caott-acc-sw01',  '10.11.10.2', 'public',   'Canada',         'Switch IOS L2',    'North America', 'Ottawa - Office'],
    ['ap-sgsin-wan-rtr01', '10.20.10.1', 'public',   'Singapore',      'Router',           'Asia Pacific',  'Singapore - Regional HQ'],
    ['ap-sgsin-wan-rtr02', '10.20.10.2', 'public',   'Singapore',      'Router',           'Asia Pacific',  'Singapore - Regional HQ'],
    ['ap-sgsin-nxos-sw01', '10.20.10.3', 'public',   'Singapore',      'Switch NXOS',      'Asia Pacific',  'Singapore - Regional HQ'],
    ['ap-sgsin-fw01',      '10.20.10.4', 'public',   'Singapore',      'Cisco ASA',        'Asia Pacific',  'Singapore - Regional HQ'],
    ['ap-jptkyo-wan-rtr01','10.21.10.1', 'public',   'Japan',          'Router',           'Asia Pacific',  'Tokyo - Office'],
    ['ap-jptkyo-acc-sw01', '10.21.10.2', 'public',   'Japan',          'Switch IOS L2',    'Asia Pacific',  'Tokyo - Office'],
    ['me-aedxb-wan-rtr01', '10.30.10.1', 'public',   'UAE',            'Router',           'Middle East',   'Dubai - Hub'],
    ['me-aedxb-wan-rtr02', '10.30.10.2', 'public',   'UAE',            'Service Router',   'Middle East',   'Dubai - Hub'],
    ['me-aedxb-acc-sw01',  '10.30.10.3', 'public',   'UAE',            'Switch IOS L2',    'Middle East',   'Dubai - Hub'],
    ['af-zanai-wan-rtr01', '10.40.10.1', 'public',   'Kenya',          'Router',           'Africa',        'Nairobi - Office'],
    ['af-zanai-acc-sw01',  '10.40.10.2', 'public',   'Kenya',          'Switch IOS L2',    'Africa',        'Nairobi - Office'],
    ['af-zacpt-wan-rtr01', '10.41.10.1', 'public',   'South Africa',   'Router',           'Africa',        'Cape Town - DC'],
    ['af-zacpt-gw-rtr01',  '10.41.10.2', 'public',   'South Africa',   'Gateways',         'Africa',        'Cape Town - DC'],
    ['af-zacpt-aci-lf01',  '10.41.10.3', 'public',   'South Africa',   'ACI-Leaf',         'Africa',        'Cape Town - DC'],
];

$rowNum = 4;
foreach ($devices as $i => $dev) {
    $bg = ($i % 2 === 0) ? 'FFFFFF' : 'f2f7fb';
    $sheet->setCellValue('A' . $rowNum, $dev[0]);
    $sheet->setCellValue('B' . $rowNum, $dev[1]);
    $sheet->setCellValue('E' . $rowNum, $dev[2]);
    $sheet->setCellValue('K' . $rowNum, $dev[3]);
    $sheet->setCellValue('M' . $rowNum, $dev[4]);
    $sheet->setCellValue('O' . $rowNum, $dev[5]);
    $sheet->setCellValue('P' . $rowNum, $dev[6]);
    $sheet->getStyle('A' . $rowNum . ':P' . $rowNum)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
    ]);
    $rowNum++;
}

// ── Column widths ─────────────────────────────────────────────────────────
$widths = [
    'A' => 26, 'B' => 14, 'C' => 10, 'D' => 10,
    'E' => 16, 'F' => 10, 'G' => 10, 'H' => 10,
    'I' => 10, 'J' => 10, 'K' => 16, 'L' => 10,
    'M' => 20, 'N' => 10, 'O' => 18, 'P' => 28,
];
foreach ($widths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// ── Notes row at the bottom ───────────────────────────────────────────────
$noteRow = $rowNum + 1;
$sheet->mergeCells('A' . $noteRow . ':P' . $noteRow);
$sheet->setCellValue('A' . $noteRow,
    'Yellow columns (A, B, E, K, M, O, P) are read by the sync. ' .
    'Data starts at row 4. Columns C, D, F-J, L, N are ignored. ' .
    'Configure column letters in the profile if your file uses different columns.'
);
$sheet->getStyle('A' . $noteRow)->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'fff8e1']],
    'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '795548']],
]);
$sheet->getRowDimension($noteRow)->setRowHeight(20);

// ── Freeze top 3 rows ─────────────────────────────────────────────────────
$sheet->freezePane('A4');

// ── Output ────────────────────────────────────────────────────────────────
$filename = 'cereus_datasync_sample.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
