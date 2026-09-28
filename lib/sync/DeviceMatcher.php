<?php
/**
 * Optimized Device Matcher for Cacti Device Synchronization
 *
 * Uses hash maps to reduce device matching from O(n²) to O(n)
 */

namespace CactiSync;

class DeviceMatcher
{
    private $cactiByHostname = [];
    private $cactiByIP = [];
    private $logger;

    public function __construct($logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Build lookup hash maps from Cacti devices
     *
     * Data structure clarification:
     * - Cacti 'hostname' field = IP address or FQDN (network address)
     * - Cacti 'description' field = Friendly name (device name)
     *
     * @param array $cactiDevices
     */
    public function buildLookupMaps($cactiDevices)
    {
        $this->cactiByHostname = [];
        $this->cactiByIP = [];

        foreach ($cactiDevices as $device) {
            // Cacti 'hostname' contains the IP address/FQDN
            $cactiIP = strtolower(trim($device['hostname'] ?? ''));
            // Cacti 'description' contains the friendly name
            $cactiDescription = strtolower(trim($device['description'] ?? ''));

            // Index by IP address (Cacti hostname field)
            // This allows matching Excel IP → Cacti hostname
            if (!empty($cactiIP)) {
                $this->cactiByIP[$cactiIP] = $device;
            }

            // Index by friendly name (Cacti description field)
            // This allows matching Excel hostname → Cacti description
            if (!empty($cactiDescription)) {
                $this->cactiByHostname[$cactiDescription] = $device;
            }
        }

        if ($this->logger) {
            $this->logger->debug(sprintf(
                "Built lookup maps: %d by description (friendly name), %d by IP/FQDN",
                count($this->cactiByHostname),
                count($this->cactiByIP)
            ));
        }
    }

    /**
     * Find matching Cacti device for an Excel device
     *
     * Matches by hostname or IP (case-insensitive)
     * Complexity: O(1) instead of O(n)
     *
     * @param array $excelDevice
     * @return array|null Cacti device or null if not found
     */
    public function findMatch($excelDevice)
    {
        $excelHostname = strtolower(trim($excelDevice['hostname'] ?? ''));
        $excelIP = strtolower(trim($excelDevice['ip'] ?? ''));

        // Try to match by hostname
        if (!empty($excelHostname)) {
            // Direct hostname match
            if (isset($this->cactiByHostname[$excelHostname])) {
                return $this->cactiByHostname[$excelHostname];
            }

            // Hostname might be stored as IP in Cacti
            if (isset($this->cactiByIP[$excelHostname])) {
                return $this->cactiByIP[$excelHostname];
            }
        }

        // Try to match by IP
        if (!empty($excelIP)) {
            // Direct IP match
            if (isset($this->cactiByIP[$excelIP])) {
                return $this->cactiByIP[$excelIP];
            }

            // IP might be stored as hostname in Cacti
            if (isset($this->cactiByHostname[$excelIP])) {
                return $this->cactiByHostname[$excelIP];
            }
        }

        return null;
    }

    /**
     * Check if an Excel device exists in Cacti
     *
     * @param array $excelDevice
     * @return bool
     */
    public function exists($excelDevice)
    {
        return $this->findMatch($excelDevice) !== null;
    }

    /**
     * Find devices in Cacti that are not in Excel
     *
     * @param array $excelDevices
     * @return array Cacti devices not found in Excel
     */
    public function findCactiDevicesNotInExcel($excelDevices)
    {
        // Build set of Excel hostnames and IPs for fast lookup
        $excelHostnames = [];
        $excelIPs = [];

        foreach ($excelDevices as $device) {
            $hostname = strtolower(trim($device['hostname'] ?? ''));
            $ip = strtolower(trim($device['ip'] ?? ''));

            if (!empty($hostname)) {
                $excelHostnames[$hostname] = true;
            }
            if (!empty($ip)) {
                $excelIPs[$ip] = true;
            }
        }

        // Find Cacti devices not in Excel
        $notInExcel = [];

        foreach ($this->cactiByHostname as $hostname => $cactiDevice) {
            // Cacti's 'hostname' column holds the network address (IP/FQDN); there
            // is no separate 'ip' key on the loaded rows. Reading the wrong key
            // meant the IP was never considered here, so a device whose friendly
            // name changed while its IP stayed the same failed this presence check
            // and was wrongly marked for deletion.
            $cactiIP = strtolower(trim($cactiDevice['hostname'] ?? ''));

            // Present if the Excel data references this device by either its
            // friendly name (Cacti description, the map key) or its IP/FQDN.
            $found = isset($excelHostnames[$hostname]) ||
                     isset($excelIPs[$hostname]) ||
                     (!empty($cactiIP) && (isset($excelHostnames[$cactiIP]) || isset($excelIPs[$cactiIP])));

            if (!$found) {
                $notInExcel[] = $cactiDevice;
            }
        }

        return $notInExcel;
    }

    /**
     * Get statistics about the lookup maps
     *
     * @return array
     */
    public function getStats()
    {
        return [
            'total_by_hostname' => count($this->cactiByHostname),
            'total_by_ip' => count($this->cactiByIP),
        ];
    }
}
