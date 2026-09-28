<?php
/**
 * Input Validator for Cacti Device Synchronization
 *
 * Validates all input data from Excel files and other sources
 */

namespace CactiSync;

class Validator
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * Validate hostname
     *
     * @param string $hostname
     * @return bool
     */
    public function validateHostname($hostname)
    {
        if (empty($hostname)) {
            return false;
        }

        $maxLength = $this->config['validation']['max_hostname_length'] ?? 255;
        if (strlen($hostname) > $maxLength) {
            return false;
        }

        $pattern = $this->config['validation']['hostname_pattern'] ?? '/^[a-zA-Z0-9\.\-_]+$/';
        return preg_match($pattern, $hostname) === 1;
    }

    /**
     * Validate IP address
     *
     * @param string $ip
     * @return bool
     */
    public function validateIP($ip)
    {
        if (empty($ip)) {
            return false;
        }

        if (!($this->config['validation']['ip_validation'] ?? true)) {
            return true; // Skip validation if disabled
        }

        // Validate IPv4 or IPv6
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate device function
     *
     * @param string $deviceFunction
     * @return bool
     */
    public function validateDeviceFunction($deviceFunction)
    {
        $allowed = $this->config['excel']['allowed_device_functions'] ?? [];
        return in_array($deviceFunction, $allowed, true);
    }

    /**
     * Validate site name
     *
     * @param string $site
     * @return bool
     */
    public function validateSite($site)
    {
        if (empty($site)) {
            return true; // Site is optional
        }

        $maxLength = $this->config['validation']['max_site_length'] ?? 255;
        return strlen($site) <= $maxLength;
    }

    /**
     * Sanitize hostname
     *
     * @param string $hostname
     * @return string
     */
    public function sanitizeHostname($hostname)
    {
        // Remove any characters that are not alphanumeric, dot, dash, or underscore
        return preg_replace('/[^a-zA-Z0-9\.\-_]/', '', trim($hostname));
    }

    /**
     * Validate Excel device data
     *
     * @param array $device
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateExcelDevice($device)
    {
        $errors = [];

        // Validate hostname
        if (!$this->validateHostname($device['hostname'] ?? '')) {
            $errors[] = "Invalid hostname: " . ($device['hostname'] ?? 'empty');
        }

        // Validate IP
        if (!$this->validateIP($device['ip'] ?? '')) {
            $errors[] = "Invalid IP address: " . ($device['ip'] ?? 'empty');
        }

        // Validate device function
        if (!$this->validateDeviceFunction($device['dev_function'] ?? '')) {
            $errors[] = "Invalid device function: " . ($device['dev_function'] ?? 'empty');
        }

        // Validate site
        if (!$this->validateSite($device['site'] ?? '')) {
            $errors[] = "Invalid site: " . ($device['site'] ?? 'empty');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate SNMP community string
     *
     * @param string $community
     * @return bool
     */
    public function validateSNMPCommunity($community)
    {
        if (empty($community)) {
            return false;
        }

        // Basic validation: no special characters that could cause issues
        return preg_match('/^[a-zA-Z0-9@_\-\.]+$/', $community) === 1;
    }
}
