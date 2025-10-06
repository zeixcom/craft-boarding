<?php

namespace zeix\boarding\exceptions;

use Throwable;

/**
 * Exception thrown when saving a tour fails
 */
class TourSaveException extends BoardingException
{
    /**
     * @var string Exception category
     */
    protected string $category = 'tour_save';

    /**
     * Create a new TourSaveException
     * 
     * @param string $message Technical error message
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "Failed to save tour", array $context = [], ?Throwable $previous = null)
    {
        $userMessage = "Unable to save the tour. Please try again.";
        
        parent::__construct($message, 500, $previous, $context, $userMessage);
    }

    /**
     * Create exception for validation failures
     * 
     * @param array $validationErrors Validation error messages
     * @param array $context Additional context
     * @return self
     */
    public static function validationFailed(array $validationErrors, array $context = []): self
    {
        $message = "Tour save failed due to validation errors: " . implode(', ', $validationErrors);
        $userMessage = "Please correct the following errors: " . implode(', ', $validationErrors);
        
        $context['validationErrors'] = $validationErrors;
        
        $exception = new self($message, $context);
        $exception->setUserMessage($userMessage);
        
        return $exception;
    }

    /**
     * Create exception for database failures
     * 
     * @param string $operation Database operation that failed
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     * @return self
     */
    public static function databaseFailed(string $operation, array $context = [], ?Throwable $previous = null): self
    {
        $message = "Database operation '{$operation}' failed during tour save";
        $userMessage = "A database error occurred while saving the tour.";
        
        $context['operation'] = $operation;
        
        $exception = new self($message, $context, $previous);
        $exception->setUserMessage($userMessage);
        
        return $exception;
    }

    /**
     * Create exception for missing required data
     * 
     * @param array $missingFields Required fields that are missing
     * @param array $context Additional context
     * @return self
     */
    public static function missingRequiredData(array $missingFields, array $context = []): self
    {
        $message = "Missing required data for tour save: " . implode(', ', $missingFields);
        $userMessage = "Please provide all required information: " . implode(', ', $missingFields);
        
        $context['missingFields'] = $missingFields;
        
        $exception = new self($message, $context);
        $exception->setUserMessage($userMessage);
        
        return $exception;
    }
}