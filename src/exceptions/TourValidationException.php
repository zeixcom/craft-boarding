<?php

namespace zeix\boarding\exceptions;

/**
 * Exception thrown when tour validation fails
 */
class TourValidationException extends BoardingException
{
    /**
     * @var string Exception category
     */
    protected string $category = 'tour_validation';

    /**
     * @var array Validation errors
     */
    protected array $validationErrors = [];

    /**
     * Create a new TourValidationException
     * 
     * @param array $errors Validation error messages
     * @param array $context Additional context
     */
    public function __construct(array $errors, array $context = [])
    {
        $this->validationErrors = $errors;
        
        $message = "Tour validation failed: " . implode(', ', $errors);
        $userMessage = "Please correct the following errors: " . implode(', ', $errors);
        
        $context['validationErrors'] = $errors;
        
        parent::__construct($message, 422, null, $context, $userMessage);
    }

    /**
     * Get validation errors
     * 
     * @return array
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Create exception for field validation
     * 
     * @param string $field Field name
     * @param string $error Error message
     * @param array $context Additional context
     * @return self
     */
    public static function forField(string $field, string $error, array $context = []): self
    {
        $context['field'] = $field;
        return new self([$field => $error], $context);
    }

    /**
     * Create exception for multiple field validation errors
     * 
     * @param array $fieldErrors Array of field => error pairs
     * @param array $context Additional context
     * @return self
     */
    public static function forFields(array $fieldErrors, array $context = []): self
    {
        $errors = [];
        foreach ($fieldErrors as $field => $error) {
            $errors[] = "{$field}: {$error}";
        }
        
        $context['fieldErrors'] = $fieldErrors;
        return new self($errors, $context);
    }

    /**
     * Create exception for step validation
     * 
     * @param int $stepIndex Step index that failed validation
     * @param string $error Error message
     * @param array $context Additional context
     * @return self
     */
    public static function forStep(int $stepIndex, string $error, array $context = []): self
    {
        $message = "Step " . ($stepIndex + 1) . ": {$error}";
        $context['stepIndex'] = $stepIndex;
        $context['stepNumber'] = $stepIndex + 1;
        
        return new self([$message], $context);
    }

    /**
     * Create exception for required fields
     * 
     * @param array $requiredFields Missing required fields
     * @param array $context Additional context
     * @return self
     */
    public static function requiredFields(array $requiredFields, array $context = []): self
    {
        $errors = array_map(function($field) {
            return "{$field} is required";
        }, $requiredFields);
        
        $context['requiredFields'] = $requiredFields;
        return new self($errors, $context);
    }
}