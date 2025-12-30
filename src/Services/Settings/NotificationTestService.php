<?php

namespace App\Services\Settings;

use App\Support\Notifications\TwilioSmsDriver;
use App\Support\SettingsRepository;
use RuntimeException;

class NotificationTestService
{
    private const DEFAULT_TIMEOUT = 10;
    private SettingsRepository $settings;
    private int $timeout;

    public function __construct(SettingsRepository $settings, int $timeout = self::DEFAULT_TIMEOUT)
    {
        $this->settings = $settings;
        $this->timeout = $timeout;
    }

    /**
     * @return array<string, string>
     */
    public function testSmtpConnection(): array
    {
        $config = $this->smtpConfig();
        [$socket, $host, $port, $encryption] = $this->openSmtpConnection($config);
        $this->sendCommand($socket, 'QUIT', [221, 250]);
        fclose($socket);

        return [
            'status' => 'ok',
            'message' => sprintf('Connected to SMTP server %s:%d using %s.', $host, $port, strtoupper($encryption)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function sendTestEmail(string $recipient): array
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid recipient email address is required.');
        }

        $config = $this->smtpConfig();
        $fromAddress = (string) $this->settings->get('notifications.mail.from_address', '');
        $fromName = (string) $this->settings->get('notifications.mail.from_name', 'Auto Repair Shop');

        if ($fromAddress === '') {
            throw new RuntimeException('SMTP "From Email" is required to send test messages.');
        }

        $subject = 'SMTP Test Email';
        $body = sprintf(
            "This is a test email from %s.\n\nIf you received this message, your SMTP settings are working.",
            $fromName !== '' ? $fromName : 'your shop'
        );

        $this->sendEmail($config, $fromAddress, $fromName, $recipient, $subject, $body);

        return [
            'status' => 'sent',
            'message' => sprintf('Test email sent to %s.', $recipient),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function testTwilioConnection(): array
    {
        $sid = (string) $this->settings->get('integrations.twilio.sid', '');
        $token = (string) $this->settings->get('integrations.twilio.token', '');

        if ($sid === '' || $token === '') {
            throw new RuntimeException('Twilio SID and token are required to test the connection.');
        }

        $url = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s.json', $sid);
        $response = $this->twilioRequest($url, $sid, $token);

        return [
            'status' => 'ok',
            'message' => sprintf('Twilio connection successful for account %s.', $response['friendly_name'] ?? $sid),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function sendTestSms(string $recipient): array
    {
        $sid = (string) $this->settings->get('integrations.twilio.sid', '');
        $token = (string) $this->settings->get('integrations.twilio.token', '');
        $fromNumber = (string) $this->settings->get('notifications.sms.from_number', '');
        $shopName = (string) $this->settings->get('shop.name', 'Auto Repair Shop');

        if ($sid === '' || $token === '' || $fromNumber === '') {
            throw new RuntimeException('Twilio SID, token, and from number are required to send test SMS.');
        }

        $normalizedRecipient = $this->normalizePhoneNumber($recipient);
        if ($normalizedRecipient === '') {
            throw new RuntimeException('A valid recipient phone number is required.');
        }

        $driver = new TwilioSmsDriver(['sid' => $sid, 'token' => $token]);
        $message = sprintf('Test SMS from %s. If you received this, your Twilio settings are working.', $shopName);
        $driver->send($normalizedRecipient, $message, $fromNumber);

        return [
            'status' => 'sent',
            'message' => sprintf('Test SMS sent to %s.', $normalizedRecipient),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function smtpConfig(): array
    {
        $host = (string) $this->settings->get('integrations.smtp.host', '');
        $port = (int) $this->settings->get('integrations.smtp.port', 0);
        $username = (string) $this->settings->get('integrations.smtp.username', '');
        $password = (string) $this->settings->get('integrations.smtp.password', '');
        $encryption = strtolower((string) $this->settings->get('integrations.smtp.encryption', 'tls'));
        if ($encryption === 'starttls') {
            $encryption = 'tls';
        }

        if ($host === '' || $port <= 0) {
            throw new RuntimeException('SMTP host and port are required.');
        }

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => $encryption !== '' ? $encryption : 'none',
        ];
    }

    /**
     * @return array{0: resource, 1: string, 2: int, 3: string}
     */
    private function openSmtpConnection(array $config): array
    {
        $host = (string) $config['host'];
        $port = (int) $config['port'];
        $encryption = (string) $config['encryption'];

        $transport = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $endpoint = sprintf('%s://%s:%d', $transport, $host, $port);
        $socket = stream_socket_client($endpoint, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT);

        if (!$socket) {
            throw new RuntimeException(sprintf('Unable to connect to SMTP server: %s (%s).', $error ?: 'unknown error', $errno));
        }

        stream_set_timeout($socket, $this->timeout);
        $this->expectResponse($socket, [220]);

        $hostname = gethostname() ?: 'localhost';
        $this->sendCommand($socket, "EHLO {$hostname}", [250]);

        if ($encryption === 'tls') {
            $this->sendCommand($socket, 'STARTTLS', [220]);
            $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('Failed to establish TLS encryption with the SMTP server.');
            }
            $this->sendCommand($socket, "EHLO {$hostname}", [250]);
        }

        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        if ($username !== '') {
            $this->authenticate($socket, $username, $password);
        }

        return [$socket, $host, $port, $encryption !== '' ? $encryption : 'none'];
    }

    private function sendEmail(array $config, string $fromAddress, string $fromName, string $recipient, string $subject, string $body): void
    {
        [$socket] = $this->openSmtpConnection($config);

        $this->sendCommand($socket, sprintf('MAIL FROM:<%s>', $fromAddress), [250]);
        $this->sendCommand($socket, sprintf('RCPT TO:<%s>', $recipient), [250, 251]);
        $this->sendCommand($socket, 'DATA', [354]);

        $fromHeader = $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromAddress) : $fromAddress;
        $headers = [
            'Subject: ' . $subject,
            'From: ' . $fromHeader,
            'To: ' . $recipient,
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $message = str_replace("\n.", "\n..", str_replace("\r\n", "\n", $message));
        $message = str_replace("\n", "\r\n", $message);

        fwrite($socket, $message . "\r\n.\r\n");
        $this->expectResponse($socket, [250]);
        $this->sendCommand($socket, 'QUIT', [221, 250]);
        fclose($socket);
    }

    private function authenticate($socket, string $username, string $password): void
    {
        $this->sendCommand($socket, 'AUTH LOGIN', [334]);
        $this->sendCommand($socket, base64_encode($username), [334]);
        $this->sendCommand($socket, base64_encode($password), [235]);
    }

    /**
     * @return array{code: int, message: string}
     */
    private function sendCommand($socket, string $command, array $expectedCodes): array
    {
        fwrite($socket, $command . "\r\n");
        $response = $this->readResponse($socket);
        if (!in_array($response['code'], $expectedCodes, true)) {
            throw new RuntimeException(sprintf('SMTP command "%s" failed: %s', $command, $response['message']));
        }

        return $response;
    }

    private function expectResponse($socket, array $expectedCodes): void
    {
        $response = $this->readResponse($socket);
        if (!in_array($response['code'], $expectedCodes, true)) {
            throw new RuntimeException(sprintf('SMTP connection failed: %s', $response['message']));
        }
    }

    /**
     * @return array{code: int, message: string}
     */
    private function readResponse($socket): array
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
            throw new RuntimeException('No response from SMTP server.');
        }

        $code = (int) substr(trim($message), 0, 3);

        return [
            'code' => $code,
            'message' => trim($message),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function twilioRequest(string $url, string $sid, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$sid}:{$token}");
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            throw new RuntimeException('Twilio connection failed: ' . ($error !== '' ? $error : $response));
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizePhoneNumber(string $phone): string
    {
        $normalized = preg_replace('/[^0-9+]/', '', $phone);
        if ($normalized === null || $normalized === '') {
            return '';
        }

        if (!str_starts_with($normalized, '+')) {
            $normalized = '+1' . $normalized;
        }

        return $normalized;
    }
}
