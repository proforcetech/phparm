<?php

namespace App\Services\Invoice;

use App\Database\Connection;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class InvoicePublicPaymentTokenService
{
    private const TOKEN_TTL_MINUTES = 15;

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array{token: string, amount: float, expires_at: string}
     */
    public function issueToken(int $invoiceId, float $amount): array
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $token = bin2hex(random_bytes(16));
        $hash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable('+' . self::TOKEN_TTL_MINUTES . ' minutes'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO invoice_public_payment_tokens (invoice_id, token_hash, amount, expires_at, created_at) '
            . 'VALUES (:invoice_id, :token_hash, :amount, :expires_at, NOW())'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'token_hash' => $hash,
            'amount' => $amount,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'amount' => $amount,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateToken(int $invoiceId, string $token): array
    {
        $hash = hash('sha256', $token);

        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM invoice_public_payment_tokens WHERE invoice_id = :invoice_id AND token_hash = :token_hash LIMIT 1'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'token_hash' => $hash,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Payment token is invalid.');
        }

        if (!empty($row['used_at'])) {
            throw new InvalidArgumentException('Payment token has already been used.');
        }

        if (isset($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            throw new InvalidArgumentException('Payment token has expired.');
        }

        return $row;
    }

    public function consumeToken(int $tokenId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE invoice_public_payment_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL'
        );
        $stmt->execute(['id' => $tokenId]);
    }
}
