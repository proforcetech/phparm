import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'
import bundleService from '../../../services/bundle.service'
import api from '../../../services/api'

export default function BundleForm() {
  const navigate = useNavigate()
  const { id } = useParams()
  const [saving, setSaving] = useState(false)
  const [success, setSuccess] = useState('')
  const [error, setError] = useState('')
  const [serviceTypes, setServiceTypes] = useState([])
  const [pricingSettings, setPricingSettings] = useState({
    laborRate: 0,
    laborTaxable: false,
    feeTaxable: false,
  })
  const [form, setForm] = useState({
    name: '',
    description: '',
    service_type_id: null,
    default_job_title: '',
    is_active: true,
    sort_order: 0,
    items: [],
  })

  const isEditing = Boolean(id)

  const goBack = () => navigate('/cp/bundles')

  const createEmptyItem = useCallback((index = 0) => ({
    type: 'LABOR',
    description: '',
    quantity: 1,
    unit_price: pricingSettings.laborRate,
    taxable: pricingSettings.laborTaxable,
    discount_type: 'fixed',
    sort_order: index,
  }), [pricingSettings.laborRate, pricingSettings.laborTaxable])

  useEffect(() => {
    const loadServiceTypes = async () => {
      try {
        const response = await api.get('/service-types', { params: { active: 1 } })
        setServiceTypes(response.data || [])
      } catch (err) {
        console.error('Failed to load service types', err)
        setServiceTypes([])
      }
    }

    const loadPricingSettings = async () => {
      try {
        const response = await api.get('/settings')
        const settings = response.data || {}
        setPricingSettings({
          laborRate: Number(settings['pricing.labor_rate']) || 0,
          laborTaxable: settings['pricing.labor_taxable'] === true || settings['pricing.labor_taxable'] === 'true',
          feeTaxable: settings['pricing.fee_taxable'] === true || settings['pricing.fee_taxable'] === 'true',
        })
      } catch (err) {
        console.error('Failed to load pricing settings', err)
      }
    }

    const loadBundle = async () => {
      if (!id) return
      try {
        const data = await bundleService.get(id)
        setForm({
          name: data.name,
          description: data.description || '',
          service_type_id: data.service_type_id,
          default_job_title: data.default_job_title,
          is_active: Boolean(data.is_active),
          sort_order: data.sort_order || 0,
          items: (data.items || []).map((item, index) => ({
            type: item.type,
            description: item.description,
            quantity: item.quantity,
            unit_price: item.unit_price,
            taxable: Boolean(item.taxable),
            discount_type: item.discount_type || 'fixed',
            sort_order: item.sort_order ?? index,
          })),
        })
      } catch (err) {
        console.error('Failed to load bundle', err)
      }
    }

    loadServiceTypes()
    loadPricingSettings()
    loadBundle()
  }, [id])

  useEffect(() => {
    if (!id && form.items.length === 0) {
      setForm((prev) => ({ ...prev, items: [createEmptyItem()] }))
    }
  }, [id, form.items.length, createEmptyItem])

  const addItem = () => {
    setForm((prev) => ({
      ...prev,
      items: [...prev.items, createEmptyItem(prev.items.length)],
    }))
  }

  const removeItem = (index) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.filter((_, idx) => idx !== index),
    }))
  }

  const updateItem = (index, changes) => {
    setForm((prev) => ({
      ...prev,
      items: prev.items.map((item, idx) => {
        if (idx !== index) return item
        const updated = { ...item, ...changes }

        if (changes.type !== undefined && changes.type !== item.type) {
          if (changes.type === 'LABOR') {
            updated.unit_price = pricingSettings.laborRate
            updated.taxable = pricingSettings.laborTaxable
          } else if (changes.type === 'FEE') {
            updated.taxable = pricingSettings.feeTaxable
          } else if (changes.type === 'DISCOUNT') {
            updated.taxable = false
            updated.discount_type = 'fixed'
            updated.quantity = 1
          } else if (changes.type === 'PART') {
            updated.taxable = true
          }
        }

        return updated
      }),
    }))
  }

  const save = async (event) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    setSuccess('')
    try {
      const payload = { ...form, items: form.items }
      if (isEditing && id) {
        await bundleService.update(id, payload)
        setSuccess('Bundle updated.')
      } else {
        await bundleService.create(payload)
        setSuccess('Bundle created.')
        goBack()
      }
    } catch (err) {
      console.error(err)
      setError('Could not save bundle.')
    } finally {
      setSaving(false)
    }
  }

  const getUnitPriceLabel = (item) => {
    if (item.type === 'LABOR') return 'Hourly Rate'
    if (item.type === 'DISCOUNT') {
      return item.discount_type === 'percent' ? 'Percentage (%)' : 'Amount ($)'
    }
    return 'Unit Price'
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{isEditing ? 'Edit Bundle' : 'New Bundle'}</h1>
          <p className="mt-1 text-sm text-gray-500">Bundle common line items for quick estimate creation.</p>
        </div>
        <Button variant="secondary" onClick={goBack}>Back to list</Button>
      </div>

      <Card className="max-w-5xl">
        <form className="space-y-6" onSubmit={save}>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label className="block text-sm font-medium text-gray-700">Name</label>
              <Input
                modelValue={form.name}
                required
                placeholder="Brake Service Bundle"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, name: value }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Default Job Title</label>
              <Input
                modelValue={form.default_job_title}
                required
                placeholder="Brake Service"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, default_job_title: value }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Service Type</label>
              <select
                value={form.service_type_id ?? ''}
                onChange={(event) => {
                  const value = event.target.value
                  setForm((prev) => ({ ...prev, service_type_id: value ? Number(value) : null }))
                }}
                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">No service type</option>
                {serviceTypes.map((type) => (
                  <option key={type.id} value={type.id}>{type.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Sort Order</label>
              <Input
                modelValue={form.sort_order}
                type="number"
                min="0"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, sort_order: Number(value) }))}
              />
            </div>
            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-700">Description</label>
              <Textarea
                modelValue={form.description}
                rows={3}
                placeholder="Optional description for the bundle"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, description: value }))}
              />
            </div>
            <div className="flex items-center gap-2 md:col-span-2">
              <input
                checked={form.is_active}
                type="checkbox"
                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                onChange={(event) => setForm((prev) => ({ ...prev, is_active: event.target.checked }))}
              />
              <span className="text-sm text-gray-700">Bundle is active</span>
            </div>
          </div>

          <div>
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-lg font-semibold text-gray-900">Bundle Items</h3>
              <Button type="button" variant="outline" onClick={addItem}>Add Item</Button>
            </div>
            {form.items.length === 0 ? (
              <div className="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-500">No items added yet.</div>
            ) : null}
            {form.items.map((item, index) => (
              <div key={`${item.type}-${index}`} className="mb-4 rounded-lg border border-gray-200 p-4 shadow-sm">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Type</label>
                    <select
                      value={item.type}
                      onChange={(event) => updateItem(index, { type: event.target.value })}
                      className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                      required
                    >
                      <option value="LABOR">Labor</option>
                      <option value="PART">Part</option>
                      <option value="FEE">Fee</option>
                      <option value="DISCOUNT">Discount</option>
                    </select>
                  </div>
                  <div className="md:col-span-2">
                    <label className="block text-sm font-medium text-gray-700">Description</label>
                    <Input
                      modelValue={item.description}
                      required
                      placeholder={item.type === 'DISCOUNT' ? 'Discount description' : 'Pad replacement'}
                      onUpdateModelValue={(value) => updateItem(index, { description: value })}
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Sort Order</label>
                    <Input
                      modelValue={item.sort_order}
                      type="number"
                      min="0"
                      onUpdateModelValue={(value) => updateItem(index, { sort_order: Number(value) })}
                    />
                  </div>
                  {item.type === 'DISCOUNT' ? (
                    <div>
                      <label className="block text-sm font-medium text-gray-700">Discount Type</label>
                      <select
                        value={item.discount_type || 'fixed'}
                        onChange={(event) => updateItem(index, { discount_type: event.target.value })}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                      >
                        <option value="fixed">Flat Rate ($)</option>
                        <option value="percent">Percentage (%)</option>
                      </select>
                    </div>
                  ) : (
                    <div>
                      <label className="block text-sm font-medium text-gray-700">Quantity</label>
                      <Input
                        modelValue={item.quantity}
                        type="number"
                        min="0"
                        step="0.01"
                        onUpdateModelValue={(value) => updateItem(index, { quantity: Number(value) })}
                      />
                    </div>
                  )}
                  <div>
                    <label className="block text-sm font-medium text-gray-700">{getUnitPriceLabel(item)}</label>
                    <Input
                      modelValue={item.unit_price}
                      type="number"
                      min="0"
                      step="0.01"
                      onUpdateModelValue={(value) => updateItem(index, { unit_price: Number(value) })}
                    />
                  </div>
                  {item.type !== 'DISCOUNT' ? (
                    <div className="flex items-center gap-2 pt-6">
                      <input
                        checked={item.taxable}
                        type="checkbox"
                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                        onChange={(event) => updateItem(index, { taxable: event.target.checked })}
                      />
                      <span className="text-sm text-gray-700">Taxable</span>
                    </div>
                  ) : null}
                </div>
                <div className="mt-3 flex justify-end">
                  <Button type="button" variant="danger" size="sm" onClick={() => removeItem(index)}>Remove</Button>
                </div>
              </div>
            ))}
          </div>

          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
              {error ? <p className="text-sm text-red-600">{error}</p> : null}
              {success ? <p className="text-sm text-green-600">{success}</p> : null}
            </div>
            <div className="flex gap-3">
              <Button type="button" variant="secondary" onClick={goBack}>Cancel</Button>
              <Button type="submit" loading={saving}>{isEditing ? 'Update Bundle' : 'Create Bundle'}</Button>
            </div>
          </div>
        </form>
      </Card>
    </div>
  )
}
