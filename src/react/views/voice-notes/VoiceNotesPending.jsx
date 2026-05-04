import VoiceNotes from './VoiceNotes'

// Standalone routing target for /cp/voice-notes/pending. Renders the
// shared VoiceNotes page locked to the "pending review" tab so the
// inbox-style URL stays bookmarkable.
//
// No "Mark all reviewed" bulk action is rendered here because the
// voice-notes service does not currently expose a reviewAll() method.
export default function VoiceNotesPending() {
  return <VoiceNotes forcedTab="pending" />
}
