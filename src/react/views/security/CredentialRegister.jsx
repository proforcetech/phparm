import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import securityCredentialsService from '../../../services/security-credentials.service'

/**
 * Phase 16 / S1 of docs/woms-expansion-plan.md.
 *
 * Tabs:
 *   1. Credentials   — list + filters + issue + drill into detail
 *   2. Schedules     — recurring access policies (sun..sat + start/end)
 *   3. Audit log     — programming_logs feed (read-only)
 *
 * Detail view shows the credential header, the door-grant table, and the
 * per-credential programming-log history.
 *
 * Read perm:  security_credentials.view  (server-enforced)
 * Write perm: security_credentials.manage
 */

const TABS = [
  { id: 'credentials', label: 'Credentials' },
  { id: 'schedules', label: 'Access schedules' },
  { id: 'audit', label: 'Audit log' },
]

const CREDENTIAL_TYPES = ['card', 'fob', 'pin', 'mobile', 'biometric', 'plate']
const STATUSES = ['active', 'suspended', 'revoked', 'lost']
const DAY_CODES = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']

const STATUS_VARIANT = {
  active: 'success',
  suspended: 'warning',
  revoked: 'danger',
  lost: 'danger',
}

const ACTION_VARIANT = {
  created: 'info',
  updated: 'info',
  deleted: 'danger',
  assigned: 'success',
  revoked: 'danger',
  enabled: 'success',
  disabled: 'warning',
  config_changed: 'info',
  webhook_received: 'secondary',
  sweep_alert: 'warning',
}

