<?php
/**
 * Excel File Loader for Cacti Device Synchronization
 *
 * Loads and validates device data from Excel files
 */

namespace CactiSync;

use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelLoader
{
    private $config;
    private $validator;
    private $logger;

    public function __construct($config, $validator, $logger = null)
    {
        $this->config = $config;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    /**
     * Load devices from Excel file
     *
     * @param string $filePath
     * @return array
     * @throws FileNotFoundException
     * @throws ExcelException
     */
    public function loadDevices($filePath)
    {
        // Check if file exists
        if (!file_exists($filePath)) {
            throw new FileNotFoundException("Excel file not found: $filePath");
        }

        if ($this->logger) {
            $this->logger->info("Loading Excel file: " . basename($filePath));
        }

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Exception $e) {
            throw new ExcelException("Failed to load Excel file: " . $e->getMessage());
        }

        // Get the worksheet
        $sheetName = $this->config['excel']['sheet_name'] ?? 'Sheet1';
        try {
            $worksheet = $spreadsheet->setActiveSheetIndexByName($sheetName);
        } catch (\Exception $e) {
            throw new ExcelException("Sheet '$sheetName' not found in Excel file");
        }

        $devices = [];
        $invalidDevices = [];
        $startRow = $this->config['excel']['start_row'] ?? 4;
        $columns = $this->config['excel']['columns'] ?? [];

        // Iterate through rows
        foreach ($worksheet->getRowIterator($startRow) as $row) {
            $rowIndex = $row->getRowIndex();

            // Get device function
            $devFunction = trim($worksheet->getCell($columns['device_function'] . $rowIndex)->getCalculatedValue());

            // Check if device function is allowed
            if (!$this->validator->validateDeviceFunction($devFunction)) {
                continue; // Skip this row
            }

            // Extract device data
            $hostname = trim($worksheet->getCell($columns['hostname'] . $rowIndex)->getCalculatedValue());
            $ip = trim($worksheet->getCell($columns['ip'] . $rowIndex)->getCalculatedValue());

            // Skip empty rows
            if (empty($hostname) && empty($ip)) {
                continue;
            }

            $siteCode = $this->extractSiteCodeFromHostname($hostname);
            $regionRaw = trim($worksheet->getCell($columns['region'] . $rowIndex)->getCalculatedValue());
            $region = array_slice(explode(" - ", $regionRaw), -1)[0];

            // Special case: VPN devices should be Cisco ASA
            if (preg_match('/vpn\d+$/i', $hostname)) {
                $devFunction = 'Cisco ASA';
            }

            $device = [
                'hostname' => $hostname,
                'site_code' => $siteCode,
                'ip' => $ip,
                'dev_function' => $devFunction,
                'snmp_community' => trim($worksheet->getCell($columns['snmp_community'] . $rowIndex)->getCalculatedValue()),
                'region' => $region,
                'country' => trim($worksheet->getCell($columns['country'] . $rowIndex)->getCalculatedValue()),
                'site' => trim($worksheet->getCell($columns['site'] . $rowIndex)->getCalculatedValue()),
            ];

            // Validate device
            $validation = $this->validator->validateExcelDevice($device);
            if (!$validation['valid']) {
                $invalidDevices[] = [
                    'row' => $rowIndex,
                    'device' => $device,
                    'errors' => $validation['errors']
                ];
                if ($this->logger) {
                    $this->logger->warning(sprintf(
                        "Row %d: Invalid device data - %s",
                        $rowIndex,
                        implode(', ', $validation['errors'])
                    ));
                }
                continue;
            }

            $devices[] = $device;
        }

        if ($this->logger) {
            $this->logger->info(sprintf(
                "Loaded %d valid devices, %d invalid devices from Excel",
                count($devices),
                count($invalidDevices)
            ));
        }

        return $devices;
    }

    /**
     * Extract site code from hostname
     *
     * @param string $hostname
     * @return string
     */
    private function extractSiteCodeFromHostname($hostname)
    {
        // Remove last segment after last dash
        if (preg_match('/^(.+)-[^-]+$/', trim($hostname), $matches)) {
            return $matches[1];
        }

        // Remove suffix if it matches R<numbers> or SW<numbers>
        $hostname = trim($hostname);
        $hostname = preg_replace('/[rR]\d+$/', '', $hostname);
        $hostname = preg_replace('/[sS][wW]\d+$/i', '', $hostname);

        return trim($hostname);
    }

    /**
     * Get latest Excel file from directory
     *
     * @param string $directory
     * @return string|null
     */
    public function getLatestExcelFile($directory)
    {
        $pattern = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.xlsx';
        $files = glob($pattern);

        if ($files === false || empty($files)) {
            return null;
        }

        // Sort by modification time, newest first
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $files[0];
    }

    /**
     * Determine input file from argument or directory
     *
     * @param string|null $input
     * @return string
     * @throws FileNotFoundException
     */
    public function getInputFile($input = null)
    {
        if (!empty($input)) {
            if (is_file($input)) {
                return $input;
            } elseif (is_dir($input)) {
                $latest = $this->getLatestExcelFile($input);
                if ($latest) {
                    if ($this->logger) {
                        $this->logger->info("Found latest file in directory '$input': " . basename($latest));
                    }
                    return $latest;
                }
                throw new FileNotFoundException("No .xlsx files found in directory: $input");
            } else {
                throw new FileNotFoundException("Input '$input' is neither a file nor a directory");
            }
        }

        // Default behavior: scan current directory
        $latest = $this->getLatestExcelFile(getcwd());
        if ($latest) {
            if ($this->logger) {
                $this->logger->info("No argument provided. Found latest file in current directory: " . basename($latest));
            }
            return $latest;
        }

        // Try default file from config
        $defaultFile = $this->config['excel']['default_file'] ?? null;
        if ($defaultFile && file_exists($defaultFile)) {
            return $defaultFile;
        }

        throw new FileNotFoundException("Please provide an Excel file or directory as an argument");
    }
}
