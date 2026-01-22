<?php

namespace App\Services\ImportExport;

use App\Database\Connection;
use App\Services\Customer\CustomerRepository;
use App\Services\Customer\CustomerValidator;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class CustomerCsvService extends CsvImportService
{
    private CustomerRepository $repository;
    private CustomerValidator $validator;

    public function __construct(Connection $connection, ?AuditLogger $audit = null, ?CustomerRepository $repository = null, ?CustomerValidator $validator = null)
    {
        parent::__construct($connection, $audit);
        $this->validator = $validator ?? new CustomerValidator();
        $this->repository = $repository ?? new CustomerRepository($connection, $this->validator);
    }

    /**
     * @return array{created:int,updated:int,failed:int,errors:array<int,string>,dry_run:bool}
     */
    public function import(string $dataset, string $csv, int $actorId, bool $dryRun = false): array
    {
        $rows = $this->parseCsv($csv);
        $stats = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [], 'dry_run' => $dryRun];
        $seenEmails = [];
        $seenPhones = [];

        foreach ($rows as $index => $row) {
            try {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $phone = trim((string) ($row['phone'] ?? ''));

                if ($email !== '') {
                    if (isset($seenEmails[$email])) {
                        throw new InvalidArgumentException('Duplicate email in CSV file.');
                    }
                    $seenEmails[$email] = true;
                }

                if ($phone !== '') {
                    if (isset($seenPhones[$phone])) {
                        throw new InvalidArgumentException('Duplicate phone in CSV file.');
                    }
                    $seenPhones[$phone] = true;
                }

                $payload = $this->validator->validate($row);
                $payloadEmail = isset($payload['email']) ? strtolower((string) $payload['email']) : null;
                $payloadPhone = $payload['phone'] ?? null;

                $existingByEmail = $this->findCustomerByEmail($payloadEmail);
                $existingByPhone = $this->findCustomerByPhone($payloadPhone);

                if ($existingByEmail !== null && $existingByPhone !== null && $existingByEmail['id'] !== $existingByPhone['id']) {
                    throw new InvalidArgumentException('Email and phone belong to different customers.');
                }

                if ($existingByPhone !== null && $existingByEmail === null && $payloadEmail !== null) {
                    throw new InvalidArgumentException('Phone number is already assigned to another customer.');
                }

                $existingId = $existingByEmail['id'] ?? $existingByPhone['id'] ?? null;

                if ($existingId !== null) {
                    if (!$dryRun) {
                        $this->repository->update($existingId, $payload);
                    }
                    $stats['updated']++;
                    $this->log('import.customers', $existingId, $actorId, ['row' => $row, 'result' => 'updated', 'dry_run' => $dryRun]);
                    continue;
                }

                if (!$dryRun) {
                    $created = $this->repository->create($payload);
                    $this->log('import.customers', $created->id, $actorId, ['row' => $row, 'result' => 'created', 'dry_run' => $dryRun]);
                }
                $stats['created']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $rowNumber = $index + 2;
                $stats['errors'][] = "Row {$rowNumber}: {$e->getMessage()}";
            }
        }

        return $stats;
    }

    /**
     * @return array{id:int, email:string|null, phone:string|null}|null
     */
    private function findCustomerByEmail(?string $email): ?array
    {
        if ($email === null || $email === '') {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare('SELECT id, email, phone FROM customers WHERE LOWER(email) = :email LIMIT 1');
        $stmt->execute(['email' => strtolower($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ['id' => (int) $row['id'], 'email' => $row['email'], 'phone' => $row['phone']] : null;
    }

    /**
     * @return array{id:int, email:string|null, phone:string|null}|null
     */
    private function findCustomerByPhone(?string $phone): ?array
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare('SELECT id, email, phone FROM customers WHERE phone = :phone LIMIT 1');
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ['id' => (int) $row['id'], 'email' => $row['email'], 'phone' => $row['phone']] : null;
    }
}
