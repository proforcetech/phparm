import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import inventoryMetaService from '../../../services/inventory-meta.service'
import inventoryService from '../../../services/inventory.service'
import { useAuthStore } from '../../stores/auth.jsx'

const emptyForm = {
  name: '',
  sku: '',
  category: '',
  location: '',
  vendor: '',
  reorder_quantity: 0,
  reorder_point_override: null,
  reorder_point_override_reason: '',
  usage_rate_30d: 0,
  suggested_reorder_point: 0,
  effective_reorder_point: 0,
  stock_quantity: 0,
  low_stock_threshold: 0,
  markup: null,
  cost: 0,
  sale_price: 0,
  notes: '',
}

const calculateSalePrice = (costValue, markupValue) => {
  const cost = parseFloat(costValue)
  const markup = parseFloat(markupValue ?? 0)

  if (Number.isNaN(cost) || !Number.isFinite(cost)) return null
  if (Number.isNaN(markup) || !Number.isFinite(markup)) return cost

  const salePrice = cost * (1 + markup / 100)
  return parseFloat(salePrice.toFixed(2))
}

export default function InventoryForm() {
  const navigate = useNavigate()
  const { id } = useParams()
  const { hasPermission } = useAuthStore()
  const [saving, setSaving] = useState(false)
  const [success, setSuccess] = useState('')
  const [error, setError] = useState('')
  const [form, setForm] = useState({ ...emptyForm })

  const [categoryOptions, setCategoryOptions] = useState([])
  const [locationOptions, setLocationOptions] = useState([])
  const [vendorOptions, setVendorOptions] = useState([])
  const [lookupsLoading, setLookupsLoading] = useState({ categories: false, locations: false, vendors: false })
  const [lookupError, setLookupError] = useState({ categories: '', locations: '', vendors: '' })

  const manualSalePrice = useRef(false)
  const isAutoUpdatingSalePrice = useRef(false)
  const isInitializing = useRef(true)

  const isEditing = Boolean(id)
  const canCreate = hasPermission('inventory.create')
  const canEdit = hasPermission('inventory.edit')
  const canAdjust = hasPermission('inventory.adjust')
  const canManageOverrides = hasPermission('inventory.manage')
  const canSave = isEditing ? canEdit : canCreate
  const canAdjustStock = !isEditing || canAdjust

  const goBack = () => navigate('/cp/inventory')

  const loadLookup = async (type, setTarget, currentForm = form) => {
    setLookupsLoading((prev) => ({ ...prev, [type]: true }))
    setLookupError((prev) => ({ ...prev, [type]: '' }))
    try {
      const params = type === 'vendors' ? { parts_supplier: true } : {}
      const data = await inventoryMetaService.list(type, params)
      const options = data.map((item) => ({ label: item.name, value: item.name }))

      const fieldMap = { categories: 'category', locations: 'location', vendors: 'vendor' }
      const field = fieldMap[type]
      const currentValue = currentForm[field]
      if (currentValue && !options.some((option) => option.value === currentValue)) {
        options.push({ label: currentValue, value: currentValue })
      }

      setTarget(options)
    } catch (err) {
      console.error(err)
      setLookupError((prev) => ({ ...prev, [type]: `Could not load ${type}.` }))
    } finally {
      setLookupsLoading((prev) => ({ ...prev, [type]: false }))
    }
  }

  const loadItem = async () => {
    if (!id) return
    const data = await inventoryService.get(id)
    const nextForm = { ...emptyForm, ...data }
    setForm(nextForm)
    return nextForm
  }

  useEffect(() => {
    const loadData = async () => {
      const nextForm = await loadItem()
      await Promise.all([
        loadLookup('categories', setCategoryOptions, nextForm || form),
        loadLookup('locations', setLocationOptions, nextForm || form),
        loadLookup('vendors', setVendorOptions, nextForm || form),
      ])
      isInitializing.current = false
    }

    loadData()
  }, [id])

  useEffect(() => {
    if (isInitializing.current || manualSalePrice.current) return

    const salePrice = calculateSalePrice(form.cost, form.markup)
    if (salePrice === null) return

    isAutoUpdatingSalePrice.current = true
    setForm((prev) => ({ ...prev, sale_price: salePrice }))
    isAutoUpdatingSalePrice.current = false
  }, [form.cost, form.markup])

  const handleSalePriceChange = (value) => {
    if (!isInitializing.current && !isAutoUpdatingSalePrice.current) {
      manualSalePrice.current = value !== '' && value !== null && value !== undefined
    }
    setForm((prev) => ({ ...prev, sale_price: value }))
  }

  const save = async (event) => {
    event.preventDefault()
    if (!canSave) {
      setError('You do not have permission to save inventory items.')
      return
    }
    setSaving(true)
    setError('')
    setSuccess('')
    try {
      const payload = { ...form }
      if (!canManageOverrides) {
        delete payload.reorder_point_override
        delete payload.reorder_point_override_reason
      }
      if (isEditing && id) {
        await inventoryService.update(id, payload)
        setSuccess('Inventory item updated.')
      } else {
        await inventoryService.create(payload)
        setSuccess('Inventory item created.')
        goBack()
      }
    } catch (err) {
      console.error(err)
      setError('Could not save inventory item.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{isEditing ? 'Edit Inventory Item' : 'New Inventory Item'}</h1>
          <p className="mt-1 text-sm text-gray-500">{isEditing ? 'Update pricing and stock' : 'Add a new part or supply'}</p>
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
                placeholder="Brake pads"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, name: value }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">SKU</label>
              <Input
                modelValue={form.sku}
                placeholder="SKU-1234"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, sku: value }))}
              />
            </div>
            <div>
              <div className="flex items-center justify-between text-sm font-medium text-gray-700">
                <span>Category</span>
                <Link className="text-indigo-600 hover:text-indigo-500" to="/cp/inventory/categories">Manage</Link>
              </div>
              <Select
                modelValue={form.category}
                options={categoryOptions}
                placeholder="Select a category"
                disabled={lookupsLoading.categories}
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, category: value }))}
              />
              {lookupsLoading.categories ? (
                <div className="mt-1 flex items-center gap-2 text-xs text-gray-500">
                  <Loading size="sm" />
                  <span>Loading categories...</span>
                </div>
              ) : null}
              {!lookupsLoading.categories && lookupError.categories ? (
                <p className="mt-1 text-xs text-red-600">{lookupError.categories}</p>
              ) : null}
            </div>
            <div>
              <div className="flex items-center justify-between text-sm font-medium text-gray-700">
                <span>Location</span>
                <Link className="text-indigo-600 hover:text-indigo-500" to="/cp/inventory/locations">Manage</Link>
              </div>
              <Select
                modelValue={form.location}
                options={locationOptions}
                placeholder="Select a location"
                disabled={lookupsLoading.locations}
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, location: value }))}
              />
              {lookupsLoading.locations ? (
                <div className="mt-1 flex items-center gap-2 text-xs text-gray-500">
                  <Loading size="sm" />
                  <span>Loading locations...</span>
                </div>
              ) : null}
              {!lookupsLoading.locations && lookupError.locations ? (
                <p className="mt-1 text-xs text-red-600">{lookupError.locations}</p>
              ) : null}
            </div>
            <div>
              <div className="flex items-center justify-between text-sm font-medium text-gray-700">
                <span>Vendor</span>
                <Link className="text-indigo-600 hover:text-indigo-500" to="/cp/inventory/vendors">Manage</Link>
              </div>
              <Select
                modelValue={form.vendor}
                options={vendorOptions}
                placeholder="Select a vendor"
                disabled={lookupsLoading.vendors}
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, vendor: value }))}
              />
              {lookupsLoading.vendors ? (
                <div className="mt-1 flex items-center gap-2 text-xs text-gray-500">
                  <Loading size="sm" />
                  <span>Loading vendors...</span>
                </div>
              ) : null}
              {!lookupsLoading.vendors && lookupError.vendors ? (
                <p className="mt-1 text-xs text-red-600">{lookupError.vendors}</p>
              ) : null}
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Reorder quantity</label>
              <Input
                modelValue={form.reorder_quantity}
                type="number"
                min="0"
                placeholder="10"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, reorder_quantity: Number(value) }))}
              />
            </div>
          </div>

          <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-4">
            <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
              <div>
                <h3 className="text-sm font-semibold text-slate-900">Stock forecasting</h3>
                <p className="text-xs text-slate-500">Based on workorders completed in the last 30 days.</p>
              </div>
              <div className="text-sm text-slate-700">
                <div>
                  <span className="font-semibold">Usage rate:</span>{' '}
                  {Number(form.usage_rate_30d || 0).toFixed(2)} / day
                </div>
                <div>
                  <span className="font-semibold">Suggested reorder point:</span>{' '}
                  {form.suggested_reorder_point ?? 0}
                </div>
                <div className="text-xs text-slate-500">
                  Effective reorder point:{' '}
                  {form.effective_reorder_point ?? form.suggested_reorder_point ?? 0}
                  {form.reorder_point_override !== null ? ' (override)' : ''}
                </div>
              </div>
            </div>

            {canManageOverrides ? (
              <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Manager override</label>
                  <Input
                    modelValue={form.reorder_point_override ?? ''}
                    type="number"
                    min="0"
                    placeholder="Leave blank to use suggested"
                    onUpdateModelValue={(value) => setForm((prev) => ({
                      ...prev,
                      reorder_point_override: value === '' ? null : Number(value)
                    }))}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Override reason</label>
                  <Input
                    modelValue={form.reorder_point_override_reason || ''}
                    placeholder="Optional reason for audit trail"
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, reorder_point_override_reason: value }))}
                  />
                </div>
              </div>
            ) : null}
          </div>

          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label className="block text-sm font-medium text-gray-700">Stock quantity</label>
              <Input
                modelValue={form.stock_quantity}
                type="number"
                min="0"
                placeholder="50"
                disabled={!canAdjustStock}
                helperText={!canAdjustStock ? 'You do not have permission to adjust stock quantities.' : ''}
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, stock_quantity: Number(value) }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Low stock threshold</label>
              <Input
                modelValue={form.low_stock_threshold}
                type="number"
                min="0"
                placeholder="5"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, low_stock_threshold: Number(value) }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Markup (%)</label>
              <Input
                modelValue={form.markup ?? ''}
                type="number"
                step="0.01"
                min="0"
                placeholder="20"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, markup: value === '' ? null : Number(value) }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Cost</label>
              <Input
                modelValue={form.cost}
                type="number"
                step="0.01"
                min="0"
                placeholder="15.00"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, cost: Number(value) }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Sale price</label>
              <Input
                modelValue={form.sale_price}
                type="number"
                step="0.01"
                min="0"
                placeholder="25.00"
                helperText="Calculated as cost × (1 + markup/100). You can override this if needed."
                onUpdateModelValue={handleSalePriceChange}
              />
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700">Notes</label>
            <textarea
              value={form.notes}
              onChange={(event) => setForm((prev) => ({ ...prev, notes: event.target.value }))}
              rows={3}
              className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              placeholder="Internal notes about the part"
            />
          </div>

          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
              <p className="text-sm text-gray-600">Fields marked with * are required. Pricing and stock will sync to alerts.</p>
              {!canSave ? <p className="text-sm text-red-600">You do not have permission to save inventory items.</p> : null}
              {error ? <p className="text-sm text-red-600">{error}</p> : null}
              {success ? <p className="text-sm text-green-600">{success}</p> : null}
            </div>
            <div className="flex gap-3">
              <Button type="button" variant="secondary" onClick={goBack}>Cancel</Button>
              {canSave ? (
                <Button type="submit" loading={saving}>{isEditing ? 'Update item' : 'Create item'}</Button>
              ) : null}
            </div>
          </div>
        </form>
      </Card>
    </div>
  )
}
