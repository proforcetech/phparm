import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import posTerminalsService from '../../../services/pos-terminals.service'

/**
 * Phase 16 / S2 of docs/woms-expansion-plan.md.
 *
 * Two-pane: registry list + drill-into-detail.
 *
 * Detail view shows the terminal header, recent heartbeats (newest first),
 * and the per-terminal programming-log feed (which captures every config
 * change, every webhook receipt, and every stale-sweep alert ticket).
 *
 * The shared_secret is shown exactly once — on create() and rotateSecret()
 * — through a modal banner. Subsequent reads return only a presence flag.
 *
 * Read perm:  pos_terminals.view  (server-enforced)
 * Write perm: pos_terminals.manage
 */

const STATUSES = ['active', 'disabled', 'decommissioned']

const STATUS_VARIANT = {
  active: 'success',
  disabled: 'warning',
  decommissioned: 'secondary',
}

const HEARTBEAT_VARIANT = {
  online: 'success',
  degraded: 'warning',
  offline: 'danger',
  error: 'danger',
}

const ACTION_VARIANT = {
  created: 'info',
  updated: 'info',
  deleted: 'danger',
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

function secondsAgo(s) {
  if (!s) return null
  const t = Date.parse(String(s).replace(' ', 'T'))
  if (!Number.isFinite(t)) return null
  return Math.max(0, Math.round((Date.now() - t) / 1000))
}

export default function PosTerminals() {
  const [error, setError] = useState('')
  const [selectedId, setSelectedId] = useState(null)

  if (selectedId !== null) {
    return (
      <PosTerminalDetail
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
          <h1 className="text-2xl font-bold text-gray-900">POS terminals</h1>
          <p className="mt-1 text-sm text-gray-500">
            Registered POS devices, their heartbeat history, and the programming-log audit trail.
            Stale terminals open a ticket via the cron sweeper; recovery auto-resolves the ticket on
            the next valid heartbeat.
          </p>
        </div>
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      <PosTerminalList onSelect={setSelectedId} onError={setError} />
    </div>
  )
}

// ============================================================================
// List
// ============================================================================

function PosTerminalList({ onSelect, onError }) {
  const [rows, setRows] = useState([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [filters, setFilters] = useState({ search: '', status: '' })
  const [page, setPage] = useState(1)
  const perPage = 50
  const [showCreate, setShowCreate] = useState(false)
  const [createdSecret, setCreatedSecret] = useState(null)

  const fetchRows = useCallback(async () => {
    setLoading(true)
    onError('')
    try {
      const params = { page, per_page: perPage }
      if (filters.search) params.search = filters.search
      if (filters.status) params.status = filters.status
      const res = await posTerminalsService.list(params)
      const data = res.data || res
      setRows(data.terminals || [])
      setTotal(data.total || 0)
    } catch (err) {
      onError(err?.response?.data?.message || err?.message || 'Failed to load terminals')
    } finally {
      setLoading(false)
    }
  }, [filters, page, onError])

  useEffect(() => {
    fetchRows()
  }, [fetchRows])

  const totalPages = Math.max(1, Math.ceil(total / perPage))

  return (
    <Card>
      <div className="flex flex-col gap-3 border-b border-gray-200 p-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:flex-1">
          <Input
            placeholder="Search code, name, vendor, serial…"
            value={filters.search}
            onChange={(e) => {
              setPage(1)
              setFilters((f) => ({ ...f, search: e.target.value }))
            }}
          />
          <select
            value={filters.status}
            onChange={(e) => {
              setPage(1)
              setFilters((f) => ({ ...f, status: e.target.value }))
            }}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="">All statuses</option>
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {titleize(s)}
              </option>
            ))}
          </select>
        </div>
        <Button onClick={() => setShowCreate(true)}>Register terminal</Button>
      </div>

      {loading ? (
        <div className="p-6">
          <Loading />
        </div>
      ) : rows.length === 0 ? (
        <div className="p-8 text-center text-sm text-gray-500">No POS terminals registered yet.</div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
              <tr>
                <th className="px-4 py-2">Terminal</th>
                <th className="px-4 py-2">Site</th>
                <th className="px-4 py-2">Vendor / Model</th>
                <th className="px-4 py-2">Status</th>
                <th className="px-4 py-2">Last heartbeat</th>
                <th className="px-4 py-2">Health</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {rows.map((row) => {
                const ago = secondsAgo(row.last_seen_at)
                return (
                  <tr
                    key={row.id}
                    onClick={() => onSelect(row.id)}
                    className="cursor-pointer hover:bg-gray-50"
                  >
                    <td className="px-4 py-3">
                      <div className="font-mono text-sm font-medium text-gray-900">
                        {row.terminal_code}
                      </div>
                      {row.name ? <div className="text-xs text-gray-500">{row.name}</div> : null}
                    </td>
                    <td className="px-4 py-3 text-gray-600">#{row.site_id}</td>
                    <td className="px-4 py-3 text-gray-600">
                      {row.vendor || '—'}
                      {row.model ? ` / ${row.model}` : ''}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={STATUS_VARIANT[row.status] || 'secondary'}>
                        {titleize(row.status)}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-gray-600">
                      {row.last_seen_at ? (
                        <>
                          <div>{formatDate(row.last_seen_at)}</div>
                          <div className="text-xs text-gray-400">
                            {ago !== null ? `${ago}s ago` : ''}
                          </div>
                        </>
                      ) : (
                        <span className="italic text-gray-400">never</span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      {row.is_stale ? (
                        <Badge variant="danger">Stale</Badge>
                      ) : row.last_status ? (
                        <Badge variant={HEARTBEAT_VARIANT[row.last_status] || 'secondary'}>
                          {titleize(row.last_status)}
                        </Badge>
                      ) : (
                        <span className="text-xs text-gray-400">—</span>
                      )}
                      {row.last_alert_ticket_id ? (
                        <div className="mt-1 text-xs text-amber-700">
                          Ticket #{row.last_alert_ticket_id}
                        </div>
                      ) : null}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {totalPages > 1 ? (
        <div className="flex items-center justify-between border-t border-gray-200 p-3 text-sm text-gray-600">
          <span>
            Page {page} of {totalPages} · {total} total
          </span>
          <div className="flex gap-2">
            <Button
              variant="secondary"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              Previous
            </Button>
            <Button
              variant="secondary"
              disabled={page >= totalPages}
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
            >
              Next
            </Button>
          </div>
        </div>
      ) : null}

      {showCreate ? (
        <PosTerminalFormModal
          onClose={() => setShowCreate(false)}
          onCreated={(payload) => {
            setShowCreate(false)
            setCreatedSecret(payload)
            fetchRows()
          }}
          onError={onError}
        />
      ) : null}

      {createdSecret ? (
        <SharedSecretModal
          terminalCode={createdSecret.terminal_code}
          sharedSecret={createdSecret.shared_secret}
          onClose={() => setCreatedSecret(null)}
        />
      ) : null}
    </Card>
  )
}

// ============================================================================
// Detail
// ============================================================================

function PosTerminalDetail({ id, onBack, onError, error }) {
  const [terminal, setTerminal] = useState(null)
  const [loading, setLoading] = useState(false)
  const [showEdit, setShowEdit] = useState(false)
  const [showRotate, setShowRotate] = useState(false)
  const [rotatedSecret, setRotatedSecret] = useState(null)

  const fetchTerminal = useCallback(async () => {
    setLoading(true)
    onError('')
    try {
      const res = await posTerminalsService.get(id)
      setTerminal(res.data || res)
    } catch (err) {
      onError(err?.response?.data?.message || err?.message || 'Failed to load terminal')
    } finally {
      setLoading(false)
    }
  }, [id, onError])

  useEffect(() => {
    fetchTerminal()
  }, [fetchTerminal])

  const lastSeenAgo = useMemo(() => secondsAgo(terminal?.last_seen_at), [terminal])

  if (loading && !terminal) {
    return (
      <div className="space-y-4">
        <Button variant="secondary" onClick={onBack}>
          ← Back to terminals
        </Button>
        <Loading />
      </div>
    )
  }
  if (!terminal) {
    return (
      <div className="space-y-4">
        <Button variant="secondary" onClick={onBack}>
          ← Back to terminals
        </Button>
        {error ? <Alert variant="danger">{error}</Alert> : null}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <Button variant="secondary" onClick={onBack}>
            ← Back to terminals
          </Button>
          <h1 className="mt-3 font-mono text-2xl font-bold text-gray-900">
            {terminal.terminal_code}
          </h1>
          {terminal.name ? <p className="text-sm text-gray-500">{terminal.name}</p> : null}
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setShowRotate(true)}>
            Rotate secret
          </Button>
          <Button onClick={() => setShowEdit(true)}>Edit</Button>
        </div>
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      <Card className="p-4">
        <div className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
          <div>
            <div className="text-xs uppercase text-gray-500">Status</div>
            <div className="mt-1">
              <Badge variant={STATUS_VARIANT[terminal.status] || 'secondary'}>
                {titleize(terminal.status)}
              </Badge>
            </div>
          </div>
          <div>
            <div className="text-xs uppercase text-gray-500">Last heartbeat</div>
            <div className="mt-1 text-gray-900">{formatDate(terminal.last_seen_at)}</div>
            {lastSeenAgo !== null ? (
              <div className="text-xs text-gray-400">{lastSeenAgo}s ago</div>
            ) : null}
          </div>
          <div>
            <div className="text-xs uppercase text-gray-500">Health</div>
            <div className="mt-1">
              {terminal.is_stale ? (
                <Badge variant="danger">Stale</Badge>
              ) : terminal.last_status ? (
                <Badge variant={HEARTBEAT_VARIANT[terminal.last_status] || 'secondary'}>
                  {titleize(terminal.last_status)}
                </Badge>
              ) : (
                <span className="text-xs text-gray-400">no heartbeat yet</span>
              )}
              {terminal.last_alert_ticket_id ? (
                <div className="mt-1 text-xs text-amber-700">
                  Open alert ticket #{terminal.last_alert_ticket_id}
                </div>
              ) : null}
            </div>
          </div>
          <div>
            <div className="text-xs uppercase text-gray-500">Site / Asset</div>
            <div className="mt-1 text-gray-900">
              Site #{terminal.site_id}
              {terminal.site_asset_id ? ` · Asset #${terminal.site_asset_id}` : ''}
            </div>
          </div>
          <div>
            <div className="text-xs uppercase text-gray-500">Vendor / Model</div>
            <div className="mt-1 text-gray-900">
              {terminal.vendor || '—'}
              {terminal.model ? ` / ${terminal.model}` : ''}
            </div>
            {terminal.serial_number ? (
              <div className="text-xs text-gray-500">SN: {terminal.serial_number}</div>
            ) : null}
          </div>
          <div>
            <div className="text-xs uppercase text-gray-500">Heartbeat policy</div>
            <div className="mt-1 text-gray-900">
              every {terminal.heartbeat_interval_seconds}s · stale after{' '}
              {terminal.stale_after_seconds}s
            </div>
            <div className="text-xs text-gray-500">
              Shared secret:{' '}
              {terminal.shared_secret_present ? 'configured' : 'NOT SET'}
            </div>
          </div>
        </div>
        {terminal.notes ? (
          <div className="mt-4 border-t border-gray-200 pt-3 text-sm text-gray-700">
            <div className="text-xs uppercase text-gray-500">Notes</div>
            <div className="mt-1 whitespace-pre-wrap">{terminal.notes}</div>
          </div>
        ) : null}
      </Card>

      <Card>
        <div className="border-b border-gray-200 px-4 py-3 text-sm font-semibold text-gray-700">
          Recent heartbeats ({terminal.heartbeat_total || 0})
        </div>
        {(terminal.heartbeats || []).length === 0 ? (
          <div className="p-6 text-center text-sm text-gray-500">No heartbeats received yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                  <th className="px-4 py-2">Received at</th>
                  <th className="px-4 py-2">Reported at</th>
                  <th className="px-4 py-2">Status</th>
                  <th className="px-4 py-2">From IP</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {(terminal.heartbeats || []).map((hb) => (
                  <tr key={hb.id}>
                    <td className="px-4 py-2 text-gray-900">{formatDate(hb.received_at)}</td>
                    <td className="px-4 py-2 text-gray-600">{formatDate(hb.reported_at)}</td>
                    <td className="px-4 py-2">
                      <Badge variant={HEARTBEAT_VARIANT[hb.status] || 'secondary'}>
                        {titleize(hb.status)}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 font-mono text-xs text-gray-500">
                      {hb.ip_address || '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card>
        <div className="border-b border-gray-200 px-4 py-3 text-sm font-semibold text-gray-700">
          Programming history
        </div>
        {(terminal.programming_logs || []).length === 0 ? (
          <div className="p-6 text-center text-sm text-gray-500">
            No programming events yet — config changes, webhook receipts, and stale-sweep alerts
            will appear here.
          </div>
        ) : (
          <ul className="divide-y divide-gray-200">
            {(terminal.programming_logs || []).map((log) => (
              <li key={log.id} className="px-4 py-3 text-sm">
                <div className="flex items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <Badge variant={ACTION_VARIANT[log.action] || 'secondary'}>
                      {titleize(log.action)}
                    </Badge>
                    <span className="text-gray-500">{formatDate(log.programmed_at)}</span>
                  </div>
                  <span className="text-xs text-gray-400">
                    {log.programmed_by_external
                      ? log.programmed_by_external
                      : log.programmed_by_user_id
                      ? `user #${log.programmed_by_user_id}`
                      : 'system'}
                  </span>
                </div>
                {log.summary ? (
                  <div className="mt-1 text-gray-800">{log.summary}</div>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </Card>

      {showEdit ? (
        <PosTerminalFormModal
          terminal={terminal}
          onClose={() => setShowEdit(false)}
          onUpdated={() => {
            setShowEdit(false)
            fetchTerminal()
          }}
          onError={onError}
        />
      ) : null}

      {showRotate ? (
        <RotateSecretModal
          terminal={terminal}
          onClose={() => setShowRotate(false)}
          onRotated={(payload) => {
            setShowRotate(false)
            setRotatedSecret(payload)
            fetchTerminal()
          }}
          onError={onError}
        />
      ) : null}

      {rotatedSecret ? (
        <SharedSecretModal
          terminalCode={rotatedSecret.terminal_code}
          sharedSecret={rotatedSecret.shared_secret}
          onClose={() => setRotatedSecret(null)}
          rotated
        />
      ) : null}
    </div>
  )
}

// ============================================================================
// Modals
// ============================================================================

function PosTerminalFormModal({ terminal, onClose, onCreated, onUpdated, onError }) {
  const isEdit = Boolean(terminal)
  const [form, setForm] = useState(() => ({
    customer_id: terminal?.customer_id ?? '',
    site_id: terminal?.site_id ?? '',
    site_asset_id: terminal?.site_asset_id ?? '',
    terminal_code: terminal?.terminal_code ?? '',
    name: terminal?.name ?? '',
    vendor: terminal?.vendor ?? '',
    model: terminal?.model ?? '',
    serial_number: terminal?.serial_number ?? '',
    heartbeat_interval_seconds: terminal?.heartbeat_interval_seconds ?? 60,
    stale_after_seconds: terminal?.stale_after_seconds ?? 300,
    status: terminal?.status ?? 'active',
    notes: terminal?.notes ?? '',
  }))
  const [saving, setSaving] = useState(false)

  const update = (key, value) => setForm((f) => ({ ...f, [key]: value }))

  const submit = async () => {
    setSaving(true)
    onError('')
    try {
      const payload = {
        ...form,
        customer_id: form.customer_id ? Number(form.customer_id) : null,
        site_id: form.site_id ? Number(form.site_id) : null,
        site_asset_id: form.site_asset_id ? Number(form.site_asset_id) : null,
        heartbeat_interval_seconds: Number(form.heartbeat_interval_seconds) || 60,
        stale_after_seconds: Number(form.stale_after_seconds) || 300,
      }
      if (isEdit) {
        await posTerminalsService.update(terminal.id, payload)
        onUpdated && onUpdated()
      } else {
        const res = await posTerminalsService.create(payload)
        const data = res.data || res
        onCreated && onCreated({
          terminal_code: data.terminal_code,
          shared_secret: data.shared_secret,
        })
      }
    } catch (err) {
      onError(err?.response?.data?.message || err?.message || 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={isEdit ? `Edit terminal ${terminal.terminal_code}` : 'Register POS terminal'}
      size="lg"
      onClose={onClose}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button onClick={submit} disabled={saving}>
            {saving ? 'Saving…' : isEdit ? 'Save changes' : 'Register'}
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {!isEdit ? (
          <>
            <Input
              label="Customer ID"
              type="number"
              value={form.customer_id}
              onChange={(e) => update('customer_id', e.target.value)}
              required
            />
            <Input
              label="Site ID"
              type="number"
              value={form.site_id}
              onChange={(e) => update('site_id', e.target.value)}
              required
            />
            <Input
              label="Terminal code"
              value={form.terminal_code}
              onChange={(e) => update('terminal_code', e.target.value)}
              required
              placeholder="e.g. lobby-pos-01"
            />
          </>
        ) : (
          <Input
            label="Site ID"
            type="number"
            value={form.site_id}
            onChange={(e) => update('site_id', e.target.value)}
          />
        )}
        <Input
          label="Site asset ID (optional)"
          type="number"
          value={form.site_asset_id}
          onChange={(e) => update('site_asset_id', e.target.value)}
        />
        <Input
          label="Friendly name"
          value={form.name}
          onChange={(e) => update('name', e.target.value)}
        />
        <Input
          label="Vendor"
          value={form.vendor}
          onChange={(e) => update('vendor', e.target.value)}
        />
        <Input
          label="Model"
          value={form.model}
          onChange={(e) => update('model', e.target.value)}
        />
        <Input
          label="Serial number"
          value={form.serial_number}
          onChange={(e) => update('serial_number', e.target.value)}
        />
        <Input
          label="Heartbeat interval (sec)"
          type="number"
          min="1"
          value={form.heartbeat_interval_seconds}
          onChange={(e) => update('heartbeat_interval_seconds', e.target.value)}
        />
        <Input
          label="Stale after (sec)"
          type="number"
          min="1"
          value={form.stale_after_seconds}
          onChange={(e) => update('stale_after_seconds', e.target.value)}
        />
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">Status</label>
          <select
            value={form.status}
            onChange={(e) => update('status', e.target.value)}
            className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
          >
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {titleize(s)}
              </option>
            ))}
          </select>
        </div>
      </div>
      <div className="mt-3">
        <Textarea
          label="Notes"
          rows={3}
          value={form.notes}
          onChange={(e) => update('notes', e.target.value)}
        />
      </div>
      {!isEdit ? (
        <Alert variant="info" className="mt-3">
          A fresh HMAC shared_secret will be generated and shown on the next screen — copy it
          immediately. It cannot be retrieved later.
        </Alert>
      ) : null}
    </Modal>
  )
}

function RotateSecretModal({ terminal, onClose, onRotated, onError }) {
  const [confirming, setConfirming] = useState(false)

  const submit = async () => {
    setConfirming(true)
    onError('')
    try {
      const res = await posTerminalsService.rotateSecret(terminal.id)
      const data = res.data || res
      onRotated({ terminal_code: data.terminal_code, shared_secret: data.shared_secret })
    } catch (err) {
      onError(err?.response?.data?.message || err?.message || 'Rotate failed')
    } finally {
      setConfirming(false)
    }
  }

  return (
    <Modal
      open
      title={`Rotate shared secret for ${terminal.terminal_code}`}
      onClose={onClose}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={confirming}>
            Cancel
          </Button>
          <Button variant="danger" onClick={submit} disabled={confirming}>
            {confirming ? 'Rotating…' : 'Rotate now'}
          </Button>
        </div>
      }
    >
      <Alert variant="warning">
        Rotating the secret will invalidate the existing key immediately. The terminal will start
        failing HMAC verification until the new secret is installed on the device. Continue only if
        you can update the device right now.
      </Alert>
    </Modal>
  )
}

function SharedSecretModal({ terminalCode, sharedSecret, onClose, rotated = false }) {
  const [copied, setCopied] = useState(false)

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(sharedSecret)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      setCopied(false)
    }
  }

  return (
    <Modal
      open
      title={rotated ? 'New shared secret' : 'Terminal registered'}
      onClose={onClose}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={copy}>
            {copied ? 'Copied!' : 'Copy'}
          </Button>
          <Button onClick={onClose}>I have stored it</Button>
        </div>
      }
    >
      <Alert variant="warning">
        Copy this shared_secret now and install it on the device. It will not be shown again — you
        will have to rotate it again if you lose it.
      </Alert>
      <div className="mt-3">
        <div className="text-xs uppercase text-gray-500">Terminal</div>
        <div className="mt-1 font-mono text-sm">{terminalCode}</div>
      </div>
      <div className="mt-3">
        <div className="text-xs uppercase text-gray-500">shared_secret (HMAC-SHA256 key, hex)</div>
        <div className="mt-1 break-all rounded-md bg-gray-100 p-3 font-mono text-sm">
          {sharedSecret}
        </div>
      </div>
    </Modal>
  )
}
