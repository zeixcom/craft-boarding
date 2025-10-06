<?php

namespace zeix\boarding\config;

/**
 * Configuration constants for import/export functionality
 */
class ImportConfig
{
    /**
     * Maximum file size in megabytes
     */
    public const MAX_FILE_SIZE_MB = 10;

    /**
     * Maximum file size in bytes
     */
    public const MAX_FILE_SIZE_BYTES = self::MAX_FILE_SIZE_MB * 1024 * 1024;

    /**
     * Allowed file extensions for import
     */
    public const ALLOWED_EXTENSIONS = ['json'];

    /**
     * Allowed MIME types for import
     */
    public const ALLOWED_MIME_TYPES = [
        'application/json',
        'text/plain',
        'application/octet-stream' // Some browsers may use this for .json files
    ];

    /**
     * Maximum JSON parsing depth
     */
    public const MAX_JSON_DEPTH = 32;

    /**
     * Valid progress position values
     */
    public const VALID_PROGRESS_POSITIONS = ['off', 'top', 'bottom', 'header', 'footer'];

    /**
     * Maximum number of tours allowed in a single import
     */
    public const MAX_TOURS_PER_IMPORT = 100;

    /**
     * Maximum number of steps per tour
     */
    public const MAX_STEPS_PER_TOUR = 50;
}
