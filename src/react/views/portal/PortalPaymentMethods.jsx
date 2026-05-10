import { useCallback, useEffect, useState } from 'react'

import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import Modal from '../../components/ui/Modal'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import { portalService } from '../../../services/portal/portal.service'

const GATEWAY_OPTIONS = [
  { value: 'stripe', label: 'Stripe' },
  { value: 'square', label: 'Square' },
  { value: 'paypal', label: 'PayPal' },
]

const initialForm = {
  gateway: 'stripe',
  external_method_id: '',
  external_customer_id: '',
  brand: '',
  last4: '',
  exp_month: '',
  exp_year: '',
  label: '',
  is_default: false,
}

export default function PortalPaymentMethods() {
  const [methods, setMethods] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [busyKey, setBusyKey] = useState(null)
  const [showAdd, setShowAdd] = useState(false)
  const [form, setForm] = useState(initialForm)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const list = await portalService.listPaymentMethods()
      setMethods(Array.isArray(list) ? list : [])
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load payment methods.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const setDefault = async (methodId) => {
    setBusyKey(`default:${methodId}`)
    setActionError(null)
    try {
      await portalService.setDefaultPaymentMethod(methodId)
      await load()
    } catch (err) {
      setActionError(err.response?.data?.message || 'Unable to set default.')
    } finally {
      setBusyKey(null)
    }
  }

  const removeMethod = async (methodId) => {
    if (!window.confirm('Delete this saved payment method?')) return
    setBusyKey(`delete:${methodId}`)
    setActionError(null)
    try {
      await portalService.deletePaymentMethod(methodId)
      await load()
    } catch (err) {
      setActionError(err.response?.data?.message || 'Unable to delete payment method.')
    } finally {
      setBusyKey(null)
    }
  }

  const updateForm = (field) => (value) => {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  const submitAdd = async () => {
    setSaving(true)
    setSaveError(null)
    try {
      const payload = {
        gateway: form.gateway,
        external_method_id: form.external_method_id.trim(),
      }
      if (form.external_customer_id.trim()) payload.external_customer_id = form.external_customer_id.trim()
      if (form.brand.trim()) payload.brand = form.brand.trim()
      if (form.last4.trim()) payload.last4 = form.last4.trim()
      if (form.exp_month) payload.exp_month = Number(form.exp_month)
      if (form.exp_year) payload.exp_year = Number(form.exp_year)
      if (form.label.trim()) payload.label = form.label.trim()
      if (form.is_default) payload.is_default = true

      await portalService.savePaymentMethod(payload)
      setShowAdd(false)
      setForm(initialForm)
      await load()
    } catch (err) {
      setSaveError(err.response?.data?.message || 'Unable to save payment method.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-2xl font-semibold">Payment methods</h1>
          <p className="text-sm text-gray-600 mt-1">
            Manage saved cards and accounts for invoice checkout.
          </p>
        </div>
        <Button onClick={() => { setShowAdd(true); setSaveError(null); setForm(initialForm) }}>
          Add payment method
        </Button>
      </header>

      {error && <Alert variant="error" closable={false}>{error}</Alert>}
      {actionError && <Alert variant="error" closable={false}>{actionError}</Alert>}

      {loading ? (
        <Card>
          <div className="py-10 flex justify-center">
            <Loading text="Loading payment methods…" />
          </div>
        </Card>
      ) : methods.length === 0 ? (
        <Card>
          <p className="text-sm text-gray-600">
            No saved payment methods yet. Add one to speed up checkout.
          </p>
        </Card>
      ) : (
        <div className="space-y-3">
          {methods.map((m) => {
            const defaultBusy = busyKey === `default:${m.id}`
            const deleteBusy = busyKey === `delete:${m.id}`
            return (
              <Card key={m.id}>
                <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                  <div>
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="font-medium capitalize">{m.gateway}</span>
                      {m.brand && <span className="text-gray-700">· {m.brand}</span>}
                      {m.last4 && <span className="text-gray-700">·••••{m.last4}</span>}
                      {m.is_default && (
                        <span className="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-800">
                          Default
                        </span>
                      )}
                    </div>
                    {m.label && (
                      <p className="text-sm text-gray-500 mt-1">{m.label}</p>
                    )}
                    {(m.exp_month && m.exp_year) && (
                      <p className="text-xs text-gray-500 mt-1">
                        Expires {String(m.exp_month).padStart(2, '0')}/{m.exp_year}
                      </p>
                    )}
                  </div>
                  <div className="flex gap-2">
                    {!m.is_default && (
                      <Button
                        variant="outline"
                        size="sm"
                        loading={defaultBusy}
                        disabled={!!busyKey}
                        onClick={() => setDefault(m.id)}
                      >
                        Set default
                      </Button>
                    )}
                    <Button
                      variant="ghost"
                      size="sm"
                      loading={deleteBusy}
                      disabled={!!busyKey}
                      onClick={() => removeMethod(m.id)}
                    >
                      Delete
                    </Button>
                  </div>
                </div>
              </Card>
            )
          })}
        </div>
      )}

      <Modal
        open={showAdd}
        size="lg"
        title="Add payment method"
        onClose={() => { setShowAdd(false); setSaveError(null) }}
        footer={(
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => { setShowAdd(false); setSaveError(null) }} disabled={saving}>
              Cancel
            </Button>
            <Button
              onClick={submitAdd}
              loading={saving}
              disabled={saving || !form.external_method_id.trim()}
            >
              Save
            </Button>
          </div>
        )}
      >
        <p className="text-sm text-gray-600 mb-4">
          Tokenize the card with your gateway&rsquo;s client SDK first, then paste the resulting method id below.
          We never store raw card numbers.
        </p>
        {saveError && (
          <div className="mb-4">
            <Alert variant="error" closable={false}>{saveError}</Alert>
          </div>
        )}
        <div className="space-y-3">
          <Select
            label="Gateway"
            required
            modelValue={form.gateway}
            options={GATEWAY_OPTIONS}
            onUpdateModelValue={updateForm('gateway')}
          />
          <Input
            label="Tokenized method id (from gateway)"
            required
            modelValue={form.external_method_id}
            onUpdateModelValue={updateForm('external_method_id')}
            placeholder="pm_xxx / nonce / etc."
          />
          <Input
            label="Customer id at gateway (optional)"
            modelValue={form.external_customer_id}
            onUpdateModelValue={updateForm('external_customer_id')}
            placeholder="cus_xxx"
          />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input
              label="Brand (optional)"
              modelValue={form.brand}
              onUpdateModelValue={updateForm('brand')}
              placeholder="Visa, Mastercard…"
            />
            <Input
              label="Last 4 (optional)"
              modelValue={form.last4}
              onUpdateModelValue={updateForm('last4')}
              placeholder="4242"
              maxLength={4}
            />
            <Input
              label="Exp month (optional)"
              type="number"
              min="1"
              max="12"
              modelValue={form.exp_month}
              onUpdateModelValue={updateForm('exp_month')}
            />
            <Input
              label="Exp year (optional)"
              type="number"
              min="2024"
              max="2100"
              modelValue={form.exp_year}
              onUpdateModelValue={updateForm('exp_year')}
            />
          </div>
          <Input
            label="Label (optional)"
            modelValue={form.label}
            onUpdateModelValue={updateForm('label')}
            placeholder="e.g. Personal Visa"
          />
          <label className="inline-flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              className="rounded"
              checked={form.is_default}
              onChange={(e) => updateForm('is_default')(e.target.checked)}
            />
            Make this the default payment method
          </label>
        </div>
      </Modal>
    </div>
  )
}
