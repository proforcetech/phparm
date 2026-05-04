import { useCallback, useEffect, useMemo, useState } from 'react'

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
import ssoService from '../../../services/sso.service'

const KIND_VARIANT = {
  oauth2: 'info',
  oidc: 'info',
  saml: 'primary',
}

const DEFAULT_KINDS = ['oauth2', 'oidc', 'saml']

const CONFIG_PLACEHOLDER = `{
  "client_id": "",
  "client_secret": "",
  "discovery_url": "https://accounts.example.com/.well-known/openid-configuration",
  "scopes": ["openid", "email", "profile"]
}`

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

function emptyForm() {
  return {
    id: null,
    name: '',
    slug: '',
    kind: 'oauth2',
    config: '',
    allowed_domains: '',
    default_role: '',
    is_active: true,
  }
}

export default function SsoProviders() {
  const toast = useToast()

  const [available, setAvailable] = useState([])
  const [availableLoading, setAvailableLoading] = useState(true)
  const [providers, setProviders] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [formOpen, setFormOpen] = useState(false)
  const [form, setForm] = useState(emptyForm())
  const [formError, setFormError] = useState('')
  const [saving, setSaving] = useState(false)

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleting, setDeleting] = useState(false)

  const loadAvailable = useCallback(() => {
    setAvailableLoading(true)
    ssoService
      .listAvailableProviders()
      .then((res) => setAvailable(res?.data ?? res ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load available providers'))
      .finally(() => setAvailableLoading(false))
  }, [])

  const loadProviders = useCallback(() => {
    setLoading(true)
    ssoService
      .listAdminProviders()
      .then((res) => setProviders(res?.data ?? res ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load providers'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    loadAvailable()
    loadProviders()
  }, [loadAvailable, loadProviders])

  const kindOptions = useMemo(() => {
    const fromAvailable = Array.from(new Set(
      (available || [])
        .map((p) => p.kind)
        .filter(Boolean)
    ))
    const kinds = fromAvailable.length > 0 ? fromAvailable : DEFAULT_KINDS
    return kinds.map((k) => ({ value: k, label: k.toUpperCase() }))
  }, [available])

  const openCreate = () => {
    setForm(emptyForm())
    setFormError('')
    setFormOpen(true)
  }

  const openEdit = async (row) => {
    setFormError('')
    try {
      const res = await ssoService.getAdminProvider(row.id)
      const full = res?.data ?? res ?? row
      setForm({
        id: full.id,
        name: full.name || '',
        slug: full.slug || '',
        kind: full.kind || 'oauth2',
        config: full.config ? JSON.stringify(full.config, null, 2) : '',
        allowed_domains: Array.isArray(full.allowed_domains)
          ? full.allowed_domains.join(', ')
          : (full.allowed_domains || ''),
        default_role: full.default_role || '',
        is_active: full.is_active !== false,
      })
      setFormOpen(true)
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Failed to load provider')
    }
  }

  const submitForm = async () => {
    setFormError('')
    if (!form.name.trim()) { setFormError('Name is required.'); return }
    if (!form.slug.trim()) { setFormError('Slug is required.'); return }
    if (!/^[a-z0-9][a-z0-9-]*$/.test(form.slug.trim())) {
      setFormError('Slug must be lowercase letters, digits, and hyphens (start with a letter or digit).')
      return
    }
    let parsedConfig = {}
    if (form.config.trim()) {
      try {
        parsedConfig = JSON.parse(form.config)
      } catch (err) {
        setFormError(`Config is not valid JSON: ${err.message}`)
        return
      }
    }
    const allowedDomains = form.allowed_domains
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)

    const payload = {
      name: form.name.trim(),
      slug: form.slug.trim(),
      kind: form.kind,
      config: parsedConfig,
      allowed_domains: allowedDomains,
      default_role: form.default_role.trim() || null,
      is_active: form.is_active,
    }

    setSaving(true)
    try {
      if (form.id) {
        await ssoService.updateAdminProvider(form.id, payload)
        toast.success('Provider updated.')
      } else {
        await ssoService.createAdminProvider(payload)
        toast.success('Provider created.')
      }
      setFormOpen(false)
      loadProviders()
    } catch (e) {
      setFormError(e?.response?.data?.message || e?.message || 'Failed to save provider')
    } finally {
      setSaving(false)
    }
  }

  const submitDelete = async () => {
    if (!deleteTarget) return
    setDeleting(true)
    try {
      await ssoService.deleteAdminProvider(deleteTarget.id)
      toast.success('Provider deleted.')
      setDeleteTarget(null)
      loadProviders()
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Failed to delete provider')
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">SSO Providers</h1>
          <p className="text-sm text-gray-500">
            Configure identity providers used for staff and customer single sign-on.
          </p>
        </div>
        <Button onClick={openCreate}>Add provider</Button>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card title="Supported provider catalog">
        {availableLoading ? (
          <Loading />
        ) : available.length === 0 ? (
          <p className="text-sm text-gray-500">No supported providers reported by the backend.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {available.map((p) => (
              <span key={p.kind ?? p.slug ?? p.name} className="inline-flex items-center gap-2 border rounded px-2 py-1 text-sm bg-gray-50">
                <strong>{p.label ?? p.name ?? p.kind}</strong>
                {p.kind && (
                  <Badge size="sm" variant={KIND_VARIANT[p.kind] || 'default'}>{p.kind.toUpperCase()}</Badge>
                )}
              </span>
            ))}
          </div>
        )}
      </Card>

      <Card padding={false}>
        {loading ? (
          <div className="p-6 text-center"><Loading /></div>
        ) : providers.length === 0 ? (
          <div className="p-6 text-center text-gray-500">No providers configured.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Name</th>
                  <th className="text-left p-2">Slug</th>
                  <th className="text-left p-2">Kind</th>
                  <th className="text-left p-2">Active</th>
                  <th className="text-left p-2">Created</th>
                  <th className="text-right p-2"> </th>
                </tr>
              </thead>
              <tbody>
                {providers.map((p) => (
                  <tr key={p.id} className="border-t">
                    <td className="p-2">{p.name}</td>
                    <td className="p-2 font-mono text-xs">{p.slug}</td>
                    <td className="p-2">
                      <Badge variant={KIND_VARIANT[p.kind] || 'default'}>{(p.kind || '').toUpperCase()}</Badge>
                    </td>
                    <td className="p-2">
                      {p.is_active === false
                        ? <Badge variant="default">No</Badge>
                        : <Badge variant="success">Yes</Badge>}
                    </td>
                    <td className="p-2">{formatDate(p.created_at)}</td>
                    <td className="p-2 text-right">
                      <div className="flex gap-2 justify-end">
                        <Button size="xs" variant="secondary" onClick={() => openEdit(p)}>Edit</Button>
                        <Button size="xs" variant="danger" onClick={() => setDeleteTarget(p)}>Delete</Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title={form.id ? 'Edit SSO provider' : 'Add SSO provider'}
        size="lg"
      >
        <div className="space-y-3">
          {formError && <Alert variant="danger" closable={false}>{formError}</Alert>}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <Input
              label="Name"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="e.g. Acme Google Workspace"
              required
            />
            <Input
              label="Slug (URL-safe)"
              value={form.slug}
              onChange={(e) => setForm({ ...form, slug: e.target.value.toLowerCase() })}
              placeholder="acme-google"
              helperText="Appears in OAuth callback URLs."
              required
            />
            <Select
              label="Kind"
              value={form.kind}
              onChange={(e) => setForm({ ...form, kind: e?.target?.value ?? form.kind })}
              options={kindOptions}
              placeholder=""
              required
            />
            <Input
              label="Default role"
              value={form.default_role}
              onChange={(e) => setForm({ ...form, default_role: e.target.value })}
              placeholder="e.g. customer or technician"
              helperText="Role assigned to newly provisioned users (optional)."
            />
          </div>
          <Input
            label="Allowed email domains (comma-separated)"
            value={form.allowed_domains}
            onChange={(e) => setForm({ ...form, allowed_domains: e.target.value })}
            placeholder="acme.com, partners.acme.com"
          />
          <Textarea
            label="Config (JSON)"
            value={form.config}
            onChange={(e) => setForm({ ...form, config: e.target.value })}
            rows={12}
            placeholder={CONFIG_PLACEHOLDER}
            helperText="Provider-specific values (client_id, client_secret, discovery_url, idp_metadata_url, etc.)."
          />
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
            />
            Active
          </label>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setFormOpen(false)}>Cancel</Button>
            <Button disabled={saving} onClick={submitForm}>
              {saving ? 'Saving…' : (form.id ? 'Save changes' : 'Create provider')}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={deleteTarget !== null}
        onClose={() => setDeleteTarget(null)}
        title="Delete SSO provider"
      >
        <div className="space-y-3">
          <Alert variant="warning" closable={false}>
            Delete <strong>{deleteTarget?.name}</strong>? Users currently linked to this provider will
            no longer be able to sign in through it.
          </Alert>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteTarget(null)}>Cancel</Button>
            <Button variant="danger" disabled={deleting} onClick={submitDelete}>
              {deleting ? 'Deleting…' : 'Delete'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
