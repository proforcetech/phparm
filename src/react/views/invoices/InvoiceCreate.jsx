import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import customerService from '../../../services/customer.service'
import invoiceService from '../../../services/invoice.service'
import { useToast } from '../../stores/toast.jsx'

const emptyLineItem = { description: '', quantity: 1, unit_price: 0 }

export default function InvoiceCreate() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { success, error } = useToast()

  const [customers, setCustomers] = useState([])
  const [loadingCustomers, setLoadingCustomers] = useState(true)
  const [saving, setSaving] = useState(false)
  const [apiError, setApiError] = useState(null)

  const [formData, setFormData] = useState({
    customer_id: searchParams.get('customer') || '',
    issue_date: new Date().toISOString().split('T')[0],
    due_date: '',
    notes: '',
  })
  const [lineItems, setLineItems] = useState([{ ...emptyLineItem }])
  const [formErrors, setFormErrors] = useState({})

  const loadCustomers = useCallback(async () => {
    setLoadingCustomers(true)
    try {
      const data = await customerService.listCustomers({ limit: 100 })
      const customerList = Array.isArray(data) ? data : data?.data || []
      setCustomers(customerList)
    } catch {
      error('Failed to load customers')
    } finally {
      setLoadingCustomers(false)
    }
  }, [error])

  useEffect(() => {
    loadCustomers()
  }, [loadCustomers])

  const updateField = (field) => (value) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
    if (formErrors[field]) {
      setFormErrors((prev) => ({ ...prev, [field]: null }))
    }
  }

  const updateLineItem = (index, field, value) => {
    setLineItems((prev) => {
      const updated = [...prev]
      updated[index] = { ...updated[index], [field]: value }
      return updated
    })
  }

  const addLineItem = () => {
    setLineItems((prev) => [...prev, { ...emptyLineItem }])
  }

  const removeLineItem = (index) => {
    if (lineItems.length === 1) return
    setLineItems((prev) => prev.filter((_, i) => i !== index))
  }

  const calculateSubtotal = () => {
    return lineItems.reduce((sum, item) => {
      return sum + (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0)
    }, 0)
  }

  const validate = () => {
    const errors = {}
    if (!formData.customer_id) {
      errors.customer_id = 'Please select a customer'
    }
    if (!formData.issue_date) {
      errors.issue_date = 'Issue date is required'
    }
    if (lineItems.length === 0 || lineItems.every((item) => !item.description.trim())) {
      errors.lineItems = 'At least one line item is required'
    }
    setFormErrors(errors)
    return Object.keys(errors).length === 0
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!validate()) return

    setSaving(true)
    setApiError(null)

    try {
      const validItems = lineItems.filter((item) => item.description.trim())
      await invoiceService.create({
        ...formData,
        items: validItems.map((item) => ({
          description: item.description,
          quantity: parseFloat(item.quantity) || 1,
          unit_price: parseFloat(item.unit_price) || 0,
        })),
      })
      success('Invoice created successfully')
      navigate('/cp/invoices')
    } catch (err) {
      setApiError(err.response?.data?.message || 'Failed to create invoice')
    } finally {
      setSaving(false)
    }
  }

  const customerOptions = customers.map((c) => ({
    value: c.id.toString(),
    label: c.name,
  }))

  if (loadingCustomers) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading..." />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Link to="/cp/invoices" className="text-gray-500 hover:text-gray-700">
          <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Create Invoice</h1>
          <p className="mt-1 text-sm text-gray-500">Create a new invoice for a customer</p>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <Card className="lg:col-span-2">
            {apiError ? (
              <Alert variant="danger" className="mb-6" closable onClose={() => setApiError(null)}>
                {apiError}
              </Alert>
            ) : null}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Customer *</label>
                <Select
                  value={formData.customer_id}
                  onChange={(e) => updateField('customer_id')(e.target.value)}
                  options={customerOptions}
                  placeholder="Select a customer..."
                />
                {formErrors.customer_id ? (
                  <p className="mt-1 text-sm text-red-600">{formErrors.customer_id}</p>
                ) : null}
              </div>

              <div className="grid grid-cols-2 gap-4">
                <Input
                  label="Issue Date"
                  type="date"
                  value={formData.issue_date}
                  onUpdateModelValue={updateField('issue_date')}
                  error={formErrors.issue_date}
                  required
                />

                <Input
                  label="Due Date"
                  type="date"
                  value={formData.due_date}
                  onUpdateModelValue={updateField('due_date')}
                />
              </div>
            </div>

            <div className="mb-6">
              <div className="flex justify-between items-center mb-3">
                <h3 className="text-sm font-medium text-gray-900">Line Items</h3>
                <Button type="button" size="sm" variant="ghost" onClick={addLineItem}>
                  + Add Item
                </Button>
              </div>

              {formErrors.lineItems ? (
                <p className="mb-2 text-sm text-red-600">{formErrors.lineItems}</p>
              ) : null}

              <div className="space-y-3">
                {lineItems.map((item, index) => (
                  <div key={index} className="flex gap-3 items-start">
                    <div className="flex-1">
                      <Input
                        placeholder="Description"
                        value={item.description}
                        onUpdateModelValue={(value) => updateLineItem(index, 'description', value)}
                      />
                    </div>
                    <div className="w-20">
                      <Input
                        type="number"
                        placeholder="Qty"
                        value={item.quantity}
                        onUpdateModelValue={(value) => updateLineItem(index, 'quantity', value)}
                        min="1"
                      />
                    </div>
                    <div className="w-28">
                      <Input
                        type="number"
                        placeholder="Price"
                        value={item.unit_price}
                        onUpdateModelValue={(value) => updateLineItem(index, 'unit_price', value)}
                        min="0"
                        step="0.01"
                      />
                    </div>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="text-red-600 hover:text-red-700 mt-1"
                      onClick={() => removeLineItem(index)}
                      disabled={lineItems.length === 1}
                    >
                      <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </Button>
                  </div>
                ))}
              </div>
            </div>

            <Textarea
              label="Notes"
              value={formData.notes}
              onUpdateModelValue={updateField('notes')}
              placeholder="Additional notes for the invoice..."
              rows={3}
            />
          </Card>

          <Card>
            <h3 className="text-lg font-medium text-gray-900 mb-4">Summary</h3>
            <dl className="space-y-3">
              <div className="flex justify-between">
                <dt className="text-sm text-gray-500">Subtotal</dt>
                <dd className="text-sm text-gray-900">
                  ${calculateSubtotal().toFixed(2)}
                </dd>
              </div>
              <div className="flex justify-between pt-3 border-t">
                <dt className="text-base font-medium text-gray-900">Total</dt>
                <dd className="text-base font-medium text-gray-900">
                  ${calculateSubtotal().toFixed(2)}
                </dd>
              </div>
            </dl>

            <div className="mt-6 space-y-3">
              <Button type="submit" fullWidth loading={saving}>
                Create Invoice
              </Button>
              <Button type="button" variant="ghost" fullWidth onClick={() => navigate('/cp/invoices')}>
                Cancel
              </Button>
            </div>
          </Card>
        </div>
      </form>
    </div>
  )
}
