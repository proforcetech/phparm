<?php

namespace App\Services\Workorder\Kit;

use App\Database\Connection;
use App\Models\WorkorderKitInstall;
use App\Models\WorkorderKitInstallItem;
use PDO;

class WorkorderKitInstallRepository
{
    private const COLUMNS = [
        'id',
        'workorder_id',
        'workorder_job_id',
        'bundle_id',
        'bundle_name_snapshot',
        'installed_by_user_id',
        'status',
        'planned_at',
        'installed_at',
        'cancelled_at',
        'cancellation_reason',
        'notes',
        'total_parts_consumed',
        'created_at',
        'updated_at',
    ];

    private const ITEM_COLUMNS = [
        'id',
        'install_id',
        'workorder_item_id',
        'bundle_item_id',
        'inventory_item_id',
        'type',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'stock_consumed',
        'stock_consumed_at',
        'created_at',
    ];

    private const WRITABLE = [
        'workorder_id',
        'workorder_job_id',
        'bundle_id',
        'bundle_name_snapshot',
        'installed_by_user_id',
        'status',
        'planned_at',
        'installed_at',
        'cancelled_at',
        'cancellation_reason',
        'notes',
        'total_parts_consumed',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?WorkorderKitInstall
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM workorder_kit_installs WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, WorkorderKitInstall>
     */
    public function listForWorkorder(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM workorder_kit_installs '
            . 'WHERE workorder_id = :wo ORDER BY id DESC'
        );
        $stmt->execute(['wo' => $workorderId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, WorkorderKitInstall>
     */
    public function listForJob(int $jobId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM workorder_kit_installs '
            . 'WHERE workorder_job_id = :job ORDER BY id DESC'
        );
        $stmt->execute(['job' => $jobId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, WorkorderKitInstall>
     */
    public function listByStatus(string $status, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM workorder_kit_installs '
            . 'WHERE status = :status ORDER BY id ASC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): WorkorderKitInstall
    {
        $columns = [];
        $placeholders = [];
        $params = [];
        foreach (self::WRITABLE as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $columns[] = $col;
            $placeholders[] = ':' . $col;
            $params[$col] = $payload[$col];
        }

        $sql = 'INSERT INTO workorder_kit_installs (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
        $this->connection->pdo()->prepare($sql)->execute($params);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->find($id) ?? new WorkorderKitInstall(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): ?WorkorderKitInstall
    {
        $sets = [];
        $params = ['id' => $id];
        foreach (self::WRITABLE as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $sets[] = $col . ' = :' . $col;
            $params[$col] = $payload[$col];
        }

        if ($sets === []) {
            return $this->find($id);
        }

        $sql = 'UPDATE workorder_kit_installs SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->connection->pdo()->prepare($sql)->execute($params);

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM workorder_kit_installs WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function addItem(int $installId, array $payload): WorkorderKitInstallItem
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO workorder_kit_install_items '
            . '(install_id, workorder_item_id, bundle_item_id, inventory_item_id, type, description, '
            . 'quantity, unit_price, line_total, stock_consumed, stock_consumed_at) VALUES '
            . '(:install_id, :workorder_item_id, :bundle_item_id, :inventory_item_id, :type, :description, '
            . ':quantity, :unit_price, :line_total, :stock_consumed, :stock_consumed_at)'
        );
        $stmt->execute([
            'install_id' => $installId,
            'workorder_item_id' => $payload['workorder_item_id'] ?? null,
            'bundle_item_id' => $payload['bundle_item_id'] ?? null,
            'inventory_item_id' => $payload['inventory_item_id'] ?? null,
            'type' => $payload['type'],
            'description' => $payload['description'],
            'quantity' => $payload['quantity'] ?? 1,
            'unit_price' => $payload['unit_price'] ?? 0,
            'line_total' => $payload['line_total'] ?? 0,
            'stock_consumed' => $payload['stock_consumed'] ?? 0,
            'stock_consumed_at' => $payload['stock_consumed_at'] ?? null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->findItem($id) ?? new WorkorderKitInstallItem(['id' => $id]);
    }

    public function findItem(int $itemId): ?WorkorderKitInstallItem
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::ITEM_COLUMNS) . ' FROM workorder_kit_install_items WHERE id = :id'
        );
        $stmt->execute(['id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateItem($row);
    }

    /**
     * @return array<int, WorkorderKitInstallItem>
     */
    public function itemsForInstall(int $installId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::ITEM_COLUMNS) . ' FROM workorder_kit_install_items '
            . 'WHERE install_id = :id ORDER BY id ASC'
        );
        $stmt->execute(['id' => $installId]);

        return array_map([$this, 'hydrateItem'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateItem(int $itemId, array $payload): ?WorkorderKitInstallItem
    {
        $allowed = ['workorder_item_id', 'inventory_item_id', 'stock_consumed', 'stock_consumed_at',
            'quantity', 'unit_price', 'line_total'];
        $sets = [];
        $params = ['id' => $itemId];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $sets[] = $col . ' = :' . $col;
            $params[$col] = $payload[$col];
        }

        if ($sets === []) {
            return $this->findItem($itemId);
        }

        $sql = 'UPDATE workorder_kit_install_items SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->connection->pdo()->prepare($sql)->execute($params);

        return $this->findItem($itemId);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): WorkorderKitInstall
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }

        return new WorkorderKitInstall($cast);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateItem(array $row): WorkorderKitInstallItem
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castItemColumn($k, $v);
        }

        return new WorkorderKitInstallItem($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'workorder_id', 'workorder_job_id', 'bundle_id', 'installed_by_user_id',
                'total_parts_consumed' => (int) $value,
            default => $value,
        };
    }

    private function castItemColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'install_id', 'workorder_item_id', 'bundle_item_id', 'inventory_item_id',
                'stock_consumed' => (int) $value,
            'quantity', 'unit_price', 'line_total' => (float) $value,
            default => $value,
        };
    }
}
