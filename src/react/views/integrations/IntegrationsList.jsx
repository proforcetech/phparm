import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import { useToast } from '../../stores/toast.jsx'
import integrationsService from '../../../services/integrations.service'

const STATUS_VARIANT = {
  connected: 'success',
  active: 'success',
  error: 'danger',
  failed: 'danger',
  disconnected: 'default',
  pending: 'warning',
}

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'connected', label: 'Connected' },
  { value: 'disconnected', label: 'Disconnected' },
  { value: 'error', label: 'Error' },
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

const DEFAULT_CONFIG_PLACEHOLDER = `{
  "api_key": "",
  "endpoint": "",
  "settings": {}
}`

export default function IntegrationsList() {
  const navigate = useNavigate()
  const toast = useToast()

  const [providers, setProviders] = useState([])
  const [providersLoading, setProvidersLoading] = useState(true)
  const [providerKind, setProviderKind] = useState('')
  const [status, setStatus] = useState('')

  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const [createOpen, setCreateOpen] = useState(false)
  const [formProvider, setFormProvider] = useState('')
  const [formName, setFormName] = useState('')
  const [formConfig, setFormConfig] = useState('')
  const [formError, setFormError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    setProvidersLoading(true)
    integrationsService
      .listProviders()
      .then((res) => setProviders(res?.data ?? res ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load providers'))
      .finally(() => setProvidersLoading(false))
  }, [])

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (providerKind) params.provider_kind = providerKind
    if (status) params.status = status
    integrationsService
      .list(params)
      .then((res) => setItems(res?.data ?? res ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load integrations'))
      .finally(() => setLoading(false))
  }, [providerKind, status])

  useEffect(() => {
    load()
  }, [load])

  const providerOptions = useMemo(() => {
    const list = providers.map((p) => ({
      value: p.key ?? p.kind ?? p.slug ?? p.name,
      label: p.label ?? p.name ?? p.key ?? p.kind,
    }))
    return [{ value: '', label: providersLoading ? 'Loading…' : 'All providers' }, ...list]
  }, [providers, providersLoading])

  const createProviderOptions = useMemo(() => {
    return providers.map((p) => ({
      value: p.key ?? p.kind ?? p.slug ?? p.name,
      label: p.label ?? p.name ?? p.key ?? p.kind,
    }))
  }, [providers])

  const openCreate = () => {
    setFormProvider('')
    setFormName('')
    setFormConfig('')
    setFormError('')
    setCreateOpen(true)
  }

  const submitCreate = async () => {
    setFormError('')
    if (!formProvider) {
      setFormError('Provider is required.')
      return
    }
    if (!formName.trim()) {
      setFormError('Display name is required.')
      return
    }
    let parsedConfig = {}
    if (formConfig.trim()) {
      try {
        parsedConfig = JSON.parse(formConfig)
      } catch (err) {
        setFormError(`Config is not valid JSON: ${err.message}`)
        return
      }
    }
    setSubmitting(true)
    try {
      const res = await integrationsService.create({
        provider: formProvider,
        provider_kind: formProvider,
        display_name: formName.trim(),
        config: parsedConfig,
      })
      const created = res?.data ?? res
      const newId = created?.id
      toast.success('Integration created.')
      setCreateOpen(false)
      if (newId) {
        navigate(`/cp/integrations/${newId}`)
      } else {
        load()
      }
    } catch (e) {
      setFormError(e?.response?.data?.message || e?.message || 'Failed to create integration')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Integrations</h1>
          <p className="text-sm text-gray-500">
            Third-party connections — pick a provider from the catalog and connect a new account.
          </p>
        </div>
        <Button onClick={openCreate}>Connect new integration</Button>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card padding={false}>
        <div className="p-4 flex items-end gap-3 flex-wrap">
          <Select
            label="Provider"
            value={providerKind}
            onChange={(e) => setProviderKind(e?.target?.value ?? '')}
            options={providerOptions}
            placeholder=""
          />
          <Select
            label="Status"
            value={status}
            onChange={(e) => setStatus(e?.target?.value ?? '')}
            options={STATUS_OPTIONS}
            placeholder=""
          />
          <Button variant="secondary" onClick={load}>Refresh</Button>
        </div>

        {loading ? (
          <div className="p-6 text-center"><Loading /></div>
        ) : items.length === 0 ? (
          <div className="p-6 text-center text-gray-500">No integrations match these filters.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Provider</th>
                  <th className="text-left p-2">Display name</th>
                  <th className="text-left p-2">Status</th>
                  <th className="text-left p-2">Last sync</th>
                  <th className="text-left p-2">Error</th>
                </tr>
              </thead>
              <tbody>
                {items.map((row) => (
                  <tr
                    key={row.id}
                    className="border-t hover:bg-gray-50 cursor-pointer"
                    onClick={() => navigate(`/cp/integrations/${row.id}`)}
                  >
                    <td className="p-2">{row.provider ?? row.provider_kind ?? row.provider_key ?? '—'}</td>
                    <td className="p-2">{row.display_name || <span className="text-gray-400">—</span>}</td>
                    <td className="p-2">{statusBadge(row.status)}</td>
                    <td className="p-2">{formatDate(row.last_sync_at)}</td>
                    <td className="p-2 max-w-xs truncate text-red-600">
                      {row.error_summary || <span className="text-gray-400">—</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title="Connect new integration"
        size="lg"
      >
        <div className="space-y-3">
          {formError && <Alert variant="danger" closable={false}>{formError}</Alert>}
          <Select
            label="Provider"
            value={formProvider}
            onChange={(e) => setFormProvider(e?.target?.value ?? '')}
            options={createProviderOptions}
            placeholder={providersLoading ? 'Loading…' : 'Select a provider'}
            required
          />
          <Input
            label="Display name"
            value={formName}
            onChange={(e) => setFormName(e.target.value)}
            placeholder="e.g. QuickBooks Production"
            required
          />
          <Textarea
            label="Config (JSON)"
            value={formConfig}
            onChange={(e) => setFormConfig(e.target.value)}
            rows={10}
            placeholder={DEFAULT_CONFIG_PLACEHOLDER}
            helperText="Paste credentials and provider-specific settings as a JSON object."
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setCreateOpen(false)}>Cancel</Button>
            <Button disabled={submitting} onClick={submitCreate}>
              {submitting ? 'Connecting…' : 'Create'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
