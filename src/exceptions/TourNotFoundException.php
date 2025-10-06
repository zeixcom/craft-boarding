<?php

namespace zeix\boarding\exceptions;

/**
 * Exception thrown when a tour is not found
 */
class TourNotFoundException extends BoardingException
{
    /**
     * @var string Exception category
     */
    protected string $category = 'tour_not_found';

    /**
     * Create a new TourNotFoundException
     * 
     * @param string|int $tourId Tour ID that was not found
     * @param array $context Additional context
     */
    public function __construct($tourId, array $context = [])
    {
        $message = "Tour not found with ID: {$tourId}";
        $userMessage = "The requested tour could not be found.";
        
        $context['tourId'] = $tourId;
        
        parent::__construct($message, 404, null, $context, $userMessage);
    }

    /**
     * Create exception for tour ID
     * 
     * @param string|int $tourId Tour ID
     * @param array $context Additional context
     * @return self
     */
    public static function forTourId($tourId, array $context = []): self
    {
        return new self($tourId, $context);
    }

    /**
     * Create exception for tour with specific context
     * 
     * @param string|int $tourId Tour ID
     * @param string $operation Operation that failed
     * @param array $context Additional context
     * @return self
     */
    public static function forOperation($tourId, string $operation, array $context = []): self
    {
        $exception = new self($tourId, $context);
        $exception->setUserMessage("Tour not found for {$operation} operation.");
        $exception->addContext('operation', $operation);
        
        return $exception;
    }
}