<?php

namespace App\Services\Workorder;

use App\Database\Connection;
use App\Services\Notification\NotificationEventService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use PDO;

/**
 * Service for handling status-driven notifications.
 * Automatically sends notifications when workorders transition between statuses.
 */
class WorkorderStatusNotificationService
{
    private Connection $connection;
    private NotificationEventService $notificationService;
    private ?AuditLogger $audit;

    /**
     * Status transition notification rules.
     * Format: 'to_status' => [recipient_type => 'template_key']
     */
    private const STATUS_NOTIFICATION_RULES = [
        'parts_pending' => [
            'role:parts_manager' => 'workorder_parts_pending',
            'role:manager' => 'workorder_parts_pending_manager',
        ],
        'in_progress' => [
            'customer' => 'workorder_in_progress',
        ],
        'on_hold' => [
            'role:manager' => 'workorder_on_hold',
            'assigned_technician' => 'workorder_on_hold_tech',
        ],
        'completed' => [
            'customer' => 'workorder_completed',
            'role:manager' => 'workorder_completed_manager',
        ],
        'ready_for_pickup' => [
            'customer' => 'workorder_ready_pickup',
            'customer_sms' => 'workorder_ready_pickup_sms',
        ],
        'awaiting_authorization' => [
            'customer' => 'workorder_awaiting_auth',
            'customer_sms' => 'workorder_awaiting_auth_sms',
        ],
        'cancelled' => [
            'customer' => 'workorder_cancelled',
            'assigned_technician' => 'workorder_cancelled_tech',
        ],
    ];

    public function __construct(
        Connection $connection,
        NotificationEventService $notificationService,
        ?AuditLogger $audit = null
    ) {
        $this->connection = $connection;
        $this->notificationService = $notificationService;
        $this->audit = $audit;
    }

    /**
     * Process a status change and send appropriate notifications.
     */
    public function onStatusChange(
        int $workorderId,
        string $fromStatus,
        string $toStatus,
        ?int $actorId = null
    ): array {
        $workorder = $this->fetchWorkorder($workorderId);
        if ($workorder === null) {
            return ['sent' => 0, 'errors' => ['Workorder not found']];
        }

        $rules = $this->getNotificationRules($toStatus);
        if (empty($rules)) {
            return ['sent' => 0, 'skipped' => 'No notification rules for status: ' . $toStatus];
        }

        $customer = $this->fetchCustomer($workorder['customer_id']);
        $vehicle = $this->fetchVehicle($workorder['vehicle_id']);
        $technician = $workorder['assigned_technician_id']
            ? $this->fetchUser($workorder['assigned_technician_id'])
            : null;

        $sent = 0;
        $errors = [];

        foreach ($rules as $recipientType => $templateKey) {
            try {
                $recipients = $this->resolveRecipients($recipientType, $workorder, $customer, $technician);

                foreach ($recipients as $recipient) {
                    $context = $this->buildNotificationContext(
                        $workorder,
                        $customer,
                        $vehicle,
                        $technician,
                        $fromStatus,
                        $toStatus,
                        $recipient
                    );

                    $this->notificationService->trigger('workorder.' . $templateKey, $context);
                    $sent++;

                    $this->log('workorder.notification_sent', $workorderId, $actorId, [
                        'template' => $templateKey,
                        'recipient_type' => $recipientType,
                        'recipient' => $recipient['email'] ?? $recipient['phone'] ?? 'unknown',
                    ]);
                }
            } catch (\Throwable $e) {
                $errors[] = "Failed to send {$templateKey}: " . $e->getMessage();
            }
        }

        return [
            'sent' => $sent,
            'errors' => $errors,
        ];
    }

