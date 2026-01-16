<?php

namespace App\Services\Employee;

use App\Database\Connection;
use App\Models\Employee;
use PDO;

class EmployeeRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function findByUserId(int $userId): ?Employee
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, user_id, hire_date, emergency_contact, pay_structure, skills, created_at, updated_at
             FROM employees
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['skills'] = $this->decodeSkills($row['skills'] ?? null);

        return new Employee($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsertByUserId(int $userId, array $data): Employee
    {
        $skills = $this->normalizeSkills($data['skills'] ?? null);
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO employees (user_id, hire_date, emergency_contact, pay_structure, skills, created_at, updated_at)
             VALUES (:user_id, :hire_date, :emergency_contact, :pay_structure, :skills, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 hire_date = VALUES(hire_date),
                 emergency_contact = VALUES(emergency_contact),
                 pay_structure = VALUES(pay_structure),
                 skills = VALUES(skills),
                 updated_at = NOW()'
        );

        $stmt->execute([
            'user_id' => $userId,
            'hire_date' => $this->normalizeNullableString($data['hire_date'] ?? null),
            'emergency_contact' => $this->normalizeNullableString($data['emergency_contact'] ?? null),
            'pay_structure' => $this->normalizeNullableString($data['pay_structure'] ?? null),
            'skills' => $skills !== null ? json_encode($skills) : null,
        ]);

        return $this->findByUserId($userId);
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $value
     * @return array<int, string>|null
     */
    private function normalizeSkills($value): ?array
    {
        if ($value === null) {
            return null;
        }

        $items = is_array($value) ? $value : [$value];
        $skills = array_values(array_filter(array_map(
            static fn ($skill) => trim((string) $skill),
            $items
        ), static fn ($skill) => $skill !== ''));

        return $skills;
    }

    /**
     * @param mixed $value
     * @return array<int, string>|null
     */
    private function decodeSkills($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn ($skill) => trim((string) $skill),
            $decoded
        ), static fn ($skill) => $skill !== ''));
    }
}
