<?php

namespace App\Services\Messaging;

use App\Database\Connection;
use PDO;

class MessagingNotificationService
{
    private Connection $connection;
    private MessagingService $messaging;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $eventMap = [
        'warranty.status_changed' => [
            'subject' => 'Warranty Claim #{claim_id}',
            'message' => '{actor} updated warranty claim #{claim_id} to {status}{message_note}.',
            'scope_type' => 'warranty_claim',
            'scope_id' => 'claim_id',
            'participants' => [
                'roles' => ['admin', 'manager'],
            ],
        ],
        'warranty.customer_reply' => [
            'subject' => 'Warranty Claim #{claim_id}',
            'message' => 'Customer replied on warranty claim #{claim_id}: "{message}"',
            'scope_type' => 'warranty_claim',
            'scope_id' => 'claim_id',
            'participants' => [
                'roles' => ['admin', 'manager'],
            ],
        ],
        'appointment.cancelled' => [
            'subject' => 'Appointment #{appointment_id}',
            'message' => '{actor} cancelled appointment #{appointment_id} scheduled for {start_time}.',
            'scope_type' => 'appointment',
            'scope_id' => 'appointment_id',
            'participants' => [
                'roles' => ['admin', 'manager'],
                'include_ids' => ['technician_id'],
            ],
        ],
        'estimate.link_sent' => [
            'subject' => 'Estimate #{estimate_number}',
            'message' => '{actor} sent estimate #{estimate_number} to {recipient} via {channel}.',
            'scope_type' => 'estimate',
            'scope_id' => 'estimate_id',
            'participants' => [
                'roles' => ['admin', 'manager'],
                'include_ids' => ['technician_id'],
            ],
        ],
        'workorder.status_changed' => [
            'subject' => 'Workorder #{workorder_number}',
            'message' => '{actor} updated workorder #{workorder_number} status from {previous_status} to {status}.',
            'scope_type' => 'workorder',
            'scope_id' => 'workorder_id',
            'participants' => [
                'roles' => ['admin', 'manager'],
                'include_ids' => ['assigned_technician_id'],
            ],
        ],
        'workorder.assignment_changed' => [
            'subject' => 'Workorder #{workorder_number}',
            'message' => '{actor} updated workorder #{workorder_number} assignment from {previous_technician} to {new_technician}.',
            'scope_type' => 'workorder',
            'scope_id' => 'workorder_id',
            'participants' => [
                'roles' => ['admin', 'manager'],
                'include_ids' => ['previous_technician_id', 'new_technician_id'],
            ],
        ],
        'workorder.job_assignment_changed' => [
            'subject' => 'Workorder #{workorder_number}',
            'message' => '{actor} updated job #{job_id} assignment on workorder #{workorder_number} from {previous_technician} to {new_technician}.',
            'scope_type' => 'workorder',
            'scope_id' => 'workorder_id',
            'participants' => [
                'roles' => ['admin', 'manager'],
                'include_ids' => ['previous_technician_id', 'new_technician_id'],
            ],
        ],
        'invoice.status_changed' => [
            'subject' => 'Invoice #{invoice_number}',
            'message' => '{actor} updated invoice #{invoice_number} status from {previous_status} to {status}.',
            'scope_type' => 'invoice',
            'scope_id' => 'invoice_id',
            'participants' => [
                'roles' => ['admin', 'manager'],
            ],
        ],
        'inventory.pull_request.created' => [
            'subject' => 'Inventory Pull Requests',
            'message' => '{actor} created a {request_type} request #{pull_request_id} for workorder #{workorder_number}: {description} (Qty {quantity_requested}).',
            'scope_type' => 'department',
            'scope_value' => 'inventory',
            'participants' => [
                'roles' => ['admin', 'manager'],
            ],
        ],
        'inventory.pull_request.auto_generated' => [
            'subject' => 'Inventory Pull Requests',
            'message' => '{actor} auto-generated a {request_type} request #{pull_request_id} for workorder #{workorder_number}: {description} (Qty {quantity_requested}).',
            'scope_type' => 'department',
            'scope_value' => 'inventory',
            'participants' => [
                'roles' => ['parts'],
            ],
        ],
        'inventory.pull_request.pulled' => [
            'subject' => 'Inventory Pull Requests',
            'message' => '{actor} pulled inventory for request #{pull_request_id} ({quantity_pulled} pulled, total {total_fulfilled}).',
            'scope_type' => 'department',
            'scope_value' => 'inventory',
            'participants' => [
                'roles' => ['admin', 'manager'],
            ],
        ],
        'inventory.pull_request.ordered' => [
            'subject' => 'Inventory Pull Requests',
            'message' => '{actor} marked request #{pull_request_id} as ordered{order_reference_note}.',
            'scope_type' => 'department',
            'scope_value' => 'inventory',
            'participants' => [
                'roles' => ['admin', 'manager'],
            ],
        ],
        'inventory.pull_request.received' => [
            'subject' => 'Inventory Pull Requests',
            'message' => '{actor} received inventory for request #{pull_request_id} ({quantity_received} received, total {total_fulfilled}).',
            'scope_type' => 'department',
            'scope_value' => 'inventory',
            'participants' => [
                'roles' => ['admin', 'manager'],
            ],
        ],
        'inventory.pull_request.cancelled' => [
            'subject' => 'Inventory Pull Requests',
            'message' => '{actor} cancelled request #{pull_request_id}.',
            'scope_type' => 'department',
            'scope_value' => 'inventory',
            'participants' => [
                'roles' => ['admin', 'manager'],
            ],
        ],
        'inventory.stock_order.auto_generated' => [
            'subject' => 'Inventory Stock Orders',
            'message' => '{actor} auto-generated stock order #{stock_order_id} for workorder #{workorder_number}: {description} (Qty {quantity_ordered}).',
            'scope_type' => 'department',
            'scope_value' => 'inventory',
            'participants' => [
                'roles' => ['parts'],
            ],
        ],
        'roadside.assistance.requested' => [
            'subject' => 'Roadside Assistance',
            'message' => 'New roadside assistance request #{request_id} received for {customer}.',
            'scope_type' => 'department',
            'scope_value' => 'roadside',
            'participants' => [
                'roles' => ['admin', 'manager'],
            ],
        ],
        'tracking.link_sent' => [
            'subject' => 'Tracking Link for Workorder #{workorder_number}',
            'message' => '{actor} sent a tracking link for job #{job_id} ({job_title}) to {recipient} via {channel}.',
            'scope_type' => 'workorder',
            'scope_id' => 'workorder_id',
            'participants' => [
                'roles' => ['admin', 'manager'],
                'include_ids' => ['assigned_technician_id'],
            ],
        ],
    ];

