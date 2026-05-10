<?php

namespace App\Support\Auth;

class TotpService
{
    private int $period;
    private int $digits;

    public function __construct(int $period = 30, int $digits = 6)
    {
        $this->period = $period;
        $this->digits = $digits;
    }

    public function generateSecret(int $length = 16): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        return $this->matchCounter($secret, $code, $window) !== null;
    }

    /**
     * Returns the matched counter slot for the supplied code, or null if no
     * slot in the ± window matches. Step-up callers persist this counter so
     * a single captured code can't be replayed within the validity window
     * (see auth_step_up_verifications.totp_counter / AUD-068).
     */
    public function matchCounter(string $secret, string $code, int $window = 1): ?int
    {
        $normalizedCode = preg_replace('/\s+/', '', $code);
        if ($normalizedCode === null) {
            return null;
        }

        $timestamp = time();
        for ($i = -$window; $i <= $window; $i++) {
            $slotTime = $timestamp + ($i * $this->period);
            $expected = $this->generateCode($secret, $slotTime);
            if (hash_equals($expected, $normalizedCode)) {
                return (int) floor($slotTime / $this->period);
            }
        }

        return null;
    }

    public function generateCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = floor($timestamp / $this->period);
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
        $otp = $truncated % (10 ** $this->digits);

        return str_pad((string) $otp, $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Generate recovery codes for 2FA backup.
     *
     * @param int $count Number of codes to generate
     * @param int $length Length of each code
     * @return array{codes: array<string>, hashes: array<string>} Plain codes and their hashes
     */
    public function generateRecoveryCodes(int $count = 8, int $length = 8): array
    {
        $codes = [];
        $hashes = [];
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Exclude confusing chars (0, O, 1, I)

        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < $length; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = $code;
            $hashes[] = $this->hashRecoveryCode($code);
        }

        return ['codes' => $codes, 'hashes' => $hashes];
    }

    /**
     * Hash a recovery code for secure storage.
     *
     * @param string $code Plain recovery code
     * @return string Hashed code
     */
    public function hashRecoveryCode(string $code): string
    {
        // Normalize: uppercase and remove spaces/dashes
        $normalized = strtoupper(preg_replace('/[\s\-]/', '', $code));
        return hash('sha256', $normalized);
    }

    /**
     * Verify a recovery code against stored hashes.
     *
     * @param string $code User-provided recovery code
     * @param array<string> $storedHashes Array of hashed recovery codes
     * @return int|false Index of matched code or false if not found
     */
    public function verifyRecoveryCode(string $code, array $storedHashes): int|false
    {
        $hash = $this->hashRecoveryCode($code);

        foreach ($storedHashes as $index => $storedHash) {
            if (hash_equals($storedHash, $hash)) {
                return $index;
            }
        }

        return false;
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $paddingCharCount = substr_count($secret, '=');
        $allowedValues = [6, 4, 3, 1, 0];
        if (!in_array($paddingCharCount, $allowedValues, true)) {
            throw new \InvalidArgumentException('Invalid base32 padding.');
        }

        $secret = str_replace('=', '', $secret);
        $binaryString = '';
        for ($i = 0; $i < strlen($secret); $i++) {
            $current = strpos($alphabet, $secret[$i]);
            if ($current === false) {
                throw new \InvalidArgumentException('Invalid base32 character detected.');
            }
            $binaryString .= str_pad(decbin($current), 5, '0', STR_PAD_LEFT);
        }

        $eightBits = str_split($binaryString, 8);
        $decoded = '';
        foreach ($eightBits as $bits) {
            if (strlen($bits) === 8) {
                $decoded .= chr(bindec($bits));
            }
        }

        return $decoded;
    }
}
