<?php

namespace App\Services\Contracts;

use App\Models\ContractPublicLink;
use App\Models\ContractSigner;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Notifications\NotificationDispatcher;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * R-02c — first-class multi-party signing.
 *
 * Replaces the legacy "issue N anonymous links" pattern with an explicit
 * invitation roster. Each invite:
 *   1. Creates a contract_signers row keyed by email.
 *   2. Issues a bound contract_public_links row via
 *      {@see ContractSigningService::issueLink()} with signer_email
 *      populated, so capture-time enforcement (R-02b) refuses anyone but
 *      the named signer.
 *   3. Optionally dispatches an invitation email.
 *
 * The service is the source of truth for the active-signer invariant
 * (no two non-revoked signers may share an email on the same contract),
 * since MySQL can't express that as a partial unique index without
 * generated-column gymnastics.
 *
 * Signing completion is wired back by
 * {@see ContractSigningService::captureSignature()}, which calls
 * {@see self::markSignerCompleted()} after a successful capture against
 * a bound link whose signer_invitation_id points at us.
 */
class ContractSignerService
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractSignerRepository $signers,
        private readonly ContractSigningService $signing,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
        private readonly ?NotificationDispatcher $notifications = null,
    ) {
    }

    /**
     * Invite a signer. Creates the signer row first so we can stamp its
     * id into the bound link's signer_invitation_id; this gives us a
     * back-reference for capture-time signer marking without a second
     * UPDATE pass.
     *
     * The optional email dispatch is best-effort — a mailer outage must
     * not abort an otherwise-valid invitation, because the admin can
     * always copy the short URL manually from the response payload.
     *
     * @return array{
     *     signer: ContractSigner,
     *     link: ContractPublicLink,
     *     token: string,
     *     short_url: string,
     *     secure_url: string,
     *     email_sent: bool,
     *     email_error: ?string
     * }
     */
    public function invite(
        User $user,
        int $contractId,
        string $baseUrl,
        array $payload
    ): array {
        $this->gate->assert($user, 'contracts.sign');
        $contract = $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new InvalidArgumentException("Contract {$contractId} not found");
        }
        if (in_array($contract->status, ['cancelled', 'renewed', 'expired'], true)) {
            throw new InvalidArgumentException(
                "cannot invite signers to a contract in '{$contract->status}' status"
            );
        }

        $email = self::normalizeEmail($payload['email'] ?? null);
        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required to invite a signer.');
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Signer name is required.');
        }
        $title = isset($payload['title']) && is_string($payload['title']) && trim($payload['title']) !== ''
            ? trim($payload['title'])
            : null;
        $notes = isset($payload['notes']) && is_string($payload['notes']) && trim($payload['notes']) !== ''
            ? trim($payload['notes'])
            : null;
        $expiresAt = isset($payload['expires_at']) && is_string($payload['expires_at']) && $payload['expires_at'] !== ''
            ? $payload['expires_at']
            : null;
        $sendEmail = array_key_exists('send_email', $payload)
            ? (bool) $payload['send_email']
            : true;

        // Active-signer invariant. The DB can't enforce this so we do it
        // here. A revoked record with the same email is fine — it leaves
        // the audit trail intact and the new invite gets a fresh row.
        if ($this->signers->findActiveByEmail($contractId, $email) !== null) {
            throw new InvalidArgumentException(
                "An active signer with email {$email} already exists on this contract. " .
                'Revoke the existing invite before re-issuing.'
            );
        }

        $displayOrder = $this->signers->nextDisplayOrder($contractId);
        $signer = $this->signers->create(
            $contractId,
            $email,
            $name,
            $title,
            $displayOrder,
            $user->id ?? null,
            $notes
        );

        $linkResult = $this->signing->issueLink(
            $user,
            $contractId,
            $baseUrl,
            $expiresAt,
            $email,
            $signer->id
        );

        $this->audit->log(new AuditEntry(
            'contract.signer_invited',
            'contract',
            $contractId,
            (int) ($user->id ?? 0),
            [
                'signer_id' => $signer->id,
                'signer_email' => $email,
                'signer_name' => $name,
                'link_id' => $linkResult['link']->id,
                'display_order' => $displayOrder,
            ]
        ));

        $emailSent = false;
        $emailError = null;
        if ($sendEmail && $this->notifications !== null) {
            try {
                $this->notifications->sendMail(
                    'contract.signer_invitation',
                    $email,
                    [
                        'signer_name' => $name,
                        'contract_number' => $contract->contract_number ?? '',
                        'contract_title' => $contract->title ?? '',
                        'sign_url' => $linkResult['short_url'],
                        'expires_note' => $expiresAt !== null
                            ? "This link expires on {$expiresAt}."
                            : '',
                    ],
                    'You have been invited to sign a contract'
                );
                $emailSent = true;
            } catch (Throwable $e) {
                $emailError = $e->getMessage();
                // Best-effort — keep the invitation valid. The admin sees
                // email_sent=false in the response and can resend.
            }
        }

        return [
            'signer' => $signer,
            'link' => $linkResult['link'],
            'token' => $linkResult['token'],
            'short_url' => $linkResult['short_url'],
            'secure_url' => $linkResult['secure_url'],
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ];
    }

    /**
     * @return array<int, ContractSigner>
     */
    public function listForContract(User $user, int $contractId, bool $includeRevoked = true): array
    {
        $this->gate->assert($user, 'contracts.view');
        return $this->signers->listForContract($contractId, $includeRevoked);
    }

    /**
     * Revoke a signer invitation. Cascades to revoke the bound public
     * link too — leaving the link active after revoking the signer would
     * defeat the purpose, since the link still names the same address.
     * If the signer has already signed, we refuse: there's nothing to
     * revoke and the signature is permanent.
     */
    public function revoke(User $user, int $contractId, int $signerId): void
    {
        $this->gate->assert($user, 'contracts.sign');
        $signer = $this->signers->findById($signerId);
        if ($signer === null || $signer->contract_id !== $contractId) {
            throw new InvalidArgumentException("signer {$signerId} not found for contract {$contractId}");
        }
        if ($signer->signed_at !== null) {
            throw new InvalidArgumentException(
                'Cannot revoke a signer who has already signed; the signature is permanent.'
            );
        }
        if ($signer->revoked_at !== null) {
            // Idempotent — already revoked, no-op.
            return;
        }

        $this->signers->revoke($signerId);

        // Cascade-revoke any non-revoked, non-consumed links bound to
        // this signer. We don't trust the DB to do it; if someone re-
        // wires the link table later we still want the invariant.
        foreach ($this->signing->listLinks($user, $contractId) as $link) {
            if ($link->signer_invitation_id === $signerId
                && $link->revoked_at === null
                && $link->consumed_at === null
            ) {
                $this->signing->revokeLink($user, $contractId, $link->id);
            }
        }

        $this->audit->log(new AuditEntry(
            'contract.signer_revoked',
            'contract',
            $contractId,
            (int) ($user->id ?? 0),
            ['signer_id' => $signerId, 'signer_email' => $signer->email]
        ));
    }

    /**
     * Called by ContractSigningService::captureSignature after a successful
     * signature capture against a bound link with signer_invitation_id set.
     * Stamps the signer row with the resulting signature id + timestamp.
     *
     * Idempotent at the repository level (the UPDATE matches on
     * signed_signature_id IS NULL) so a duplicate call after a retry
     * cannot silently overwrite the first signing fact.
     */
    public function markSignerCompleted(int $signerId, int $signatureId, string $signedAt): void
    {
        $signer = $this->signers->findById($signerId);
        if ($signer === null) {
            // Stale invitation reference — nothing to do. The audit log
            // already shows the capture; no need to fail it.
            return;
        }
        $this->signers->markSigned($signerId, $signatureId, $signedAt);
        $this->audit->log(new AuditEntry(
            'contract.signer_signed',
            'contract',
            $signer->contract_id,
            null,
            [
                'signer_id' => $signerId,
                'signature_id' => $signatureId,
                'signer_email' => $signer->email,
                'signed_at' => $signedAt,
            ]
        ));
    }

    /**
     * Canonicalize an email for storage / comparison. Matches the
     * normalization in ContractSigningService so a single round-trip
     * keeps the bound email aligned with the signer row.
     */
    private static function normalizeEmail(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        return mb_strtolower($trimmed);
    }
}