    public function __construct(Connection $connection, MessagingService $messaging)
    {
        $this->connection = $connection;
        $this->messaging = $messaging;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $type, array $payload): void
    {
        if (!isset($this->eventMap[$type])) {
            return;
        }

        $event = $this->eventMap[$type];
        $context = $this->buildContext($type, $payload);
        $context['type'] = $type;

        $scopeType = (string) ($event['scope_type'] ?? 'general');
        $scopeIdKey = (string) ($event['scope_id'] ?? 'id');
        $scopeId = isset($event['scope_value'])
            ? (string) $event['scope_value']
            : (string) ($context[$scopeIdKey] ?? $payload[$scopeIdKey] ?? '');
        if ($scopeId === '') {
            return;
        }

        $participants = $this->resolveParticipants($event['participants'] ?? [], $context);
        $senderId = $this->resolveSenderId($payload);

        if ($senderId === null) {
            return;
        }

        if (!in_array($senderId, $participants, true)) {
            $participants[] = $senderId;
        }

        if ($participants === []) {
            return;
        }

        $subject = $this->renderTemplate((string) $event['subject'], $context);
        $message = $this->renderTemplate((string) $event['message'], $context);

        $threadId = $this->findThreadId($scopeType, $scopeId);
        if ($threadId === null) {
            $thread = $this->messaging->createThread($senderId, $participants, $subject);
            $threadId = (int) ($thread['id'] ?? 0);
            if ($threadId === 0) {
                return;
            }
            $this->storeThreadLink($scopeType, $scopeId, $threadId);
        } else {
            $this->ensureParticipants($threadId, $participants);
        }

        $this->messaging->postMessage($threadId, $senderId, $message);
    }

