<?php

namespace App\Services\CMS;

use App\Models\User;

/**
 * CMS Authentication Bridge
 *
 * Maps phparm users to CMS access levels and provides
 * unified authentication for CMS operations.
 */
class CMSAuthBridge
{
    /**
     * CMS role mapping from phparm roles
     */
    private const ROLE_MAP = [
        'admin' => 'admin',      // Full CMS access
        'manager' => 'admin',    // Full CMS access
        'technician' => 'editor', // Content editing only
        'customer' => null,      // No CMS access
    ];

    /**
     * Check if user has CMS access
     */
    public function hasCMSAccess(?User $user): bool
    {
        if ($user === null) {
            error_log('CMSAuthBridge: User is null');
            return false;
        }

        // Safely get the role, handling uninitialized typed properties
        $role = $this->getUserRole($user);

        if ($role === null) {
            error_log('CMSAuthBridge: User role is not set or empty');
            return false;
        }

        $hasAccess = isset(self::ROLE_MAP[$role]) && self::ROLE_MAP[$role] !== null;
        error_log("CMSAuthBridge: Checking access for role '{$role}' - Result: " . ($hasAccess ? 'granted' : 'denied'));

        return $hasAccess;
    }

    /**
     * Get CMS role for phparm user
     */
    public function getCMSRole(?User $user): ?string
    {
        $role = $this->getUserRole($user);
        if ($role === null) {
            return null;
        }

        return self::ROLE_MAP[$role] ?? null;
    }

    /**
     * Safely get the user's role, handling uninitialized typed properties
     */
    private function getUserRole(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        try {
            // Try to access the role property
            // This might throw an Error if the typed property is uninitialized
            $role = $user->role;
            return is_string($role) && $role !== '' ? $role : null;
        } catch (\Error $e) {
            error_log('CMSAuthBridge: Error accessing user role - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if user has CMS admin access
     */
    public function isCMSAdmin(?User $user): bool
    {
        return $this->getCMSRole($user) === 'admin';
    }

    /**
     * Check if user can edit CMS content
     */
    public function canEditContent(?User $user): bool
    {
        $role = $this->getCMSRole($user);
        return in_array($role, ['admin', 'editor'], true);
    }

    /**
     * Check if user can manage CMS settings
     */
    public function canManageSettings(?User $user): bool
    {
        return $this->isCMSAdmin($user);
    }

    /**
     * Check if user can manage CMS users
     */
    public function canManageUsers(?User $user): bool
    {
        return $this->isCMSAdmin($user);
    }

    /**
     * Initialize CMS session variables from phparm user
     * This bridges the phparm JWT auth to CMS session-based auth
     */
    public function initializeCMSSession(User $user): void
    {
        if (!$this->hasCMSAccess($user)) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Set CMS session variables to bridge authentication
        $_SESSION['cms_user_id'] = $user->id;
        $_SESSION['cms_username'] = $user->name;
        $_SESSION['cms_user_email'] = $user->email;
        $_SESSION['cms_user_role'] = $this->getCMSRole($user);
        $_SESSION['cms_logged_in_at'] = date('Y-m-d H:i:s');
    }

    /**
     * Clear CMS session
     */
    public function clearCMSSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        unset($_SESSION['cms_user_id']);
        unset($_SESSION['cms_username']);
        unset($_SESSION['cms_user_email']);
        unset($_SESSION['cms_user_role']);
        unset($_SESSION['cms_logged_in_at']);
    }
}
