import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Textarea from '../../components/ui/Textarea'
import customerService from '../../../services/customer.service'
import { useToast } from '../../stores/toast.jsx'

const initialFormData = {
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  street: '',
  city: '',
  state: '',
  postal_code: '',
  notes: '',
  is_commercial: false,
  business_name: '',
  tax_exempt: false,
  billing_street: '',
  billing_city: '',
  billing_state: '',
  billing_postal_code: '',
}

export default function CustomerForm() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()
  const isEditing = Boolean(id)

  const [formData, setFormData] = useState(initialFormData)
  const [formErrors, setFormErrors] = useState({})
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [apiError, setApiError] = useState(null)

  const loadCustomer = useCallback(async () => {
    if (!id) return
    setLoading(true)
    try {
      const customer = await customerService.getCustomer(id)
      setFormData({
        first_name: customer.first_name || '',
        last_name: customer.last_name || '',
        email: customer.email || '',
        phone: customer.phone || '',
        street: customer.street || '',
        city: customer.city || '',
        state: customer.state || '',
        postal_code: customer.postal_code || '',
        notes: customer.notes || '',
        is_commercial: Boolean(customer.is_commercial),
        business_name: customer.business_name || '',
        tax_exempt: Boolean(customer.tax_exempt),
        billing_street: customer.billing_street || '',
        billing_city: customer.billing_city || '',
        billing_state: customer.billing_state || '',
        billing_postal_code: customer.billing_postal_code || '',
      })
    } catch {
      error('Failed to load customer')
      navigate('/cp/customers')
    } finally {
      setLoading(false)
    }
  }, [id, error, navigate])

  useEffect(() => {
    loadCustomer()
  }, [loadCustomer])

  const updateField = (field) => (value) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
    if (formErrors[field]) {
      setFormErrors((prev) => ({ ...prev, [field]: null }))
    }
  }

  const updateCheckbox = (field) => (e) => {
    const checked = e.target ? e.target.checked : e
    setFormData((prev) => ({ ...prev, [field]: checked }))
    if (formErrors[field]) {
      setFormErrors((prev) => ({ ...prev, [field]: null }))
    }
  }

  const validate = () => {
    const errors = {}
    if (!formData.first_name.trim()) {
      errors.first_name = 'First name is required'
    }
    if (!formData.last_name.trim()) {
      errors.last_name = 'Last name is required'
    }
    if (!formData.email.trim() && !formData.phone.trim()) {
      errors.email = 'Email or phone is required'
      errors.phone = 'Email or phone is required'
    }
    if (formData.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      errors.email = 'Please enter a valid email address'
    }
    if (formData.is_commercial && !formData.business_name.trim()) {
      errors.business_name = 'Business name is required for commercial accounts'
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
      if (isEditing) {
        await customerService.updateCustomer(id, formData)
        success('Customer updated successfully')
      } else {
        await customerService.createCustomer(formData)
        success('Customer created successfully')
      }
      navigate('/cp/customers')
    } catch (err) {
      setApiError(err.response?.data?.message || 'Failed to save customer')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading customer..." />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Link to="/cp/customers" className="text-gray-500 hover:text-gray-700">
          <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {isEditing ? 'Edit Customer' : 'Add Customer'}
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            {isEditing ? 'Update customer information' : 'Create a new customer record'}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <Card>
          {apiError ? (
            <Alert variant="danger" className="mb-6" closable onClose={() => setApiError(null)}>
              {apiError}
            </Alert>
          ) : null}

          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Input
              label="First Name"
              value={formData.first_name}
              onUpdateModelValue={updateField('first_name')}
              error={formErrors.first_name}
              placeholder="First name"
              required
            />

            <Input
              label="Last Name"
              value={formData.last_name}
              onUpdateModelValue={updateField('last_name')}
              error={formErrors.last_name}
              placeholder="Last name"
              required
            />

            <Input
              label="Email"
              type="email"
              value={formData.email}
              onUpdateModelValue={updateField('email')}
              error={formErrors.email}
              placeholder="customer@example.com"
            />

            <Input
              label="Phone"
              type="tel"
              value={formData.phone}
              onUpdateModelValue={updateField('phone')}
              error={formErrors.phone}
              placeholder="(555) 123-4567"
            />

            <Input
              label="Address"
              value={formData.street}
              onUpdateModelValue={updateField('street')}
              placeholder="Street address"
            />

            <Input
              label="City"
              value={formData.city}
              onUpdateModelValue={updateField('city')}
              placeholder="City"
            />

            <div className="grid grid-cols-2 gap-4">
              <Input
                label="State"
                value={formData.state}
                onUpdateModelValue={updateField('state')}
                placeholder="State"
              />

              <Input
                label="ZIP Code"
                value={formData.postal_code}
                onUpdateModelValue={updateField('postal_code')}
                placeholder="12345"
              />
            </div>
          </div>

          {/* Commercial Account Section */}
          <div className="mt-8 pt-6 border-t border-gray-200">
            <div className="flex items-center mb-4">
              <label className="flex items-center cursor-pointer">
                <input
                  type="checkbox"
                  checked={formData.is_commercial}
                  onChange={updateCheckbox('is_commercial')}
                  className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                />
                <span className="ml-2 text-sm font-medium text-gray-900">
                  Commercial Account
                </span>
              </label>
            </div>

            {formData.is_commercial ? (
              <div className="space-y-6 mt-4 p-4 bg-gray-50 rounded-lg">
                <Input
                  label="Business Name"
                  value={formData.business_name}
                  onUpdateModelValue={updateField('business_name')}
                  error={formErrors.business_name}
                  placeholder="Company or business name"
                  required
                />

                <div className="flex items-center">
                  <label className="flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      checked={formData.tax_exempt}
                      onChange={updateCheckbox('tax_exempt')}
                      className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                    />
                    <span className="ml-2 text-sm font-medium text-gray-700">
                      Tax Exempt
                    </span>
                  </label>
                  <span className="ml-2 text-xs text-gray-500">
                    (Check if this business is exempt from sales tax)
                  </span>
                </div>

                <div className="mt-4">
                  <h4 className="text-sm font-medium text-gray-900 mb-3">Billing Address</h4>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="md:col-span-2">
                      <Input
                        label="Billing Street"
                        value={formData.billing_street}
                        onUpdateModelValue={updateField('billing_street')}
                        placeholder="Billing street address"
                      />
                    </div>

                    <Input
                      label="Billing City"
                      value={formData.billing_city}
                      onUpdateModelValue={updateField('billing_city')}
                      placeholder="City"
                    />

                    <div className="grid grid-cols-2 gap-4">
                      <Input
                        label="Billing State"
                        value={formData.billing_state}
                        onUpdateModelValue={updateField('billing_state')}
                        placeholder="State"
                      />

                      <Input
                        label="Billing ZIP"
                        value={formData.billing_postal_code}
                        onUpdateModelValue={updateField('billing_postal_code')}
                        placeholder="12345"
                      />
                    </div>
                  </div>
                </div>
              </div>
            ) : null}
          </div>

          <div className="mt-6">
            <Textarea
              label="Notes"
              value={formData.notes}
              onUpdateModelValue={updateField('notes')}
              placeholder="Additional notes about this customer..."
              rows={4}
            />
          </div>

          <div className="mt-6 flex justify-end gap-3">
            <Button type="button" variant="ghost" onClick={() => navigate('/cp/customers')}>
              Cancel
            </Button>
            <Button type="submit" loading={saving}>
              {isEditing ? 'Update Customer' : 'Create Customer'}
            </Button>
          </div>
        </Card>
      </form>
    </div>
  )
}
