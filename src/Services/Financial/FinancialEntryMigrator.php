<?php

namespace App\Services\Financial;

use App\Database\Connection;
use PDO;

class FinancialEntryMigrator
{
    private Connection $connection;
    private string $basePath;

    public function __construct(Connection $connection, string $basePath)
    {
        $this->connection = $connection;
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Attempt to backfill missing vendor/reference/purchase order/category fields
     * from legacy metadata or receipt attachments. Returns the number of rows updated
     * along with a list of entry ids that still lack hints after processing.
     *
     * @return array{updated:int, missing:array<int,int>}
     */
    public function migrate(): array
    {
        $pdo = $this->connection->pdo();
        $hasMetadataColumn = $this->columnExists($pdo, 'financial_entries', 'metadata');

        $select = $pdo->query('SELECT * FROM financial_entries');
        $update = $pdo->prepare(
            'UPDATE financial_entries SET category = :category, reference = :reference, purchase_order = :purchase_order, vendor = :vendor, description = :description WHERE id = :id'
        );

        $updated = 0;
        $missing = [];

        while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
            $hints = $this->mergeHints(
                $hasMetadataColumn ? $this->decodeJson($row['metadata'] ?? null) : [],
                $this->attachmentHints($row['attachment_path'] ?? null)
            );

            $category = $this->coalesce([$row['category'] ?? null, $hints['category'] ?? null, $hints['type'] ?? null, 'uncategorized']);
            $reference = $this->coalesce([
                $row['reference'] ?? null,
                $hints['reference'] ?? null,
                $hints['ref'] ?? null,
                $hints['invoice'] ?? null,
                sprintf('REF-%d', $row['id'] ?? 0),
            ]);
            $purchaseOrder = $this->coalesce([
                $row['purchase_order'] ?? null,
                $hints['purchase_order'] ?? null,
                $reference,
                sprintf('PO-%d', $row['id'] ?? 0),
            ]);
            $vendor = $this->coalesce([
                $row['vendor'] ?? null,
                $hints['vendor'] ?? null,
                $hints['vendor_name'] ?? null,
                $hints['supplier'] ?? null,
                'Unknown vendor',
            ]);
            $description = $this->coalesce([
                $row['description'] ?? null,
                $hints['description'] ?? null,
                $hints['notes'] ?? null,
            ], allowEmpty: true);

            $needsUpdate = ($row['category'] ?? null) !== $category
                || ($row['reference'] ?? null) !== $reference
                || ($row['purchase_order'] ?? null) !== $purchaseOrder
                || ($row['vendor'] ?? null) !== $vendor
                || ($row['description'] ?? null) !== $description;

            if ($needsUpdate) {
                $update->execute([
                    'id' => $row['id'],
                    'category' => $category,
                    'reference' => $reference,
                    'purchase_order' => $purchaseOrder,
                    'vendor' => $vendor,
                    'description' => $description,
                ]);
                $updated++;
            }

            if (empty($hints)) {
                $missing[] = (int) $row['id'];
            }
        }

        return ['updated' => $updated, 'missing' => $missing];
    }

    private function attachmentHints(?string $path): array
    {
        if (empty($path)) {
            return [];
        }

        $candidate = $this->basePath . '/' . ltrim($path, '/');
        if (!is_file($candidate)) {
            return [];
        }

        $contents = @file_get_contents($candidate);
        if ($contents === false) {
            return [];
        }

        return $this->decodeJson($contents);
    }

    private function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function mergeHints(array ...$hintGroups): array
    {
        $merged = [];
        foreach ($hintGroups as $hints) {
            foreach ($hints as $key => $value) {
                if ($value !== null && $value !== '' && !array_key_exists($key, $merged)) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function coalesce(array $values, bool $allowEmpty = false): mixed
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (!$allowEmpty && $value === '') {
                continue;
            }

            return $value;
        }

        return $allowEmpty ? '' : null;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_name = :table AND column_name = :column AND table_schema = DATABASE()');
        $stmt->execute(['table' => $table, 'column' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
