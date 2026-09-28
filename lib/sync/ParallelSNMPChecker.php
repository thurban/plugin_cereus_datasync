<?php
/**
 * Parallel SNMP Checker for Cacti Device Synchronization
 *
 * Performs SNMP checks in parallel using process forking to dramatically
 * speed up device validation when dealing with slow/unreachable devices
 */

namespace CactiSync;

class ParallelSNMPChecker
{
    private $config;
    private $logger;
    private $maxProcesses;
    private $timeout;
    private $results = [];

    public function __construct($config, $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->maxProcesses = $config['performance']['parallel_snmp_processes'] ?? 10;
        $this->timeout = $config['snmp']['wan_check_timeout'] ?? 2000;
    }

    /**
     * Check if parallel processing is available
     *
     * @return bool
     */
    public function isAvailable()
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_wait');
    }

    /**
     * Perform SNMP checks in parallel
     *
     * @param array $devices Array of devices to check
     * @param callable $checkCallback Function to perform the check
     * @return array Results indexed by device identifier
     */
    public function checkDevicesParallel($devices, $checkCallback)
    {
        if (!$this->isAvailable()) {
            if ($this->logger) {
                $this->logger->warning("Parallel processing not available, falling back to sequential");
            }
            return $this->checkDevicesSequential($devices, $checkCallback);
        }

        if ($this->logger) {
            $this->logger->info(sprintf(
                "Starting parallel SNMP checks for %d devices (max %d concurrent processes)",
                count($devices),
                $this->maxProcesses
            ));
        }

        $startTime = microtime(true);
        $results = [];
        $activeProcesses = [];
        $deviceQueue = $devices;
        $completedCount = 0;

        while (!empty($deviceQueue) || !empty($activeProcesses)) {
            // Start new processes up to max limit
            while (count($activeProcesses) < $this->maxProcesses && !empty($deviceQueue)) {
                $device = array_shift($deviceQueue);
                $pid = $this->startCheckProcess($device, $checkCallback);

                if ($pid > 0) {
                    $activeProcesses[$pid] = [
                        'device' => $device,
                        'start_time' => microtime(true)
                    ];
                }
            }

            // Check for completed processes (non-blocking)
            foreach ($activeProcesses as $pid => $processInfo) {
                $status = null;
                $result = pcntl_waitpid($pid, $status, WNOHANG);

                if ($result > 0) {
                    // Process finished
                    $deviceKey = $this->getDeviceKey($processInfo['device']);
                    $results[$deviceKey] = $this->readProcessResult($pid);

                    $completedCount++;
                    $elapsed = microtime(true) - $processInfo['start_time'];

                    if ($this->logger) {
                        $this->logger->debug(sprintf(
                            "SNMP check completed for %s in %.2f seconds [%d/%d]",
                            $deviceKey,
                            $elapsed,
                            $completedCount,
                            count($devices)
                        ));
                    }

                    unset($activeProcesses[$pid]);
                } elseif ($result < 0) {
                    // Error occurred
                    if ($this->logger) {
                        $this->logger->error("Error waiting for process $pid");
                    }
                    unset($activeProcesses[$pid]);
                }
            }

            // Small sleep to prevent CPU spinning
            usleep(10000); // 10ms
        }

        $totalTime = microtime(true) - $startTime;

        if ($this->logger) {
            $this->logger->info(sprintf(
                "Parallel SNMP checks completed: %d devices in %.2f seconds (avg %.2f sec/device)",
                count($devices),
                $totalTime,
                count($devices) > 0 ? $totalTime / count($devices) : 0
            ));
        }

        return $results;
    }

    /**
     * Start a child process to perform SNMP check
     *
     * @param array $device
     * @param callable $checkCallback
     * @return int PID of child process
     */
    private function startCheckProcess($device, $checkCallback)
    {
        // Generate unique result file BEFORE fork to ensure both parent and child use same path
        $uniqueId = uniqid('snmp_', true);
        $resultFile = sys_get_temp_dir() . '/cacti_snmp_' . $uniqueId . '.json';

        $pid = pcntl_fork();

        if ($pid == -1) {
            // Fork failed
            if ($this->logger) {
                $this->logger->error("Failed to fork process for SNMP check");
            }
            return false;
        } elseif ($pid == 0) {
            // Child process
            try {
                $result = $checkCallback($device);
                $data = [
                    'device' => $this->getDeviceKey($device),
                    'result' => $result,
                    'pid' => getmypid(),
                    'success' => true
                ];

                // Write result file atomically
                $tempFile = $resultFile . '.tmp';
                file_put_contents($tempFile, json_encode($data));
                rename($tempFile, $resultFile);  // Atomic operation

            } catch (\Exception $e) {
                $data = [
                    'device' => $this->getDeviceKey($device),
                    'result' => ['error' => $e->getMessage(), 'has_wan' => false],
                    'pid' => getmypid(),
                    'success' => false
                ];

                $tempFile = $resultFile . '.tmp';
                file_put_contents($tempFile, json_encode($data));
                rename($tempFile, $resultFile);
            }
            exit(0); // Child must exit
        } else {
            // Parent process - store result file location
            $this->results[$pid] = $resultFile;
            return $pid;
        }
    }

    /**
     * Read result from child process
     *
     * @param int $pid
     * @return array
     */
    private function readProcessResult($pid)
    {
        if (!isset($this->results[$pid])) {
            if ($this->logger) {
                $this->logger->debug("No result file registered for PID $pid");
            }
            return ['error' => 'No result file registered', 'has_wan' => false];
        }

        $resultFile = $this->results[$pid];

        // Wait for result file to exist (with timeout)
        $maxWait = 5; // seconds
        $waited = 0;
        $interval = 0.1; // 100ms

        while (!file_exists($resultFile) && $waited < $maxWait) {
            usleep($interval * 1000000);
            $waited += $interval;
        }

        if (!file_exists($resultFile)) {
            if ($this->logger) {
                $this->logger->debug("SNMP check failed for PID $pid: Result file does not exist after {$maxWait}s wait");
            }
            unset($this->results[$pid]);
            return ['error' => 'Result file timeout', 'has_wan' => false];
        }

        // Read result
        $contents = @file_get_contents($resultFile);
        if ($contents === false) {
            if ($this->logger) {
                $this->logger->debug("Failed to read result file for PID $pid");
            }
            @unlink($resultFile);
            unset($this->results[$pid]);
            return ['error' => 'Failed to read result file', 'has_wan' => false];
        }

        $data = json_decode($contents, true);
        if ($data === null) {
            if ($this->logger) {
                $this->logger->debug("Invalid JSON in result file for PID $pid");
            }
            @unlink($resultFile);
            unset($this->results[$pid]);
            return ['error' => 'Invalid result format', 'has_wan' => false];
        }

        // Clean up
        @unlink($resultFile);
        @unlink($resultFile . '.tmp'); // Clean up temp file if exists
        unset($this->results[$pid]);

        return $data['result'] ?? ['error' => 'Missing result data', 'has_wan' => false];
    }

    /**
     * Fallback to sequential checking
     *
     * @param array $devices
     * @param callable $checkCallback
     * @return array
     */
    private function checkDevicesSequential($devices, $checkCallback)
    {
        if ($this->logger) {
            $this->logger->info("Using sequential SNMP checks");
        }

        $results = [];
        $startTime = microtime(true);

        foreach ($devices as $device) {
            $deviceKey = $this->getDeviceKey($device);
            $results[$deviceKey] = $checkCallback($device);
        }

        $totalTime = microtime(true) - $startTime;

        if ($this->logger) {
            $this->logger->info(sprintf(
                "Sequential SNMP checks completed: %d devices in %.2f seconds",
                count($devices),
                $totalTime
            ));
        }

        return $results;
    }

    /**
     * Get a unique key for a device
     *
     * @param array $device
     * @return string
     */
    private function getDeviceKey($device)
    {
        return $device['hostname'] ?? $device['ip'] ?? uniqid();
    }

    /**
     * Get statistics about current processing
     *
     * @return array
     */
    public function getStats()
    {
        return [
            'parallel_available' => $this->isAvailable(),
            'max_processes' => $this->maxProcesses,
            'timeout' => $this->timeout,
        ];
    }
}
