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

    /**
     * R-02c — late-bound back-reference to the signer roster. We can't
     * inject it through the constructor because ContractSignerService
     * itself depends on this service (the invitation flow issues bound
     * links through us), so the wiring layer sets this after both
     * services are constructed. Optional — when null, capture works in
     * the legacy "open link" mode and never touches the roster.
     */
    private ?ContractSignerService $signerService = null;

    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractPublicLinkRepository $links,
        private readonly ContractSignatureRepository $signatures,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Wire the signer roster service in after construction (see the
     * $signerService property docblock for the circular-dep rationale).
     */
    public function setSignerService(?ContractSignerService $service): void
    {
        $this->signerService = $service;
    }

    /**
     * Issue a new signing link. Requires contracts.sign permission.
     * Returns the plaintext token once — only the hash is stored.
     *
     * When $signerEmail is non-null, the resulting link is *bound*: only a
     * signature payload whose signer_email matches (case-insensitive,
     * trimmed) will be accepted at capture time (R-02b). NULL keeps the
     * legacy "open link" behavior.
     *
     * @return array{link: ContractPublicLink, token: string, short_url: string, secure_url: string}
     */
    public function issueLink(
        User $user,
        int $contractId,
        string $baseUrl,
        ?string $expiresAt = null,
        ?string $signerEmail = null,
        ?int $signerInvitationId = null
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

        $normalizedEmail = self::normalizeEmail($signerEmail);

        // R-04 — snapshot the document hash + JSON now so the capture-time
        // verifier can detect mutation between issue and sign. Both are
        // stored on the link row; legacy in-flight links predating this
        // migration carry NULL for both columns and bypass enforcement
        // (the audit recommendation accepts that as the upgrade path).
        $snapshotJson = self::canonicalizeContractSnapshot($contract);
        $documentHashAtIssue = hash('sha256', $snapshotJson);

        $link = $this->links->create(
            $contractId,
            $hash,
            $shortCode,
            $expiresAt,
            $user->id ?? null,
            $normalizedEmail,
            $signerInvitationId,
            $documentHashAtIssue,
            $snapshotJson
        );

        $this->audit->log(new AuditEntry(
            'contract.link_issued',
            'contract',
            $contractId,
            (int) ($user->id ?? 0),
            [
                'link_id' => $link->id,
                'short_code' => $shortCode,
                'expires_at' => $expiresAt,
                'signer_email' => $normalizedEmail,
                'signer_invitation_id' => $signerInvitationId,
                'document_hash_at_issue' => $documentHashAtIssue,
            ]
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

        // AUD-064 — defend against link-replay impersonation. A link that
        // has already produced a signature is dead: re-using it to attach
        // additional signer identities would let anyone with the URL
        // (forwarded email, over-the-shoulder, MITM) graft fake signers
        // onto a contract that the real party already signed. Multi-party
        // co-signing is supported by issuing one link per signer (see
        // docs/audit-v2-recommendations.md).
        if ($link->consumed_at !== null) {
            throw new RuntimeException('This contract link has already been used to capture a signature.');
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

        // R-02b — when the link was issued with a bound signer_email, the
        // capture payload must declare the same address. We compare on the
        // trimmed/lowercased forms so trivial casing/whitespace differences
        // don't lock out a legitimate signer. A mismatch is logged as a
        // security event because the alternative — a stranger trying to
        // sign someone else's link — is the only realistic way to hit it.
        if ($link->signer_email !== null) {
            $bound = self::normalizeEmail($link->signer_email);
            $declared = self::normalizeEmail($payload['signer_email'] ?? null);
            if ($declared === null) {
                $this->audit->log(new AuditEntry(
                    'contract.signer_mismatch',
                    'contract',
                    $contract->id,
                    null,
                    [
                        'link_id' => $link->id,
                        'reason' => 'missing_signer_email',
                        'expected_signer_email' => $bound,
                        'ip_address' => $ipAddress,
                    ]
                ));
                throw new InvalidArgumentException(
                    'signer_email is required for this signing link.'
                );
            }
            if ($declared !== $bound) {
                $this->audit->log(new AuditEntry(
                    'contract.signer_mismatch',
                    'contract',
                    $contract->id,
                    null,
                    [
                        'link_id' => $link->id,
                        'reason' => 'email_mismatch',
                        'expected_signer_email' => $bound,
                        'attempted_signer_email' => $declared,
                        'ip_address' => $ipAddress,
                    ]
                ));
                throw new RuntimeException(
                    'This signing link is bound to a different signer.'
                );
            }
        }

        // R-04 — verify the document hasn't drifted between issue-time
        // and capture-time. We compute the comparison BEFORE the atomic
        // claim so a refused capture (default behaviour on mismatch)
        // doesn't burn the link — the admin can re-issue without losing
        // the audit trail. Only when the override is explicitly opted in
        // do we proceed past the gate.
        $currentDocumentHash = $this->hashContractSnapshot($contract);
        $issueDocumentHash = $link->document_hash_at_issue;
        $documentChangedAccepted = false;
        if ($issueDocumentHash !== null && !hash_equals($issueDocumentHash, $currentDocumentHash)) {
            $acceptChanges = !empty($payload['accept_document_changes']);
            $this->audit->log(new AuditEntry(
                $acceptChanges ? 'contract.document_changed_accepted' : 'contract.document_changed_refused',
                'contract',
                $contract->id,
                null,
                [
                    'link_id' => $link->id,
                    'document_hash_at_issue' => $issueDocumentHash,
                    'document_hash_at_capture' => $currentDocumentHash,
                    'ip_address' => $ipAddress,
                ]
            ));
            if (!$acceptChanges) {
                throw new RuntimeException(
                    'This contract was modified after the signing link was sent. '
                    . 'Request a fresh link from the issuer, or re-submit with '
                    . 'accept_document_changes=true to acknowledge the changes.'
                );
            }
            $documentChangedAccepted = true;
        }

        // AUD-064 — atomic claim closes the race window between the
        // optimistic check above and the signature INSERT below. If two
        // requests for the same link arrive simultaneously, only one of
        // them sees rowCount() === 1.
        if (!$this->links->claim($link->id)) {
            throw new RuntimeException('This contract link has already been used to capture a signature.');
        }

        $signedAt = date('Y-m-d H:i:s');
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
            'document_hash' => $currentDocumentHash,
            'document_hash_at_issue' => $issueDocumentHash,
            'document_changed_accepted' => $documentChangedAccepted,
            'signature_hash' => $signatureHash,
            'legal_consent' => (bool) $payload['legal_consent'],
            'consent_text' => $payload['consent_text'] ?? null,
            'comment' => $payload['comment'] ?? null,
            'signed_at' => $signedAt,
        ]);

        // AUD-064 — best-effort backfill of the link → signature pointer
        // for forensic linkage. The claim above is what enforces single-
        // use; this is purely informational.
        $this->links->attachSignature($link->id, (int) $signature->id);

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

        // R-02c — if this link was bound to an invitation roster entry,
        // stamp the signer row so the admin UI can show "Jane Doe ✓
        // signed at …". The marker is idempotent at the repo level so a
        // duplicate hook here (which shouldn't happen — the link's
        // single-use claim above prevents it) would still leave the
        // first signing fact intact.
        if ($this->signerService !== null && $link->signer_invitation_id !== null) {
            $this->signerService->markSignerCompleted(
                $link->signer_invitation_id,
                (int) $signature->id,
                $signedAt
            );
        }

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
        return hash('sha256', self::canonicalizeContractSnapshot($contract));
    }

    /**
     * R-04 — produce the canonical JSON snapshot used for both the
     * issue-time hash and the stored snapshot blob. Strips the volatile
     * timestamp columns and sorts keys so structurally-identical
     * documents hash to the same value across PHP runs.
     */
    private static function canonicalizeContractSnapshot(Contract $contract): string
    {
        $data = $contract->toArray();
        unset($data['updated_at'], $data['created_at']);
        ksort($data);
        return json_encode($data, JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    /**
     * Canonicalize an email for storage / comparison. Returns null for empty
     * input so callers can use the result directly as a "bound or open"
     * marker. Casing and surrounding whitespace are stripped — domain part
     * is case-insensitive per RFC and the local part is too in every real
     * mailer we hit, so we treat the whole address as case-insensitive.
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
