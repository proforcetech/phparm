<?php

namespace App\Services\Portal;

use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 6.1 of docs/expansion-plan.md — HTTP facade over PortalAuthService.
 *
 * Keeps route handlers in routes/modules/portal.php trivially thin: the
 * Router maps request → controller method, the controller orchestrates
 * validation + service calls, and the service owns the invariants.
 */
class PortalController
{
    public function __construct(
        private readonly PortalAuthService $auth,
        private readonly PortalAccountRepository $accounts,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function login(array $input, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($email === '' || $password === '') {
            throw new InvalidArgumentException('email and password are required');
        }
        $result = $this->auth->login($email, $password, $ipAddress, $userAgent);
        if ($result === null) {
            throw new InvalidArgumentException('invalid portal credentials');
        }
        return [
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'expires_in' => $result['expires_in'],
            'token_type' => 'Bearer',
            'user' => $this->serializeUser($result['user']),
            'portal_account' => $this->serializeAccount($result['portal_account']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function me(User $user, int $portalAccountId): array
    {
        $account = $this->accounts->findById($portalAccountId);
        if ($account === null) {
            throw new InvalidArgumentException('portal_account not found');
        }
        return [
            'user' => $this->serializeUser($user),
            'portal_account' => $this->serializeAccount($account),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function provision(User $actor, array $input): array
    {
        $account = $this->auth->provisionAccount($actor, $input);
        return $this->serializeAccount($account);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateAccount(User $actor, int $accountId, array $input): array
    {
        $account = $this->auth->updateAccount($actor, $accountId, $input);
        return $this->serializeAccount($account);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function revoke(User $actor, int $accountId, array $input): void
    {
        $reason = trim((string) ($input['reason'] ?? 'revoked_by_admin'));
        if ($reason === '') {
            $reason = 'revoked_by_admin';
        }
        $this->auth->revokeAccount($actor, $accountId, $reason);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForCompany(User $actor, int $companyId, bool $activeOnly = false): array
    {
        $this->auth->assertListAccess($actor);
        $accounts = $this->accounts->listByCompany($companyId, $activeOnly);
        return array_map(fn($a) => $this->serializeAccount($a), $accounts);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAccount(\App\Models\PortalAccount $account): array
    {
        return [
            'id' => $account->id,
            'user_id' => $account->user_id,
            'company_id' => $account->company_id,
            'allowed_site_ids' => $account->allowed_site_ids,
            'is_active' => $account->is_active,
            'provisioned_by_user_id' => $account->provisioned_by_user_id,
            'provisioned_at' => $account->provisioned_at,
            'last_login_at' => $account->last_login_at,
            'revoked_at' => $account->revoked_at,
            'revoked_reason' => $account->revoked_reason,
            'notes' => $account->notes,
        ];
    }
}
