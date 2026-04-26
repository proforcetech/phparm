<?php

namespace App\Services\Division;

use App\Models\Division;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

class DivisionController
{
    public function __construct(
        private readonly DivisionRepository $repository,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, bool $includeInactive = false): array
    {
        // Read is broadly allowed — division list feeds UI pickers across
        // modules. Write operations are gated at the mutation methods.
        $divisions = $includeInactive
            ? $this->repository->listAll()
            : $this->repository->listActive();

        return array_map([self::class, 'toArray'], $divisions);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $division = $this->repository->findById($id);
        if ($division === null) {
            throw new InvalidArgumentException("Division {$id} not found");
        }

        return self::toArray($division);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function store(User $user, array $body): array
    {
        $this->gate->assert($user, 'settings.divisions.manage');

        $code = trim((string) ($body['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw new InvalidArgumentException('code and name are required');
        }
        if (!preg_match('/^[a-z0-9_]+$/', $code)) {
            throw new InvalidArgumentException('code must be lowercase alphanumeric with underscores');
        }
        if ($this->repository->findByCode($code) !== null) {
            throw new InvalidArgumentException("Division code '{$code}' already exists");
        }

        $division = $this->repository->create([
            'code' => $code,
            'name' => $name,
            'description' => isset($body['description']) ? (string) $body['description'] : null,
            'is_active' => (bool) ($body['is_active'] ?? true),
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ]);

        return self::toArray($division);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'settings.divisions.manage');

        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Division {$id} not found");
        }

        $updates = [];
        if (isset($body['name'])) {
            $updates['name'] = trim((string) $body['name']);
        }
        if (array_key_exists('description', $body)) {
            $updates['description'] = $body['description'] === null ? null : (string) $body['description'];
        }
        if (isset($body['is_active'])) {
            $updates['is_active'] = (bool) $body['is_active'];
        }
        if (isset($body['sort_order'])) {
            $updates['sort_order'] = (int) $body['sort_order'];
        }

        // code is intentionally NOT updatable — it's a stable external key

        $updated = $this->repository->update($id, $updates);

        return self::toArray($updated);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Division $d): array
    {
        return [
            'id' => $d->id,
            'code' => $d->code,
            'name' => $d->name,
            'description' => $d->description,
            'is_active' => $d->is_active,
            'sort_order' => $d->sort_order,
            'created_at' => $d->created_at,
            'updated_at' => $d->updated_at,
        ];
    }
}
