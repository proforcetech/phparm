<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractPublicLink;
use App\Models\ContractSignature;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 4.2 of docs/expansion-plan.md: contract e-sign workflow.
 *
 * Two entry points:
 *   * issueLink(user, contractId, …)  — authenticated, emits shareable token
 *   * viewByToken()/viewByShortCode() — unauthenticated, resolves link +
 *                                       records last_accessed_at
 *   * captureSignature(token|shortCode, …) — unauthenticated, persists
 *                                       signature audit row AND flips the
 *                                       contract's state machine
 *
 * The state-machine semantics:
 *   * Signing is only meaningful on draft or pending_signature contracts.
 *   * The first captured signature sets contracts.signed_at + signer_ip /
 *     signer_user_agent / signature_data and transitions the contract to
 *     'active' (going through TRANSITIONS via the repo — both draft→active
 *     and pending_signature→active are allowed).
 *   * Additional signatures after activation are allowed (multi-party
 *     co-signers) but do not re-transition status.
 */
class ContractSigningService
{
    /** Bytes of entropy per token (base64url encoded). */
    private const TOKEN_BYTES = 32;

    /** Short-code length taken off the token hash. */
    private const SHORT_CODE_LEN = 10;

    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractPublicLinkRepository $links,
        private readonly ContractSignatureRepository $signatures,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Issue a new signing link. Requires contracts.sign permission.
     * Returns the plaintext token once — only the hash is stored.
     *
     * @return array{link: ContractPublicLink, token: string, short_url: string, secure_url: string}
     */
    public function issueLink(
        User $user,
        int $contractId,
        string $baseUrl,
        ?string $expiresAt = null
    ): array {
        $this->gate->assert($user, 'contracts.sign');
        $contract = $this->requireContract($contractId);
        if (in_array($contract->status, ['cancelled', 'renewed'], true)) {
            throw new InvalidArgumentException(
                "cannot issue signing link for contract in '{$contract->status}' status"
            );
        }

        $token = $this->generateToken();
        $hash = hash('sha256', $token);
        $shortCode = substr($hash, 0, self::SHORT_CODE_LEN);

        $link = $this->links->create(
            $contractId,
            $hash,
            $shortCode,
            $expiresAt,
            $user->id ?? null
        );

        $this->audit->log(new AuditEntry(
            'contract.link_issued',
            'contract',
            $contractId,
            (int) ($user->id ?? 0),
            ['link_id' => $link->id, 'short_code' => $shortCode, 'expires_at' => $expiresAt]
        ));

        $base = rtrim($baseUrl, '/');
        return [
            'link' => $link,
            'token' => $token,
            'short_url' => $base . '/c/' . $shortCode,
            'secure_url' => $base . '/contract/view?token=' . $token,
        ];
    }

    public function revokeLink(User $user, int $contractId, int $linkId): void
    {
        $this->gate->assert($user, 'contracts.sign');
        $link = $this->links->findById($linkId);
        if ($link === null || $link->contract_id !== $contractId) {
            throw new InvalidArgumentException("link {$linkId} not found for contract {$contractId}");
        }
        $this->links->revoke($linkId);
        $this->audit->log(new AuditEntry(
            'contract.link_revoked',
            'contract',
            $contractId,
            (int) ($user->id ?? 0),
            ['link_id' => $linkId]
        ));
    }

    /**
     * @return array<int, ContractPublicLink>
     */
    public function listLinks(User $user, int $contractId): array
    {
        $this->gate->assert($user, 'contracts.view');
        return $this->links->listForContract($contractId);
    }

    /**
     * @return array<int, ContractSignature>
     */
    public function listSignatures(User $user, int $contractId): array
    {
        $this->gate->assert($user, 'contracts.view');
        return $this->signatures->listForContract($contractId);
    }

    /**
     * Resolve a public link by token OR short code, enforce expiry/revocation,
     * touch last_accessed_at, and return { contract, link }.
     *
     * @return array{contract: Contract, link: ContractPublicLink}
     */
    public function fetchPublicView(?string $token, ?string $shortCode = null): array
    {
        $link = $this->resolveLink($token, $shortCode);
        $contract = $this->requireContract($link->contract_id);
        $this->links->touchLastAccessed($link->id);
        return ['contract' => $contract, 'link' => $link];
    }

