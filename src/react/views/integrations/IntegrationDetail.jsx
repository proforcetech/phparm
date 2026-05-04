import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import { useToast } from '../../stores/toast.jsx'
import integrationsService from '../../../services/integrations.service'

const STATUS_VARIANT = {
  connected: 'success',
  active: 'success',
  success: 'success',
  ok: 'success',
  error: 'danger',
  failed: 'danger',
  disconnected: 'default',
  pending: 'warning',
  warning: 'warning',
}

const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'actions', label: 'Actions' },
  { id: 'sync', label: 'Sync logs' },
  { id: 'webhooks', label: 'Webhook events' },
]

function formatDate(value) {
  if (!value) return '—'
  try {
    const d = new Date(String(value).replace(' ', 'T'))
    if (Number.isNaN(d.getTime())) return String(value)
    return d.toLocaleString()
  } catch {
    return String(value)
  }
}

function statusBadge(status) {
  const variant = STATUS_VARIANT[status] || 'default'
  return <Badge variant={variant}>{status || 'unknown'}</Badge>
}

function truncate(value, n = 80) {
  if (!value) return ''
  const s = typeof value === 'string' ? value : JSON.stringify(value)
  return s.length > n ? `${s.slice(0, n)}…` : s
}

export default function IntegrationDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()

  const [integration, setIntegration] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [tab, setTab] = useState('overview')

  const [editOpen, setEditOpen] = useState(false)
  const [editName, setEditName] = useState('')
  const [editConfig, setEditConfig] = useState('')
  const [editActive, setEditActive] = useState(true)
  const [editError, setEditError] = useState('')
  const [saving, setSaving] = useState(false)

  const [testResult, setTestResult] = useState(null)
  const [testing, setTesting] = useState(false)
  const [syncing, setSyncing] = useState(false)
  const [disconnectOpen, setDisconnectOpen] = useState(false)
  const [disconnecting, setDisconnecting] = useState(false)

  const [syncLogs, setSyncLogs] = useState([])
  const [syncLogsLoading, setSyncLogsLoading] = useState(false)
  const [webhooks, setWebhooks] = useState([])
  const [webhooksLoading, setWebhooksLoading] = useState(false)

  const [detailModal, setDetailModal] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    integrationsService
      .get(id)
      .then((res) => setIntegration(res?.data ?? res))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load integration'))
      .finally(() => setLoading(false))
  }, [id])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    if (tab !== 'sync' || !id) return
    setSyncLogsLoading(true)
    integrationsService
      .listSyncLogs(id, { limit: 50 })
      .then((res) => setSyncLogs(res?.data ?? res ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load sync logs'))
      .finally(() => setSyncLogsLoading(false))
  }, [tab, id])

  useEffect(() => {
    if (tab !== 'webhooks' || !id) return
    setWebhooksLoading(true)
    integrationsService
      .listWebhooks(id, { limit: 50 })
      .then((res) => setWebhooks(res?.data ?? res ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load webhook events'))
      .finally(() => setWebhooksLoading(false))
  }, [tab, id])

  const openEdit = () => {
    if (!integration) return
    setEditName(integration.display_name || '')
    setEditConfig(integration.config ? JSON.stringify(integration.config, null, 2) : '')
    setEditActive(integration.is_active !== false)
    setEditError('')
    setEditOpen(true)
  }

  const submitEdit = async () => {
    setEditError('')
    let parsedConfig = {}
    if (editConfig.trim()) {
      try {
        parsedConfig = JSON.parse(editConfig)
      } catch (err) {
        setEditError(`Config is not valid JSON: ${err.message}`)
        return
      }
    }
    setSaving(true)
    try {
      await integrationsService.update(id, {
        display_name: editName.trim(),
        config: parsedConfig,
        is_active: editActive,
      })
      toast.success('Integration updated.')
      setEditOpen(false)
      load()
    } catch (e) {
      setEditError(e?.response?.data?.message || e?.message || 'Failed to update integration')
    } finally {
      setSaving(false)
    }
  }

  const runTest = async () => {
    setTesting(true)
    setTestResult(null)
    try {
      const res = await integrationsService.test(id)
      setTestResult(res?.data ?? res)
      toast.success('Test completed.')
    } catch (e) {
      setTestResult({ error: e?.response?.data?.message || e?.message || 'Test failed' })
      toast.error('Test failed.')
    } finally {
      setTesting(false)
    }
  }

  const runSync = async () => {
    setSyncing(true)
    try {
      await integrationsService.sync(id)
      toast.success('Sync triggered.')
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Sync failed.')
    } finally {
      setSyncing(false)
    }
  }

  const submitDisconnect = async () => {
    setDisconnecting(true)
    try {
      await integrationsService.disconnect(id)
      toast.success('Disconnected.')
      setDisconnectOpen(false)
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Disconnect failed.')
    } finally {
      setDisconnecting(false)
    }
  }

  const overview = useMemo(() => integration ?? {}, [integration])

  if (loading) {
    return <div className="p-6 flex justify-center"><Loading size="lg" /></div>
  }

  if (!integration) {
    return (
      <div className="p-4 space-y-3">
        {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}
        <Button variant="secondary" onClick={() => navigate('/cp/integrations')}>Back</Button>
      </div>
    )
  }

  return (
    <div className="space-y-4 p-4">
      <header>
        <div className="flex items-center gap-2 text-sm text-gray-500">
          <Link to="/cp/integrations" className="hover:underline">Integrations</Link>
          <span>/</span>
          <span>{overview.display_name || `#${overview.id}`}</span>
        </div>
        <h1 className="text-xl font-semibold mt-1">
          {overview.display_name || overview.provider || `Integration #${overview.id}`}
        </h1>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card padding={false}>
        <div className="border-b border-gray-200 flex">
          {TABS.map((t) => (
            <button
              key={t.id}
              type="button"
              onClick={() => setTab(t.id)}
              className={`px-4 py-2 text-sm border-b-2 -mb-px ${
                tab === t.id
                  ? 'border-primary-600 text-primary-700 font-medium'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              {t.label}
            </button>
          ))}
        </div>

        <div className="p-4">
          {tab === 'overview' && (
            <div className="space-y-3">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div><span className="text-gray-500">Provider:</span> {overview.provider ?? overview.provider_kind ?? '—'}</div>
                <div><span className="text-gray-500">Status:</span> {statusBadge(overview.status)}</div>
                <div><span className="text-gray-500">Display name:</span> {overview.display_name || '—'}</div>
                <div><span className="text-gray-500">Active:</span> {overview.is_active === false ? 'No' : 'Yes'}</div>
                <div><span className="text-gray-500">Last sync:</span> {formatDate(overview.last_sync_at)}</div>
                <div><span className="text-gray-500">Last error:</span> {formatDate(overview.last_error_at)}</div>
              </div>
              {overview.error_summary && (
                <Alert variant="danger" closable={false}>
                  <div className="text-sm whitespace-pre-wrap">{overview.error_summary}</div>
                </Alert>
              )}
              <div className="flex justify-end">
                <Button onClick={openEdit}>Edit</Button>
              </div>
            </div>
          )}

          {tab === 'actions' && (
            <div className="space-y-3">
              <div className="flex flex-wrap gap-2">
                <Button onClick={runTest} disabled={testing}>
                  {testing ? 'Testing…' : 'Test connection'}
                </Button>
                <Button onClick={runSync} disabled={syncing} variant="secondary">
                  {syncing ? 'Syncing…' : 'Sync now'}
                </Button>
                <Button onClick={() => setDisconnectOpen(true)} variant="danger">
                  Disconnect
                </Button>
              </div>
              {testResult !== null && (
                <Card>
                  <div className="text-xs uppercase text-gray-500 mb-2">Test result</div>
                  <pre className="text-xs whitespace-pre-wrap break-words bg-gray-50 p-2 rounded">
                    {JSON.stringify(testResult, null, 2)}
                  </pre>
                </Card>
              )}
            </div>
          )}

          {tab === 'sync' && (
            syncLogsLoading ? (
              <div className="p-6 text-center"><Loading /></div>
            ) : syncLogs.length === 0 ? (
              <div className="p-6 text-center text-gray-500">No sync logs.</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                      <th className="text-left p-2">Started</th>
                      <th className="text-left p-2">Ended</th>
                      <th className="text-left p-2">In</th>
                      <th className="text-left p-2">Out</th>
                      <th className="text-left p-2">Status</th>
                      <th className="text-left p-2">Error</th>
                    </tr>
                  </thead>
                  <tbody>
                    {syncLogs.map((log) => (
                      <tr key={log.id} className="border-t">
                        <td className="p-2">{formatDate(log.started_at)}</td>
                        <td className="p-2">{formatDate(log.ended_at)}</td>
                        <td className="p-2">{log.records_in ?? '—'}</td>
                        <td className="p-2">{log.records_out ?? '—'}</td>
                        <td className="p-2">{statusBadge(log.status)}</td>
                        <td className="p-2 max-w-xs">
                          {log.error_message ? (
                            <button
                              type="button"
                              className="text-red-600 underline text-left"
                              onClick={() => setDetailModal({
                                title: 'Sync error',
                                body: log.error_message,
                              })}
                            >
                              {truncate(log.error_message)}
                            </button>
                          ) : <span className="text-gray-400">—</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )
          )}

          {tab === 'webhooks' && (
            webhooksLoading ? (
              <div className="p-6 text-center"><Loading /></div>
            ) : webhooks.length === 0 ? (
              <div className="p-6 text-center text-gray-500">No webhook events received.</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                      <th className="text-left p-2">Received</th>
                      <th className="text-left p-2">Event</th>
                      <th className="text-left p-2">Status</th>
                      <th className="text-left p-2">Payload</th>
                    </tr>
                  </thead>
                  <tbody>
                    {webhooks.map((w) => (
                      <tr
                        key={w.id}
                        className="border-t hover:bg-gray-50 cursor-pointer"
                        onClick={() => setDetailModal({
                          title: `Webhook: ${w.event_type ?? '—'}`,
                          body: w.payload ?? w,
                        })}
                      >
                        <td className="p-2">{formatDate(w.received_at)}</td>
                        <td className="p-2">{w.event_type || '—'}</td>
                        <td className="p-2">{statusBadge(w.status)}</td>
                        <td className="p-2 max-w-xs truncate text-gray-600">
                          {truncate(w.payload, 100)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )
          )}
        </div>
      </Card>

      <Modal open={editOpen} onClose={() => setEditOpen(false)} title="Edit integration" size="lg">
        <div className="space-y-3">
          {editError && <Alert variant="danger" closable={false}>{editError}</Alert>}
          <Input
            label="Display name"
            value={editName}
            onChange={(e) => setEditName(e.target.value)}
          />
          <Textarea
            label="Config (JSON)"
            value={editConfig}
            onChange={(e) => setEditConfig(e.target.value)}
            rows={12}
            placeholder='{"api_key": "..."}'
          />
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={editActive}
              onChange={(e) => setEditActive(e.target.checked)}
            />
            Active
          </label>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setEditOpen(false)}>Cancel</Button>
            <Button disabled={saving} onClick={submitEdit}>{saving ? 'Saving…' : 'Save'}</Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={disconnectOpen}
        onClose={() => setDisconnectOpen(false)}
        title="Disconnect integration"
      >
        <div className="space-y-3">
          <Alert variant="warning" closable={false}>
            This will disconnect the integration. Sync will stop until reconnected.
          </Alert>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDisconnectOpen(false)}>Cancel</Button>
            <Button variant="danger" disabled={disconnecting} onClick={submitDisconnect}>
              {disconnecting ? 'Disconnecting…' : 'Disconnect'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={detailModal !== null}
        onClose={() => setDetailModal(null)}
        title={detailModal?.title || 'Detail'}
        size="lg"
      >
        <pre className="text-xs whitespace-pre-wrap break-words bg-gray-50 p-2 rounded max-h-[60vh] overflow-auto">
          {detailModal
            ? (typeof detailModal.body === 'string'
              ? detailModal.body
              : JSON.stringify(detailModal.body, null, 2))
            : ''}
        </pre>
      </Modal>
    </div>
  )
}
