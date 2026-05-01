<?php

namespace App\Services\Estimate;

use App\Database\Connection;
use App\Services\Messaging\MessagingNotificationService;
use App\Support\Notifications\NotificationDispatcher;

class EstimateShareService
{
    private Connection $connection;
    private EstimateRepository $estimates;
    private EstimatePublicLinkService $links;
    private NotificationDispatcher $notifications;
    private ?MessagingNotificationService $messagingNotifications;
    private array $settings;

    public function __construct(
        Connection $connection,
        EstimateRepository $estimates,
        EstimatePublicLinkService $links,
        NotificationDispatcher $notifications,
        ?MessagingNotificationService $messagingNotifications = null,
        array $settings = []
    ) {
        $this->connection = $connection;
        $this->estimates = $estimates;
        $this->links = $links;
        $this->notifications = $notifications;
        $this->messagingNotifications = $messagingNotifications;
        $this->settings = $settings;
    }

    public function shareViaEmail(int $estimateId, string $email, string $baseUrl, ?int $actorId = null): array
    {
        $estimate = $this->estimates->find($estimateId);
        if ($estimate === null) {
            throw new \InvalidArgumentException('Estimate not found');
        }

        // Generate the public link
        $link = $this->links->issueLink($estimateId, $baseUrl, $estimate->expiration_date);

        // Get template from settings or use defaults
        $subjectTemplate = $this->getSetting('templates.estimate.email_subject', 'Your Estimate #{estimate_number} from {shop_name}');
        $bodyTemplate = $this->getSetting('templates.estimate.email_body', $this->getDefaultEmailBody());

        // Build the variables for template substitution
        $variables = $this->buildTemplateVariables($estimate, $link['secure_url']);

        // Replace variables in templates
        $subject = $this->replaceVariables($subjectTemplate, $variables);
        $body = $this->replaceVariables($bodyTemplate, $variables);

        // Send the email directly using SMTP
        $this->sendDirectEmail($email, $subject, $body);

        $this->messagingNotifications?->dispatch('estimate.link_sent', [
            'estimate_id' => $estimateId,
            'channel' => 'email',
            'recipient' => $email,
            'actor_id' => $actorId,
        ]);

        return [
            'success' => true,
            'recipient' => $email,
            'link' => $link['secure_url'],
        ];
    }

    public function shareViaSms(int $estimateId, string $phone, string $baseUrl, ?int $actorId = null): array
    {
        $estimate = $this->estimates->find($estimateId);
        if ($estimate === null) {
            throw new \InvalidArgumentException('Estimate not found');
        }

        // Generate the public link - use short URL for SMS
        $link = $this->links->issueLink($estimateId, $baseUrl, $estimate->expiration_date);

        // Get template from settings or use default
        $bodyTemplate = $this->getSetting('templates.estimate.sms_body', 'Hi {customer}, your estimate #{estimate_number} for {total} is ready. View: {link} - {shop_name}');

        // Build the variables for template substitution
        $variables = $this->buildTemplateVariables($estimate, $link['short_url']);

        // Replace variables in template
        $message = $this->replaceVariables($bodyTemplate, $variables);

        // Send the SMS directly using Twilio
        $this->sendDirectSms($phone, $message);

        $this->messagingNotifications?->dispatch('estimate.link_sent', [
            'estimate_id' => $estimateId,
            'channel' => 'sms',
            'recipient' => $phone,
            'actor_id' => $actorId,
        ]);

        return [
            'success' => true,
            'recipient' => $phone,
            'link' => $link['short_url'],
        ];
    }

    private function buildTemplateVariables($estimate, string $linkUrl): array
    {
        $customer = $this->fetchCustomer($estimate->customer_id);
        // vehicle_id is NULL on non-automotive estimates (property, IT, etc.).
        $vehicle = $estimate->vehicle_id ? $this->fetchVehicle($estimate->vehicle_id) : null;

        $vehicleDescription = '';
        if ($vehicle) {
            $parts = array_filter([$vehicle['year'], $vehicle['make'], $vehicle['model']]);
            $vehicleDescription = implode(' ', $parts);
        }

        return [
            'customer' => $customer['name'] ?? 'Valued Customer',
            'customer_name' => $customer['name'] ?? 'Valued Customer',
            'customer_email' => $customer['email'] ?? '',
            'customer_phone' => $customer['phone'] ?? '',
            'estimate_number' => $estimate->number,
            'total' => $this->formatCurrency($estimate->grand_total),
            'vehicle' => $vehicleDescription ?: 'Your Vehicle',
            'expiration_date' => $estimate->expiration_date ? date('M j, Y', strtotime($estimate->expiration_date)) : 'N/A',
            'link' => $linkUrl,
            'shop_name' => $this->getSetting('shop.name', 'Our Shop'),
            'shop_phone' => $this->getSetting('shop.phone', ''),
            'shop_email' => $this->getSetting('shop.email', ''),
        ];
    }