    /**
     * @param array<string, mixed> $eventParticipants
     * @param array<string, mixed> $context
     * @return array<int, int>
     */
    private function resolveParticipants(array $eventParticipants, array $context): array
    {
        $participantIds = [];
        $roles = $eventParticipants['roles'] ?? [];
        if (is_array($roles) && $roles !== []) {
            $participantIds = array_merge($participantIds, $this->getUserIdsByRoles($roles));
        }

        $includeIds = $eventParticipants['include_ids'] ?? [];
        if (is_array($includeIds)) {
            foreach ($includeIds as $key) {
                $value = $context[$key] ?? null;
                if ($value !== null) {
                    $participantIds[] = (int) $value;
                }
            }
        }

        $participantIds = array_values(array_unique(array_filter($participantIds)));

        return $participantIds;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveSenderId(array $payload): ?int
    {
        $actorId = isset($payload['actor_id']) ? (int) $payload['actor_id'] : null;
        if ($actorId !== null && $actorId > 0 && $this->isStaffUser($actorId)) {
            return $actorId;
        }

        return $this->defaultSenderId();
    }

    private function defaultSenderId(): ?int
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id FROM users WHERE role IN (\'admin\', \'manager\') ORDER BY id ASC LIMIT 1');
        $stmt->execute();
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $fallback = $this->connection->pdo()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();

        return $fallback !== false ? (int) $fallback : null;
    }

    private function isStaffUser(int $userId): bool
    {
        $stmt = $this->connection->pdo()->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $role = $stmt->fetchColumn();

        return $role !== false && $role !== 'customer';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildContext(string $type, array $payload): array
    {
        $context = $payload;
        $context['actor'] = $this->resolveActorName($payload['actor_id'] ?? null);

        if (isset($payload['message']) && $payload['message'] !== '') {
            $context['message_note'] = ': ' . $payload['message'];
        } else {
            $context['message_note'] = '';
        }

        if (isset($payload['order_reference']) && $payload['order_reference']) {
            $context['order_reference_note'] = ' (Ref ' . $payload['order_reference'] . ')';
        } else {
            $context['order_reference_note'] = '';
        }

        if (str_starts_with($type, 'appointment.')) {
            $appointment = $this->fetchAppointment((int) ($payload['appointment_id'] ?? 0));
            $context = array_merge($context, $appointment);
        }

        if (str_starts_with($type, 'estimate.')) {
            $estimate = $this->fetchEstimate((int) ($payload['estimate_id'] ?? 0));
            $context = array_merge($context, $estimate);
        }

        if (str_starts_with($type, 'workorder.')) {
            $workorder = $this->fetchWorkorder((int) ($payload['workorder_id'] ?? 0));
            $context = array_merge($context, $workorder);
        }

        if (str_starts_with($type, 'invoice.')) {
            $invoice = $this->fetchInvoice((int) ($payload['invoice_id'] ?? 0));
            $context = array_merge($context, $invoice);
        }

        if (str_starts_with($type, 'warranty.')) {
            $claim = $this->fetchWarrantyClaim((int) ($payload['claim_id'] ?? 0));
            $context = array_merge($context, $claim);
        }

        if (str_starts_with($type, 'inventory.pull_request.')) {
            $request = $this->fetchPullRequest((int) ($payload['pull_request_id'] ?? 0));
            $context = array_merge($context, $request);
        }

        if (str_starts_with($type, 'inventory.stock_order.')) {
            $stockOrder = $this->fetchStockOrder((int) ($payload['stock_order_id'] ?? 0));
            $context = array_merge($context, $stockOrder);
        }

        if (str_starts_with($type, 'inventory.') && isset($payload['workorder_id'])) {
            $workorder = $this->fetchWorkorder((int) ($payload['workorder_id'] ?? 0));
            $context = array_merge($context, $workorder);
        }

        if (str_starts_with($type, 'tracking.')) {
            $tracking = $this->fetchTrackingJob((int) ($payload['job_id'] ?? 0));
            $context = array_merge($context, $tracking);
        }

        if (array_key_exists('previous_technician_id', $context) && !isset($context['previous_technician'])) {
            $context['previous_technician'] = $context['previous_technician_id']
                ? $this->resolveActorName((int) $context['previous_technician_id'])
                : 'Unassigned';
        }

        if (array_key_exists('new_technician_id', $context) && !isset($context['new_technician'])) {
            $context['new_technician'] = $context['new_technician_id']
                ? $this->resolveActorName((int) $context['new_technician_id'])
                : 'Unassigned';
        }

        return $context;
    }

    private function renderTemplate(string $template, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replace['{' . $key . '}'] = (string) ($value ?? '');
            }
        }

