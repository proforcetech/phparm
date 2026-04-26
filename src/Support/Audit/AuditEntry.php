<?php

namespace App\Support\Audit;

class AuditEntry
{
    public string $event;
    public string $entityType;
    public $entityId;
    public ?int $actorId;
    public array $context;
    public ?string $createdAt;

    public function __construct(string $event, string $entityType, $entityId = null, ?int $actorId = null, array $context = [], ?string $createdAt = null)
    {
        $this->event = $event;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->actorId = $actorId;
        $this->context = $context;
        $this->createdAt = $createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'actor_id' => $this->actorId,
            'context' => $this->context,
            'created_at' => $this->createdAt,
        ];
    }
}
