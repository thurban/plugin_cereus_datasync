<?php
/**
 * Database Transaction Handler for Cacti Device Synchronization
 *
 * Provides transaction support for multi-step database operations
 */

namespace CactiSync;

class DatabaseTransaction
{
    private $logger;
    private $inTransaction = false;

    public function __construct($logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Begin a database transaction
     *
     * @return bool
     */
    public function begin()
    {
        if ($this->inTransaction) {
            if ($this->logger) {
                $this->logger->warning("Transaction already started");
            }
            return false;
        }

        try {
            db_execute("START TRANSACTION");
            $this->inTransaction = true;

            if ($this->logger) {
                $this->logger->debug("Transaction started");
            }

            return true;
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to start transaction: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Commit a database transaction
     *
     * @return bool
     */
    public function commit()
    {
        if (!$this->inTransaction) {
            if ($this->logger) {
                $this->logger->warning("No transaction to commit");
            }
            return false;
        }

        try {
            db_execute("COMMIT");
            $this->inTransaction = false;

            if ($this->logger) {
                $this->logger->debug("Transaction committed");
            }

            return true;
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to commit transaction: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Rollback a database transaction
     *
     * @return bool
     */
    public function rollback()
    {
        if (!$this->inTransaction) {
            if ($this->logger) {
                $this->logger->warning("No transaction to rollback");
            }
            return false;
        }

        try {
            db_execute("ROLLBACK");
            $this->inTransaction = false;

            if ($this->logger) {
                $this->logger->debug("Transaction rolled back");
            }

            return true;
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to rollback transaction: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Execute a callback within a transaction
     *
     * @param callable $callback
     * @return mixed Result of callback or false on failure
     */
    public function execute($callback)
    {
        if (!$this->begin()) {
            return false;
        }

        try {
            $result = $callback();

            if ($result === false) {
                $this->rollback();
                if ($this->logger) {
                    $this->logger->warning("Transaction rolled back due to callback returning false");
                }
                return false;
            }

            if (!$this->commit()) {
                $this->rollback();
                return false;
            }

            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            if ($this->logger) {
                $this->logger->error("Transaction failed: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Check if currently in a transaction
     *
     * @return bool
     */
    public function isInTransaction()
    {
        return $this->inTransaction;
    }
}
