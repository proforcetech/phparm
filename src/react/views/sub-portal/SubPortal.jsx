import { useCallback, useEffect, useMemo, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import subPortalService from '../../../services/sub-portal.service'

/**
 * Phase 18 / C2 — public subcontractor self-service portal.
 *
 * Mounted OUTSIDE the staff app shell at /sub-portal, /sub-portal/:token,
 * or /sub-portal?token=.... Link tokens still work, and subcontractors can
 * also sign in with email/password. We attach the resulting portal token to
 * subPortalService and never call the shared `api` axios instance.
 *
 * UX is intentionally minimal — meant to work on a phone in the field:
 *   - top bar shows who you're logged in as
 *   - assignment list with status badges + primary action button per row
 *   - tap-to-open detail panel with accept/decline/start/complete flow
 *   - POD upload: file + optional caption, kind selector
 *   - notes
 */

const STATUS_VARIANT = {
  pending: 'warning',
  accepted: 'info',
  in_progress: 'primary',
  completed: 'success',
  declined: 'secondary',
  cancelled: 'danger',
}

const KINDS = [
  { value: 'pod', label: 'Proof of delivery' },
  { value: 'photo', label: 'Photo' },
  { value: 'signature', label: 'Signature' },
]

function formatMoney(cents) {
  if (cents === null || cents === undefined || cents === '') return ''
  const num = Number(cents) / 100
  if (!Number.isFinite(num)) return ''
  return num.toLocaleString(undefined, { style: 'currency', currency: 'USD' })
}

function formatDateTime(s) {
  if (!s) return '—'
  const d = new Date(s.replace(' ', 'T'))
  return Number.isNaN(d.valueOf()) ? s : d.toLocaleString()
}

export default function SubPortal() {
  const { token: routeToken } = useParams()
  const [search] = useSearchParams()
  const queryToken = search.get('token')
  const urlToken = (routeToken || queryToken || '').trim()

  const [me, setMe] = useState(null)
  const [authError, setAuthError] = useState('')
  const [loadingMe, setLoadingMe] = useState(true)
  const [loginEmail, setLoginEmail] = useState('')
  const [loginPassword, setLoginPassword] = useState('')
  const [loginBusy, setLoginBusy] = useState(false)

  const [assignments, setAssignments] = useState([])
  const [loadingAssignments, setLoadingAssignments] = useState(false)
  const [statusFilter, setStatusFilter] = useState('')
  const [error, setError] = useState('')

  const [openId, setOpenId] = useState(null)
  const [openAssignment, setOpenAssignment] = useState(null)
  const [openLoading, setOpenLoading] = useState(false)
  const [pods, setPods] = useState([])
  const [actionBusy, setActionBusy] = useState('')

  // Complete modal
  const [completeOpen, setCompleteOpen] = useState(false)
  const [completeCost, setCompleteCost] = useState('')
  const [completeNotes, setCompleteNotes] = useState('')

  // Upload form
  const [uploadFile, setUploadFile] = useState(null)
  const [uploadKind, setUploadKind] = useState('pod')
  const [uploadNote, setUploadNote] = useState('')
  const [noteText, setNoteText] = useState('')

  useEffect(() => {
    const token = urlToken || subPortalService.getToken()
    if (!token) {
      setAuthError('')
      setLoadingMe(false)
      return
    }
    subPortalService.setToken(token)
    setLoadingMe(true)
    setAuthError('')
    subPortalService
      .me()
      .then((res) => setMe(res?.data ?? null))
      .catch((e) => {
        if (!urlToken) subPortalService.clearToken()
        setMe(null)
        setAuthError(
          e?.response?.data?.message
          || (e?.response?.status === 401 ? 'Session is invalid or expired.' : '')
          || e?.message
          || 'Could not load portal.'
        )
      })
      .finally(() => setLoadingMe(false))
  }, [urlToken])

  const submitLogin = async (event) => {
    event.preventDefault()
    setLoginBusy(true)
    setAuthError('')
    try {
      const res = await subPortalService.login(loginEmail.trim(), loginPassword)
      const session = res?.data ?? res ?? null
      if (session?.subcontractor) {
        setMe({ subcontractor: session.subcontractor, token: session.token })
      } else {
        const refreshed = await subPortalService.me()
        setMe(refreshed?.data ?? null)
      }
      setLoginPassword('')
    } catch (e) {
      subPortalService.clearToken()
      setAuthError(e?.response?.data?.message || e?.message || 'Unable to sign in.')
    } finally {
      setLoginBusy(false)
      setLoadingMe(false)
    }
  }

  const logout = () => {
    subPortalService.clearToken()
    setMe(null)
    setAssignments([])
    setOpenId(null)
    setOpenAssignment(null)
    setPods([])
    setAuthError('')
  }

  const loadAssignments = useCallback(() => {
    if (!me) return
    setLoadingAssignments(true)
    subPortalService
      .listAssignments(statusFilter || undefined)
      .then((res) => setAssignments(res?.data ?? []))
      .catch((e) => setError(
        e?.response?.data?.message || e?.message || 'Could not load assignments.'
      ))
      .finally(() => setLoadingAssignments(false))
  }, [me, statusFilter])

  useEffect(() => {
    loadAssignments()
  }, [loadAssignments])

  const openAssignmentDetail = useCallback((id) => {
    setOpenId(id)
    setOpenLoading(true)
    Promise.all([
      subPortalService.getAssignment(id),
      subPortalService.listPods(id),
    ])
      .then(([detail, podRes]) => {
        setOpenAssignment(detail?.data ?? null)
        setPods(podRes?.data ?? [])
      })
      .catch((e) => setError(
        e?.response?.data?.message || e?.message || 'Could not load assignment.'
      ))
      .finally(() => setOpenLoading(false))
  }, [])

  const closeDetail = () => {
    setOpenId(null)
    setOpenAssignment(null)
    setPods([])
    setUploadFile(null)
    setUploadNote('')
    setNoteText('')
    setCompleteOpen(false)
  }

  const refreshOpen = useCallback(async () => {
    if (!openId) return
    try {
      const [detail, podRes] = await Promise.all([
        subPortalService.getAssignment(openId),
        subPortalService.listPods(openId),
      ])
      setOpenAssignment(detail?.data ?? null)
      setPods(podRes?.data ?? [])
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Refresh failed.')
    }
  }, [openId])

  const doAction = async (action) => {
    if (!openAssignment) return
    setActionBusy(action)
    try {
      if (action === 'accept') await subPortalService.accept(openAssignment.id)
      else if (action === 'decline') await subPortalService.decline(openAssignment.id)
      else if (action === 'start') await subPortalService.start(openAssignment.id)
      await refreshOpen()
      loadAssignments()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || `${action} failed.`)
    } finally {
      setActionBusy('')
    }
  }

  const submitComplete = async () => {
    if (!openAssignment) return
    setActionBusy('complete')
    try {
      const payload = {}
      if (completeCost !== '') {
        const cents = Math.round(Number(completeCost) * 100)
        if (Number.isFinite(cents)) payload.actual_cost_cents = cents
      }
      if (completeNotes.trim() !== '') payload.description = completeNotes.trim()
      await subPortalService.complete(openAssignment.id, payload)
      setCompleteOpen(false)
      setCompleteCost('')
      setCompleteNotes('')
      await refreshOpen()
      loadAssignments()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Complete failed.')
    } finally {
      setActionBusy('')
    }
  }

  const submitUpload = async (e) => {
    e?.preventDefault?.()
    if (!openAssignment || !uploadFile) return
    setActionBusy('upload')
    try {
      await subPortalService.uploadPod(openAssignment.id, uploadFile, uploadKind, uploadNote)
      setUploadFile(null)
      setUploadNote('')
      await refreshOpen()
    } catch (e2) {
      setError(e2?.response?.data?.message || e2?.message || 'Upload failed.')
    } finally {
      setActionBusy('')
    }
  }

  const submitNote = async () => {
    if (!openAssignment || noteText.trim() === '') return
    setActionBusy('note')
    try {
      await subPortalService.addNote(openAssignment.id, noteText.trim())
      setNoteText('')
      await refreshOpen()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Note failed.')
    } finally {
      setActionBusy('')
    }
  }

  const removePod = async (podId) => {
    setActionBusy('pod-' + podId)
    try {
      await subPortalService.deletePod(podId)
      await refreshOpen()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Delete failed.')
    } finally {
      setActionBusy('')
    }
  }

  const availableActions = useMemo(() => {
    if (!openAssignment) return []
    const s = openAssignment.status
    const out = []
    if (s === 'pending') {
      out.push({ key: 'accept', label: 'Accept', variant: 'primary' })
      out.push({ key: 'decline', label: 'Decline', variant: 'danger' })
      out.push({ key: 'start', label: 'Start work now', variant: 'secondary' })
    } else if (s === 'accepted') {
      out.push({ key: 'start', label: 'Start work', variant: 'primary' })
      out.push({ key: 'complete-modal', label: 'Mark complete', variant: 'success' })
    } else if (s === 'in_progress') {
      out.push({ key: 'complete-modal', label: 'Mark complete', variant: 'success' })
    }
    return out
  }, [openAssignment])

  if (loadingMe) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 p-4">
        <Loading />
      </div>
    )
  }

  if (!me) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 p-4">
        <Card className="max-w-md w-full">
          <form onSubmit={submitLogin} className="p-6 space-y-4">
            <h1 className="text-xl font-semibold">Subcontractor portal</h1>
            {authError && <Alert variant="danger">{authError}</Alert>}
            <Input
              label="Email"
              type="email"
              value={loginEmail}
              onChange={(e) => setLoginEmail(e.target.value)}
              required
              autocomplete="email"
              placeholder="you@company.com"
            />
            <Input
              label="Password"
              type="password"
              value={loginPassword}
              onChange={(e) => setLoginPassword(e.target.value)}
              required
              autocomplete="current-password"
            />
            <Button
              type="submit"
              fullWidth
              loading={loginBusy}
              disabled={loginBusy || !loginEmail.trim() || !loginPassword}
            >
              Sign in
            </Button>
          </form>
        </Card>
      </div>
    )
  }

  const sub = me.subcontractor

  return (
    <div className="min-h-screen bg-gray-50">
      <header className="bg-white border-b">
        <div className="max-w-3xl mx-auto p-4 flex items-center justify-between">
          <div>
            <div className="text-xs text-gray-500 uppercase tracking-wide">Subcontractor portal</div>
            <h1 className="text-lg font-semibold">{sub.company_name}</h1>
            {sub.contact_name && <div className="text-sm text-gray-500">{sub.contact_name}</div>}
          </div>
          <div className="flex items-center gap-2">
            <Badge variant="success">Active</Badge>
            <Button variant="ghost" size="sm" onClick={logout}>Sign out</Button>
          </div>
        </div>
      </header>

      <main className="max-w-3xl mx-auto p-4 space-y-4">
        {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

        <Card>
          <div className="p-4 flex items-center justify-between gap-2 flex-wrap">
            <div>
              <h2 className="text-sm font-semibold">Your assignments</h2>
              <p className="text-xs text-gray-500">
                Tap any row to view details, accept, complete, or upload proof.
              </p>
            </div>
            <div className="flex items-center gap-2">
              <select
                className="border rounded px-2 py-1 text-sm"
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
              >
                <option value="">All</option>
                <option value="pending">Pending</option>
                <option value="accepted">Accepted</option>
                <option value="in_progress">In progress</option>
                <option value="completed">Completed</option>
                <option value="declined">Declined</option>
                <option value="cancelled">Cancelled</option>
              </select>
              <Button variant="secondary" onClick={loadAssignments}>Refresh</Button>
            </div>
          </div>
          {loadingAssignments ? (
            <div className="p-6 text-center"><Loading /></div>
          ) : assignments.length === 0 ? (
            <div className="p-6 text-center text-gray-500">No assignments to show.</div>
          ) : (
            <ul className="divide-y">
              {assignments.map((a) => (
                <li key={a.id}>
                  <button
                    type="button"
                    onClick={() => openAssignmentDetail(a.id)}
                    className="w-full text-left p-4 hover:bg-gray-50 flex items-center justify-between gap-3"
                  >
                    <div className="min-w-0">
                      <div className="text-sm font-semibold truncate">
                        WO #{a.workorder_id} — Assignment #{a.id}
                      </div>
                      {a.description && (
                        <div className="text-xs text-gray-500 truncate">{a.description}</div>
                      )}
                      <div className="text-xs text-gray-400 mt-1">
                        Assigned {formatDateTime(a.assigned_at)}
                      </div>
                    </div>
                    <Badge variant={STATUS_VARIANT[a.status] || 'secondary'}>
                      {a.status.replace('_', ' ')}
                    </Badge>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </main>

      <Modal open={openId !== null} onClose={closeDetail} title={
        openAssignment ? `WO #${openAssignment.workorder_id} · Assignment #${openAssignment.id}` : 'Assignment'
      }>
        {openLoading || !openAssignment ? (
          <div className="p-6 text-center"><Loading /></div>
        ) : (
          <div className="space-y-4">
            <div className="flex items-center gap-2">
              <Badge variant={STATUS_VARIANT[openAssignment.status] || 'secondary'}>
                {openAssignment.status.replace('_', ' ')}
              </Badge>
              {openAssignment.estimated_cost_cents != null && (
                <span className="text-xs text-gray-500">
                  Est. {formatMoney(openAssignment.estimated_cost_cents)}
                </span>
              )}
              {openAssignment.actual_cost_cents != null && (
                <span className="text-xs text-gray-500">
                  Actual {formatMoney(openAssignment.actual_cost_cents)}
                </span>
              )}
            </div>

            {openAssignment.description && (
              <div>
                <div className="text-xs uppercase text-gray-500 tracking-wide">Description</div>
                <div className="text-sm whitespace-pre-wrap">{openAssignment.description}</div>
              </div>
            )}

            {availableActions.length > 0 && (
              <div className="flex flex-wrap gap-2">
                {availableActions.map((act) => (
                  <Button
                    key={act.key}
                    variant={act.variant}
                    disabled={actionBusy !== ''}
                    onClick={() => act.key === 'complete-modal'
                      ? setCompleteOpen(true)
                      : doAction(act.key)}
                  >
                    {actionBusy === act.key ? '…' : act.label}
                  </Button>
                ))}
              </div>
            )}

            <div className="border-t pt-4">
              <div className="text-xs uppercase text-gray-500 tracking-wide mb-2">
                Proof of work ({pods.length})
              </div>
              {pods.length === 0 ? (
                <p className="text-xs text-gray-400">Nothing uploaded yet.</p>
              ) : (
                <ul className="space-y-2">
                  {pods.map((p) => (
                    <li key={p.id} className="flex items-start justify-between gap-3 text-sm border rounded p-2">
                      <div className="min-w-0">
                        <div className="font-medium capitalize">{p.kind}</div>
                        {p.original_name && (
                          <div className="text-xs text-gray-500 truncate">{p.original_name}</div>
                        )}
                        {p.notes && (
                          <div className="text-xs text-gray-600 mt-1 whitespace-pre-wrap">{p.notes}</div>
                        )}
                        <div className="text-[11px] text-gray-400 mt-1">
                          {formatDateTime(p.uploaded_at)}
                        </div>
                      </div>
                      <Button
                        variant="ghost"
                        disabled={actionBusy === 'pod-' + p.id}
                        onClick={() => removePod(p.id)}
                      >
                        Remove
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <form onSubmit={submitUpload} className="border-t pt-4 space-y-2">
              <div className="text-xs uppercase text-gray-500 tracking-wide">Upload proof</div>
              <div className="flex flex-wrap gap-2 items-end">
                <select
                  className="border rounded px-2 py-1 text-sm"
                  value={uploadKind}
                  onChange={(e) => setUploadKind(e.target.value)}
                >
                  {KINDS.map((k) => (
                    <option key={k.value} value={k.value}>{k.label}</option>
                  ))}
                </select>
                <input
                  type="file"
                  accept="image/*,application/pdf"
                  onChange={(e) => setUploadFile(e.target.files?.[0] || null)}
                  className="text-sm"
                />
                <Input
                  placeholder="Caption (optional)"
                  value={uploadNote}
                  onChange={(e) => setUploadNote(e.target.value)}
                />
                <Button type="submit" disabled={!uploadFile || actionBusy === 'upload'}>
                  {actionBusy === 'upload' ? 'Uploading…' : 'Upload'}
                </Button>
              </div>
              <p className="text-[11px] text-gray-400">
                JPG / PNG / PDF up to 10MB.
              </p>
            </form>

            <div className="border-t pt-4 space-y-2">
              <div className="text-xs uppercase text-gray-500 tracking-wide">Add a note</div>
              <Textarea
                rows={2}
                value={noteText}
                onChange={(e) => setNoteText(e.target.value)}
                placeholder="Note for the dispatcher…"
              />
              <div className="flex justify-end">
                <Button
                  variant="secondary"
                  disabled={noteText.trim() === '' || actionBusy === 'note'}
                  onClick={submitNote}
                >
                  {actionBusy === 'note' ? '…' : 'Save note'}
                </Button>
              </div>
            </div>
          </div>
        )}
      </Modal>

      <Modal
        open={completeOpen}
        onClose={() => setCompleteOpen(false)}
        title="Mark assignment complete"
      >
        <div className="space-y-3">
          <Input
            label="Final cost (USD)"
            type="number"
            min="0"
            step="0.01"
            value={completeCost}
            onChange={(e) => setCompleteCost(e.target.value)}
            placeholder="optional"
          />
          <Textarea
            label="Closeout notes"
            rows={3}
            value={completeNotes}
            onChange={(e) => setCompleteNotes(e.target.value)}
            placeholder="optional"
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setCompleteOpen(false)}>Cancel</Button>
            <Button variant="success" disabled={actionBusy === 'complete'} onClick={submitComplete}>
              {actionBusy === 'complete' ? 'Saving…' : 'Confirm complete'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
