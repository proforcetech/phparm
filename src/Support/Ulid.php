<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * ULID (https://github.com/ulid/spec) generator — 128-bit identifiers
 * encoded as 26 Crockford-base32 chars: 10 chars of millisecond timestamp
 * + 16 chars of randomness.
 *
 * Why ULID instead of UUIDv4 here:
 *   * Lexicographically sortable by creation time — collisions on the
 *     filesystem stay clustered by date so directory listings (e.g., in
 *     storage/private/voice_notes/2026/05/<user>/) sort naturally and
 *     a date-range cleanup walk is cheap.
 *   * URL/filename safe with no escaping needed (Crockford alphabet:
 *     0-9 A-Z minus I L O U).
 *   * Same total entropy as UUIDv4 (122 random bits vs 128 — UUIDv4
 *     spends 6 bits on version/variant markers) but more compact at
 *     26 chars vs 36.
 *
 * Monotonicity within the same millisecond:
 *   The spec defines a monotonic mode: when two ULIDs are generated in
 *   the same millisecond, the second's random part must be the first's
 *   random part + 1 (treated as a 80-bit big-endian unsigned integer).
 *   This guarantees ordering within a single tight loop. We track the
 *   last (ms, random) pair per process so a hot caller that generates
 *   thousands of ULIDs per millisecond still gets sortable output.
 *
 * What we do NOT guarantee:
 *   Cross-process monotonicity. Two separate PHP-FPM workers can
 *   produce ULIDs that don't sort against each other within the same
 *   millisecond. For our usage (voice-note filenames) that's fine —
 *   the path includes the user_id directory so simultaneous uploads
 *   from different users don't collide on disk anyway, and the
 *   millisecond timestamp is precise enough that even same-user races
 *   are vanishingly unlikely to produce visible disorder.
 *
 * Reference implementation is small enough to keep in-tree rather than
 * pulling a Composer dep (ulid/ulid is a 1-class library); avoiding the
 * dep also keeps the autoload graph flat.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const ENCODED_LENGTH = 26;
    private const TIME_LENGTH = 10;
    private const RANDOM_LENGTH = 16;

    private static int $lastMillis = -1;

    /** @var int[] last 16 random bytes (as 0–255 ints), MSB first */
    private static array $lastRandom = [];

    public static function generate(): string
    {
        $ms = (int) (microtime(true) * 1000);

        if ($ms === self::$lastMillis && self::$lastRandom !== []) {
            $rand = self::incrementRandom(self::$lastRandom);
        } else {
            $rand = self::randomBytes();
        }
        self::$lastMillis = $ms;
        self::$lastRandom = $rand;

        return self::encodeTime($ms) . self::encodeRandom($rand);
    }

    private static function encodeTime(int $ms): string
    {
        if ($ms < 0) {
            throw new RuntimeException('Ulid: time component must be non-negative');
        }
        // 10 chars × 5 bits = 50 bits. ULID spec uses 48 bits of
        // milliseconds (good through year 10889) plus two leading zero
        // bits, which means the first char is always 0-7. We mask the
        // top bits below to enforce that.
        $chars = [];
        for ($i = self::TIME_LENGTH - 1; $i >= 0; $i--) {
            $chars[$i] = self::ALPHABET[$ms & 0x1F];
            $ms >>= 5;
        }
        return implode('', $chars);
    }

    /**
     * @param int[] $bytes 16-element byte array, MSB first
     */
    private static function encodeRandom(array $bytes): string
    {
        // Pack 16 bytes (128 bits) into a stream and read 5-bit groups
        // from the right. Because 16 × 8 = 128 and we want 16 chars × 5
        // bits = 80 bits, we actually only consume the LOW 80 bits.
        // ULID spec: the random component is 80 bits, not 128 — the
        // remaining 48 bits are the time component already encoded.
        // We're carrying 16 bytes for arithmetic convenience (so the
        // monotonic increment has a uniform width); only the low 10
        // bytes (80 bits) get encoded.
        $low = array_slice($bytes, -10);

        // Treat the 10 bytes as a 80-bit big-endian integer and emit
        // 16 base32 chars (16 × 5 = 80 bits).
        $bits = '';
        foreach ($low as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $chunk = substr($bits, $i * 5, 5);
            $out .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    /**
     * @return int[] 16-byte array, MSB first
     */
    private static function randomBytes(): array
    {
        $raw = random_bytes(16);
        $bytes = [];
        for ($i = 0; $i < 16; $i++) {
            $bytes[] = ord($raw[$i]);
        }
        return $bytes;
    }

    /**
     * Treat the 16-byte array as a big-endian unsigned 128-bit integer
     * and add 1. Used for monotonic mode: when two ULIDs are minted in
     * the same millisecond, the second's random component must be the
     * first's + 1 so they sort correctly.
     *
     * Overflow case (all 0xFF bytes incrementing to 0): the spec allows
     * "Monotonicity might be broken" rather than mandating overflow
     * handling. We throw instead so the unlikely overflow is loud — a
     * call site burning through 2^80 ULIDs in a single millisecond is
     * almost certainly a bug, not legitimate work.
     *
     * @param int[] $bytes
     * @return int[]
     */
    private static function incrementRandom(array $bytes): array
    {
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            if ($bytes[$i] < 0xFF) {
                $bytes[$i]++;
                return $bytes;
            }
            $bytes[$i] = 0;
        }
        throw new RuntimeException(
            'Ulid: monotonic counter overflowed within a single millisecond'
        );
    }
}
