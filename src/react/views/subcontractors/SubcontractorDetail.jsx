import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import subcontractorsService from '../../../services/subcontractors.service'
import { useToast } from '../../stores/toast.jsx'

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'blocked', label: 'Blocked' },
]

const TABS = [
  { value: 'overview', label: 'Overview' },
  { value: 'assignments', label: 'Assignments' },
]

function parseTrades(value) {
  if (!value) return []
  if (Array.isArray(value)) return value.filter(Boolean).map(String)
  const str = String(value).trim()
  if (!str) return []
  if (str.startsWith('[')) {
    try {
      const arr = JSON.parse(str)
      if (Array.isArray(arr)) return arr.filter(Boolean).map(String)
    } catch {
      // fall through
    }
  }
  return str.split(',').map((s) => s.trim()).filter(Boolean)
}

function tradesToInput(value) {
  return parseTrades(value).join(', ')
}

function formatDateTime(value) {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString()
}

function statusVariant(status) {
  const v = String(status || '').toLowerCase()
  if (v === 'active') return 'success'
  if (v === 'blocked') return 'danger'
  if (['done', 'completed', 'closed'].includes(v)) return 'success'
  if (['pending', 'in_progress', 'in-progress', 'open'].includes(v)) return 'warning'
  if (['cancelled', 'canceled', 'failed'].includes(v)) return 'danger'
  return 'default'
}

