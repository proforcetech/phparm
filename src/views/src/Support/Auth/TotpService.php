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
        $timestamp = time();
        $normalizedCode = preg_replace('/\s+/', '', $code);
        if ($normalizedCode === null) {
            return false;
        }

        for ($i = -$window; $i <= $window; $i++) {
            $expected = $this->generateCode($secret, $timestamp + ($i * $this->period));
            if (hash_equals($expected, $normalizedCode)) {
                return true;
            }
        }

        return false;
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
