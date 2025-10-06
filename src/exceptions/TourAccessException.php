<?php

namespace zeix\boarding\exceptions;

/**
 * Exception thrown when tour access is denied
 */
class TourAccessException extends BoardingException
{
    /**
     * @var string Exception category
     */
    protected string $category = 'tour_access';

    /**
     * Create a new TourAccessException
     * 
     * @param string $message Technical error message
     * @param array $context Additional context
     */
    public function __construct(string $message = "Access denied to tour", array $context = [])
    {
        $userMessage = "You don't have permission to access this tour.";
        
        parent::__construct($message, 403, null, $context, $userMessage);
    }

    /**
     * Create exception for missing permissions
     * 
     * @param string $permission Required permission
     * @param array $context Additional context
     * @return self
     */
    public static function missingPermission(string $permission, array $context = []): self
    {
        $message = "Missing required permission: {$permission}";
        $userMessage = "You don't have the required permission to perform this action.";
        
        $context['requiredPermission'] = $permission;
        
        $exception = new self($message, $context);
        $exception->setUserMessage($userMessage);
        
        return $exception;
    }

    /**
     * Create exception for user group restrictions
     * 
     * @param array $allowedGroups Allowed user groups
     * @param array $userGroups Current user's groups
     * @param array $context Additional context
     * @return self
     */
    public static function userGroupRestricted(array $allowedGroups, array $userGroups, array $context = []): self
    {
        $message = "User groups don't match tour restrictions";
        $userMessage = "This tour is not available to your user group.";
        
        $context['allowedGroups'] = $allowedGroups;
        $context['userGroups'] = $userGroups;
        
        $exception = new self($message, $context);
        $exception->setUserMessage($userMessage);
        
        return $exception;
    }

    /**
     * Create exception for site restrictions
     * 
     * @param int $currentSiteId Current site ID
     * @param array $enabledSites Sites where tour is enabled
     * @param array $context Additional context
     * @return self
     */
    public static function siteRestricted(int $currentSiteId, array $enabledSites, array $context = []): self
    {
        $message = "Tour is not enabled for current site: {$currentSiteId}";
        $userMessage = "This tour is not available on the current site.";
        
        $context['currentSiteId'] = $currentSiteId;
        $context['enabledSites'] = $enabledSites;
        
        $exception = new self($message, $context);
        $exception->setUserMessage($userMessage);
        
        return $exception;
    }

    /**
     * Create exception for disabled tours
     * 
     * @param string|int $tourId Tour ID
     * @param array $context Additional context
     * @return self
     */
    public static function tourDisabled($tourId, array $context = []): self
    {
        $message = "Tour is disabled: {$tourId}";
        $userMessage = "This tour is currently not available.";
        
        $context['tourId'] = $tourId;
        
        $exception = new self($message, $context);
        $exception->setUserMessage($userMessage);
        
        return $exception;
    }
}