        return strtr($template, $replace);
    }

    private function findThreadId(string $scopeType, string $scopeId): ?int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT thread_id FROM message_notification_threads WHERE scope_type = :scope_type AND scope_id = :scope_id'
        );
        $stmt->execute(['scope_type' => $scopeType, 'scope_id' => $scopeId]);
        $threadId = $stmt->fetchColumn();

        return $threadId !== false ? (int) $threadId : null;
    }

    private function storeThreadLink(string $scopeType, string $scopeId, int $threadId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO message_notification_threads (scope_type, scope_id, thread_id, created_at)
             VALUES (:scope_type, :scope_id, :thread_id, NOW())
             ON DUPLICATE KEY UPDATE thread_id = VALUES(thread_id)'
        );
        $stmt->execute([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'thread_id' => $threadId,
        ]);
    }

    /**
     * @param array<int, int> $participantIds
     */
    private function ensureParticipants(int $threadId, array $participantIds): void
    {
        if ($participantIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT participant_id FROM message_participants WHERE thread_id = ? AND participant_id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$threadId], $participantIds));
        $existing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $missing = array_values(array_diff($participantIds, $existing));
        if ($missing === []) {
            return;
        }

        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO message_participants (thread_id, participant_id, created_at) VALUES (:thread_id, :participant_id, NOW())'
        );
        foreach ($missing as $participantId) {
            $insert->execute([
                'thread_id' => $threadId,
                'participant_id' => $participantId,
            ]);
        }
    }

    /**
     * @param string[] $roles
     * @return array<int, int>
     */
    private function getUserIdsByRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM users WHERE role IN (' . $placeholders . ')'
        );
        $stmt->execute($roles);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function resolveActorName(?int $actorId): string
    {
        if ($actorId === null) {
            return 'System';
        }

        $stmt = $this->connection->pdo()->prepare('SELECT name FROM users WHERE id = :id');
        $stmt->execute(['id' => $actorId]);
        $name = $stmt->fetchColumn();

        return $name !== false ? (string) $name : 'System';
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAppointment(int $appointmentId): array
    {
        if ($appointmentId === 0) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, technician_id, start_time FROM appointments WHERE id = :id'
        );
        $stmt->execute(['id' => $appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        return [
            'appointment_id' => (int) $row['id'],
            'technician_id' => $row['technician_id'] !== null ? (int) $row['technician_id'] : null,
            'start_time' => $row['start_time'] ? date('M j, Y g:i A', strtotime((string) $row['start_time'])) : 'N/A',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchEstimate(int $estimateId): array
    {
        if ($estimateId === 0) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, number, technician_id FROM estimates WHERE id = :id'
        );
        $stmt->execute(['id' => $estimateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        return [
            'estimate_id' => (int) $row['id'],
            'estimate_number' => (string) $row['number'],
            'technician_id' => $row['technician_id'] !== null ? (int) $row['technician_id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWorkorder(int $workorderId): array
    {
        if ($workorderId === 0) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, number, assigned_technician_id FROM workorders WHERE id = :id'
        );
        $stmt->execute(['id' => $workorderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        return [
            'workorder_id' => (int) $row['id'],
            'workorder_number' => (string) $row['number'],
            'assigned_technician_id' => $row['assigned_technician_id'] !== null ? (int) $row['assigned_technician_id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchInvoice(int $invoiceId): array
    {
        if ($invoiceId === 0) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, number FROM invoices WHERE id = :id'
        );
        $stmt->execute(['id' => $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        return [
            'invoice_id' => (int) $row['id'],
            'invoice_number' => (string) $row['number'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWarrantyClaim(int $claimId): array
    {
        if ($claimId === 0) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM warranty_claims WHERE id = :id'
        );
        $stmt->execute(['id' => $claimId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        return [
            'claim_id' => (int) $row['id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPullRequest(int $requestId): array
    {
        if ($requestId === 0) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT pr.id, pr.description, pr.quantity_requested, pr.quantity_fulfilled, pr.request_type, w.number AS workorder_number
             FROM inventory_pull_requests pr
             LEFT JOIN workorders w ON pr.workorder_id = w.id
             WHERE pr.id = :id'
        );
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        return [
            'pull_request_id' => (int) $row['id'],
            'description' => (string) ($row['description'] ?? ''),
            'quantity_requested' => (int) ($row['quantity_requested'] ?? 0),
            'total_fulfilled' => (int) ($row['quantity_fulfilled'] ?? 0),
            'request_type' => (string) ($row['request_type'] ?? ''),
            'workorder_number' => (string) ($row['workorder_number'] ?? 'N/A'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchStockOrder(int $stockOrderId): array
    {
        if ($stockOrderId === 0) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, description, quantity_ordered, vendor FROM inventory_stock_orders WHERE id = :id'
        );
        $stmt->execute(['id' => $stockOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        return [
            'stock_order_id' => (int) $row['id'],
            'description' => (string) ($row['description'] ?? ''),
            'quantity_ordered' => (int) ($row['quantity_ordered'] ?? 0),
            'vendor' => (string) ($row['vendor'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTrackingJob(int $jobId): array
    {
        if ($jobId === 0) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT
                workorder_jobs.id AS job_id,
                workorder_jobs.title AS job_title,
                workorders.id AS workorder_id,
                workorders.number AS workorder_number,
                workorders.assigned_technician_id AS assigned_technician_id,
                customers.first_name AS customer_first_name,
                customers.last_name AS customer_last_name,
                customer_vehicles.year AS vehicle_year,
                customer_vehicles.make AS vehicle_make,
                customer_vehicles.model AS vehicle_model
             FROM workorder_jobs
             INNER JOIN workorders ON workorder_jobs.workorder_id = workorders.id
             LEFT JOIN customers ON workorders.customer_id = customers.id
             LEFT JOIN customer_vehicles ON workorders.vehicle_id = customer_vehicles.id
             WHERE workorder_jobs.id = :job_id'
        );
        $stmt->execute(['job_id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [];
        }

        $vehicleParts = array_filter([
            $row['vehicle_year'] ?? null,
            $row['vehicle_make'] ?? null,
            $row['vehicle_model'] ?? null,
        ]);

        return [
            'job_id' => (int) $row['job_id'],
            'job_title' => (string) ($row['job_title'] ?? ''),
            'workorder_id' => (int) $row['workorder_id'],
            'workorder_number' => (string) ($row['workorder_number'] ?? ''),
            'assigned_technician_id' => $row['assigned_technician_id'] !== null ? (int) $row['assigned_technician_id'] : null,
            'customer_name' => trim(($row['customer_first_name'] ?? '') . ' ' . ($row['customer_last_name'] ?? '')) ?: 'Customer',
            'vehicle' => $vehicleParts ? implode(' ', $vehicleParts) : null,
        ];
    }
}
