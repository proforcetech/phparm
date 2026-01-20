import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Autocomplete from '../../components/ui/Autocomplete'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import estimateService from '../../../services/estimate.service'
import customerService from '../../../services/customer.service'
import technicianService from '../../../services/technician.service'
import bundleService from '../../../services/bundle.service'
import api from '../../../services/api'
import { useToast } from '../../stores/toast'

const statusOptions = [
  { value: 'sent', label: 'Sent' },
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
]

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount || 0)
}

const buildDefaultExpirationDate = () => {
  return new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().substring(0, 10)
}

export default function EstimateForm() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [bundles, setBundles] = useState([])
  const [bundleLoading, setBundleLoading] = useState(false)
  const [bundleSelection, setBundleSelection] = useState('')
  const [addingBundle, setAddingBundle] = useState(false)
  const [customerVehicles, setCustomerVehicles] = useState([])
  const [vehiclesLoading, setVehiclesLoading] = useState(false)
  const [selectedCustomer, setSelectedCustomer] = useState(null)
  const [pricingSettings, setPricingSettings] = useState({
    laborRate: null,
    laborTaxable: false,
    feeTaxable: false,
  })
  const [form, setForm] = useState({
    customer_id: null,
    vehicle_id: null,
    is_mobile: false,
    technician_id: null,
    expiration_date: buildDefaultExpirationDate(),
    subtotal: 0,
    tax: 0,
    call_out_fee: 0,
    mileage_total: 0,
    discounts: 0,
    grand_total: 0,
    customer_notes: '',
    internal_notes: '',
    status: 'pending',
    line_items: [],
  })

  const isEditing = Boolean(id)
  const today = new Date().toISOString().substring(0, 10)

  const createEmptyLineItem = useCallback(() => ({
    type: 'LABOR',
    description: '',
    quantity: 1,
    unit_price: pricingSettings.laborRate ?? 0,
    taxable: pricingSettings.laborTaxable,
    discount_type: 'fixed',
    notes: '',
  }), [pricingSettings.laborRate, pricingSettings.laborTaxable])

  const subtotal = useMemo(() => {
    return form.line_items.reduce((sum, item) => {
      const quantity = Number(item.quantity) || 0
      const unitPrice = Number(item.unit_price) || 0
      return sum + quantity * unitPrice
    }, 0)
  }, [form.line_items])

  const grandTotal = useMemo(() => {
    const tax = parseFloat(form.tax) || 0
    const callOutFee = parseFloat(form.call_out_fee) || 0
    const mileageTotal = parseFloat(form.mileage_total) || 0
    const discounts = parseFloat(form.discounts) || 0

    return subtotal + tax + callOutFee + mileageTotal - discounts
  }, [form.tax, form.call_out_fee, form.mileage_total, form.discounts, subtotal])

  useEffect(() => {
    setForm((prev) => {
      if (prev.subtotal === subtotal && prev.grand_total === grandTotal) {
        return prev
      }
      return {
        ...prev,
        subtotal,
        grand_total: grandTotal,
      }
    })
  }, [grandTotal, subtotal])

  const loadEstimate = useCallback(async () => {
    if (!id) return
    setLoading(true)
    try {
      const response = await estimateService.getEstimate(id)
      const data = response.data
      const customerId = data.customer_id ?? data.customer?.id ?? null
      const vehicleId = data.vehicle_id ?? data.vehicle?.id ?? null

      setForm((prev) => ({
        ...prev,
        ...data,
        customer_id: customerId,
        vehicle_id: vehicleId,
        is_mobile: Boolean(data.is_mobile),
        line_items: data.line_items?.length
          ? data.line_items.map((item) => ({
              type: item.type || 'LABOR',
              description: item.description || '',
              quantity: Number(item.quantity) || 1,
              unit_price: Number(item.unit_price) || 0,
              taxable: item.taxable !== undefined ? Boolean(item.taxable) : true,
              discount_type: item.discount_type || 'fixed',
              notes: item.notes || '',
            }))
          : [],
      }))
    } catch (loadError) {
      console.error('Failed to load estimate:', loadError)
      error('Failed to load estimate')
      navigate('/cp/estimates')
    } finally {
      setLoading(false)
    }
  }, [error, id, navigate])

  const loadBundles = useCallback(async () => {
    setBundleLoading(true)
    try {
      const data = await bundleService.list({ active: 1 })
      setBundles(Array.isArray(data) ? data : [])
    } catch (bundleError) {
      console.error('Failed to load bundles:', bundleError)
      setBundles([])
    } finally {
      setBundleLoading(false)
    }
  }, [])

  const loadPricingSettings = useCallback(async () => {
    try {
      const response = await api.get('/settings')
      const settings = response.data || {}
      setPricingSettings({
        laborRate: Number(settings['pricing.labor_rate']?.value) || 0,
        laborTaxable: settings['pricing.labor_taxable']?.value === true || settings['pricing.labor_taxable']?.value === 'true',
        feeTaxable: settings['pricing.fee_taxable']?.value === true || settings['pricing.fee_taxable']?.value === 'true',
      })
    } catch (err) {
      console.error('Failed to load pricing settings', err)
    }
  }, [])

  useEffect(() => {
    loadPricingSettings()
  }, [loadPricingSettings])

  useEffect(() => {
    if (isEditing) {
      loadEstimate()
    }
  }, [isEditing, loadEstimate])

  useEffect(() => {
    loadBundles()
  }, [loadBundles])

  useEffect(() => {
    if (!isEditing && form.line_items.length === 0 && pricingSettings.laborRate !== null) {
      setForm((prev) => ({ ...prev, line_items: [createEmptyLineItem()] }))
    }
  }, [isEditing, form.line_items.length, createEmptyLineItem, pricingSettings.laborRate])

  const loadCustomerVehicles = useCallback(async (customerId) => {
    if (!customerId) {
      setCustomerVehicles([])
      return
    }
    setVehiclesLoading(true)
    try {
      const vehicles = await customerService.getCustomerVehicles(customerId)
      setCustomerVehicles(Array.isArray(vehicles) ? vehicles : [])
    } catch (vehicleError) {
      console.error('Failed to load customer vehicles:', vehicleError)
      setCustomerVehicles([])
    } finally {
      setVehiclesLoading(false)
    }
  }, [])

  useEffect(() => {
    if (form.customer_id) {
      loadCustomerVehicles(form.customer_id)
    } else {
      setCustomerVehicles([])
    }
  }, [form.customer_id, loadCustomerVehicles])

  const addLineItem = () => {
    setForm((prev) => ({
      ...prev,
      line_items: [...prev.line_items, createEmptyLineItem()],
    }))
  }

  const removeLineItem = (index) => {
    setForm((prev) => {
      if (prev.line_items.length <= 1) {
        return prev
      }
      const nextItems = prev.line_items.filter((_, idx) => idx !== index)
      return {
        ...prev,
        line_items: nextItems,
      }
    })
  }

  const updateLineItem = (index, field, value) => {
    setForm((prev) => ({
      ...prev,
      line_items: prev.line_items.map((item, idx) => {
        if (idx !== index) return item
        const updated = { ...item, [field]: value }

        if (field === 'type' && value !== item.type) {
          if (value === 'LABOR') {
            updated.unit_price = pricingSettings.laborRate ?? 0
            updated.taxable = pricingSettings.laborTaxable
          } else if (value === 'FEE') {
            updated.taxable = pricingSettings.feeTaxable
          } else if (value === 'DISCOUNT') {
            updated.taxable = false
            updated.discount_type = 'fixed'
            updated.quantity = 1
          } else if (value === 'PART') {
            updated.taxable = true
          }
        }

        return updated
      }),
    }))
  }

  const addBundleItems = async () => {
    if (!bundleSelection) return
    setAddingBundle(true)
    try {
      const items = await bundleService.fetchItemsForEstimate(bundleSelection)
      if (!Array.isArray(items) || items.length === 0) {
        error('Selected bundle has no items.')
        return
      }

      const nextItems = items.map((item) => {
        const unitPrice = Number(item.unit_price) || 0
        const adjustedPrice = item.type === 'DISCOUNT' ? -Math.abs(unitPrice) : unitPrice
        return {
          type: item.type || 'LABOR',
          description: item.description || '',
          quantity: Number(item.quantity) || 1,
          unit_price: adjustedPrice,
          taxable: item.type === 'DISCOUNT' ? false : Boolean(item.taxable),
          discount_type: item.discount_type || 'fixed',
          notes: '',
        }
      })

      setForm((prev) => ({
        ...prev,
        line_items: [...prev.line_items, ...nextItems],
      }))
      success('Bundle items added to estimate.')
    } catch (bundleError) {
      console.error('Failed to add bundle items:', bundleError)
      error('Failed to add bundle items.')
    } finally {
      setAddingBundle(false)
    }
  }

  const saveEstimate = async (event) => {
    event.preventDefault()
    setSaving(true)

    if (form.expiration_date && form.expiration_date < today) {
      error('Expiration date cannot be in the past')
      setSaving(false)
      return
    }

    try {
      const payload = {
        customer_id: form.customer_id ? parseInt(form.customer_id, 10) : null,
        vehicle_id: form.vehicle_id ? parseInt(form.vehicle_id, 10) : null,
        is_mobile: Boolean(form.is_mobile),
        technician_id: form.technician_id ? parseInt(form.technician_id, 10) : null,
        expiration_date: form.expiration_date || null,
        subtotal: parseFloat(form.subtotal),
        tax: parseFloat(form.tax) || 0,
        call_out_fee: parseFloat(form.call_out_fee) || 0,
        mileage_total: parseFloat(form.mileage_total) || 0,
        discounts: parseFloat(form.discounts) || 0,
        grand_total: parseFloat(form.grand_total),
        customer_notes: form.customer_notes || null,
        internal_notes: form.internal_notes || null,
        status: form.status || 'pending',
        line_items: form.line_items.map((item) => ({
          type: item.type || 'LABOR',
          description: item.description,
          quantity: Number(item.quantity) || 0,
          unit_price: Number(item.unit_price) || 0,
          taxable: item.type === 'DISCOUNT' ? false : Boolean(item.taxable),
          discount_type: item.discount_type || 'fixed',
          notes: item.notes || null,
        })),
      }

      let response
      if (isEditing) {
        response = await estimateService.updateEstimate(id, payload)
        success('Estimate updated successfully')
      } else {
        response = await estimateService.createEstimate(payload)
        success('Estimate created successfully')
      }

      if (response.data?.id) {
        navigate(`/cp/estimates/${response.data.id}`)
      } else {
        navigate('/cp/estimates')
      }
    } catch (saveError) {
      console.error('Failed to save estimate:', saveError)
      error(saveError.response?.data?.message || 'Failed to save estimate')
    } finally {
      setSaving(false)
    }
  }

  const searchCustomers = useCallback(async (query) => {
    try {
      return await customerService.searchCustomers(query)
    } catch (searchError) {
      console.error('Customer search failed:', searchError)
      return []
    }
  }, [])

  const searchTechnicians = useCallback(async (query) => {
    try {
      return await technicianService.searchTechnicians(query)
    } catch (searchError) {
      console.error('Technician search failed:', searchError)
      return []
    }
  }, [])

  const handleCustomerSelect = (customer) => {
    setSelectedCustomer(customer)
    setForm((prev) => ({
      ...prev,
      customer_id: customer?.id || null,
      vehicle_id: null,
    }))
  }

  const formatVehicleLabel = (vehicle) => {
    const parts = []
    if (vehicle.year) parts.push(vehicle.year)
    if (vehicle.make) parts.push(vehicle.make)
    if (vehicle.model) parts.push(vehicle.model)
    if (parts.length === 0) return `Vehicle #${vehicle.id}`
    return parts.join(' ')
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
      <div className="mb-6">
        <div className="flex items-center gap-4 mb-2">
          <Button variant="ghost" onClick={() => navigate('/cp/estimates')}>
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Button>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{isEditing ? 'Edit Estimate' : 'Create Estimate'}</h1>
            <p className="mt-1 text-sm text-gray-500">
              {isEditing ? 'Update estimate details' : 'Create a new estimate for a customer'}
            </p>
          </div>
        </div>
      </div>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading..." />
        </div>
      ) : (
        <form onSubmit={saveEstimate}>
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="lg:col-span-2 space-y-6">
              <Card>
                <div className="mb-4">
                  <h3 className="text-lg font-medium text-gray-900">Customer Information</h3>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <Autocomplete
                      modelValue={form.customer_id}
                      label="Customer"
                      placeholder="Search by name, email, phone, or ID..."
                      searchFn={searchCustomers}
                      itemValue={(item) => item.id}
                      itemLabel={(item) => item.name || `Customer #${item.id}`}
                      itemSubtext={(item) => `${item.email || ''} ${item.phone ? '• ' + item.phone : ''}`}
                      required
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, customer_id: value, vehicle_id: null }))}
                      onSelect={handleCustomerSelect}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700">Vehicle *</label>
                    <Select
                      value={form.vehicle_id ?? ''}
                      placeholder={vehiclesLoading ? 'Loading vehicles...' : (form.customer_id ? 'Select a vehicle' : 'Select a customer first')}
                      options={customerVehicles.map((vehicle) => ({
                        value: vehicle.id,
                        label: formatVehicleLabel(vehicle) + (vehicle.license_plate ? ` (${vehicle.license_plate})` : ''),
                      }))}
                      disabled={!form.customer_id || vehiclesLoading}
                      required
                      onChange={(event) => setForm((prev) => ({ ...prev, vehicle_id: event.target.value ? Number(event.target.value) : null }))}
                    />
                    {form.customer_id && !vehiclesLoading && customerVehicles.length === 0 ? (
                      <p className="mt-1 text-xs text-amber-600">No vehicles found for this customer.</p>
                    ) : null}
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700">Repair Type</label>
                    <div className="mt-2 flex flex-wrap gap-3 text-sm text-gray-700">
                      <label className="inline-flex items-center gap-2">
                        <input
                          type="radio"
                          checked={!form.is_mobile}
                          onChange={() => setForm((prev) => ({ ...prev, is_mobile: false }))}
                          className="h-4 w-4 text-primary-600"
                        />
                        In shop
                      </label>
                      <label className="inline-flex items-center gap-2">
                        <input
                          type="radio"
                          checked={form.is_mobile}
                          onChange={() => setForm((prev) => ({ ...prev, is_mobile: true }))}
                          className="h-4 w-4 text-primary-600"
                        />
                        Mobile (location required for time tracking)
                      </label>
                    </div>
                  </div>

                  <div>
                    <Autocomplete
                      modelValue={form.technician_id}
                      label="Technician"
                      placeholder="Search by name or email..."
                      searchFn={searchTechnicians}
                      itemValue={(item) => item.id}
                      itemLabel={(item) => item.name || `Technician #${item.id}`}
                      itemSubtext={(item) => item.email || ''}
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, technician_id: value }))}
                      onSelect={() => {}}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700">Expiration Date</label>
                    <Input
                      value={form.expiration_date}
                      type="date"
                      min={today}
                      className="mt-1"
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, expiration_date: value }))}
                    />
                  </div>
                </div>
              </Card>

              <Card>
                <div className="mb-4">
                  <h3 className="text-lg font-medium text-gray-900">Line Items</h3>
                </div>

                <div className="mb-5 rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4">
                  <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div className="flex-1">
                      <Select
                        value={bundleSelection}
                        label="Quick add from Service Menu"
                        placeholder={bundleLoading ? 'Loading canned jobs...' : 'Select a canned job'}
                        options={bundles.map((bundle) => ({
                          value: bundle.id,
                          label: bundle.service_type_name ? `${bundle.name} • ${bundle.service_type_name}` : bundle.name,
                        }))}
                        onChange={(event) => setBundleSelection(event.target.value)}
                        disabled={bundleLoading}
                      />
                      <p className="mt-1 text-xs text-gray-500">
                        Canned jobs bundle labor, parts, and fees for fast entry.
                      </p>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row">
                      <Button
                        variant="outline"
                        onClick={() => navigate('/cp/bundles')}
                        type="button"
                      >
                        Manage Canned Jobs
                      </Button>
                      <Button
                        onClick={addBundleItems}
                        type="button"
                        disabled={!bundleSelection || addingBundle}
                        loading={addingBundle}
                      >
                        {addingBundle ? 'Adding...' : 'Add Bundle'}
                      </Button>
                    </div>
                  </div>
                </div>

                <div className="space-y-4">
                  {form.line_items.map((item, index) => (
                    <div key={index} className="border border-gray-200 rounded-lg p-4">
                      <div className="grid grid-cols-12 gap-3">
                        <div className="col-span-6 md:col-span-2">
                          <label className="block text-sm font-medium text-gray-700">Type</label>
                          <select
                            value={item.type}
                            onChange={(event) => updateLineItem(index, 'type', event.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                            required
                          >
                            <option value="LABOR">Labor</option>
                            <option value="PART">Part</option>
                            <option value="FEE">Fee</option>
                            <option value="DISCOUNT">Discount</option>
                          </select>
                        </div>

                        <div className="col-span-6 md:col-span-4">
                          <Input
                            value={item.description}
                            placeholder="Service or part description"
                            label="Description"
                            required
                            onUpdateModelValue={(value) => updateLineItem(index, 'description', value)}
                          />
                        </div>

                        {item.type === 'DISCOUNT' ? (
                          <div className="col-span-4 md:col-span-1">
                            <label className="block text-sm font-medium text-gray-700">Discount Type</label>
                            <select
                              value={item.discount_type || 'fixed'}
                              onChange={(event) => updateLineItem(index, 'discount_type', event.target.value)}
                              className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                            >
                              <option value="fixed">Flat Rate ($)</option>
                              <option value="percent">Percentage (%)</option>
                            </select>
                          </div>
                        ) : (
                          <div className="col-span-4 md:col-span-1">
                            <Input
                              value={item.quantity}
                              type="number"
                              label="Qty"
                              min="0"
                              step="0.01"
                              required
                              onUpdateModelValue={(value) => updateLineItem(index, 'quantity', value)}
                            />
                          </div>
                        )}

                        <div className="col-span-4 md:col-span-2">
                          <Input
                            value={item.unit_price}
                            type="number"
                            label={getUnitPriceLabel(item)}
                            min="0"
                            step="0.01"
                            required
                            onUpdateModelValue={(value) => updateLineItem(index, 'unit_price', value)}
                          />
                        </div>

                        <div className="col-span-4 md:col-span-2 flex items-end">
                          <div>
                            <p className="text-xs text-gray-500">Amount</p>
                            <p className="text-sm font-semibold">{formatCurrency((Number(item.quantity) || 0) * (Number(item.unit_price) || 0))}</p>
                          </div>
                        </div>

                        <div className="col-span-12 md:col-span-1 flex items-end justify-end">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => removeLineItem(index)}
                            disabled={form.line_items.length === 1}
                          >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                              />
                            </svg>
                          </Button>
                        </div>
                      </div>

                      <div className="mt-3 flex items-center justify-between">
                        {item.type !== 'DISCOUNT' ? (
                          <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input
                              type="checkbox"
                              checked={item.taxable}
                              onChange={(event) => updateLineItem(index, 'taxable', event.target.checked)}
                              className="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                            />
                            Taxable
                          </label>
                        ) : (
                          <span />
                        )}
                        <Textarea
                          value={item.notes}
                          placeholder="Additional notes (optional)"
                          rows={1}
                          className="flex-1 ml-4 max-w-md"
                          onUpdateModelValue={(value) => updateLineItem(index, 'notes', value)}
                        />
                      </div>
                    </div>
                  ))}

                  <Button variant="outline" onClick={addLineItem} className="w-full">
                    <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Line Item
                  </Button>
                </div>
              </Card>

              <Card>
                <div className="mb-4">
                  <h3 className="text-lg font-medium text-gray-900">Pricing Details</h3>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Subtotal *</label>
                    <Input
                      value={form.subtotal}
                      type="number"
                      step="0.01"
                      min="0"
                      placeholder="0.00"
                      className="mt-1 bg-gray-50"
                      required
                      readOnly
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700">Tax</label>
                    <Input
                      value={form.tax}
                      type="number"
                      step="0.01"
                      min="0"
                      placeholder="0.00"
                      className="mt-1"
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, tax: value }))}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700">Call-out Fee</label>
                    <Input
                      value={form.call_out_fee}
                      type="number"
                      step="0.01"
                      min="0"
                      placeholder="0.00"
                      className="mt-1"
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, call_out_fee: value }))}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700">Mileage Total</label>
                    <Input
                      value={form.mileage_total}
                      type="number"
                      step="0.01"
                      min="0"
                      placeholder="0.00"
                      className="mt-1"
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, mileage_total: value }))}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700">Discounts</label>
                    <Input
                      value={form.discounts}
                      type="number"
                      step="0.01"
                      min="0"
                      placeholder="0.00"
                      className="mt-1"
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, discounts: value }))}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700">Grand Total</label>
                    <Input
                      value={form.grand_total}
                      type="number"
                      step="0.01"
                      className="mt-1 bg-gray-50"
                      readOnly
                    />
                  </div>
                </div>
              </Card>

              <Card>
                <div className="mb-4">
                  <h3 className="text-lg font-medium text-gray-900">Notes</h3>
                </div>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Customer Notes</label>
                    <Textarea
                      value={form.customer_notes}
                      rows={3}
                      placeholder="Notes visible to customer"
                      className="mt-1"
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, customer_notes: value }))}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700">Internal Notes</label>
                    <Textarea
                      value={form.internal_notes}
                      rows={3}
                      placeholder="Internal notes (not visible to customer)"
                      className="mt-1"
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, internal_notes: value }))}
                    />
                  </div>
                </div>
              </Card>
            </div>

            <div className="space-y-6">
              <Card>
                <div className="mb-4">
                  <h3 className="text-lg font-medium text-gray-900">Summary</h3>
                </div>
                <div className="space-y-3">
                  <div className="flex justify-between text-sm">
                    <span className="text-gray-600">Subtotal</span>
                    <span className="font-medium">{formatCurrency(form.subtotal)}</span>
                  </div>
                  {Number(form.call_out_fee) > 0 ? (
                    <div className="flex justify-between text-sm">
                      <span className="text-gray-600">Call-out Fee</span>
                      <span className="font-medium">{formatCurrency(form.call_out_fee)}</span>
                    </div>
                  ) : null}
                  {Number(form.mileage_total) > 0 ? (
                    <div className="flex justify-between text-sm">
                      <span className="text-gray-600">Mileage</span>
                      <span className="font-medium">{formatCurrency(form.mileage_total)}</span>
                    </div>
                  ) : null}
                  {Number(form.discounts) > 0 ? (
                    <div className="flex justify-between text-sm text-green-600">
                      <span>Discounts</span>
                      <span className="font-medium">-{formatCurrency(form.discounts)}</span>
                    </div>
                  ) : null}
                  <div className="flex justify-between text-sm">
                    <span className="text-gray-600">Tax</span>
                    <span className="font-medium">{formatCurrency(form.tax)}</span>
                  </div>
                  <div className="border-t border-gray-200 pt-3 flex justify-between">
                    <span className="font-medium">Grand Total</span>
                    <span className="text-lg font-bold text-primary-600">{formatCurrency(form.grand_total)}</span>
                  </div>
                </div>
              </Card>

              {isEditing ? (
                <Card>
                  <div className="mb-4">
                    <h3 className="text-lg font-medium text-gray-900">Status</h3>
                  </div>
                  <Select
                    value={form.status}
                    options={statusOptions}
                    label="Estimate Status"
                    required
                    onChange={(event) => setForm((prev) => ({ ...prev, status: event.target.value }))}
                  />
                </Card>
              ) : null}

              <Card>
                <div className="mb-4">
                  <h3 className="text-lg font-medium text-gray-900">Actions</h3>
                </div>
                <div className="space-y-3">
                  <Button type="submit" className="w-full" disabled={saving}>
                    {saving ? 'Saving...' : (isEditing ? 'Update Estimate' : 'Create Estimate')}
                  </Button>
                  <Button
                    variant="outline"
                    className="w-full"
                    onClick={() => navigate('/cp/estimates')}
                    disabled={saving}
                  >
                    Cancel
                  </Button>
                </div>
              </Card>
            </div>
          </div>
        </form>
      )}
    </div>
  )
}
