<?php

namespace App\Services\Workorder;

use App\Database\Connection;
use App\Models\ApprovalAuditLog;
use PDO;

class WorkorderTimelineService
{
    private Connection $connection;
    private ?bool $hasInternalNotesTable = null;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(int $workorderId, bool $isCustomerView = false): array
    {
        $events = [];
        $estimates = $this->fetchEstimates($workorderId);
        $estimateIds = array_keys($estimates);

        $events = array_merge($events, $this->buildStatusEvents($workorderId, $isCustomerView));
        $events = array_merge($events, $this->buildMessageEvents($workorderId));
        $events = array_merge($events, $this->buildPhotoEvents($workorderId));
        if (!$isCustomerView) {
            $events = array_merge($events, $this->buildInternalNoteEvents($workorderId));
        }

        if ($estimateIds !== []) {
            $events = array_merge($events, $this->buildApprovalEvents($estimateIds, $estimates));
            $events = array_merge(
                $events,
                $this->buildEstimateAuditEvents($estimateIds, $estimates, $isCustomerView)
            );
        }

        usort($events, static function (array $a, array $b): int {
            return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        });

        return $events;
    }

    /**
     * @return array<int, array{number: string, estimate_type: string}>
     */
    private function fetchEstimates(int $workorderId): array
    {
        $pdo = $this->connection->pdo();
        $estimates = [];

        $stmt = $pdo->prepare('SELECT estimate_id FROM workorders WHERE id = :id');
        $stmt->execute(['id' => $workorderId]);
        $estimateId = (int) ($stmt->fetchColumn() ?? 0);

        $estimateIds = [];
        if ($estimateId > 0) {
            $estimateIds[] = $estimateId;
        }

        $subStmt = $pdo->prepare(
            "SELECT id FROM estimates WHERE workorder_id = :workorder_id AND estimate_type = 'sub_estimate'"
        );
        $subStmt->execute(['workorder_id' => $workorderId]);
        $subIds = array_map('intval', $subStmt->fetchAll(PDO::FETCH_COLUMN));

        $estimateIds = array_values(array_unique(array_merge($estimateIds, $subIds)));
        if ($estimateIds === []) {
            return $estimates;
        }

        $placeholders = implode(',', array_fill(0, count($estimateIds), '?'));
        $fetch = $pdo->prepare("SELECT id, number, estimate_type FROM estimates WHERE id IN ($placeholders)");
        $fetch->execute($estimateIds);

        foreach ($fetch->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $estimates[$id] = [
                'number' => (string) $row['number'],
                'estimate_type' => (string) $row['estimate_type'],
            ];
        }

        return $estimates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildStatusEvents(int $workorderId, bool $isCustomerView): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT wsh.id,
                   wsh.from_status,
                   wsh.to_status,
                   wsh.notes,
                   wsh.created_at,
                   u.name AS actor_name
              FROM workorder_status_history wsh
         LEFT JOIN users u ON u.id = wsh.changed_by
             WHERE wsh.workorder_id = :workorder_id
          ORDER BY wsh.created_at ASC
        SQL);
        $stmt->execute(['workorder_id' => $workorderId]);

        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $descriptionParts = [];
            if (!empty($row['actor_name'])) {
                $descriptionParts[] = 'Changed by ' . $row['actor_name'];
            }
            if (!$isCustomerView && !empty($row['notes'])) {
                $descriptionParts[] = (string) $row['notes'];
            }

