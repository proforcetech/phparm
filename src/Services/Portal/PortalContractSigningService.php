<?php

namespace App\Services\Portal;

use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\PortalAccount;
use App\Models\User;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSignatureRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Portal-scoped contract e-signing.
 *
 * Mirrors ContractSigningService::captureInternalSignature for the
 * authorization model the customer portal uses: ownership is established
 * by portal_account.company_id == contract.company_id (NOT by staff
 * AccessGate), and the SIGN_CONTRACTS permission is asserted via the
 * portal permission service so a viewer/requester tier cannot sign even
 * if they can read.
 *
 * Signature audit trail (ip, ua, document_hash, signature_hash,
 * legal_consent) is recorded the same way as the public + internal
 * paths, so disputes have a uniform forensic shape regardless of where
 * the signature was captured. The first signature transitions the
 * contract from draft / pending_signature to active and stamps
 * signed_at / signer_ip / signer_user_agent on the contract row;
 * subsequent co-signers append rows but do not re-transition.
 */
class PortalContractSigningService
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractSignatureRepository $signatures,
        private readonly PortalPermissionService $permissions,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array{
     *   signer_name: string, signer_email?: ?string, signer_title?: ?string,
     *   signature_data: string, comment?: ?string, device_fingerprint?: ?string,
     *   legal_consent?: bool, consent_text?: ?string
     * } $payload
     */
    public function sign(
        User $user,
        PortalAccount $account,
        int $contractId,
        array $payload,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ContractSignature {
        $this->assertUsable($account);
        $this->permissions->assert($account, PortalPermission::SIGN_CONTRACTS);
        $contract = $this->loadScopedContract($account, $contractId);

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
        $documentHash = hash(
            'sha256',
            json_encode($contract->toArray(), JSON_UNESCAPED_SLASHES) ?: ''
        );
        $signatureHash = hash('sha256', $signatureData . '|' . $name . '|' . $signedAt);

        $signature = $this->signatures->create([
            'contract_id' => $contract->id,
            'signer_name' => $name,
            'signer_email' => $payload['signer_email'] ?? $user->email ?? null,
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

        $wasFirst = $contract->signed_at === null;
        if ($wasFirst) {
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
            'portal.contract.signed',
            'contract',
            $contract->id,
            (int) ($user->id ?? 0),
            [
                'signature_id' => $signature->id,
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'signer_name' => $name,
                'activated' => $wasFirst,
            ]
        ));

        return $signature;
    }

    /**
     * Read-only signature audit for a contract the portal account owns.
     *
     * @return array<int, ContractSignature>
     */
    public function listSignatures(User $user, PortalAccount $account, int $contractId): array
    {
        $this->assertUsable($account);
        $this->loadScopedContract($account, $contractId);
        return $this->signatures->listForContract($contractId);
    }

    private function loadScopedContract(PortalAccount $account, int $contractId): Contract
    {
        if ($contractId <= 0) {
            throw new InvalidArgumentException('contract id is required');
        }
        $contract = $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new InvalidArgumentException("contract {$contractId} not found");
        }
        if ($contract->company_id !== $account->company_id) {
            // Don't reveal cross-tenant existence.
            throw new UnauthorizedException('contract belongs to a different company');
        }
        return $contract;
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }
}
