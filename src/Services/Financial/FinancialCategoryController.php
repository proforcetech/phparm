<?php

namespace App\Services\Financial;

use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use PDO;

class FinancialCategoryController
{
    private Connection $connection;
    private AccessGate $gate;

    public function __construct(Connection $connection, AccessGate $gate)
    {
        $this->connection = $connection;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view financial categories');
        }

        $sql = 'SELECT id, name, type FROM financial_categories';
        $params = [];

        if (!empty($filters['type'])) {
            $sql .= ' WHERE type = :type';
            $params['type'] = $filters['type'];
        }

        $sql .= ' ORDER BY type ASC, name ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