    /**
     * Register a custom notification rule for a status.
     */
    public function registerCustomRule(string $toStatus, string $recipientType, string $templateKey): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO workorder_notification_rules (to_status, recipient_type, template_key, active, created_at)
            VALUES (:status, :recipient, :template, 1, NOW())
            ON DUPLICATE KEY UPDATE template_key = VALUES(template_key), active = 1
        SQL);

        $stmt->execute([
            'status' => $toStatus,
            'recipient' => $recipientType,
            'template' => $templateKey,
        ]);
    }

    /**
     * Get all notification rules for a status (built-in + custom).
     *
     * @return array<string, string>
     */
    private function getNotificationRules(string $toStatus): array
    {
        // Start with built-in rules
        $rules = self::STATUS_NOTIFICATION_RULES[$toStatus] ?? [];

        // Add/override with custom rules from database
        $customRules = $this->fetchCustomRules($toStatus);
        foreach ($customRules as $rule) {
            $rules[$rule['recipient_type']] = $rule['template_key'];
        }

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCustomRules(string $toStatus): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT recipient_type, template_key
            FROM workorder_notification_rules
            WHERE to_status = :status AND active = 1
        SQL);
        $stmt->execute(['status' => $toStatus]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Resolve recipients based on recipient type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveRecipients(
        string $recipientType,
        array $workorder,
        ?array $customer,
        ?array $technician
    ): array {
        $recipients = [];

        // Customer notification
        if ($recipientType === 'customer' && $customer !== null) {
            if (!empty($customer['email'])) {
                $recipients[] = [
                    'type' => 'email',
                    'email' => $customer['email'],
                    'name' => $customer['name'] ?? 'Customer',
                ];
            }
        }

        // Customer SMS notification
        if ($recipientType === 'customer_sms' && $customer !== null) {
            if (!empty($customer['phone'])) {
                $recipients[] = [
                    'type' => 'sms',
                    'phone' => $customer['phone'],
                    'name' => $customer['name'] ?? 'Customer',
                ];
            }
        }

        // Assigned technician
        if ($recipientType === 'assigned_technician' && $technician !== null) {
            if (!empty($technician['email'])) {
                $recipients[] = [
                    'type' => 'email',
                    'email' => $technician['email'],
                    'name' => $technician['name'] ?? 'Technician',
                ];
            }
        }

        // Role-based recipients
        if (str_starts_with($recipientType, 'role:')) {
            $role = substr($recipientType, 5);
            $roleUsers = $this->fetchUsersByRole($role);
            foreach ($roleUsers as $user) {
                if (!empty($user['email'])) {
                    $recipients[] = [
                        'type' => 'email',
                        'email' => $user['email'],
                        'name' => $user['name'] ?? $role,
                    ];
                }
            }
        }

        return $recipients;
    }

    /**
     * Build the notification context with all variables.
     *
     * @return array<string, mixed>
     */
    private function buildNotificationContext(
        array $workorder,
        ?array $customer,
        ?array $vehicle,
        ?array $technician,
        string $fromStatus,
        string $toStatus,
        array $recipient
    ): array {
        return [
            // Recipient
            'recipient' => $recipient['email'] ?? $recipient['phone'] ?? '',
            'recipient_name' => $recipient['name'] ?? '',
            'channel' => $recipient['type'] ?? 'email',

            // Workorder details
            'workorder_id' => $workorder['id'],
            'workorder_number' => $workorder['number'] ?? 'WO-' . $workorder['id'],
            'from_status' => $this->formatStatus($fromStatus),
            'to_status' => $this->formatStatus($toStatus),
            'status' => $this->formatStatus($toStatus),
            'status_raw' => $toStatus,
            'priority' => ucfirst($workorder['priority'] ?? 'normal'),

            // Customer details
            'customer_name' => $customer['name'] ?? 'Customer',
            'customer_email' => $customer['email'] ?? '',
            'customer_phone' => $customer['phone'] ?? '',

            // Vehicle details
            'vehicle_year' => $vehicle['year'] ?? '',
            'vehicle_make' => $vehicle['make'] ?? '',
            'vehicle_model' => $vehicle['model'] ?? '',
            'vehicle_info' => trim(implode(' ', [
                $vehicle['year'] ?? '',
                $vehicle['make'] ?? '',
                $vehicle['model'] ?? '',
            ])),
            'vehicle_vin' => $vehicle['vin'] ?? '',
            'vehicle_license_plate' => $vehicle['license_plate'] ?? '',

            // Technician details
            'technician_name' => $technician['name'] ?? 'Unassigned',
            'technician_email' => $technician['email'] ?? '',

            // Financial
            'grand_total' => number_format((float) ($workorder['grand_total'] ?? 0), 2),

            // Timestamps
            'created_at' => $workorder['created_at'] ?? '',
            'updated_at' => $workorder['updated_at'] ?? date('Y-m-d H:i:s'),

            // Links (to be replaced with actual URLs)
            'workorder_link' => '/cp/workorders/' . $workorder['id'],
            'portal_link' => '/portal/workorders/' . $workorder['id'],
        ];
    }

    private function formatStatus(string $status): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $status));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWorkorder(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM workorders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCustomer(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM customers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchVehicle(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT cv.*, vm.year, vm.make, vm.model
            FROM customer_vehicles cv
            LEFT JOIN vehicle_master vm ON cv.vehicle_master_id = vm.id
            WHERE cv.id = :id
        SQL);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUser(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchUsersByRole(string $role): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT u.* FROM users u
            WHERE u.role = :role AND u.active = 1
            ORDER BY u.name ASC
        SQL);
        $stmt->execute(['role' => $role]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function log(string $event, int $workorderId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'workorder', (string) $workorderId, $actorId, $context));
    }
}
