<?php

namespace zeix\boarding\exceptions;

use Exception;
use Throwable;

/**
 * Base exception class for the Boarding plugin
 * 
 * Provides context management, user-friendly messages, and logging control
 */
class BoardingException extends Exception
{
    /**
     * @var array Additional context data for debugging
     */
    protected array $context = [];

    /**
     * @var string|null User-friendly message for display
     */
    protected ?string $userMessage = null;

    /**
     * @var bool Whether this exception should be logged
     */
    protected bool $shouldLog = true;

    /**
     * @var string Exception category for tracking
     */
    protected string $category = 'general';

    /**
     * Create a new BoardingException
     * 
     * @param string $message Technical error message
     * @param int $code Error code
     * @param Throwable|null $previous Previous exception
     * @param array $context Additional context data
     * @param string|null $userMessage User-friendly message
     */
    public function __construct(
        string $message = "", 
        int $code = 0, 
        ?Throwable $previous = null,
        array $context = [],
        ?string $userMessage = null
    ) {
        parent::__construct($message, $code, $previous);
        
        $this->context = $context;
        $this->userMessage = $userMessage;
    }

    /**
     * Get additional context data
     * 
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Add context data to the exception
     * 
     * @param string $key Context key
     * @param mixed $value Context value
     * @return self
     */
    public function addContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Set multiple context values at once
     * 
     * @param array $context Context data
     * @return self
     */
    public function setContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * Get user-friendly message for display
     * 
     * @return string
     */
    public function getUserMessage(): string
    {
        return $this->userMessage ?? $this->getMessage();
    }

    /**
     * Set user-friendly message
     * 
     * @param string $message User message
     * @return self
     */
    public function setUserMessage(string $message): self
    {
        $this->userMessage = $message;
        return $this;
    }

    /**
     * Check if this exception should be logged
     * 
     * @return bool
     */
    public function shouldLog(): bool
    {
        return $this->shouldLog;
    }

    /**
     * Set whether this exception should be logged
     * 
     * @param bool $shouldLog
     * @return self
     */
    public function setShouldLog(bool $shouldLog): self
    {
        $this->shouldLog = $shouldLog;
        return $this;
    }

    /**
     * Get exception category
     * 
     * @return string
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * Set exception category
     * 
     * @param string $category
     * @return self
     */
    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    /**
     * Convert exception to array for logging or API responses
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'userMessage' => $this->getUserMessage(),
            'code' => $this->getCode(),
            'category' => $this->getCategory(),
            'context' => $this->getContext(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString()
        ];
    }

    /**
     * Create a static factory method for quick exception creation
     * 
     * @param string $message Technical message
     * @param array $context Context data
     * @param string|null $userMessage User-friendly message
     * @return self
     */
    public static function create(string $message, array $context = [], ?string $userMessage = null): self
    {
        return new self($message, 0, null, $context, $userMessage);
    }
}