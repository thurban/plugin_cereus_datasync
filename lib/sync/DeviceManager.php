<?php
/**
 * Device Manager for Cacti Device Synchronization
 *
 * Handles device operations with proper separation of concerns
 */

namespace CactiSync;

class DeviceManager
{
    private $config;
    private $logger;
    private $transaction;
    private $validator;

    public function __construct($config, $logger, $transaction, $validator)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->transaction = $transaction;
        $this->validator = $validator;
    }

    /**
     * Load all devices from Cacti database
     *
     * @return array
     */
    public function loadCactiDevices()
    {
        $devices = db_fetch_assoc("SELECT
            h.id,
            h.hostname,
            h.description,
            h.disabled,
            h.snmp_community,
            h.snmp_version,
            h.snmp_username,
            h.availability_method,
            h.ping_method,
            h.notes,
            h.site_id,
            h.location,
            ht.name as host_template_name
        FROM host AS h
        LEFT JOIN host_template AS ht ON h.host_template_id = ht.id
        ORDER BY h.hostname");

        if (!is_array($devices)) {
            $this->logger->error('Could not load devices from Cacti database');
            return [];
        }

        return $devices;
    }

    /**
     * Check if device has WAN interfaces via SNMP
     *
     * @param string $hostname
     * @param string $snmpCommunity
     * @param int $snmpVersion
     * @param int $snmpPort
     * @param int $timeout
     * @return array
     */
    public function checkWANInterfaces($hostname, $snmpCommunity, $snmpVersion = 2, $snmpPort = 161, $timeout = 2000)
    {
        global $config;

        if (!function_exists('cacti_snmp_walk')) {
            require_once($config['base_path'] . '/lib/snmp.php');
        }

        $result = [
            'has_wan' => false,
            'wan_interfaces' => [],
            'total_interfaces' => 0,
            'error' => ''
        ];

        if (empty($hostname) || empty($snmpCommunity)) {
            $result['error'] = 'Missing hostname or SNMP community';
            return $result;
        }

        $ifAlias_oid = '.1.3.6.1.2.1.31.1.1.1.18';

        try {
            $ifAliases = cacti_snmp_walk(
                $hostname,
                $snmpCommunity,
                $ifAlias_oid,
                $snmpVersion,
                '', '', '', '', '', '',
                $snmpPort,
                $timeout,
                1, 10
            );

            if ($ifAliases === false || !is_array($ifAliases) || empty($ifAliases)) {
                $result['error'] = 'SNMP walk failed or returned no data';
                return $result;
            }

            $result['total_interfaces'] = count($ifAliases);
            $wanInterfaces = [];

            foreach ($ifAliases as $entry) {
                $oid = $entry['oid'] ?? '';
                $alias = trim($entry['value'] ?? '');

                if (empty($alias)) {
                    continue;
                }

                preg_match('/\.(\d+)$/', $oid, $matches);
                $ifIndex = $matches[1] ?? 'unknown';

                $wanPattern = $this->config['automation']['wan_pattern'] ?? '-WAN-';
                if (stripos($alias, $wanPattern) !== false) {
                    $wanInterfaces[] = [
                        'ifIndex' => $ifIndex,
                        'ifAlias' => $alias
                    ];
                }
            }

            if (count($wanInterfaces) > 0) {
                $result['has_wan'] = true;
                $result['wan_interfaces'] = $wanInterfaces;
            }

        } catch (\Exception $e) {
            $result['error'] = 'Exception during SNMP check: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Add a device to Cacti
     *
     * @param array $deviceData
     * @param array $options
     * @return int|false Device ID or false on failure
     */
    public function addDevice($deviceData, $options = [])
    {
        global $config;

        if (!function_exists('api_device_save')) {
            require_once($config['base_path'] . '/lib/api_device.php');
        }

        // Check WAN interfaces for L2 switches if configured
        if ($deviceData['dev_function'] === 'Switch IOS L2' &&
            ($this->config['snmp']['check_wan_for_l2_switches'] ?? true)) {

            $snmpCheck = $this->checkWANInterfaces(
                $deviceData['ip'],
                $deviceData['snmp_community'] ?: ($this->config['snmp']['default_community'] ?? 'public'),
                Constants::SNMP_VERSION_2C,
                $this->config['device_options']['snmp_port'] ?? 161,
                $this->config['snmp']['wan_check_timeout'] ?? 2000
            );

            if (!empty($snmpCheck['error'])) {
                $this->logger->warning(sprintf(
                    "SNMP check failed for %s [%s]: %s - Device will NOT be added",
                    $deviceData['hostname'],
                    $deviceData['ip'],
                    $snmpCheck['error']
                ));
                return false;
            }

            if (!$snmpCheck['has_wan']) {
                $this->logger->info(sprintf(
                    "Device %s [%s] has NO WAN interfaces - Device will NOT be added",
                    $deviceData['hostname'],
                    $deviceData['ip']
                ));
                return false;
            }

            $this->logger->debug(sprintf(
                "Device %s [%s] has %d WAN interface(s)",
                $deviceData['hostname'],
                $deviceData['ip'],
                count($snmpCheck['wan_interfaces'])
            ));
        }

        // Get host template and site
        $hostTemplateId = $this->getHostTemplateId($deviceData['dev_function']);
        $siteId = $this->getOrCreateSiteId(
            $deviceData['region'],
            $deviceData['country'],
            $deviceData['site']
        );

        // Merge options with defaults
        $opts = array_merge($this->config['device_options'] ?? [], $options);

        // Prepare location field (truncate to varchar(40) if needed)
        $location = $deviceData['site'] ?? '';
        if (strlen($location) > 40) {
            $originalLocation = $location;
            $location = substr($location, 0, 40);
            $this->logger->warning(sprintf(
                "Location truncated from %d to 40 chars for device %s [%s]: '%s...' (original: '%s')",
                strlen($originalLocation),
                $deviceData['hostname'],
                $deviceData['ip'],
                $location,
                $originalLocation
            ));
        }

        // Prepare device data
        $save = [
            'id' => "0",
            'host_template_id' => $hostTemplateId,
            'description' => $deviceData['hostname'],
            'hostname' => $deviceData['ip'],
            'notes' => sprintf(
                "Region: %s | Country: %s | Site: %s | Added from Excel: %s",
                $deviceData['region'],
                $deviceData['country'],
                $deviceData['site'],
                date('Y-m-d H:i:s')
            ),
            'snmp_community' => $deviceData['snmp_community'] ?: ($opts['snmp_community'] ?? 'public'),
            'snmp_version' => $opts['snmp_version'] ?? Constants::SNMP_VERSION_2C,
            'snmp_username' => '',
            'snmp_password' => '',
            'snmp_auth_protocol' => '',
            'snmp_priv_passphrase' => '',
            'snmp_priv_protocol' => '',
            'snmp_context' => '',
            'snmp_port' => $opts['snmp_port'] ?? 161,
            'snmp_timeout' => $opts['snmp_timeout'] ?? 500,
            'disabled' => $opts['disabled'] ?? '',
            'availability_method' => $opts['availability_method'] ?? Constants::AVAILABILITY_SNMP,
            'ping_method' => $opts['ping_method'] ?? Constants::PING_ICMP,
            'ping_port' => 0,
            'ping_timeout' => $opts['ping_timeout'] ?? 500,
            'ping_retries' => $opts['ping_retries'] ?? 2,
            'max_oids' => $opts['max_oids'] ?? 10,
            'device_threads' => $opts['device_threads'] ?? 1,
            'poller_id' => $opts['poller_id'] ?? 1,
            'site_id' => $siteId,
            'external_id' => '',
            'location' => $location,
        ];

        // Add device using Cacti API (wrapped in transaction)
        $deviceId = $this->transaction->execute(function () use ($save, $deviceData, $hostTemplateId) {
            $id = api_device_save(
                $save['id'],
                $save['host_template_id'],
                $save['description'],
                $save['hostname'],
                $save['snmp_community'],
                $save['snmp_version'],
                $save['snmp_username'],
                $save['snmp_password'],
                $save['snmp_port'],
                $save['snmp_timeout'],
                $save['disabled'],
                $save['availability_method'],
                $save['ping_method'],
                $save['ping_port'],
                $save['ping_timeout'],
                $save['ping_retries'],
                $save['notes'],
                $save['snmp_auth_protocol'],
                $save['snmp_priv_passphrase'],
                $save['snmp_priv_protocol'],
                $save['snmp_context'],
                '',
                $save['max_oids'],
                $save['device_threads'],
                $save['poller_id'],
                $save['site_id'],
                $save['external_id'],
                $save['location'],
                -1
            );

            if (!$id) {
                return false;
            }

            // Log the change
            $details = [
                'ip' => $save['hostname'],
                'region' => $deviceData['region'] ?? '',
                'country' => $deviceData['country'] ?? '',
                'site' => $deviceData['site'] ?? '',
                'host_template_id' => $hostTemplateId,
            ];
            $this->logDeviceChange($id, $save['description'], Constants::ACTION_ADDED, json_encode($details));

            return $id;
        });

        if ($deviceId) {
            $this->logger->info(sprintf(
                "Device added successfully: %s [%s] - ID: %d",
                $save['description'],
                $save['hostname'],
                $deviceId
            ));
        } else {
            $this->logger->error(sprintf(
                "Failed to add device: %s [%s]",
                $save['description'],
                $save['hostname']
            ));
        }

        return $deviceId;
    }

    /**
     * Update device location and site
     *
     * @param int $deviceId
     * @param string $location
     * @param int $siteId
     * @param string $hostname
     * @return bool
     */
    public function updateLocationAndSite($deviceId, $location, $siteId, $hostname)
    {
        global $config;

        // Truncate location to fit database field (varchar(40))
        if (strlen($location) > 40) {
            $originalLocation = $location;
            $location = substr($location, 0, 40);
            $this->logger->warning(sprintf(
                "Location truncated from %d to 40 chars for device %s: '%s...' (original: '%s')",
                strlen($originalLocation),
                $hostname,
                $location,
                $originalLocation
            ));
        }

        if (!function_exists('api_device_save')) {
            require_once($config['base_path'] . '/lib/api_device.php');
        }

        $device = db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', [$deviceId]);
        if (empty($device)) {
            $this->logger->error(sprintf(
                "Device ID %d not found for location/site update (hostname: %s)",
                $deviceId,
                $hostname
            ));
            return false;
        }

        $result = $this->transaction->execute(function () use ($device, $deviceId, $location, $siteId, $hostname) {
            $changes = [];
            if ((string)$device['location'] !== (string)$location) {
                $changes[] = sprintf(
                    "Location: '%s' \xE2\x86\x92 '%s'",
                    $device['location'] !== '' ? $device['location'] : '(none)',
                    $location !== '' ? $location : '(none)'
                );
            }
            if ((int)$device['site_id'] !== (int)$siteId) {
                $changes[] = sprintf('Site: #%d \xE2\x86\x92 #%d', (int)$device['site_id'], (int)$siteId);
            }

            $device['location'] = $location;
            $device['site_id']  = $siteId;
            $device['notes']    = $this->appendSyncNote($device['notes'], $changes);

            $resultId = api_device_save(
                $device['id'],
                $device['host_template_id'],
                $device['description'],
                $device['hostname'],
                $device['snmp_community'],
                $device['snmp_version'],
                $device['snmp_username'],
                $device['snmp_password'],
                $device['snmp_port'],
                $device['snmp_timeout'],
                $device['disabled'],
                $device['availability_method'],
                $device['ping_method'],
                $device['ping_port'],
                $device['ping_timeout'],
                $device['ping_retries'],
                $device['notes'],
                $device['snmp_auth_protocol'],
                $device['snmp_priv_passphrase'],
                $device['snmp_priv_protocol'],
                $device['snmp_context'],
                '',
                $device['max_oids'],
                $device['device_threads'],
                $device['poller_id'],
                $device['site_id'],
                $device['external_id'],
                $device['location'],
                -1
            );

            if (!$resultId) {
                return false;
            }

            $this->logDeviceChange(
                $deviceId,
                $hostname,
                Constants::ACTION_UPDATED,
                "Location updated to: $location, Site ID updated to: $siteId"
            );

            return true;
        });

        if ($result) {
            $this->logger->info("Updated location and site for device $hostname (ID: $deviceId)");
        } else {
            $this->logger->error("Failed to update location and site for device $hostname (ID: $deviceId)");
        }

        return $result;
    }

    /**
     * Update device description (hostname)
     *
     * @param int $deviceId
     * @param string $newDescription
     * @param string $oldDescription
     * @return bool
     */
    public function updateDescription($deviceId, $newDescription, $oldDescription)
    {
        global $config;

        // Enhanced debugging - log what we received
        $this->logger->debug(sprintf(
            "updateDescription called with: deviceId=%s (type: %s), newDescription='%s', oldDescription='%s'",
            var_export($deviceId, true),
            gettype($deviceId),
            $newDescription,
            $oldDescription
        ));

        // Check if deviceId is valid
        if (empty($deviceId) || !is_numeric($deviceId)) {
            $this->logger->error(sprintf(
                "Invalid device ID for description update: %s (type: %s)",
                var_export($deviceId, true),
                gettype($deviceId)
            ));
            return false;
        }

        // Ensure it's an integer
        $deviceId = (int)$deviceId;

        if (!function_exists('api_device_save')) {
            require_once($config['base_path'] . '/lib/api_device.php');
        }

        $device = db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', [$deviceId]);

        // Enhanced debugging for query result
        if (empty($device)) {
            $this->logger->error(sprintf(
                "Device ID %d not found in host table for description update (Excel hostname: '%s')",
                $deviceId,
                $newDescription
            ));

            // Try to find similar devices for debugging
            $similarDevices = db_fetch_assoc_prepared(
                'SELECT id, description, hostname FROM host WHERE description LIKE ? OR hostname LIKE ? LIMIT 5',
                ['%' . $oldDescription . '%', '%' . $oldDescription . '%']
            );

            if (!empty($similarDevices)) {
                $this->logger->debug("Similar devices found in database:");
                foreach ($similarDevices as $dev) {
                    $this->logger->debug(sprintf(
                        "  - ID: %d, Description: '%s', Hostname: '%s'",
                        $dev['id'],
                        $dev['description'],
                        $dev['hostname']
                    ));
                }
            } else {
                $this->logger->debug("No similar devices found matching description '$oldDescription'");
            }

            return false;
        }

        $this->logger->debug(sprintf(
            "Found device in database: ID=%d, Description='%s', Hostname='%s'",
            $device['id'],
            $device['description'],
            $device['hostname']
        ));

        $result = $this->transaction->execute(function () use ($device, $deviceId, $newDescription, $oldDescription) {
            $device['description'] = $newDescription;
            $device['notes']       = $this->appendSyncNote($device['notes'], [
                sprintf(
                    "Description: '%s' \xE2\x86\x92 '%s'",
                    $oldDescription !== '' ? $oldDescription : '(none)',
                    $newDescription
                ),
            ]);

            $resultId = api_device_save(
                $device['id'],
                $device['host_template_id'],
                $device['description'],  // Updated description
                $device['hostname'],      // IP stays the same
                $device['snmp_community'],
                $device['snmp_version'],
                $device['snmp_username'],
                $device['snmp_password'],
                $device['snmp_port'],
                $device['snmp_timeout'],
                $device['disabled'],
                $device['availability_method'],
                $device['ping_method'],
                $device['ping_port'],
                $device['ping_timeout'],
                $device['ping_retries'],
                $device['notes'],
                $device['snmp_auth_protocol'],
                $device['snmp_priv_passphrase'],
                $device['snmp_priv_protocol'],
                $device['snmp_context'],
                '',
                $device['max_oids'],
                $device['device_threads'],
                $device['poller_id'],
                $device['site_id'],
                $device['external_id'],
                $device['location'],
                -1
            );

            if (!$resultId) {
                return false;
            }

            $details = [
                'old_description' => $oldDescription,
                'new_description' => $newDescription,
                'ip' => $device['hostname'],
            ];
            $this->logDeviceChange(
                $deviceId,
                $newDescription,
                Constants::ACTION_UPDATED,
                json_encode($details)
            );

            return true;
        });

        if ($result) {
            $this->logger->info(sprintf(
                "Updated device description: '%s' -> '%s' (ID: %d, IP: %s)",
                $oldDescription,
                $newDescription,
                $deviceId,
                $device['hostname']
            ));
        } else {
            $this->logger->error(sprintf(
                "Failed to update device description for ID: %d",
                $deviceId
            ));
        }

        return $result;
    }

    /**
     * Mark device as deleted
     *
     * @param int $deviceId
     * @param string $reason
     * @return bool
     */
    public function markAsDeleted($deviceId, $reason = '')
    {
        $device = db_fetch_row_prepared(
            'SELECT id, description, hostname, notes, disabled FROM host WHERE id = ?',
            [$deviceId]
        );

        if (empty($device)) {
            $this->logger->error("Device ID $deviceId not found");
            return false;
        }

        // Skip localhost
        if (($this->config['deletion']['skip_localhost'] ?? true) &&
            $device['description'] == 'localhost') {
            return false;
        }

        $tag = $this->config['deletion']['mark_as_deleted_tag'] ?? Constants::TAG_TO_BE_DELETED;

        // Check if already marked
        if (strpos($device['description'], $tag) !== false) {
            $this->logger->debug("Device ID $deviceId is already marked as to be deleted");
            return true;
        }

        $newDescription = $tag . ' ' . $device['description'];
        $deletionInfo = "\n--- TO BE DELETED: " . date('Y-m-d H:i:s') . " ---";
        if (!empty($reason)) {
            $deletionInfo .= "\nReason: " . $reason;
        }
        $newNotes = $device['notes'] . $deletionInfo;

        $result = $this->transaction->execute(function () use ($deviceId, $newDescription, $newNotes, $device, $reason) {
            $updateResult = db_execute_prepared(
                'UPDATE host SET description = ?, notes = ? WHERE id = ?',
                [$newDescription, $newNotes, $deviceId]
            );

            if (!$updateResult) {
                return false;
            }

            $details = [
                'reason' => $reason,
                'old' => ['description' => $device['description']],
                'new' => ['description' => $newDescription],
            ];
            $this->logDeviceChange($deviceId, $device['hostname'], Constants::ACTION_MARKED_DELETED, json_encode($details));

            return true;
        });

        if ($result) {
            $this->logger->info(sprintf(
                "Device marked as to be deleted: ID=%d, Hostname=%s",
                $deviceId,
                $device['hostname']
            ));
        } else {
            $this->logger->error("Failed to mark device ID $deviceId as to be deleted");
        }

        return $result;
    }

    /**
     * Get or create site ID
     *
     * @param string $region
     * @param string $country
     * @param string $site
     * @return int
     */
    public function getOrCreateSiteId($region, $country, $site)
    {
        $parts = [];
        foreach ([$region, $country, $site] as $p) {
            $p = trim((string)$p);
            if ($p !== '') {
                $parts[] = $p;
            }
        }

        if (empty($parts)) {
            return 0;
        }

        $name = implode('/', $parts);

        // Check if name is too long for database field (varchar(100))
        if (strlen($name) > 100) {
            $originalName = $name;
            $name = substr($name, 0, 100);
            $this->logger->warning(sprintf(
                "Site name truncated from %d to 100 chars: '%s...' (original: '%s')",
                strlen($originalName),
                $name,
                $originalName
            ));
        }

        // Try to find existing site
        $row = db_fetch_row_prepared('SELECT id FROM sites WHERE name = ?', [$name]);
        if (!empty($row) && isset($row['id'])) {
            $this->logger->debug("Found existing site: [$name] - ID: {$row['id']}");
            return $row['id'];
        }

        // A previous sync may have flagged this site as empty (deletion tag
        // prefixed onto its name) without it having been removed yet. Reuse it so
        // the returning device lands back in the same site rather than a duplicate;
        // the post-sync reconciliation strips the tag once it is non-empty again.
        $tag = $this->config['deletion']['mark_as_deleted_tag'] ?? Constants::TAG_TO_BE_DELETED;
        if (!empty($tag)) {
            $taggedName = substr($tag . ' ' . $name, 0, 100);
            $row = db_fetch_row_prepared('SELECT id FROM sites WHERE name = ?', [$taggedName]);
            if (!empty($row) && isset($row['id'])) {
                $this->logger->debug("Reusing flagged-empty site: [$taggedName] - ID: {$row['id']}");
                return $row['id'];
            }
        }

        // Create new site
        $timezone = $this->getTimezoneByCountryName($country);
        $notes = sprintf('Auto-created from Excel import on %s', date('Y-m-d H:i:s'));

        try {
            $ok = db_execute_prepared(
                'INSERT INTO sites (name, country, timezone, notes) VALUES (?, ?, ?, ?)',
                [$name, $country, $timezone, $notes]
            );

            if ($ok) {
                $row = db_fetch_row_prepared('SELECT id FROM sites WHERE name = ?', [$name]);
                if (!empty($row) && isset($row['id'])) {
                    $this->logger->info("Site created successfully: [$name] - ID: {$row['id']}");
                    return $row['id'];
                }
            }

            // If we got here, something went wrong
            $this->logger->error(sprintf(
                "Failed to create site '%s' (region: %s, country: %s, site: %s). Database returned: %s",
                $name,
                $region,
                $country,
                $site,
                $ok ? 'success but no ID' : 'false'
            ));

            // Try to get last error
            if (function_exists('db_error')) {
                $dbError = db_error();
                if (!empty($dbError)) {
                    $this->logger->error("Database error: $dbError");
                }
            }

        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                "Exception creating site '%s': %s",
                $name,
                $e->getMessage()
            ));
        }

        return 0;
    }

    /**
     * Get host template ID by device function
     *
     * @param string $deviceFunction
     * @return int
     */
    private function getHostTemplateId($deviceFunction)
    {
        $templateMapping = [
            'Router' => 'Cisco Router',
            'Switch IOS L3' => 'Cisco Router',
            'Switch IOS L2' => 'Cisco Router',
            'Firewall-FTD' => 'Cisco Router',
            'Switch NXOS' => 'Cisco Nexus 5500',
            'Firewall' => 'Cisco ASA',
            'Service Router' => 'Cisco Router',
            'AWS: Router' => 'Cisco Router',
            'Gateways' => 'Cisco Router',
            'ACI-Leaf' => 'Cisco Nexus 5500',
            'ACI-Spine' => 'Cisco Nexus 5500',
            'Cisco ASA' => 'Cisco ASA'
        ];

        $templateName = $templateMapping[$deviceFunction] ?? 'Generic SNMP Device';

        $templateId = db_fetch_cell_prepared(
            'SELECT id FROM host_template WHERE name LIKE ?',
            ['%' . $templateName . '%']
        );

        if (empty($templateId)) {
            $templateId = db_fetch_cell(
                "SELECT id FROM host_template WHERE name LIKE '%Generic%' OR name LIKE '%SNMP%' LIMIT 1"
            );

            if (empty($templateId)) {
                $templateId = 0;
            }
        }

        return $templateId;
    }

    /**
     * Get timezone by country name
     *
     * @param string $countryName
     * @return string|null
     */
    private function getTimezoneByCountryName($countryName)
    {
        $countryName = trim($countryName);
        $countries = [];

        foreach (\ResourceBundle::getLocales('') as $loc) {
            $region = \Locale::getRegion($loc);
            if ($region && strlen($region) === 2) {
                $name = \Locale::getDisplayRegion($loc . '_' . $region, 'en');
                if ($name && $name !== $region) {
                    $countries[$region] = $name;
                }
            }
        }

        // Exact match
        foreach ($countries as $code => $name) {
            if (strcasecmp($name, $countryName) === 0) {
                $timezones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $code);
                return !empty($timezones) ? $timezones[0] : null;
            }
        }

        // Partial match
        foreach ($countries as $code => $name) {
            if (stripos($name, $countryName) !== false || stripos($countryName, $name) !== false) {
                $timezones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $code);
                return !empty($timezones) ? $timezones[0] : null;
            }
        }

        return null;
    }

    /**
     * Log device change
     *
     * @param int $deviceId
     * @param string $hostname
     * @param string $action
     * @param string|null $details
     */
    /**
     * Append one or more Data Sync change entries to a device's notes field.
     *
     * Each change becomes a timestamped, human-readable line so an operator
     * reading the device in Cacti can see exactly what the sync altered and
     * when. The field is bounded so repeated syncs never grow it without limit.
     *
     * @param  string $existingNotes  Current host.notes value
     * @param  array  $changes        List of change description strings
     * @return string                 New notes value
     */
    private function appendSyncNote($existingNotes, array $changes)
    {
        if (empty($changes)) {
            return $existingNotes;
        }

        $stamp = date('Y-m-d H:i');
        $lines = [];
        foreach ($changes as $change) {
            $lines[] = sprintf('[Data Sync %s] %s', $stamp, $change);
        }
        $block = implode("\n", $lines);

        $notes = rtrim((string)$existingNotes);
        $notes = ($notes === '') ? $block : $notes . "\n" . $block;

        // Bound the field — host.notes is TEXT, but there is no value in letting
        // it grow unbounded. Keep the most recent ~8000 chars, dropping any
        // partially-truncated leading line.
        $max = 8000;
        if (strlen($notes) > $max) {
            $notes = substr($notes, -$max);
            $nl = strpos($notes, "\n");
            if ($nl !== false) {
                $notes = substr($notes, $nl + 1);
            }
        }

        return $notes;
    }

    private function logDeviceChange($deviceId, $hostname, $action, $details = null)
    {
        $deviceId = !empty($deviceId) ? (int)$deviceId : null;
        $hostname = (string)$hostname;
        $action = (string)$action;
        $byHost = function_exists('gethostname') ? gethostname() : php_uname('n');
        $now = date('Y-m-d H:i:s');

        // Ensure tables exist
        if (function_exists('db_execute')) {
            @db_execute("CREATE TABLE IF NOT EXISTS report_mailer_device_changes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                device_id INT UNSIGNED NULL,
                hostname VARCHAR(255) NULL,
                action ENUM('added','updated','marked_deleted','unmarked','deleted') NOT NULL,
                details TEXT NULL,
                changed_by_host VARCHAR(255) NULL,
                changed_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_changed_at (changed_at),
                KEY idx_action (action),
                KEY idx_device_id (device_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

            @db_execute("CREATE TABLE IF NOT EXISTS report_mailer_device_last_update (
                device_id INT UNSIGNED NOT NULL,
                hostname VARCHAR(255) NULL,
                last_syn_at DATETIME NOT NULL,
                last_action VARCHAR(32) NOT NULL,
                last_hash VARCHAR(64) NULL,
                PRIMARY KEY (device_id),
                KEY idx_last_syn_at (last_syn_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }

        // Insert change row
        $sql = "INSERT INTO report_mailer_device_changes (device_id, hostname, action, details, changed_by_host, changed_at)
                VALUES (?,?,?,?,?,?)";
        db_execute_prepared($sql, [$deviceId, $hostname, $action, $details, $byHost, $now]);

        // Update last update table
        $sql2 = "REPLACE INTO report_mailer_device_last_update (device_id, hostname, last_syn_at, last_action, last_hash)
                 VALUES (?,?,?,?,?)";
        db_execute_prepared($sql2, [$deviceId, $hostname, $now, $action, null]);
    }

    /**
     * Create SNMP check callback for parallel processing
     * Returns a callable that can be passed to ParallelSNMPChecker
     *
     * @return callable
     */
    public function createSNMPCheckCallback()
    {
        return function ($device) {
            $snmpCommunity = $device['snmp_community'] ?: ($this->config['snmp']['default_community'] ?? 'public');
            $snmpPort = $this->config['device_options']['snmp_port'] ?? 161;
            $timeout = $this->config['snmp']['wan_check_timeout'] ?? 2000;

            return $this->checkWANInterfaces(
                $device['ip'],
                $snmpCommunity,
                Constants::SNMP_VERSION_2C,
                $snmpPort,
                $timeout
            );
        };
    }

    /**
     * Add a device without SNMP pre-check
     * Used when SNMP check was already performed in batch
     *
     * @param array $deviceData
     * @param array $options
     * @param bool $hasWAN Whether the device has WAN interfaces (from prior SNMP check)
     * @return int|false Device ID or false on failure
     */
    public function addDeviceWithoutSNMPCheck($deviceData, $options = [], $hasWAN = true)
    {
        global $config;

        if (!function_exists('api_device_save')) {
            require_once($config['base_path'] . '/lib/api_device.php');
        }

        // For L2 switches, check if we should skip based on WAN status
        if ($deviceData['dev_function'] === 'Switch IOS L2' &&
            ($this->config['snmp']['check_wan_for_l2_switches'] ?? true) &&
            !$hasWAN) {
            $this->logger->info(sprintf(
                "Device %s [%s] has NO WAN interfaces - Device will NOT be added",
                $deviceData['hostname'],
                $deviceData['ip']
            ));
            return false;
        }

        // Get host template and site
        $hostTemplateId = $this->getHostTemplateId($deviceData['dev_function']);
        $siteId = $this->getOrCreateSiteId(
            $deviceData['region'],
            $deviceData['country'],
            $deviceData['site']
        );

        // Merge options with defaults
        $opts = array_merge($this->config['device_options'] ?? [], $options);

        // Prepare location field (truncate to varchar(40) if needed)
        $location = $deviceData['site'] ?? '';
        if (strlen($location) > 40) {
            $originalLocation = $location;
            $location = substr($location, 0, 40);
            $this->logger->warning(sprintf(
                "Location truncated from %d to 40 chars for device %s [%s]: '%s...' (original: '%s')",
                strlen($originalLocation),
                $deviceData['hostname'],
                $deviceData['ip'],
                $location,
                $originalLocation
            ));
        }

        // Prepare device data
        $save = [
            'id' => "0",
            'host_template_id' => $hostTemplateId,
            'description' => $deviceData['hostname'],
            'hostname' => $deviceData['ip'],
            'notes' => sprintf(
                "Region: %s | Country: %s | Site: %s | Added from Excel: %s",
                $deviceData['region'],
                $deviceData['country'],
                $deviceData['site'],
                date('Y-m-d H:i:s')
            ),
            'snmp_community' => $deviceData['snmp_community'] ?: ($opts['snmp_community'] ?? 'public'),
            'snmp_version' => $opts['snmp_version'] ?? Constants::SNMP_VERSION_2C,
            'snmp_username' => '',
            'snmp_password' => '',
            'snmp_auth_protocol' => '',
            'snmp_priv_passphrase' => '',
            'snmp_priv_protocol' => '',
            'snmp_context' => '',
            'snmp_port' => $opts['snmp_port'] ?? 161,
            'snmp_timeout' => $opts['snmp_timeout'] ?? 500,
            'disabled' => $opts['disabled'] ?? '',
            'availability_method' => $opts['availability_method'] ?? Constants::AVAILABILITY_SNMP,
            'ping_method' => $opts['ping_method'] ?? Constants::PING_ICMP,
            'ping_port' => 0,
            'ping_timeout' => $opts['ping_timeout'] ?? 500,
            'ping_retries' => $opts['ping_retries'] ?? 2,
            'max_oids' => $opts['max_oids'] ?? 10,
            'device_threads' => $opts['device_threads'] ?? 1,
            'poller_id' => $opts['poller_id'] ?? 1,
            'site_id' => $siteId,
            'external_id' => '',
            'location' => $location,
        ];

        try {
            $result = $this->transaction->execute(function () use ($save, $deviceData) {
                $deviceId = api_device_save(0, $save['host_template_id'], $save['description'],
                    $save['hostname'], $save['snmp_community'], $save['snmp_version'],
                    $save['snmp_username'], $save['snmp_password'], $save['snmp_port'],
                    $save['snmp_timeout'], $save['disabled'], $save['availability_method'],
                    $save['ping_method'], $save['ping_port'], $save['ping_timeout'],
                    $save['ping_retries'], $save['notes'], $save['snmp_auth_protocol'],
                    $save['snmp_priv_passphrase'], $save['snmp_priv_protocol'], $save['snmp_context'],
                    $save['max_oids'], $save['device_threads'], $save['poller_id'],
                    $save['site_id'], $save['external_id'], $save['location']);

                if (!$deviceId || $deviceId <= 0) {
                    throw new \Exception("api_device_save returned invalid device ID");
                }

                $details = [
                    'region' => $deviceData['region'],
                    'country' => $deviceData['country'],
                    'site' => $deviceData['site'],
                    'dev_function' => $deviceData['dev_function'],
                ];
                $this->logDeviceChange($deviceId, $deviceData['hostname'], Constants::ACTION_ADDED, json_encode($details));

                return $deviceId;
            });

            if ($result && $result > 0) {
                $this->logger->info(sprintf(
                    "Device added: ID=%d, Hostname=%s, IP=%s",
                    $result,
                    $deviceData['hostname'],
                    $deviceData['ip']
                ));
                return $result;
            } else {
                $this->logger->error(sprintf(
                    "Failed to add device: %s [%s]",
                    $deviceData['hostname'],
                    $deviceData['ip']
                ));
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                "Exception adding device %s [%s]: %s",
                $deviceData['hostname'],
                $deviceData['ip'],
                $e->getMessage()
            ));
            return false;
        }
    }
}
