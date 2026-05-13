import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import voiceNotesService from '../../../services/voice-notes.service'
import { useToast } from '../../stores/toast.jsx'

const TAB_ALL = 'all'
const TAB_MINE = 'mine'
const TAB_PENDING = 'pending'

const ENTITY_KIND_OPTIONS = [
  { value: '', label: 'Any entity' },
  { value: 'ticket', label: 'Ticket' },
  { value: 'workorder', label: 'Workorder' },
]

const NEW_NOTE_ENTITY_OPTIONS = [
  { value: '', label: 'None' },
  { value: 'ticket', label: 'Ticket' },
  { value: 'workorder', label: 'Workorder' },
]

function formatRelative(value) {
  if (!value) return '—'
  const ts = typeof value === 'string' ? Date.parse(value) : Number(value)
  if (Number.isNaN(ts)) return String(value)
  const diffSec = Math.floor((Date.now() - ts) / 1000)
  if (diffSec < 60) return `${diffSec}s ago`
  if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`
  if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`
  if (diffSec < 86400 * 30) return `${Math.floor(diffSec / 86400)}d ago`
  return new Date(ts).toLocaleDateString()
}

function formatDuration(seconds) {
  const num = Number(seconds)
  if (!Number.isFinite(num) || num <= 0) return '—'
  const mm = Math.floor(num / 60)
  const ss = Math.floor(num % 60).toString().padStart(2, '0')
  return `${mm}:${ss}`
}

function entityLink(note) {
  const kind = note?.entity_kind || note?.entity_type
  const id = note?.entity_id
  if (!kind || !id) return null
  if (kind === 'ticket') return { to: `/cp/tickets/${id}`, label: `Ticket #${id}` }
  if (kind === 'workorder') return { to: `/cp/workorders/${id}`, label: `WO #${id}` }
  return { to: null, label: `${kind} #${id}` }
}

function unwrap(res) {
  if (Array.isArray(res)) return res
  if (Array.isArray(res?.data)) return res.data
  if (Array.isArray(res?.data?.data)) return res.data.data
  if (Array.isArray(res?.items)) return res.items
  return []
}

function unwrapOne(res) {
  if (!res) return null
  if (res.data && typeof res.data === 'object' && !Array.isArray(res.data)) return res.data
  return res
}

