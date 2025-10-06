<?php

namespace zeix\boarding\exceptions;

use Throwable;

/**
 * Exception thrown when database operations fail
 */
class DatabaseException extends BoardingException
{
    /**
     * @var string Exception category
     */
    protected string $category = 'database';

    /**
     * Create a new DatabaseException
     * 
     * @param string $message Technical error message
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "Database operation failed", array $context = [], ?Throwable $previous = null)
    {
        $userMessage = "A database error occurred. Please try again.";

        parent::__construct($message, 500, $previous, $context, $userMessage);
    }

    /**
     * Create exception for connection failures
     * 
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     * @return self
     */
    public static function connectionFailed(array $context = [], ?Throwable $previous = null): self
    {
        $message = "Database connection failed";
        $userMessage = "Unable to connect to the database. Please try again later.";

        $exception = new self($message, $context, $previous);
        $exception->setUserMessage($userMessage);

        return $exception;
    }

    /**
     * Create exception for query failures
     * 
     * @param string $query SQL query that failed
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     * @return self
     */
    public static function queryFailed(string $query, array $context = [], ?Throwable $previous = null): self
    {
        $message = "Database query failed: " . substr($query, 0, 100) . (strlen($query) > 100 ? '...' : '');
        $userMessage = "A database query failed. Please try again.";

        $context['query'] = $query;

        $exception = new self($message, $context, $previous);
        $exception->setUserMessage($userMessage);

        return $exception;
    }

    /**
     * Create exception for transaction failures
     * 
     * @param string $operation Operation that failed
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     * @return self
     */
    public static function transactionFailed(string $operation, array $context = [], ?Throwable $previous = null): self
    {
        $message = "Database transaction failed during: {$operation}";
        $userMessage = "A database transaction failed. Changes have been rolled back.";

        $context['operation'] = $operation;

        $exception = new self($message, $context, $previous);
        $exception->setUserMessage($userMessage);

        return $exception;
    }
}
