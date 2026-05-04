<?php

namespace App\Services\Assets;

use App\Models\AssetImport;
use App\Models\AssetImportRow;
use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 18 / S12 — HTTP facade for the bulk asset import flow.
 *
 * Uploads → mapping updates → dry-run validate → apply. Each step writes
 * back the header so the client can re-render the wizard from a single
 * response.
 */
class AssetImportController
{
    public function __construct(private readonly AssetImportService $service)
    {
    }

    // ─────────────────────────────────────── upload ────

    /**
     * Body MAY contain a file upload (multipart, field "file") or a raw "csv"
     * text field. Optional header defaults can come either as JSON body or
     * as multipart form fields.
     *
     * @param array<string, mixed> $payload  parsed body fields
     * @param array<string, mixed>|null $file standard $_FILES entry
     * @return array<string, mixed>
     */
    public function upload(User $actor, array $payload, ?array $file): array
    {
        $csv = $this->extractCsv($payload, $file);
        $defaults = [
            'filename' => is_array($file) && isset($file['name']) ? (string) $file['name'] : ($payload['filename'] ?? null),
            'default_site_id' => $this->intOrNull($payload['default_site_id'] ?? null),
            'default_division_id' => $this->intOrNull($payload['default_division_id'] ?? null),
            'default_asset_type_id' => $this->intOrNull($payload['default_asset_type_id'] ?? null),
            'mapping' => $this->decodeMapping($payload['mapping'] ?? null),
            'notes' => isset($payload['notes']) ? (string) $payload['notes'] : null,
        ];
        $header = $this->service->upload($actor, $csv, $defaults);
        return ['data' => self::headerToArray($header)];
    }

    // ─────────────────────────────────────── mapping update ────

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateMapping(User $actor, int $importId, array $payload): array
    {
        $update = [];
        if (array_key_exists('mapping', $payload)) {
            $update['mapping'] = $this->decodeMapping($payload['mapping']);
        }
        foreach (['default_site_id', 'default_division_id', 'default_asset_type_id'] as $col) {
            if (array_key_exists($col, $payload)) {
                $update[$col] = $this->intOrNull($payload[$col]);
            }
        }
        if (array_key_exists('notes', $payload)) {
            $update['notes'] = $payload['notes'] === null ? null : (string) $payload['notes'];
        }
        $header = $this->service->updateMapping($actor, $importId, $update);
        return ['data' => self::headerToArray($header)];
    }

    // ─────────────────────────────────────── validate / apply / cancel ────

    /**
     * @return array<string, mixed>
     */
    public function validate(User $actor, int $importId): array
    {
        $result = $this->service->validate($actor, $importId);
        return [
            'data' => [
                'header' => self::headerToArray($result['header']),
                'valid' => $result['valid'],
                'invalid' => $result['invalid'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(User $actor, int $importId): array
    {
        $result = $this->service->apply($actor, $importId);
        return [
            'data' => [
                'header' => self::headerToArray($result['header']),
                'created' => $result['created'],
                'failed' => $result['failed'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function cancel(User $actor, int $importId, array $payload): array
    {
        $reason = isset($payload['reason']) && $payload['reason'] !== ''
            ? (string) $payload['reason']
            : null;
        $header = $this->service->cancel($actor, $importId, $reason);
        return ['data' => self::headerToArray($header)];
    }

    // ─────────────────────────────────────── read ────

    /**
     * @return array<string, mixed>
     */
    public function listImports(User $actor, int $limit = 50): array
    {
        $headers = $this->service->listImports($actor, $limit);
        return ['data' => array_map(self::headerToArray(...), $headers)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetail(User $actor, int $importId): array
    {
        $detail = $this->service->getDetail($actor, $importId);
        return [
            'data' => [
                'header' => self::headerToArray($detail['header']),
                'status_counts' => $detail['status_counts'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listRows(User $actor, int $importId, ?string $status, int $limit, int $offset): array
    {
        $rows = $this->service->listRows($actor, $importId, $status, $limit, $offset);
        return ['data' => array_map(self::rowToArray(...), $rows)];
    }

    // ─────────────────────────────────────── helpers ────

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $file
     */
    private function extractCsv(array $payload, ?array $file): string
    {
        if (is_array($file) && isset($file['tmp_name']) && is_string($file['tmp_name']) && $file['tmp_name'] !== '') {
            if (isset($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload failed (php error code ' . (int) $file['error'] . ')');
            }
            $contents = @file_get_contents($file['tmp_name']);
            if ($contents === false) {
                throw new InvalidArgumentException('Could not read uploaded file');
            }
            return $contents;
        }
        if (isset($payload['csv']) && is_string($payload['csv']) && $payload['csv'] !== '') {
            return $payload['csv'];
        }
        throw new InvalidArgumentException('A CSV file (multipart "file") or raw "csv" body field is required');
    }

    /**
     * Mapping can arrive as a JSON object (preferred) or a JSON string when
     * it slips through a multipart form field — accept either.
     */
    private function decodeMapping(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $out[$k] = $v;
                }
            }
            return $out === [] ? null : $out;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    private function intOrNull(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        return (int) $raw;
    }

    private static function headerToArray(AssetImport $h): array
    {
        return [
            'id' => $h->id,
            'status' => $h->status,
            'original_filename' => $h->original_filename,
            'mapping' => $h->mapping,
            'default_site_id' => $h->default_site_id,
            'default_division_id' => $h->default_division_id,
            'default_asset_type_id' => $h->default_asset_type_id,
            'total_rows' => $h->total_rows,
            'valid_rows' => $h->valid_rows,
            'error_rows' => $h->error_rows,
            'created_rows' => $h->created_rows,
            'started_by_user_id' => $h->started_by_user_id,
            'started_at' => $h->started_at,
            'validated_at' => $h->validated_at,
            'applied_at' => $h->applied_at,
            'notes' => $h->notes,
        ];
    }

    private static function rowToArray(AssetImportRow $r): array
    {
        return [
            'id' => $r->id,
            'import_id' => $r->import_id,
            'row_number' => $r->row_number,
            'raw_data' => $r->raw_data,
            'parsed_data' => $r->parsed_data,
            'status' => $r->status,
            'error_message' => $r->error_message,
            'created_asset_id' => $r->created_asset_id,
        ];
    }
}
