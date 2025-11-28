<?php

namespace App\Support\Audit;

class AuditEntry
{
    public string $event;
    public string $entityType;
    public $entityId;
    public ?int $actorId;
    public array $context;

    public function __construct(string $event, string $entityType, $entityId = null, ?int $actorId = null, array $context = [])
    {
        $this->event = $event;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->actorId = $actorId;
        $this->context = $context;
    }
}
