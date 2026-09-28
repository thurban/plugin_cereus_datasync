<?php
/**
 * Constants for Cacti Device Synchronization
 *
 * Defines all magic numbers and constants used throughout the sync process
 */

namespace CactiSync;

class Constants
{
    // Cacti Availability Methods
    const AVAILABILITY_NONE = 0;
    const AVAILABILITY_SNMP = 2;
    const AVAILABILITY_ICMP = 3;
    const AVAILABILITY_SNMP_ICMP = 4;

    // Cacti Ping Methods
    const PING_NONE = 0;
    const PING_ICMP = 2;
    const PING_TCP = 3;
    const PING_UDP = 4;

    // SNMP Versions
    const SNMP_VERSION_1 = 1;
    const SNMP_VERSION_2C = 2;
    const SNMP_VERSION_3 = 3;

    // Tree Item Types
    const TREE_ITEM_TYPE_HEADER = 1;
    const TREE_ITEM_TYPE_GRAPH = 2;
    const TREE_ITEM_TYPE_HOST = 3;

    // Host Grouping Types
    const HOST_GROUPING_GRAPH_TEMPLATE = 1;
    const HOST_GROUPING_DATA_QUERY = 2;

    // Sort Types
    const SORT_MANUAL_ALPHABETIC = 1;
    const SORT_NATURAL = 2;
    const SORT_NUMERIC = 3;

    // Automation Rule Types
    const RULE_TYPE_GRAPH = 1;
    const RULE_TYPE_TREE = 2;
    const RULE_TYPE_TREE_OBJECT_SELECTION = 3;

    // Automation Operations
    const OPERATION_NONE = 0;
    const OPERATION_AND = 1;
    const OPERATION_OR = 2;

    // Automation Operators
    const OPERATOR_CONTAINS = 1;
    const OPERATOR_NOT_CONTAINS = 2;
    const OPERATOR_MATCHES = 3;
    const OPERATOR_NOT_MATCHES = 4;

    // Device Actions for Logging
    const ACTION_ADDED = 'added';
    const ACTION_UPDATED = 'updated';
    const ACTION_MARKED_DELETED = 'marked_deleted';
    const ACTION_UNMARKED = 'unmarked';
    const ACTION_DELETED = 'deleted';

    // Log Levels
    const LOG_DEBUG = 'DEBUG';
    const LOG_INFO = 'INFO';
    const LOG_WARNING = 'WARNING';
    const LOG_ERROR = 'ERROR';

    // Status Tags
    const TAG_TO_BE_DELETED = '[TO BE DELETED]';
    const TAG_DELETED = '[DELETED]';

    // Exit Codes
    const EXIT_SUCCESS = 0;
    const EXIT_ERROR_CONFIG = 1;
    const EXIT_ERROR_FILE_NOT_FOUND = 2;
    const EXIT_ERROR_DATABASE = 3;
    const EXIT_ERROR_VALIDATION = 4;
    const EXIT_ERROR_GENERAL = 99;
}