            $events[] = [
                'id' => 'status-' . $row['id'],
                'type' => 'status',
                'title' => 'Status changed to ' . $this->formatStatus((string) $row['to_status']),
                'description' => $descriptionParts !== [] ? implode(' · ', $descriptionParts) : null,
                'created_at' => $row['created_at'],
                'meta' => [
                    'from_status' => $row['from_status'],
                    'to_status' => $row['to_status'],
                    'actor_name' => $row['actor_name'],
                ],
            ];
        }

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMessageEvents(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT msm.id,
                   msm.body,
                   msm.direction,
                   msm.sender_role,
                   msm.created_at,
                   mss.driver_user_id,
                   mss.customer_id,
                   u.name AS driver_name,
                   c.name AS customer_name
              FROM masked_sms_messages msm
              JOIN masked_sms_sessions mss ON mss.id = msm.session_id
         LEFT JOIN users u ON u.id = mss.driver_user_id
         LEFT JOIN customers c ON c.id = mss.customer_id
             WHERE mss.job_reference = :job_reference
               AND mss.job_type = 'workorder'
          ORDER BY msm.created_at ASC
        SQL);
        $stmt->execute(['job_reference' => (string) $workorderId]);

        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $senderRole = (string) ($row['sender_role'] ?? '');
            $senderLabel = $this->formatSenderLabel(
                $senderRole,
                (string) ($row['driver_name'] ?? ''),
                (string) ($row['customer_name'] ?? '')
            );

            $events[] = [
                'id' => 'message-' . $row['id'],
                'type' => 'message',
                'title' => 'Message from ' . $senderLabel,
                'description' => (string) $row['body'],
                'created_at' => $row['created_at'],
                'meta' => [
                    'direction' => $row['direction'],
                    'sender_role' => $senderRole,
                    'sender_name' => $senderLabel,
                ],
            ];
        }

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPhotoEvents(int $workorderId): array
    {
        $events = [];
        $pdo = $this->connection->pdo();

        $damageStmt = $pdo->prepare(<<<SQL
            SELECT jdm.id,
                   jdm.file_path,
                   jdm.created_at,
                   jdm.uploaded_by,
                   wj.title AS job_title,
                   u.name AS uploader_name
              FROM job_damage_media jdm
              JOIN workorder_jobs wj ON wj.id = jdm.workorder_job_id
         LEFT JOIN users u ON u.id = jdm.uploaded_by
             WHERE wj.workorder_id = :workorder_id
          ORDER BY jdm.created_at ASC
        SQL);
        $damageStmt->execute(['workorder_id' => $workorderId]);

        foreach ($damageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => 'damage-photo-' . $row['id'],
                'type' => 'photo',
                'title' => 'Damage photo added',
                'description' => $row['job_title'] ?: 'Damage evidence photo',
                'created_at' => $row['created_at'],
                'meta' => [
                    'file_path' => $row['file_path'],
                    'job_title' => $row['job_title'],
                    'uploader_name' => $row['uploader_name'],
                    'category' => 'damage',
                ],
            ];
        }

        $checkpointStmt = $pdo->prepare(<<<SQL
            SELECT jcm.id,
                   jcm.checkpoint_type,
                   jcm.file_path,
                   jcm.created_at,
                   jcm.uploaded_by,
                   wj.title AS job_title,
                   u.name AS uploader_name
              FROM job_checkpoint_media jcm
              JOIN workorder_jobs wj ON wj.id = jcm.workorder_job_id
         LEFT JOIN users u ON u.id = jcm.uploaded_by
             WHERE wj.workorder_id = :workorder_id
          ORDER BY jcm.created_at ASC
        SQL);
        $checkpointStmt->execute(['workorder_id' => $workorderId]);

        foreach ($checkpointStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => 'checkpoint-photo-' . $row['id'],
                'type' => 'photo',
                'title' => 'Checkpoint photo added (' . $this->formatCheckpoint((string) $row['checkpoint_type']) . ')',
                'description' => $row['job_title'] ?: 'Checkpoint photo',
                'created_at' => $row['created_at'],
                'meta' => [
                    'file_path' => $row['file_path'],
                    'job_title' => $row['job_title'],
                    'checkpoint_type' => $row['checkpoint_type'],
                    'uploader_name' => $row['uploader_name'],
                    'category' => 'checkpoint',
                ],
            ];
        }

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildInternalNoteEvents(int $workorderId): array
    {
        if (!$this->internalNotesTableExists()) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT win.id,
                   win.body,
                   win.context,
                   win.created_at,
                   u.name AS author_name
              FROM workorder_internal_notes win
         LEFT JOIN users u ON u.id = win.author_user_id
             WHERE win.workorder_id = :workorder_id
          ORDER BY win.created_at ASC
        SQL);
        $stmt->execute(['workorder_id' => $workorderId]);

        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $author = trim((string) ($row['author_name'] ?? ''));
            $context = trim((string) ($row['context'] ?? ''));
            $details = [];
            if ($author !== '') {
                $details[] = 'Added by ' . $author;
            }
            if ($context !== '') {
                $details[] = $context;
            }
            $details[] = (string) $row['body'];

            $events[] = [
                'id' => 'internal-note-' . $row['id'],
                'type' => 'note',
                'title' => 'Internal note added',
                'description' => implode(' · ', $details),
                'created_at' => $row['created_at'],
                'meta' => [
                    'author_name' => $row['author_name'],
                    'context' => $row['context'],
                ],
            ];
        }

        return $events;
    }

    private function internalNotesTableExists(): bool
    {
        if ($this->hasInternalNotesTable !== null) {
            return $this->hasInternalNotesTable;
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'workorder_internal_notes'
        SQL);
        $stmt->execute();
        $this->hasInternalNotesTable = (int) $stmt->fetchColumn() > 0;

        return $this->hasInternalNotesTable;
    }

    /**
     * @param array<int> $estimateIds
     * @param array<int, array{number: string, estimate_type: string}> $estimates
     * @return array<int, array<string, mixed>>
     */
    private function buildApprovalEvents(array $estimateIds, array $estimates): array
    {
        $placeholders = implode(',', array_fill(0, count($estimateIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            "SELECT * FROM approval_audit_log WHERE entity_type = 'estimate' AND entity_id IN ($placeholders) ORDER BY created_at ASC"
        );
        $stmt->execute($estimateIds);

        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $estimateNumber = $estimates[(int) $row['entity_id']]['number'] ?? ('#' . $row['entity_id']);
            $title = $this->approvalTitle((string) $row['action'], $estimateNumber, $row['job_id'] ?? null);
            $description = $this->approvalDescription($row);

            $events[] = [
                'id' => 'approval-' . $row['id'],
                'type' => 'approval',
                'title' => $title,
                'description' => $description,
                'created_at' => $row['created_at'],
                'meta' => [
                    'action' => $row['action'],
                    'job_id' => $row['job_id'],
                    'estimate_id' => $row['entity_id'],
                    'estimate_number' => $estimateNumber,
                ],
            ];
        }

        return $events;
    }

    /**
     * @param array<int> $estimateIds
     * @param array<int, array{number: string, estimate_type: string}> $estimates
     * @return array<int, array<string, mixed>>
     */
    private function buildEstimateAuditEvents(array $estimateIds, array $estimates, bool $isCustomerView): array
    {
        $placeholders = implode(',', array_fill(0, count($estimateIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            "SELECT id, event, entity_id, actor_id, context, created_at
               FROM audit_logs
              WHERE entity_type = 'estimate'
                AND entity_id IN ($placeholders)
           ORDER BY created_at ASC"
        );
        $stmt->execute($estimateIds);

        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $event = (string) $row['event'];
            if ($isCustomerView && !$this->isCustomerVisibleEstimateEvent($event)) {
                continue;
            }

            $context = json_decode($row['context'] ?? '[]', true) ?: [];
            $estimateNumber = $estimates[(int) $row['entity_id']]['number'] ?? ('#' . $row['entity_id']);
            [$title, $description] = $this->describeEstimateEvent($event, $context, $estimateNumber);

            $events[] = [
                'id' => 'estimate-' . $row['id'],
                'type' => 'estimate',
                'title' => $title,
                'description' => $description,
                'created_at' => $row['created_at'],
                'meta' => [
                    'event' => $event,
                    'estimate_id' => $row['entity_id'],
                    'estimate_number' => $estimateNumber,
                ],
            ];
        }

        return $events;
    }

    private function formatStatus(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    private function formatCheckpoint(string $checkpoint): string
    {
        return ucwords(str_replace('_', ' ', $checkpoint));
    }

    private function formatSenderLabel(string $role, string $driverName, string $customerName): string
    {
        return match ($role) {
            'customer' => $customerName !== '' ? $customerName : 'Customer',
            'driver' => $driverName !== '' ? $driverName : 'Driver',
            'dispatcher' => 'Dispatch',
            default => $role !== '' ? ucfirst($role) : 'Unknown',
        };
    }

    private function approvalTitle(string $action, string $estimateNumber, $jobId): string
    {
        $jobText = $jobId ? ' (Job #' . $jobId . ')' : '';

        return match ($action) {
            ApprovalAuditLog::ACTION_JOB_APPROVED => "Estimate {$estimateNumber} job approved{$jobText}",
            ApprovalAuditLog::ACTION_JOB_REJECTED => "Estimate {$estimateNumber} job rejected{$jobText}",
            ApprovalAuditLog::ACTION_FULLY_APPROVED => "Estimate {$estimateNumber} approved",
            ApprovalAuditLog::ACTION_FULLY_REJECTED => "Estimate {$estimateNumber} rejected",
            ApprovalAuditLog::ACTION_SIGNATURE_CAPTURED => "Estimate {$estimateNumber} signature captured",
            ApprovalAuditLog::ACTION_VIEWED => "Estimate {$estimateNumber} viewed",
            ApprovalAuditLog::ACTION_LINK_GENERATED => "Estimate {$estimateNumber} link generated",
            ApprovalAuditLog::ACTION_DOCUMENT_SENT => "Estimate {$estimateNumber} sent to customer",
            default => "Estimate {$estimateNumber} update",
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function approvalDescription(array $row): ?string
    {
        $details = [];
        if (!empty($row['signer_name'])) {
            $details[] = 'Signed by ' . $row['signer_name'];
        } elseif (!empty($row['signer_email'])) {
            $details[] = 'Signed by ' . $row['signer_email'];
        }

        if (!empty($row['comment'])) {
            $details[] = (string) $row['comment'];
        }

        return $details !== [] ? implode(' · ', $details) : null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{0: string, 1: string|null}
     */
    private function describeEstimateEvent(string $event, array $context, string $estimateNumber): array
    {
        return match ($event) {
            'estimate.status_changed' => [
                "Estimate {$estimateNumber} status updated",
                isset($context['after']['status']) ? 'Status: ' . $this->formatStatus((string) $context['after']['status']) : null,
            ],
            'estimate.converted' => [
                "Estimate {$estimateNumber} converted to invoice",
                isset($context['invoice_number']) ? 'Invoice #' . $context['invoice_number'] : null,
            ],
            'estimate.link_sent' => [
                "Estimate {$estimateNumber} link sent",
                isset($context['channel']) ? 'Channel: ' . ucfirst((string) $context['channel']) : null,
            ],
            'estimate.public_link_created' => [
                "Estimate {$estimateNumber} link created",
                null,
            ],
            'estimate.signature_captured' => [
                "Estimate {$estimateNumber} signature captured",
                isset($context['signer']) ? 'Signer: ' . $context['signer'] : null,
            ],
            'estimate.public_comment_added' => [
                "Estimate {$estimateNumber} comment added",
                isset($context['comment']) ? (string) $context['comment'] : null,
            ],
            'estimate.job_public_approved' => [
                "Estimate {$estimateNumber} job approved",
                isset($context['job_id']) ? 'Job #' . $context['job_id'] : null,
            ],
            'estimate.job_public_rejected' => [
                "Estimate {$estimateNumber} job rejected",
                isset($context['job_id']) ? 'Job #' . $context['job_id'] : null,
            ],
            'estimate.created' => [
                "Estimate {$estimateNumber} created",
                null,
            ],
            'estimate.updated' => [
                "Estimate {$estimateNumber} updated",
                null,
            ],
            'estimate.all_jobs_approved' => [
                "Estimate {$estimateNumber} approved",
                null,
            ],
            'estimate.rejected' => [
                "Estimate {$estimateNumber} rejected",
                isset($context['reason']) ? (string) $context['reason'] : null,
            ],
            default => [
                "Estimate {$estimateNumber} update",
                null,
            ],
        };
    }

    private function isCustomerVisibleEstimateEvent(string $event): bool
    {
        $allowlist = [
            'estimate.status_changed',
            'estimate.converted',
            'estimate.link_sent',
            'estimate.public_link_created',
            'estimate.signature_captured',
            'estimate.public_comment_added',
            'estimate.job_public_approved',
            'estimate.job_public_rejected',
            'estimate.all_jobs_approved',
            'estimate.rejected',
            'estimate.created',
        ];

        return in_array($event, $allowlist, true);
    }
}
