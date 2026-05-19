<?php

namespace App\Services\Subcontractor;

use App\Models\Subcontractor;
use App\Models\SubcontractorPortalToken;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Notifications\NotificationDispatcher;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SubcontractorPortalPasswordSetupService
{
    private const TOKEN_BYTES = 32;
    private const TTL_HOURS = 72;
    private const CREDENTIAL_SESSION_DAYS = 30;

    public function __construct(
        private readonly SubcontractorRepository $subRepo,
        private readonly SubcontractorPortalPasswordSetupRepository $setupRepo,
        private readonly SubcontractorPortalTokenRepository $portalTokenRepo,
        private readonly AccessGate $gate,
        private readonly NotificationDispatcher $notifications,
    ) {
    }

    /**
     * @return array{email_sent: bool, email_error: ?string, expires_at: string, subcontractor_id: int, recipient: string}
     */
    public function sendSetupLink(
        User $actor,
        int $subcontractorId,
        string $baseUrl,
        ?string $shopName = null
    ): array {
        $this->gate->assert($actor, 'subcontractors.manage');

        $sub = $this->subRepo->findSubcontractor($subcontractorId);
        if ($sub === null) {
            throw new InvalidArgumentException("Subcontractor {$subcontractorId} not found");
        }

        return $this->sendSetupLinkForSubcontractor(
            $sub,
            $baseUrl,
            $actor->id ?? null,
            $shopName,
        );
    }

    /**
     * @return array{email_sent: bool, email_error: ?string, expires_at: string, subcontractor_id: int, recipient: string}
     */
    public function sendSetupLinkForSubcontractor(
        Subcontractor $sub,
        string $baseUrl,
        ?int $createdByUserId = null,
        ?string $shopName = null
    ): array {
        $email = trim((string) $sub->email);
        if (!$sub->portal_login_enabled) {
            throw new InvalidArgumentException('portal login must be enabled before sending a setup link');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('a valid subcontractor email is required before sending a setup link');
        }

        $plaintext = self::generatePlaintext();
        $expiresAt = (new DateTimeImmutable('+' . self::TTL_HOURS . ' hours'))->format('Y-m-d H:i:s');
        $this->setupRepo->cancelOutstandingForSubcontractor($sub->id);
        $this->setupRepo->create($sub->id, $plaintext, $expiresAt, $createdByUserId);

        $setupUrl = rtrim($baseUrl, '/') . '/sub-portal/setup-password?token=' . rawurlencode($plaintext);
        $emailSent = false;
        $emailError = null;
        try {
            $this->notifications->sendMail(
                'subcontractor.portal_password_setup',
                $email,
                [
                    'contact_name' => $sub->contact_name ?: $sub->company_name,
                    'company_name' => $sub->company_name,
                    'setup_url' => $setupUrl,
                    'expiry_hours' => (string) self::TTL_HOURS,
                    'shop_name' => $shopName ?: 'our team',
                ],
                'Set up your subcontractor portal password'
            );
            $emailSent = true;
        } catch (Throwable $e) {
            $emailError = $e->getMessage();
            error_log('Subcontractor portal setup email failed: ' . $emailError);
        }

        return [
            'email_sent' => $emailSent,
            'email_error' => $emailError,
            'expires_at' => $expiresAt,
            'subcontractor_id' => $sub->id,
            'recipient' => $email,
        ];
    }

    /**
     * @return array{subcontractor: Subcontractor, expires_at: string}
     */
    public function inspectToken(string $plaintext): array
    {
        $row = $this->setupRepo->findActiveByPlaintext($plaintext);
        if ($row === null) {
            throw new InvalidArgumentException('This setup link is invalid or expired.');
        }

        $sub = $this->subRepo->findSubcontractor((int) $row['subcontractor_id']);
        if ($sub === null || !$sub->portal_login_enabled) {
            throw new InvalidArgumentException('This setup link is invalid or expired.');
        }

        return [
            'subcontractor' => $sub,
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    /**
     * @return array{subcontractor: Subcontractor, token: SubcontractorPortalToken, plaintext: string}
     */
    public function completeSetup(string $plaintext, string $password, ?string $clientIp = null): array
    {
        $row = $this->setupRepo->findActiveByPlaintext($plaintext);
        if ($row === null) {
            throw new InvalidArgumentException('This setup link is invalid or expired.');
        }

        $sub = $this->subRepo->findSubcontractor((int) $row['subcontractor_id']);
        if ($sub === null || !$sub->portal_login_enabled) {
            throw new InvalidArgumentException('This setup link is invalid or expired.');
        }

        $cleanPassword = (string) $password;
        if (strlen($cleanPassword) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        $sub = $this->subRepo->updateSubcontractor($sub->id, [
            'portal_login_enabled' => true,
            'portal_password_hash' => password_hash($cleanPassword, PASSWORD_DEFAULT),
            'portal_password_updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->setupRepo->markUsed((int) $row['id']);
        $this->setupRepo->cancelOutstandingForSubcontractor($sub->id);

        $sessionPlaintext = self::generatePlaintext();
        $expiresAt = (new DateTimeImmutable('+' . self::CREDENTIAL_SESSION_DAYS . ' days'))
            ->format('Y-m-d H:i:s');
        $portalToken = $this->portalTokenRepo->create(
            $sub->id,
            $sessionPlaintext,
            'Password setup',
            $expiresAt,
            null,
        );
        $this->subRepo->recordPortalLogin($sub->id, $clientIp);
        $this->portalTokenRepo->recordUse($portalToken->id, $clientIp);

        return [
            'subcontractor' => $sub,
            'token' => $portalToken,
            'plaintext' => $sessionPlaintext,
        ];
    }

    private static function generatePlaintext(): string
    {
        try {
            return bin2hex(random_bytes(self::TOKEN_BYTES));
        } catch (Throwable $e) {
            throw new RuntimeException('random_bytes unavailable: ' . $e->getMessage(), 0, $e);
        }
    }
}
