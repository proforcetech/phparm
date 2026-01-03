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
  name: '',
  email: '',
  phone: '',
  address: '',
  city: '',
  state: '',
  zip: '',
  notes: '',
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
        name: customer.name || '',
        email: customer.email || '',
        phone: customer.phone || '',
        address: customer.address || '',
        city: customer.city || '',
        state: customer.state || '',
        zip: customer.zip || '',
        notes: customer.notes || '',
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

  const validate = () => {
    const errors = {}
    if (!formData.name.trim()) {
      errors.name = 'Name is required'
    }
    if (formData.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      errors.email = 'Please enter a valid email address'
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
              label="Name"
              value={formData.name}
              onUpdateModelValue={updateField('name')}
              error={formErrors.name}
              placeholder="Customer name"
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
              placeholder="(555) 123-4567"
            />

            <Input
              label="Address"
              value={formData.address}
              onUpdateModelValue={updateField('address')}
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
                value={formData.zip}
                onUpdateModelValue={updateField('zip')}
                placeholder="12345"
              />
            </div>
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