function titleize(s) {
  if (!s) return ''
  return String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function formatDate(s) {
  if (!s) return '—'
  try {
    return new Date(String(s).replace(' ', 'T')).toLocaleString()
  } catch {
    return s
  }
}

export default function CredentialRegister() {
  const [tab, setTab] = useState('credentials')
  const [error, setError] = useState('')
  const [selectedId, setSelectedId] = useState(null)

  if (selectedId !== null) {
    return (
      <CredentialDetail
        id={selectedId}
        onBack={() => setSelectedId(null)}
        onError={setError}
        error={error}
      />
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Security credentials</h1>
          <p className="mt-1 text-sm text-gray-500">
            Issued cards, fobs, PINs, mobile tokens, biometrics, and plates with their door
            assignments and full programming-log audit trail.
          </p>
        </div>
      </div>

      <div className="border-b border-gray-200">
        <nav className="-mb-px flex flex-wrap gap-6">
          {TABS.map((t) => (
            <button
              key={t.id}
              onClick={() => setTab(t.id)}
              className={`py-2 px-1 border-b-2 text-sm font-medium ${
                tab === t.id
                  ? 'border-blue-600 text-blue-700'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      {tab === 'credentials' && (
        <CredentialListTab onError={setError} onSelect={setSelectedId} />
      )}
      {tab === 'schedules' && <ScheduleListTab onError={setError} />}
      {tab === 'audit' && <AuditLogTab onError={setError} />}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Credential list tab
// ---------------------------------------------------------------------------

function CredentialListTab({ onError, onSelect }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [search, setSearch] = useState('')
  const [showForm, setShowForm] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = { per_page: 100 }
      if (statusFilter) params.status = statusFilter
      if (typeFilter) params.credential_type = typeFilter
      if (search) params.search = search
      const res = await securityCredentialsService.list(params)
      setRows(res?.data?.credentials || [])
    } catch (err) {
      console.error('Failed to load credentials', err)
      onError('Unable to load credentials.')
    } finally {
      setLoading(false)
    }
  }, [statusFilter, typeFilter, search, onError])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <label className="block text-sm font-medium text-gray-700">
            Status
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="mt-1 block rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
            >
              <option value="">All</option>
              {STATUSES.map((s) => <option key={s} value={s}>{titleize(s)}</option>)}
            </select>
          </label>
          <label className="block text-sm font-medium text-gray-700">
            Type
            <select
              value={typeFilter}
              onChange={(e) => setTypeFilter(e.target.value)}
              className="mt-1 block rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
            >
              <option value="">All</option>
              {CREDENTIAL_TYPES.map((t) => <option key={t} value={t}>{titleize(t)}</option>)}
            </select>
          </label>
          <label className="block text-sm font-medium text-gray-700 flex-1 min-w-[200px]">
            Search
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Holder name, email, employee ID, code"
            />
          </label>
          <Button variant="primary" onClick={() => setShowForm(true)}>Issue credential</Button>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading credentials..." /></div>
        ) : rows.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No credentials found.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Holder</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Issued</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm">
                      <div className="font-medium text-gray-900">{row.holder_name}</div>
                      {row.holder_email
                        ? <div className="text-xs text-gray-500">{row.holder_email}</div>
                        : null}
                    </td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant="secondary">{titleize(row.credential_type)}</Badge>
                    </td>
                    <td className="px-4 py-2 text-sm font-mono text-gray-700">{row.credential_code}</td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={STATUS_VARIANT[row.status] || 'secondary'}>{titleize(row.status)}</Badge>
                      {row.is_expired
                        ? <span className="ml-2 text-xs text-red-600">expired</span>
                        : null}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{formatDate(row.issued_at)}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {row.expires_at ? formatDate(row.expires_at) : <span className="text-gray-400">—</span>}
                    </td>
                    <td className="px-4 py-2 text-sm text-right">
                      <button
                        onClick={() => onSelect(row.id)}
                        className="text-blue-600 hover:underline"
                      >
                        Open
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {showForm && (
        <CredentialFormModal
          onClose={() => setShowForm(false)}
          onSaved={async (id) => { setShowForm(false); await load(); if (id) onSelect(id) }}
          onError={onError}
        />
      )}
    </div>
  )
}

function CredentialFormModal({ onClose, onSaved, onError, row = null }) {
  const isEdit = !!row
  const [form, setForm] = useState({
    customer_id: row?.customer_id ?? '',
    site_id: row?.site_id ?? '',
    holder_name: row?.holder_name || '',
    holder_email: row?.holder_email || '',
    holder_phone: row?.holder_phone || '',
    holder_employee_id: row?.holder_employee_id || '',
    credential_type: row?.credential_type || 'card',
    credential_code: '',
    credential_format: row?.credential_format || '',
    expires_at: row?.expires_at || '',
    notes: row?.notes || '',
  })
  const [saving, setSaving] = useState(false)

  const handleSave = async () => {
    setSaving(true)
    try {
      const payload = { ...form }
      if (!payload.customer_id) {
        onError('customer_id is required')
        setSaving(false)
        return
      }
      payload.customer_id = parseInt(payload.customer_id, 10)
      if (payload.site_id) payload.site_id = parseInt(payload.site_id, 10); else delete payload.site_id
      Object.keys(payload).forEach((k) => { if (payload[k] === '') delete payload[k] })

      let saved
      if (isEdit) {
        // Don't ship credential_code on edit — change-code is intentionally
        // not supported through this form; do it via re-issue.
        delete payload.credential_code
        delete payload.credential_type
        delete payload.customer_id
        saved = await securityCredentialsService.update(row.id, payload)
      } else {
        if (!payload.credential_code) {
          onError('credential_code is required')
          setSaving(false)
          return
        }
        saved = await securityCredentialsService.create(payload)
      }
      onSaved(saved?.data?.id ?? row?.id ?? null)
    } catch (err) {
      console.error('Failed to save credential', err)
      onError(err?.response?.data?.message || 'Failed to save credential.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={isEdit ? `Edit credential #${row.id}` : 'Issue credential'}
      size="lg"
      onClose={onClose}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="primary" onClick={handleSave} disabled={saving}>
            {saving ? 'Saving…' : (isEdit ? 'Save' : 'Issue')}
          </Button>
        </div>
      )}
    >
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <label className="text-sm font-medium text-gray-700">
          Customer ID
          <Input
            value={form.customer_id}
            onChange={(e) => setForm({ ...form, customer_id: e.target.value })}
            disabled={isEdit}
            type="number"
          />
        </label>
        <label className="text-sm font-medium text-gray-700">
          Site ID (optional)
          <Input
            value={form.site_id}
            onChange={(e) => setForm({ ...form, site_id: e.target.value })}
            type="number"
          />
        </label>
        <label className="text-sm font-medium text-gray-700 md:col-span-2">
          Holder name
          <Input
            value={form.holder_name}
            onChange={(e) => setForm({ ...form, holder_name: e.target.value })}
          />
        </label>
        <label className="text-sm font-medium text-gray-700">
          Holder email
          <Input
            value={form.holder_email}
            onChange={(e) => setForm({ ...form, holder_email: e.target.value })}
            type="email"
          />
        </label>
        <label className="text-sm font-medium text-gray-700">
          Holder phone
          <Input
            value={form.holder_phone}
            onChange={(e) => setForm({ ...form, holder_phone: e.target.value })}
          />
        </label>
        <label className="text-sm font-medium text-gray-700">
          Employee ID
          <Input
            value={form.holder_employee_id}
            onChange={(e) => setForm({ ...form, holder_employee_id: e.target.value })}
          />
        </label>
        <label className="text-sm font-medium text-gray-700">
          Credential type
          <select
            value={form.credential_type}
            onChange={(e) => setForm({ ...form, credential_type: e.target.value })}
            disabled={isEdit}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
          >
            {CREDENTIAL_TYPES.map((t) => <option key={t} value={t}>{titleize(t)}</option>)}
          </select>
        </label>
        {!isEdit && (
          <label className="text-sm font-medium text-gray-700">
            Credential code
            <Input
              value={form.credential_code}
              onChange={(e) => setForm({ ...form, credential_code: e.target.value })}
              placeholder={form.credential_type === 'pin' ? 'Plaintext (server hashes)' : 'Card/fob ID'}
            />
          </label>
        )}
        <label className="text-sm font-medium text-gray-700">
          Format (e.g. wiegand-26)
          <Input
            value={form.credential_format}
            onChange={(e) => setForm({ ...form, credential_format: e.target.value })}
          />
        </label>
        <label className="text-sm font-medium text-gray-700">
          Expires at
          <Input
            type="datetime-local"
            value={form.expires_at?.replace?.(' ', 'T')?.slice(0, 16) || ''}
            onChange={(e) => setForm({ ...form, expires_at: e.target.value.replace('T', ' ') })}
          />
        </label>
        <label className="text-sm font-medium text-gray-700 md:col-span-2">
          Notes
          <Textarea
            value={form.notes}
            onChange={(e) => setForm({ ...form, notes: e.target.value })}
            rows={3}
          />
        </label>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// Credential detail
// ---------------------------------------------------------------------------

function CredentialDetail({ id, onBack, onError, error }) {
  const [loading, setLoading] = useState(true)
  const [credential, setCredential] = useState(null)
  const [doors, setDoors] = useState([])
  const [logs, setLogs] = useState([])
  const [showEdit, setShowEdit] = useState(false)
  const [showStatus, setShowStatus] = useState(false)
  const [showGrant, setShowGrant] = useState(false)
  const [revokingDoor, setRevokingDoor] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await securityCredentialsService.get(id)
      setCredential(res?.data || null)
      setDoors(res?.data?.doors || [])
      setLogs(res?.data?.programming_logs || [])
    } catch (err) {
      console.error('Failed to load credential', err)
      onError('Unable to load credential.')
    } finally {
      setLoading(false)
    }
  }, [id, onError])

  useEffect(() => { load() }, [load])

  if (loading || !credential) {
    return (
      <div className="space-y-4">
        <Button variant="secondary" onClick={onBack}>← Back</Button>
        <Card><div className="py-10 flex justify-center"><Loading text="Loading credential..." /></div></Card>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <Button variant="secondary" onClick={onBack}>← Back to credentials</Button>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setShowEdit(true)}>Edit</Button>
          <Button variant="primary" onClick={() => setShowStatus(true)}>Change status</Button>
        </div>
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      <Card>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <div className="text-xs uppercase text-gray-500">#{credential.id}</div>
            <h2 className="text-xl font-semibold text-gray-900">{credential.holder_name}</h2>
            <div className="mt-1 flex flex-wrap gap-2 text-sm text-gray-600">
              <span>{credential.holder_email || '—'}</span>
              <span>•</span>
              <span>{credential.holder_phone || '—'}</span>
              {credential.holder_employee_id
                ? <><span>•</span><span>Emp #{credential.holder_employee_id}</span></>
                : null}
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            <Badge variant="secondary">{titleize(credential.credential_type)}</Badge>
            <Badge variant={STATUS_VARIANT[credential.status] || 'secondary'}>
              {titleize(credential.status)}
            </Badge>
            {credential.is_expired ? <Badge variant="danger">Expired</Badge> : null}
          </div>
        </div>
        <dl className="mt-4 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-2 text-sm">
          <div>
            <dt className="text-gray-500">Code</dt>
            <dd className="font-mono text-gray-900">{credential.credential_code}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Format</dt>
            <dd className="text-gray-900">{credential.credential_format || '—'}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Issued</dt>
            <dd className="text-gray-900">{formatDate(credential.issued_at)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Expires</dt>
            <dd className="text-gray-900">{credential.expires_at ? formatDate(credential.expires_at) : '—'}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Suspended</dt>
            <dd className="text-gray-900">{credential.suspended_at ? formatDate(credential.suspended_at) : '—'}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Revoked</dt>
            <dd className="text-gray-900">{credential.revoked_at ? formatDate(credential.revoked_at) : '—'}</dd>
          </div>
          <div className="col-span-2">
            <dt className="text-gray-500">Revoke reason</dt>
            <dd className="text-gray-900">{credential.revoke_reason || '—'}</dd>
          </div>
          {credential.notes ? (
            <div className="col-span-2 md:col-span-4">
              <dt className="text-gray-500">Notes</dt>
              <dd className="text-gray-900 whitespace-pre-wrap">{credential.notes}</dd>
            </div>
          ) : null}
        </dl>
      </Card>

      <Card>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-lg font-semibold text-gray-900">Door access</h3>
          <Button variant="primary" onClick={() => setShowGrant(true)}>Grant door</Button>
        </div>
        {doors.length === 0 ? (
          <div className="py-6 text-center text-gray-500 text-sm">
            No door grants yet — this credential cannot unlock anything.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Door</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Schedule</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Granted</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {doors.map((door) => (
                  <tr key={door.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">site_asset #{door.site_asset_id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {door.access_schedule_id
                        ? `Schedule #${door.access_schedule_id}`
                        : <span className="text-gray-400">24/7</span>}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{formatDate(door.granted_at)}</td>
                    <td className="px-4 py-2 text-sm">
                      {door.is_active
                        ? <Badge variant="success">Active</Badge>
                        : <Badge variant="danger" title={door.revoke_reason || ''}>Revoked</Badge>}
                    </td>
                    <td className="px-4 py-2 text-sm text-right">
                      {door.is_active ? (
                        <button
                          onClick={() => setRevokingDoor(door)}
                          className="text-red-600 hover:underline"
                        >
                          Revoke
                        </button>
                      ) : (
                        <span className="text-gray-400">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card>
        <h3 className="text-lg font-semibold text-gray-900 mb-3">Programming history</h3>
        {logs.length === 0 ? (
          <div className="py-6 text-center text-gray-500 text-sm">No history yet.</div>
        ) : (
          <ul className="divide-y divide-gray-100">
            {logs.map((log) => (
              <li key={log.id} className="py-2 flex items-start gap-3 text-sm">
                <Badge variant={ACTION_VARIANT[log.action] || 'secondary'}>{titleize(log.action)}</Badge>
                <div className="flex-1">
                  <div className="text-gray-900">{log.summary || '(no summary)'}</div>
                  <div className="text-xs text-gray-500">
                    {formatDate(log.programmed_at)}
                    {log.programmed_by_user_id ? ` • user #${log.programmed_by_user_id}` : ''}
                    {log.programmed_by_external ? ` • ${log.programmed_by_external}` : ''}
                    {log.ip_address ? ` • ${log.ip_address}` : ''}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {showEdit && (
        <CredentialFormModal
          row={credential}
          onClose={() => setShowEdit(false)}
          onSaved={async () => { setShowEdit(false); await load() }}
          onError={onError}
        />
      )}
      {showStatus && (
        <StatusChangeModal
          credential={credential}
          onClose={() => setShowStatus(false)}
          onSaved={async () => { setShowStatus(false); await load() }}
          onError={onError}
        />
      )}
      {showGrant && (
        <GrantDoorModal
          credentialId={credential.id}
          onClose={() => setShowGrant(false)}
          onSaved={async () => { setShowGrant(false); await load() }}
          onError={onError}
        />
      )}
      {revokingDoor && (
        <RevokeDoorModal
          door={revokingDoor}
          onClose={() => setRevokingDoor(null)}
          onSaved={async () => { setRevokingDoor(null); await load() }}
          onError={onError}
        />
      )}
    </div>
  )
}

function StatusChangeModal({ credential, onClose, onSaved, onError }) {
  const [status, setStatus] = useState(credential.status === 'active' ? 'suspended' : 'active')
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)

  const handleSave = async () => {
    setSaving(true)
    try {
      await securityCredentialsService.changeStatus(credential.id, status, reason || null)
      onSaved()
    } catch (err) {
      console.error('Failed to change credential status', err)
      onError(err?.response?.data?.message || 'Failed to change status.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={`Change status — ${credential.holder_name}`}
      size="md"
      onClose={onClose}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="primary" onClick={handleSave} disabled={saving}>
            {saving ? 'Saving…' : 'Apply'}
          </Button>
        </div>
      )}
    >
      <div className="space-y-3">
        <label className="block text-sm font-medium text-gray-700">
          New status
          <select
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
          >
            {STATUSES.map((s) => <option key={s} value={s}>{titleize(s)}</option>)}
          </select>
        </label>
        <label className="block text-sm font-medium text-gray-700">
          Reason (recommended for revoke / lost)
          <Textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
        </label>
        {(status === 'revoked' || status === 'lost') && (
          <Alert variant="warning">
            All active door grants for this credential will be revoked automatically.
          </Alert>
        )}
      </div>
    </Modal>
  )
}

function GrantDoorModal({ credentialId, onClose, onSaved, onError }) {
  const [siteAssetId, setSiteAssetId] = useState('')
  const [scheduleId, setScheduleId] = useState('')
  const [notes, setNotes] = useState('')
  const [saving, setSaving] = useState(false)

  const handleSave = async () => {
    if (!siteAssetId) {
      onError('site_asset_id is required')
      return
    }
    setSaving(true)
    try {
      await securityCredentialsService.grantDoor(credentialId, {
        site_asset_id: parseInt(siteAssetId, 10),
        access_schedule_id: scheduleId ? parseInt(scheduleId, 10) : null,
        notes: notes || null,
      })
      onSaved()
    } catch (err) {
      console.error('Failed to grant door', err)
      onError(err?.response?.data?.message || 'Failed to grant door.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title="Grant door access"
      size="md"
      onClose={onClose}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="primary" onClick={handleSave} disabled={saving}>
            {saving ? 'Saving…' : 'Grant'}
          </Button>
        </div>
      )}
    >
      <div className="space-y-3">
        <label className="block text-sm font-medium text-gray-700">
          Door (site_asset ID)
          <Input value={siteAssetId} onChange={(e) => setSiteAssetId(e.target.value)} type="number" />
        </label>
        <label className="block text-sm font-medium text-gray-700">
          Access schedule ID (optional — leave blank for 24/7)
          <Input value={scheduleId} onChange={(e) => setScheduleId(e.target.value)} type="number" />
        </label>
        <label className="block text-sm font-medium text-gray-700">
          Notes
          <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} />
        </label>
      </div>
    </Modal>
  )
}

function RevokeDoorModal({ door, onClose, onSaved, onError }) {
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)

  const handleSave = async () => {
    setSaving(true)
    try {
      await securityCredentialsService.revokeDoor(door.id, reason || null)
      onSaved()
    } catch (err) {
      console.error('Failed to revoke door', err)
      onError(err?.response?.data?.message || 'Failed to revoke door.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={`Revoke access to door #${door.site_asset_id}`}
      size="md"
      onClose={onClose}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="danger" onClick={handleSave} disabled={saving}>
            {saving ? 'Revoking…' : 'Revoke'}
          </Button>
        </div>
      )}
    >
      <div className="space-y-3">
        <p className="text-sm text-gray-700">
          The credential will no longer unlock this door. The grant row stays in the audit
          trail and can be reactivated later by re-granting.
        </p>
        <label className="block text-sm font-medium text-gray-700">
          Reason
          <Textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
        </label>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// Schedules tab
// ---------------------------------------------------------------------------

function ScheduleListTab({ onError }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(null) // null | 'new' | row

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await securityCredentialsService.listSchedules({ per_page: 200 })
      setRows(res?.data?.access_schedules || [])
    } catch (err) {
      console.error('Failed to load schedules', err)
      onError('Unable to load access schedules.')
    } finally {
      setLoading(false)
    }
  }, [onError])

  useEffect(() => { load() }, [load])

  const handleDelete = async (id) => {
    if (!window.confirm('Delete this access schedule? Any door grants pointing at it will fall back to 24/7.')) {
      return
    }
    try {
      await securityCredentialsService.deleteSchedule(id)
      await load()
    } catch (err) {
      console.error('Failed to delete schedule', err)
      onError('Failed to delete schedule.')
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex items-center justify-between">
          <h3 className="text-lg font-semibold text-gray-900">Access schedules</h3>
          <Button variant="primary" onClick={() => setShowForm('new')}>New schedule</Button>
        </div>
      </Card>
      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading schedules..." /></div>
        ) : rows.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No schedules defined.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Days</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Window</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">TZ</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900 font-medium">{row.name}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {(row.days || []).map((d) => titleize(d).slice(0, 3)).join(', ')}
                    </td>
                    <td className="px-4 py-2 text-sm font-mono text-gray-700">
                      {row.start_time?.slice(0, 5)} – {row.end_time?.slice(0, 5)}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{row.timezone || '—'}</td>
                    <td className="px-4 py-2 text-sm">
                      {row.is_active
                        ? <Badge variant="success">Active</Badge>
                        : <Badge variant="secondary">Inactive</Badge>}
                    </td>
                    <td className="px-4 py-2 text-sm text-right space-x-3">
                      <button onClick={() => setShowForm(row)} className="text-blue-600 hover:underline">Edit</button>
                      <button onClick={() => handleDelete(row.id)} className="text-red-600 hover:underline">Delete</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {showForm !== null && (
        <ScheduleFormModal
          row={showForm === 'new' ? null : showForm}
          onClose={() => setShowForm(null)}
          onSaved={async () => { setShowForm(null); await load() }}
          onError={onError}
        />
      )}
    </div>
  )
}

function ScheduleFormModal({ row, onClose, onSaved, onError }) {
  const isEdit = !!row
  const [form, setForm] = useState({
    customer_id: row?.customer_id ?? '',
    name: row?.name || '',
    description: row?.description || '',
    days_of_week: row?.days_of_week || 'mon,tue,wed,thu,fri',
    start_time: (row?.start_time || '09:00:00').slice(0, 5),
    end_time: (row?.end_time || '17:00:00').slice(0, 5),
    timezone: row?.timezone || '',
    is_active: row?.is_active !== false,
  })
  const [saving, setSaving] = useState(false)

  const selectedDays = useMemo(
    () => new Set((form.days_of_week || '').split(',').map((d) => d.trim()).filter(Boolean)),
    [form.days_of_week],
  )

  const toggleDay = (code) => {
    const next = new Set(selectedDays)
    if (next.has(code)) next.delete(code); else next.add(code)
    setForm({
      ...form,
      days_of_week: DAY_CODES.filter((d) => next.has(d)).join(','),
    })
  }

  const handleSave = async () => {
    if (!form.name || !form.days_of_week) {
      onError('name and at least one day are required')
      return
    }
    setSaving(true)
    try {
      const payload = { ...form }
      if (!isEdit) {
        if (!payload.customer_id) {
          onError('customer_id is required')
          setSaving(false)
          return
        }
        payload.customer_id = parseInt(payload.customer_id, 10)
      } else {
        delete payload.customer_id
      }
      Object.keys(payload).forEach((k) => { if (payload[k] === '') delete payload[k] })
      if (isEdit) {
        await securityCredentialsService.updateSchedule(row.id, payload)
      } else {
        await securityCredentialsService.createSchedule(payload)
      }
      onSaved()
    } catch (err) {
      console.error('Failed to save schedule', err)
      onError(err?.response?.data?.message || 'Failed to save schedule.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={isEdit ? `Edit schedule #${row.id}` : 'New access schedule'}
      size="lg"
      onClose={onClose}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="primary" onClick={handleSave} disabled={saving}>
            {saving ? 'Saving…' : 'Save'}
          </Button>
        </div>
      )}
    >
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <label className="text-sm font-medium text-gray-700">
          Customer ID
          <Input
            value={form.customer_id}
            onChange={(e) => setForm({ ...form, customer_id: e.target.value })}
            disabled={isEdit}
            type="number"
          />
        </label>
        <label className="text-sm font-medium text-gray-700">
          Name
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </label>
        <label className="text-sm font-medium text-gray-700 md:col-span-2">
          Description
          <Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} rows={2} />
        </label>
        <div className="md:col-span-2">
          <div className="text-sm font-medium text-gray-700">Days of week</div>
          <div className="mt-2 flex flex-wrap gap-2">
            {DAY_CODES.map((d) => (
              <button
                key={d}
                type="button"
                onClick={() => toggleDay(d)}
                className={`px-3 py-1 rounded-full text-sm border ${
                  selectedDays.has(d)
                    ? 'bg-blue-600 text-white border-blue-600'
                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                }`}
              >
                {titleize(d)}
              </button>
            ))}
          </div>
        </div>
        <label className="text-sm font-medium text-gray-700">
          Start time
          <Input
            type="time"
            value={form.start_time}
            onChange={(e) => setForm({ ...form, start_time: e.target.value })}
          />
        </label>
        <label className="text-sm font-medium text-gray-700">
          End time
          <Input
            type="time"
            value={form.end_time}
            onChange={(e) => setForm({ ...form, end_time: e.target.value })}
          />
        </label>
        <label className="text-sm font-medium text-gray-700">
          Timezone (optional)
          <Input
            value={form.timezone}
            onChange={(e) => setForm({ ...form, timezone: e.target.value })}
            placeholder="America/Los_Angeles"
          />
        </label>
        <label className="text-sm font-medium text-gray-700 flex items-center gap-2">
          <input
            type="checkbox"
            checked={form.is_active}
            onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
          />
          <span>Active</span>
        </label>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// Audit log tab
// ---------------------------------------------------------------------------

function AuditLogTab({ onError }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [actionFilter, setActionFilter] = useState('')
  const [targetTypeFilter, setTargetTypeFilter] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = { per_page: 200 }
      if (actionFilter) params.action = actionFilter
      if (targetTypeFilter) params.target_type = targetTypeFilter
      const res = await securityCredentialsService.listLogs(params)
      setRows(res?.data?.programming_logs || [])
    } catch (err) {
      console.error('Failed to load programming logs', err)
      onError('Unable to load programming logs.')
    } finally {
      setLoading(false)
    }
  }, [actionFilter, targetTypeFilter, onError])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <label className="block text-sm font-medium text-gray-700">
            Target
            <select
              value={targetTypeFilter}
              onChange={(e) => setTargetTypeFilter(e.target.value)}
              className="mt-1 block rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
            >
              <option value="">All</option>
              <option value="credential">Credential</option>
              <option value="credential_door">Credential ↔ door</option>
              <option value="access_schedule">Access schedule</option>
              <option value="pos_terminal">POS terminal</option>
              <option value="door">Door</option>
            </select>
          </label>
          <label className="block text-sm font-medium text-gray-700">
            Action
            <select
              value={actionFilter}
              onChange={(e) => setActionFilter(e.target.value)}
              className="mt-1 block rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
            >
              <option value="">All</option>
              {Object.keys(ACTION_VARIANT).map((a) => <option key={a} value={a}>{titleize(a)}</option>)}
            </select>
          </label>
        </div>
      </Card>
      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading audit log..." /></div>
        ) : rows.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No audit entries match these filters.</div>
        ) : (
          <ul className="divide-y divide-gray-100">
            {rows.map((log) => (
              <li key={log.id} className="py-2 flex items-start gap-3 text-sm">
                <Badge variant={ACTION_VARIANT[log.action] || 'secondary'}>{titleize(log.action)}</Badge>
                <div className="flex-1">
                  <div className="text-gray-900">
                    <span className="font-mono text-xs text-gray-500 mr-2">
                      {log.target_type}#{log.target_id}
                    </span>
                    {log.summary || '(no summary)'}
                  </div>
                  <div className="text-xs text-gray-500">
                    {formatDate(log.programmed_at)}
                    {log.programmed_by_user_id ? ` • user #${log.programmed_by_user_id}` : ''}
                    {log.programmed_by_external ? ` • ${log.programmed_by_external}` : ''}
                    {log.ip_address ? ` • ${log.ip_address}` : ''}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