export default function SubcontractorDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()

  const [tab, setTab] = useState('overview')
  const [sub, setSub] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [deleting, setDeleting] = useState(false)

  const [form, setForm] = useState(null)

  const [assignments, setAssignments] = useState([])
  const [assignmentsLoading, setAssignmentsLoading] = useState(false)
  const [assignmentsLoaded, setAssignmentsLoaded] = useState(false)

  const load = useCallback(() => {
    if (!id) return
    setLoading(true)
    setError('')
    subcontractorsService
      .get(id)
      .then((res) => {
        const data = res?.data ?? res ?? null
        setSub(data)
        if (data) {
          setForm({
            name: data.name || '',
            contact_name: data.contact_name || data.primary_contact || '',
            contact_email: data.contact_email || data.email || '',
            contact_phone: data.contact_phone || data.phone || '',
            address: data.address || '',
            trades: tradesToInput(data.trades),
            notes: data.notes || '',
            status: data.status || 'active',
          })
        }
      })
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load subcontractor'))
      .finally(() => setLoading(false))
  }, [id])

  useEffect(() => { load() }, [load])

  const loadAssignments = useCallback(() => {
    if (!id) return
    setAssignmentsLoading(true)
    subcontractorsService
      .listAssignments({ subcontractor_id: id })
      .then((res) => setAssignments(res?.data ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load assignments'))
      .finally(() => {
        setAssignmentsLoading(false)
        setAssignmentsLoaded(true)
      })
  }, [id])

  useEffect(() => {
    if (tab === 'assignments' && !assignmentsLoaded) {
      loadAssignments()
    }
  }, [tab, assignmentsLoaded, loadAssignments])

  const submitUpdate = async () => {
    if (!form?.name?.trim()) {
      toast.error('Name is required')
      return
    }
    setSaving(true)
    const trades = parseTrades(form.trades)
    const payload = {
      name: form.name.trim(),
      contact_name: form.contact_name.trim() || undefined,
      contact_email: form.contact_email.trim() || undefined,
      contact_phone: form.contact_phone.trim() || undefined,
      address: form.address.trim() || undefined,
      trades: trades.length ? trades : undefined,
      notes: form.notes.trim() || undefined,
      status: form.status || 'active',
    }
    try {
      await subcontractorsService.update(id, payload)
      toast.success('Subcontractor updated')
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Update failed')
    } finally {
      setSaving(false)
    }
  }

  const submitDelete = async () => {
    setDeleting(true)
    try {
      await subcontractorsService.delete(id)
      toast.success('Subcontractor deleted')
      navigate('/cp/subcontractors')
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Delete failed')
      setDeleting(false)
    }
  }

  const tradeBadges = useMemo(() => parseTrades(sub?.trades), [sub])

  if (loading) {
    return <div className="p-6 text-center"><Loading /></div>
  }

  if (!sub) {
    return (
      <div className="p-4">
        <Alert variant="danger">{error || 'Subcontractor not found.'}</Alert>
        <div className="mt-3">
          <Link to="/cp/subcontractors" className="text-primary-600 hover:underline text-sm">
            &larr; Back to subcontractors
          </Link>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-end justify-between gap-3 flex-wrap">
        <div>
          <Link to="/cp/subcontractors" className="text-xs text-primary-600 hover:underline">
            &larr; Subcontractors
          </Link>
          <div className="flex items-center gap-3 mt-1">
            <h1 className="text-xl font-semibold">{sub.name}</h1>
            <Badge variant={statusVariant(sub.status)}>{sub.status || 'unknown'}</Badge>
          </div>
          {tradeBadges.length > 0 && (
            <div className="flex flex-wrap gap-1 mt-2">
              {tradeBadges.map((t) => (
                <Badge key={t} variant="info" size="sm">{t}</Badge>
              ))}
            </div>
          )}
        </div>
        <Button variant="danger" onClick={() => setConfirmDelete(true)}>Delete</Button>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <div className="border-b border-gray-200">
        <nav className="-mb-px flex gap-4">
          {TABS.map((t) => (
            <button
              key={t.value}
              type="button"
              onClick={() => setTab(t.value)}
              className={`px-3 py-2 text-sm font-medium border-b-2 transition-colors ${
                tab === t.value
                  ? 'border-primary-500 text-primary-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {tab === 'overview' && form && (
        <Card title="Edit details">
          <div className="space-y-3">
            <Input
              label="Name"
              required
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            />
            <div className="grid gap-3 sm:grid-cols-2">
              <Input
                label="Contact name"
                value={form.contact_name}
                onChange={(e) => setForm((f) => ({ ...f, contact_name: e.target.value }))}
              />
              <Input
                label="Contact email"
                type="email"
                value={form.contact_email}
                onChange={(e) => setForm((f) => ({ ...f, contact_email: e.target.value }))}
              />
              <Input
                label="Contact phone"
                value={form.contact_phone}
                onChange={(e) => setForm((f) => ({ ...f, contact_phone: e.target.value }))}
              />
              <Select
                label="Status"
                value={form.status}
                placeholder=""
                options={STATUS_OPTIONS}
                onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}
              />
            </div>
            <Input
              label="Address"
              value={form.address}
              onChange={(e) => setForm((f) => ({ ...f, address: e.target.value }))}
            />
            <Textarea
              label="Trades"
              rows={2}
              value={form.trades}
              onChange={(e) => setForm((f) => ({ ...f, trades: e.target.value }))}
              helperText="Comma-separated, or JSON array"
            />
            <Textarea
              label="Notes"
              rows={4}
              value={form.notes}
              onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
            />
            <div className="flex justify-end gap-2 pt-2">
              <Button variant="secondary" onClick={load} disabled={saving}>Reset</Button>
              <Button onClick={submitUpdate} loading={saving}>Save changes</Button>
            </div>
          </div>
        </Card>
      )}

      {tab === 'assignments' && (
        <Card padding={false}>
          <div className="p-4 flex items-center justify-between">
            <h3 className="text-base font-medium text-gray-900">Assignments</h3>
            <Button variant="secondary" onClick={loadAssignments}>Refresh</Button>
          </div>
          {assignmentsLoading ? (
            <div className="p-6 text-center"><Loading /></div>
          ) : assignments.length === 0 ? (
            <div className="p-6 text-center text-gray-500">No assignments yet.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                  <tr>
                    <th className="text-left p-2">Work order</th>
                    <th className="text-left p-2">Assigned at</th>
                    <th className="text-left p-2">Status</th>
                    <th className="text-left p-2">Notes</th>
                  </tr>
                </thead>
                <tbody>
                  {assignments.map((a) => (
                    <tr key={a.id ?? `${a.workorder_id}-${a.assigned_at}`} className="border-t">
                      <td className="p-2">
                        {a.workorder_id ? (
                          <Link
                            to={`/cp/workorders/${a.workorder_id}`}
                            className="text-primary-600 hover:underline"
                          >
                            #{a.workorder_id}
                          </Link>
                        ) : <span className="text-gray-400">—</span>}
                      </td>
                      <td className="p-2 text-gray-600">{formatDateTime(a.assigned_at)}</td>
                      <td className="p-2">
                        {a.status
                          ? <Badge variant={statusVariant(a.status)}>{a.status}</Badge>
                          : <span className="text-gray-400">—</span>}
                      </td>
                      <td className="p-2 text-gray-600">
                        {a.notes || <span className="text-gray-400">—</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      <Modal
        open={confirmDelete}
        onClose={() => (deleting ? null : setConfirmDelete(false))}
        title="Delete subcontractor"
      >
        <div className="space-y-3">
          <Alert variant="warning">
            This will permanently delete <strong>{sub.name}</strong>. This cannot be undone.
          </Alert>
          <div className="flex justify-end gap-2">
            <Button
              variant="secondary"
              onClick={() => setConfirmDelete(false)}
              disabled={deleting}
            >
              Cancel
            </Button>
            <Button variant="danger" onClick={submitDelete} loading={deleting}>
              Delete
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