export default function VoiceNotes({ forcedTab = null }) {
  const { success, error } = useToast()

  const [tab, setTab] = useState(forcedTab || TAB_ALL)
  const [notes, setNotes] = useState([])
  const [loading, setLoading] = useState(true)
  const [tagOptions, setTagOptions] = useState([])
  const [tagFilter, setTagFilter] = useState('')
  const [entityFilter, setEntityFilter] = useState('')
  const [search, setSearch] = useState('')
  const [pageError, setPageError] = useState('')

  const [detail, setDetail] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailTags, setDetailTags] = useState('')
  const [detailBusy, setDetailBusy] = useState(false)

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleting, setDeleting] = useState(false)

  const [createOpen, setCreateOpen] = useState(false)
  const [createBusy, setCreateBusy] = useState(false)
  const [createTitle, setCreateTitle] = useState('')
  const [createFile, setCreateFile] = useState(null)
  const [createEntityKind, setCreateEntityKind] = useState('')
  const [createEntityId, setCreateEntityId] = useState('')
  const [createTags, setCreateTags] = useState('')

  const activeTab = forcedTab || tab

  const loadTags = useCallback(() => {
    voiceNotesService
      .tags()
      .then((res) => {
        const list = unwrap(res)
        const opts = list.map((t) => {
          if (typeof t === 'string') return { value: t, label: t }
          return { value: String(t.name ?? t.tag ?? t.value ?? t.id), label: String(t.label ?? t.name ?? t.tag ?? t.value ?? t.id) }
        })
        setTagOptions([{ value: '', label: 'All tags' }, ...opts])
      })
      .catch(() => setTagOptions([{ value: '', label: 'All tags' }]))
  }, [])

  const loadNotes = useCallback(() => {
    setLoading(true)
    setPageError('')
    const params = {}
    if (tagFilter) params.tag = tagFilter
    if (entityFilter) params.entity_kind = entityFilter

    let promise
    if (activeTab === TAB_PENDING) {
      promise = voiceNotesService.pending(params)
    } else if (activeTab === TAB_MINE) {
      promise = voiceNotesService.mine(params)
    } else {
      // UIG-10 — cross-shop firehose. Backend gates on voice_notes.view_global
      // (dispatch / manager / admin); a 403 here means the actor doesn't have
      // the global view permission and should use the Mine tab instead.
      promise = voiceNotesService.all(params)
    }

    promise
      .then((res) => setNotes(unwrap(res)))
      .catch((e) => {
        setNotes([])
        if (e?.response?.status === 403 && activeTab === TAB_ALL) {
          setPageError('You do not have permission to view all voice notes. Switch to the Mine tab to see your own.')
        } else {
          setPageError(e?.response?.data?.message || e?.message || 'Failed to load voice notes')
        }
      })
      .finally(() => setLoading(false))
  }, [activeTab, tagFilter, entityFilter])

  useEffect(() => { loadTags() }, [loadTags])
  useEffect(() => { loadNotes() }, [loadNotes])

  const filteredNotes = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return notes
    return notes.filter((n) => {
      const title = String(n?.title || '').toLowerCase()
      const transcript = String(n?.transcript || '').toLowerCase()
      return title.includes(q) || transcript.includes(q)
    })
  }, [notes, search])

  const openDetail = async (id) => {
    setDetail({ id, loading: true })
    setDetailLoading(true)
    try {
      const res = await voiceNotesService.get(id)
      const data = unwrapOne(res) || {}
      const tags = Array.isArray(data.tags)
        ? data.tags.map((t) => (typeof t === 'string' ? t : (t?.name || t?.tag || ''))).filter(Boolean)
        : []
      setDetail({ ...data, id })
      setDetailTags(tags.join(', '))
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to load voice note')
      setDetail(null)
    } finally {
      setDetailLoading(false)
    }
  }

  const closeDetail = () => {
    setDetail(null)
    setDetailTags('')
  }

  const saveDetailTags = async () => {
    if (!detail?.id) return
    setDetailBusy(true)
    try {
      const tags = detailTags
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean)
      await voiceNotesService.setTags(detail.id, tags)
      success('Tags updated')
      loadNotes()
      loadTags()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to update tags')
    } finally {
      setDetailBusy(false)
    }
  }

  const togglePin = async (note) => {
    try {
      if (note.pinned || note.is_pinned) {
        await voiceNotesService.unpin(note.id)
        success('Unpinned')
      } else {
        await voiceNotesService.pin(note.id)
        success('Pinned')
      }
      loadNotes()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to update pin')
    }
  }

  const transcribeNote = async (note) => {
    try {
      await voiceNotesService.transcribe(note.id, {})
      success('Transcription requested')
      loadNotes()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to start transcription')
    }
  }

  const confirmDelete = async () => {
    if (!deleteTarget) return
    setDeleting(true)
    try {
      await voiceNotesService.delete(deleteTarget.id)
      success('Voice note deleted')
      setDeleteTarget(null)
      if (detail?.id === deleteTarget.id) closeDetail()
      loadNotes()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to delete voice note')
    } finally {
      setDeleting(false)
    }
  }

  const resetCreate = () => {
    setCreateTitle('')
    setCreateFile(null)
    setCreateEntityKind('')
    setCreateEntityId('')
    setCreateTags('')
  }

  const submitCreate = async () => {
    if (!createFile) {
      error('Please select an audio file')
      return
    }
    setCreateBusy(true)
    try {
      const fd = new FormData()
      fd.append('audio', createFile)
      if (createTitle.trim()) fd.append('title', createTitle.trim())
      if (createEntityKind) {
        fd.append('entity_kind', createEntityKind)
        if (createEntityId.trim()) fd.append('entity_id', createEntityId.trim())
      }
      const tagList = createTags
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean)
      tagList.forEach((t) => fd.append('tags[]', t))

      await voiceNotesService.create(fd)
      success('Voice note created')
      setCreateOpen(false)
      resetCreate()
      loadNotes()
      loadTags()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to create voice note')
    } finally {
      setCreateBusy(false)
    }
  }

  const tabClass = (key) =>
    `px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
      activeTab === key
        ? 'border-primary-600 text-primary-700'
        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
    }`

  const detailAudioUrl = detail?.audio_url || detail?.url || detail?.file_url || ''
  const detailTranscript = detail?.transcript || detail?.transcription || ''

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Voice Notes</h1>
          <p className="mt-1 text-sm text-gray-500">
            Audio notes attached to tickets and workorders.
          </p>
        </div>
        <div>
          <Button onClick={() => setCreateOpen(true)}>New voice note</Button>
        </div>
      </div>

      {pageError ? (
        <Alert variant="danger" onClose={() => setPageError('')}>{pageError}</Alert>
      ) : null}

      <Card padding={false}>
        {!forcedTab ? (
          <div className="px-4 pt-3 border-b border-gray-200">
            <div className="flex gap-2">
              <button type="button" className={tabClass(TAB_ALL)} onClick={() => setTab(TAB_ALL)}>All</button>
              <button type="button" className={tabClass(TAB_MINE)} onClick={() => setTab(TAB_MINE)}>Mine</button>
              <button type="button" className={tabClass(TAB_PENDING)} onClick={() => setTab(TAB_PENDING)}>Pending review</button>
            </div>
          </div>
        ) : null}

        <div className="p-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Select
            label="Tag"
            value={tagFilter}
            options={tagOptions}
            placeholder=""
            onChange={(e) => setTagFilter(e.target.value)}
          />
          <Select
            label="Entity"
            value={entityFilter}
            options={ENTITY_KIND_OPTIONS}
            placeholder=""
            onChange={(e) => setEntityFilter(e.target.value)}
          />
          <Input
            label="Search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Title or transcript..."
          />
          <div className="flex items-end">
            <Button variant="secondary" onClick={loadNotes}>Refresh</Button>
          </div>
        </div>

        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading voice notes..." /></div>
        ) : filteredNotes.length === 0 ? (
          <div className="text-center py-12 text-gray-500">
            <p>No voice notes found.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entity</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Duration</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tags</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {filteredNotes.map((note) => {
                  const link = entityLink(note)
                  const pinned = !!(note.pinned || note.is_pinned)
                  const transcribed = !!(note.transcribed || note.is_transcribed || note.transcript)
                  const tags = Array.isArray(note.tags) ? note.tags : []
                  return (
                    <tr key={note.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-sm text-gray-500" title={note.created_at || ''}>
                        {formatRelative(note.created_at)}
                      </td>
                      <td className="px-4 py-3 text-sm">
                        {link ? (
                          link.to ? (
                            <Link to={link.to} className="text-primary-600 hover:text-primary-500">
                              {link.label}
                            </Link>
                          ) : (
                            <span className="text-gray-700">{link.label}</span>
                          )
                        ) : (
                          <span className="text-gray-400">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-sm font-medium text-gray-900">
                        <button
                          type="button"
                          className="text-primary-600 hover:text-primary-500"
                          onClick={() => openDetail(note.id)}
                        >
                          {note.title || 'Untitled'}
                        </button>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {formatDuration(note.duration_seconds ?? note.duration)}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-1">
                          {tags.length === 0
                            ? <span className="text-gray-400 text-sm">—</span>
                            : tags.map((t) => {
                                const label = typeof t === 'string' ? t : (t?.name || t?.tag || '')
                                return label ? <Badge key={label} size="sm" variant="info">{label}</Badge> : null
                              })}
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-1">
                          {pinned ? <Badge size="sm" variant="warning">Pinned</Badge> : null}
                          {transcribed
                            ? <Badge size="sm" variant="success">Transcribed</Badge>
                            : <Badge size="sm" variant="default">Audio only</Badge>}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex justify-end gap-1 flex-wrap">
                          <Button size="xs" variant="ghost" onClick={() => openDetail(note.id)}>Open</Button>
                          <Button size="xs" variant="ghost" onClick={() => togglePin(note)}>
                            {pinned ? 'Unpin' : 'Pin'}
                          </Button>
                          {!transcribed ? (
                            <Button size="xs" variant="ghost" onClick={() => transcribeNote(note)}>
                              Transcribe
                            </Button>
                          ) : null}
                          <Button
                            size="xs"
                            variant="ghost"
                            className="text-red-600 hover:text-red-700"
                            onClick={() => setDeleteTarget(note)}
                          >
                            Delete
                          </Button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={!!detail}
        onClose={closeDetail}
        title="Voice Note Detail"
        size="lg"
      >
        {detailLoading || !detail ? (
          <div className="py-10 flex justify-center"><Loading /></div>
        ) : (
          <div className="space-y-4">
            <div>
              <div className="text-xs uppercase text-gray-500 tracking-wide mb-1">Title</div>
              <div className="text-sm text-gray-900">{detail.title || 'Untitled'}</div>
            </div>

            {detailAudioUrl ? (
              <div>
                <div className="text-xs uppercase text-gray-500 tracking-wide mb-1">Audio</div>
                <audio controls src={detailAudioUrl} className="w-full" />
              </div>
            ) : (
              <Alert variant="info">No audio URL available for this note.</Alert>
            )}

            <div>
              <div className="text-xs uppercase text-gray-500 tracking-wide mb-1">Transcript</div>
              {detailTranscript ? (
                <div className="text-sm whitespace-pre-wrap border rounded p-3 bg-gray-50 max-h-64 overflow-y-auto">
                  {detailTranscript}
                </div>
              ) : (
                <p className="text-sm text-gray-500">No transcript available.</p>
              )}
            </div>

            <div>
              <Input
                label="Tags (comma-separated)"
                value={detailTags}
                onChange={(e) => setDetailTags(e.target.value)}
                placeholder="oil-change, follow-up"
              />
              <div className="mt-2 flex justify-end">
                <Button size="sm" loading={detailBusy} onClick={saveDetailTags}>Save tags</Button>
              </div>
            </div>

            <div className="flex justify-end gap-2 pt-2 border-t">
              <Button variant="ghost" onClick={closeDetail}>Close</Button>
            </div>
          </div>
        )}
      </Modal>

      <Modal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title="Delete voice note"
      >
        <p className="text-sm text-gray-600 mb-4">
          Delete <strong>{deleteTarget?.title || 'this voice note'}</strong>? This cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteTarget(null)}>Cancel</Button>
          <Button variant="danger" loading={deleting} onClick={confirmDelete}>Delete</Button>
        </div>
      </Modal>

      <Modal
        open={createOpen}
        onClose={() => { setCreateOpen(false); resetCreate() }}
        title="New voice note"
      >
        <div className="space-y-3">
          <Input
            label="Title"
            value={createTitle}
            onChange={(e) => setCreateTitle(e.target.value)}
            placeholder="Optional title"
          />
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Audio file</label>
            <input
              type="file"
              accept="audio/*"
              onChange={(e) => setCreateFile(e.target.files?.[0] || null)}
              className="block w-full text-sm text-gray-700 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100"
            />
          </div>
          <Select
            label="Entity"
            value={createEntityKind}
            options={NEW_NOTE_ENTITY_OPTIONS}
            placeholder=""
            onChange={(e) => setCreateEntityKind(e.target.value)}
          />
          {createEntityKind ? (
            <Input
              label="Entity ID"
              value={createEntityId}
              onChange={(e) => setCreateEntityId(e.target.value)}
              placeholder="Numeric id"
            />
          ) : null}
          <Input
            label="Tags (comma-separated)"
            value={createTags}
            onChange={(e) => setCreateTags(e.target.value)}
            placeholder="oil-change, follow-up"
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => { setCreateOpen(false); resetCreate() }}>
              Cancel
            </Button>
            <Button loading={createBusy} onClick={submitCreate}>Create</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
