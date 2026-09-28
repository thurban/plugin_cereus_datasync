<?php
/**
 * Exception Classes for Cacti Device Synchronization
 *
 * Replaces die() calls with proper exception handling
 */

namespace CactiSync;

/**
 * Base exception for all sync-related errors
 */
class SyncException extends \Exception
{
    protected $exitCode = Constants::EXIT_ERROR_GENERAL;

    public function getExitCode()
    {
        return $this->exitCode;
    }
}

/**
 * Configuration error
 */
class ConfigException extends SyncException
{
    protected $exitCode = Constants::EXIT_ERROR_CONFIG;
}

/**
 * File not found error
 */
class FileNotFoundException extends SyncException
{
    protected $exitCode = Constants::EXIT_ERROR_FILE_NOT_FOUND;
}

/**
 * Database error
 */
class DatabaseException extends SyncException
{
    protected $exitCode = Constants::EXIT_ERROR_DATABASE;
}

/**
 * Validation error
 */
class ValidationException extends SyncException
{
    protected $exitCode = Constants::EXIT_ERROR_VALIDATION;
}

/**
 * Excel file error
 */
class ExcelException extends SyncException
{
    protected $exitCode = Constants::EXIT_ERROR_FILE_NOT_FOUND;
}

/**
 * SNMP error
 */
class SNMPException extends SyncException
{
}
