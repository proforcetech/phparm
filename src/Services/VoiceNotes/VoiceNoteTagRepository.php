<?php

namespace App\Services\VoiceNotes;

use App\Database\Connection;
use App\Models\VoiceNoteTag;
use PDO;

/**
 * Phase 10.7 — persistence for voice_note_tags.
 *
 * Tag rows are append-only from the database's perspective; the only
 * mutating operation is replaceTags(), which the service layer calls when
 * a reviewer overwrites the tag set on a note. The repository implements
 * replaceTags() as a diff operation rather than a delete-and-reinsert so
 * the created_at on existing tags is preserved (UI surfaces "tagged 3
 * weeks ago" affordances).
 *
 * The DB-level UNIQUE (voice_note_id, tag) constraint catches accidental
 * duplicate adds; addTag() proactively swallows duplicate-key errors so
 * the API call still returns success on the second add.
 */
class VoiceNoteTagRepository
{
    private const COLUMNS = 'id, voice_note_id, tag, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, VoiceNoteTag>
     */
    public function listForNote(int $voiceNoteId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM voice_note_tags
             WHERE voice_note_id = :v
             ORDER BY tag ASC'
        );
        $stmt->execute(['v' => $voiceNoteId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new VoiceNoteTag($r), $rows);
    }

    /**
     * Distinct tags across the table — drives the autocomplete dropdown
     * in the tagging UI. Limited so a long tag-set doesn't blow the
     * payload.
     *
     * @return array<int, string>
     */
    public function listAllTags(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->connection->pdo()->prepare(
            "SELECT DISTINCT tag FROM voice_note_tags ORDER BY tag ASC LIMIT {$limit}"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map(static fn($t) => (string) $t, $rows);
    }

    /**
     * Idempotent — duplicate (voice_note_id, tag) is a no-op via the
     * UNIQUE constraint. Returns true if a row was inserted, false if
     * the tag was already present.
     */
    public function addTag(int $voiceNoteId, string $tag): bool
    {
        $tag = self::normaliseTag($tag);
        if ($tag === '') {
            return false;
        }
        try {
            $stmt = $this->connection->pdo()->prepare(
                'INSERT INTO voice_note_tags (voice_note_id, tag) VALUES (:v, :t)'
            );
            $stmt->execute(['v' => $voiceNoteId, 't' => $tag]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            // SQLSTATE 23000 (integrity constraint) — duplicate tag, swallow.
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    public function removeTag(int $voiceNoteId, string $tag): void
    {
        $tag = self::normaliseTag($tag);
        if ($tag === '') {
            return;
        }
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM voice_note_tags WHERE voice_note_id = :v AND tag = :t'
        );
        $stmt->execute(['v' => $voiceNoteId, 't' => $tag]);
    }

    /**
     * Diff-based replace: tags present in $tags but not on the row are
     * inserted; tags on the row but not in $tags are deleted; tags in
     * both are left alone (preserving created_at).
     *
     * @param array<int, string> $tags
     * @return array{added: array<int, string>, removed: array<int, string>}
     */
    public function replaceTags(int $voiceNoteId, array $tags): array
    {
        $desired = [];
        foreach ($tags as $raw) {
            $clean = self::normaliseTag((string) $raw);
            if ($clean !== '') {
                $desired[$clean] = true;
            }
        }
        $current = [];
        foreach ($this->listForNote($voiceNoteId) as $row) {
            $current[$row->tag] = true;
        }
        $toAdd = array_diff_key($desired, $current);
        $toRemove = array_diff_key($current, $desired);

        foreach (array_keys($toAdd) as $tag) {
            $this->addTag($voiceNoteId, $tag);
        }
        foreach (array_keys($toRemove) as $tag) {
            $this->removeTag($voiceNoteId, $tag);
        }

        return [
            'added' => array_keys($toAdd),
            'removed' => array_keys($toRemove),
        ];
    }

    public function deleteAllForNote(int $voiceNoteId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM voice_note_tags WHERE voice_note_id = :v'
        );
        $stmt->execute(['v' => $voiceNoteId]);
    }

    /**
     * Lowercase, trim, collapse internal whitespace runs to single spaces.
     * Matches the canonical form the UNIQUE constraint sees, so "Brake "
     * and "brake" collapse to one row.
     */
    private static function normaliseTag(string $tag): string
    {
        $cleaned = trim(strtolower($tag));
        return (string) preg_replace('/\s+/', ' ', $cleaned);
    }
}