    /**
     * Capture a signature via a public link. Transitions the contract to
     * 'active' on the first signature if it was draft/pending_signature.
     *
     * @param array{
     *   signer_name: string, signer_email?: ?string, signer_title?: ?string,
     *   signature_data: string, comment?: ?string, device_fingerprint?: ?string,
     *   legal_consent?: bool, consent_text?: ?string
     * } $payload
     */
    public function captureSignature(
        ?string $token,
        array $payload,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $shortCode = null
    ): ContractSignature {
        $link = $this->resolveLink($token, $shortCode);
        $contract = $this->requireContract($link->contract_id);

        if (in_array($contract->status, ['cancelled', 'renewed', 'expired'], true)) {
            throw new InvalidArgumentException(
                "contract in '{$contract->status}' status cannot be signed"
            );
        }

        $name = trim((string) ($payload['signer_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('signer_name is required');
        }
        $signatureData = (string) ($payload['signature_data'] ?? '');
        if (trim($signatureData) === '') {
            throw new InvalidArgumentException('signature_data is required');
        }
        if (empty($payload['legal_consent'])) {
            throw new InvalidArgumentException('legal_consent must be acknowledged');
        }

        $signedAt = date('Y-m-d H:i:s');
        $documentHash = $this->hashContractSnapshot($contract);
        $signatureHash = hash('sha256', $signatureData . '|' . $name . '|' . $signedAt);

        $signature = $this->signatures->create([
            'contract_id' => $contract->id,
            'signer_name' => $name,
            'signer_email' => $payload['signer_email'] ?? null,
            'signer_title' => $payload['signer_title'] ?? null,
            'signature_data' => $signatureData,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_fingerprint' => $payload['device_fingerprint'] ?? null,
            'document_hash' => $documentHash,
            'signature_hash' => $signatureHash,
            'legal_consent' => (bool) $payload['legal_consent'],
            'consent_text' => $payload['consent_text'] ?? null,
            'comment' => $payload['comment'] ?? null,
            'signed_at' => $signedAt,
        ]);

        // First signature activates the contract + stamps primary signer fields.
        if ($contract->signed_at === null) {
            $updates = [
                'signed_at' => $signedAt,
                'signer_ip' => $ipAddress,
                'signer_user_agent' => $userAgent !== null
                    ? substr($userAgent, 0, 255)
                    : null,
                'signature_data' => $signatureData,
            ];
            if (in_array($contract->status, ['draft', 'pending_signature'], true)) {
                $updates['status'] = 'active';
            }
            $this->contracts->update($contract->id, $updates);
        }

        $this->audit->log(new AuditEntry(
            'contract.signed',
            'contract',
            $contract->id,
            null,
            [
                'signature_id' => $signature->id,
                'signer_name' => $name,
                'link_id' => $link->id,
                'activated' => $contract->signed_at === null,
            ]
        ));

        return $signature;
    }

    /**
     * Attach a signature captured by an authenticated user (e.g. shop admin
     * signing on behalf during an in-person walkthrough). Still runs through
     * the full audit trail + state transition.
     *
     * @param array{
     *   signer_name: string, signer_email?: ?string, signer_title?: ?string,
     *   signature_data: string, comment?: ?string, device_fingerprint?: ?string,
     *   legal_consent?: bool, consent_text?: ?string
     * } $payload
     */
    public function captureInternalSignature(
        User $user,
        int $contractId,
        array $payload,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ContractSignature {
        $this->gate->assert($user, 'contracts.sign');
        $contract = $this->requireContract($contractId);
        if (in_array($contract->status, ['cancelled', 'renewed', 'expired'], true)) {
            throw new InvalidArgumentException(
                "contract in '{$contract->status}' status cannot be signed"
            );
        }

        $name = trim((string) ($payload['signer_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('signer_name is required');
        }
        $signatureData = (string) ($payload['signature_data'] ?? '');
        if (trim($signatureData) === '') {
            throw new InvalidArgumentException('signature_data is required');
        }
        if (empty($payload['legal_consent'])) {
            throw new InvalidArgumentException('legal_consent must be acknowledged');
        }

        $signedAt = date('Y-m-d H:i:s');
        $documentHash = $this->hashContractSnapshot($contract);
        $signatureHash = hash('sha256', $signatureData . '|' . $name . '|' . $signedAt);

        $signature = $this->signatures->create([
            'contract_id' => $contract->id,
            'signer_name' => $name,
            'signer_email' => $payload['signer_email'] ?? null,
            'signer_title' => $payload['signer_title'] ?? null,
            'signature_data' => $signatureData,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_fingerprint' => $payload['device_fingerprint'] ?? null,
            'document_hash' => $documentHash,
            'signature_hash' => $signatureHash,
            'legal_consent' => (bool) $payload['legal_consent'],
            'consent_text' => $payload['consent_text'] ?? null,
            'comment' => $payload['comment'] ?? null,
            'signed_at' => $signedAt,
        ]);

        if ($contract->signed_at === null) {
            $updates = [
                'signed_at' => $signedAt,
                'signer_ip' => $ipAddress,
                'signer_user_agent' => $userAgent !== null
                    ? substr($userAgent, 0, 255)
                    : null,
                'signature_data' => $signatureData,
            ];
            if (in_array($contract->status, ['draft', 'pending_signature'], true)) {
                $updates['status'] = 'active';
            }
            $this->contracts->update($contract->id, $updates);
        }

        $this->audit->log(new AuditEntry(
            'contract.signed',
            'contract',
            $contractId,
            (int) ($user->id ?? 0),
            [
                'signature_id' => $signature->id,
                'signer_name' => $name,
                'captured_by' => 'internal',
                'activated' => $contract->signed_at === null,
            ]
        ));

        return $signature;
    }

    private function resolveLink(?string $token, ?string $shortCode): ContractPublicLink
    {
        $link = null;
        if ($token !== null && $token !== '') {
            $link = $this->links->findByTokenHash(hash('sha256', $token));
        } elseif ($shortCode !== null && $shortCode !== '') {
            $link = $this->links->findByShortCode($shortCode);
        } else {
            throw new InvalidArgumentException('token or short_code is required');
        }
        if ($link === null) {
            throw new RuntimeException('Invalid or unknown contract link.');
        }
        if ($link->revoked_at !== null) {
            throw new RuntimeException('This contract link has been revoked.');
        }
        if ($link->expires_at !== null && strtotime($link->expires_at) < time()) {
            throw new RuntimeException('This contract link has expired.');
        }
        return $link;
    }

    private function requireContract(int $id): Contract
    {
        $contract = $this->contracts->findById($id);
        if ($contract === null) {
            throw new InvalidArgumentException("Contract {$id} not found");
        }
        return $contract;
    }

    private function hashContractSnapshot(Contract $contract): string
    {
        return hash('sha256', json_encode($contract->toArray(), JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }
}