    private function replaceVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        return $template;
    }

    private function fetchCustomer(int $customerId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id, CONCAT(first_name, " ", last_name) AS name, email, phone FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function fetchVehicle(int $vehicleId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id, year, make, model, vin, license_plate FROM customer_vehicles WHERE id = :id');
        $stmt->execute(['id' => $vehicleId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function formatCurrency(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    private function getSetting(string $key, $default = null)
    {
        // First check in-memory settings array
        if (isset($this->settings[$key])) {
            return $this->settings[$key];
        }

        // Then check database
        $stmt = $this->connection->pdo()->prepare('SELECT value FROM settings WHERE `key` = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row && isset($row['value'])) {
            $decoded = json_decode($row['value'], true);
            return $decoded ?? $row['value'];
        }

        return $default;
    }

    private function getDefaultEmailBody(): string
    {
        return '<p>Hi {customer},</p>' .
            '<p>Your estimate <strong>#{estimate_number}</strong> for <strong>{total}</strong> is ready for review.</p>' .
            '<p>Vehicle: {vehicle}</p>' .
            '<p><a href="{link}">Click here to view your estimate</a></p>' .
            '<p>This estimate expires on {expiration_date}.</p>' .
            '<p>Thank you,<br>{shop_name}<br>{shop_phone}</p>';
    }

    private function sendDirectEmail(string $to, string $subject, string $body): void
    {
        $smtpHost = $this->getSetting('integrations.smtp.host');
        $smtpPort = (int) $this->getSetting('integrations.smtp.port', 587);
        $smtpUser = $this->getSetting('integrations.smtp.username');
        $smtpPass = $this->getSetting('integrations.smtp.password');
        $smtpEncryption = strtolower((string) $this->getSetting('integrations.smtp.encryption', 'tls'));
        $fromName = $this->getSetting('notifications.mail.from_name', 'Auto Shop');
        $fromAddress = $this->getSetting('notifications.mail.from_address');

        if (!$smtpHost || !$fromAddress) {
            throw new \RuntimeException('SMTP is not configured. Please configure email settings.');
        }

        if ($smtpEncryption === 'starttls') {
            $smtpEncryption = 'tls';
        }

        // Open SMTP connection
        $transport = $smtpEncryption === 'ssl' ? 'ssl' : 'tcp';
        $endpoint = sprintf('%s://%s:%d', $transport, $smtpHost, $smtpPort);
        $socket = stream_socket_client($endpoint, $errno, $error, 10, STREAM_CLIENT_CONNECT);

        if (!$socket) {
            throw new \RuntimeException(sprintf('Unable to connect to SMTP server: %s (%s)', $error ?: 'unknown error', $errno));
        }

        stream_set_timeout($socket, 10);
        $this->smtpExpectResponse($socket, [220]);

        $hostname = gethostname() ?: 'localhost';
        $this->smtpSendCommand($socket, "EHLO {$hostname}", [250]);

        if ($smtpEncryption === 'tls') {
            $this->smtpSendCommand($socket, 'STARTTLS', [220]);
            $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new \RuntimeException('Failed to establish TLS encryption with the SMTP server.');
            }
            $this->smtpSendCommand($socket, "EHLO {$hostname}", [250]);
        }

        if ($smtpUser !== '' && $smtpUser !== null) {
            $this->smtpSendCommand($socket, 'AUTH LOGIN', [334]);
            $this->smtpSendCommand($socket, base64_encode($smtpUser), [334]);
            $this->smtpSendCommand($socket, base64_encode($smtpPass), [235]);
        }

        $this->smtpSendCommand($socket, sprintf('MAIL FROM:<%s>', $fromAddress), [250]);
        $this->smtpSendCommand($socket, sprintf('RCPT TO:<%s>', $to), [250, 251]);
        $this->smtpSendCommand($socket, 'DATA', [354]);

        $fromHeader = $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromAddress) : $fromAddress;
        $headers = [
            'Subject: ' . $subject,
            'From: ' . $fromHeader,
            'To: ' . $to,
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $message = str_replace("\n.", "\n..", str_replace("\r\n", "\n", $message));
        $message = str_replace("\n", "\r\n", $message);

        fwrite($socket, $message . "\r\n.\r\n");
        $this->smtpExpectResponse($socket, [250]);
        $this->smtpSendCommand($socket, 'QUIT', [221, 250]);
        fclose($socket);
    }

    private function smtpSendCommand($socket, string $command, array $expectedCodes): void
    {
        fwrite($socket, $command . "\r\n");
        $this->smtpExpectResponse($socket, $expectedCodes);
    }

    private function smtpExpectResponse($socket, array $expectedCodes): void
    {
        $message = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }
            $message .= $line;
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }

        if ($message === '') {
            throw new \RuntimeException('No response from SMTP server.');
        }

        $code = (int) substr(trim($message), 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException(sprintf('SMTP error: %s', trim($message)));
        }
    }

    private function sendDirectSms(string $to, string $message): void
    {
        $twilioSid = $this->getSetting('integrations.twilio.sid');
        $twilioToken = $this->getSetting('integrations.twilio.token');
        $fromNumber = $this->getSetting('notifications.sms.from_number');

        if (!$twilioSid || !$twilioToken || !$fromNumber) {
            throw new \RuntimeException('Twilio is not configured. Please configure SMS settings.');
        }

        // Normalize phone number
        $to = preg_replace('/[^0-9+]/', '', $to);
        if (!str_starts_with($to, '+')) {
            $to = '+1' . $to; // Default to US
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Messages.json";

        $data = [
            'To' => $to,
            'From' => $fromNumber,
            'Body' => $message,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$twilioSid}:{$twilioToken}");
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            $error = json_decode($response, true);
            throw new \RuntimeException($error['message'] ?? 'Failed to send SMS');
        }
    }
}
