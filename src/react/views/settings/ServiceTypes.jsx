import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Table from '../../components/ui/Table'
import Textarea from '../../components/ui/Textarea'
import { createServiceType, fetchServiceTypes } from '../../../services/service-types.service'

const initialFormState = {
  name: '',
  alias: '',
  color: '',
  icon: '',
  description: '',
  displayOrder: 0,
  active: true,
}

export default function ServiceTypes() {
  const [form, setForm] = useState(initialFormState)
  const [serviceTypes, setServiceTypes] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  const loadServiceTypes = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const data = await fetchServiceTypes()
      setServiceTypes(Array.isArray(data) ? data : [])
    } catch (fetchError) {
      setError(fetchError?.message || 'Unable to load service types.')
      setServiceTypes([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadServiceTypes()
  }, [loadServiceTypes])

  const updateField = (key, value) => {
    setForm((prev) => ({
      ...prev,
      [key]: value,
    }))
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    setError('')

    try {
      await createServiceType({
        name: form.name,
        alias: form.alias,
        color: form.color,
        icon: form.icon,
        description: form.description,
        display_order: Number(form.displayOrder) || 0,
        active: form.active,
      })
      setMessage('Service type created successfully.')
      setForm(initialFormState)
      await loadServiceTypes()
    } catch (submitError) {
      setError(submitError?.response?.data?.message || submitError?.message || 'Failed to create service type.')
    } finally {
      setSaving(false)
    }
  }

  const columns = useMemo(() => ([
    { key: 'name', label: 'Name' },
    { key: 'alias', label: 'Alias' },
    { key: 'description', label: 'Description' },
    { key: 'active', label: 'Active' },
    { key: 'display_order', label: 'Order' },
  ]), [])

  const cellRenderers = useMemo(() => ({
    active: (value) => (value ? 'Yes' : 'No'),
  }), [])

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Service types</h1>
        <p className="mt-1 text-sm text-gray-500">
          Manage the categories used for estimates, bundles, and public request forms.
        </p>
      </div>

      {message ? <Alert variant="success" className="mb-4">{message}</Alert> : null}
      {error ? <Alert variant="danger" className="mb-4">{error}</Alert> : null}

      <div className="space-y-6">
        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Add service type</h2>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Name</label>
                <Input
                  value={form.name}
                  placeholder="Oil change"
                  className="mt-1"
                  onChange={(event) => updateField('name', event.target.value)}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Alias</label>
                <Input
                  value={form.alias}
                  placeholder="oil_change"
                  className="mt-1"
                  onChange={(event) => updateField('alias', event.target.value)}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Color</label>
                <Input
                  value={form.color}
                  placeholder="#1F2937"
                  className="mt-1"
                  onChange={(event) => updateField('color', event.target.value)}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Icon</label>
                <Input
                  value={form.icon}
                  placeholder="bolt"
                  className="mt-1"
                  onChange={(event) => updateField('icon', event.target.value)}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Display order</label>
                <Input
                  type="number"
                  min="0"
                  value={form.displayOrder}
                  className="mt-1"
                  onChange={(event) => updateField('displayOrder', event.target.value)}
                />
              </div>
              <div className="flex items-center space-x-2">
                <input
                  id="service-type-active"
                  type="checkbox"
                  checked={form.active}
                  className="h-4 w-4 text-indigo-600 border-gray-300 rounded"
                  onChange={(event) => updateField('active', event.target.checked)}
                />
                <label htmlFor="service-type-active" className="text-sm font-medium text-gray-700">
                  Active
                </label>
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Description</label>
              <Textarea
                value={form.description}
                rows={3}
                placeholder="Describe what this service type covers."
                className="mt-1"
                onChange={(event) => updateField('description', event.target.value)}
              />
            </div>
            <div className="flex justify-end">
              <Button type="submit" loading={saving}>Create Service Type</Button>
            </div>
          </form>
        </Card>

        <Card>
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Existing service types</h2>
          <Table
            columns={columns}
            data={serviceTypes}
            loading={loading}
            hoverable={false}
            cellRenderers={cellRenderers}
            renderEmpty="No service types have been created yet."
          />
        </Card>
      </div>
    </div>
  )
}
