<?php

namespace App\Models;

/**
 * Phase 10.7 — A free-form tag applied to a voice note.
 *
 * Tags are normalised to lowercase + trimmed at the service layer so the
 * UNIQUE (voice_note_id, tag) DB-level constraint catches "Brake" vs
 * "brake" duplicates as a single row. The repository's replaceTags()
 * does a diff-based update rather than a delete-and-reinsert so the
 * created_at on existing tags is preserved (useful when surfacing
 * "tagged 3 weeks ago" affordances in UI).
 */
class VoiceNoteTag extends BaseModel
{
    public int $id = 0;
    public int $voice_note_id = 0;
    public string $tag = '';
    public ?string $created_at = null;
}
