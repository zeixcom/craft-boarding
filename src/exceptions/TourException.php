<?php

namespace zeix\boarding\exceptions;

use Exception;

/**
 * TourException - Simplified exception handling for Boarding plugin
 *
 * Replaces the over-engineered exception hierarchy with simple, focused exceptions.
 * Uses Craft's standard error handling patterns.
 */
class TourException extends Exception
{
    /**
     * Create exception for tour not found
     *
     * @param int|string $tourId Tour ID
     * @return self
     */
    public static function notFound($tourId): self
    {
        return new self("Tour with ID {$tourId} not found", 404);
    }

    /**
     * Create exception for access denied
     *
     * @param string|null $reason Optional reason for denial
     * @return self
     */
    public static function accessDenied(?string $reason = null): self
    {
        $message = $reason ? "Access denied: {$reason}" : "Access denied to tour";
        return new self($message, 403);
    }

    /**
     * Create exception for validation failure
     *
     * @param array $errors Validation errors
     * @return self
     */
    public static function validationFailed(array $errors): self
    {
        return new self("Validation failed: " . implode(', ', $errors), 422);
    }

    /**
     * Create exception for save failure
     *
     * @param string|null $reason Optional reason for failure
     * @return self
     */
    public static function saveFailed(?string $reason = null): self
    {
        $message = $reason ? "Failed to save tour: {$reason}" : "Failed to save tour";
        return new self($message, 500);
    }

    /**
     * Create exception for delete failure
     *
     * @param int|string $tourId Tour ID
     * @return self
     */
    public static function deleteFailed($tourId): self
    {
        return new self("Failed to delete tour with ID {$tourId}", 500);
    }

    /**
     * Create exception for invalid operation
     *
     * @param string $operation Operation name
     * @return self
     */
    public static function invalidOperation(string $operation): self
    {
        return new self("Invalid operation: {$operation}", 400);
    }
}
