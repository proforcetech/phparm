<?php

namespace App\Services\Contracts;

use App\Database\Connection;
use App\Models\ContractSignature;
use PDO;
use RuntimeException;

/**
 * Phase 4.2 of docs/expansion-plan.md: append-only signature audit trail
 * for contracts. Matches the estimate_signatures pattern — every captured
 * signature persists the IP / user agent / device fingerprint / document
 * hash / legal consent flag needed to defend the signature in a compliance
 * dispute.
 */
class ContractSignatureRepository
{
    private const COLUMNS = 'id, contract_id, signer_name, signer_email,
        signer_title, signature_data, ip_address, user_agent,
        device_fingerprint, document_hash, document_hash_at_issue,
        document_changed_accepted, signature_hash, legal_consent,
        consent_text, comment, signed_at, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): ContractSignature
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO contract_signatures (
                contract_id, signer_name, signer_email, signer_title,
                signature_data, ip_address, user_agent, device_fingerprint,
                document_hash, document_hash_at_issue, document_changed_accepted,
                signature_hash, legal_consent, consent_text,
                comment, signed_at
             ) VALUES (
                :cid, :name, :email, :title, :sig_data, :ip, :ua, :fp,
                :doc_hash, :doc_hash_issue, :doc_changed,
                :sig_hash, :consent, :consent_text, :comment, :signed_at
             )'
        );
        $stmt->execute([
            'cid' => (int) $data['contract_id'],
            'name' => (string) $data['signer_name'],
            'email' => $data['signer_email'] ?? null,
            'title' => $data['signer_title'] ?? null,
            'sig_data' => (string) $data['signature_data'],
            'ip' => $data['ip_address'] ?? null,
            'ua' => $data['user_agent'] ?? null,
            'fp' => $data['device_fingerprint'] ?? null,
            'doc_hash' => $data['document_hash'] ?? null,
            'doc_hash_issue' => $data['document_hash_at_issue'] ?? null,
            'doc_changed' => !empty($data['document_changed_accepted']) ? 1 : 0,
            'sig_hash' => $data['signature_hash'] ?? null,
            'consent' => !empty($data['legal_consent']) ? 1 : 0,
            'consent_text' => $data['consent_text'] ?? null,
            'comment' => $data['comment'] ?? null,
            'signed_at' => $data['signed_at'] ?? date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('contract_signatures insert did not return a row');
        }
        return $found;
    }

    public function findById(int $id): ?ContractSignature
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_signatures WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractSignature($row) : null;
    }

    /**
     * @return array<int, ContractSignature>
     */
    public function listForContract(int $contractId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_signatures
             WHERE contract_id = :cid ORDER BY signed_at DESC, id DESC'
        );
        $stmt->execute(['cid' => $contractId]);
        return array_map(
            static fn(array $r) => new ContractSignature($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function countForContract(int $contractId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM contract_signatures WHERE contract_id = :cid'
        );
        $stmt->execute(['cid' => $contractId]);
        return (int) $stmt->fetchColumn();
    }
}
