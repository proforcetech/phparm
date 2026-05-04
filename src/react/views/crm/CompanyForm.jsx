import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import crm from '../../../services/crm.service'
import divisionsService from '../../../services/divisions.service'
import { useToast } from '../../stores/toast.jsx'

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'prospect', label: 'Prospect' },
  { value: 'inactive', label: 'Inactive' },
]

const initialFormData = {
  name: '',
  status: 'active',
  division_id: '',
  tax_id: '',
  billing_email: '',
  notes: '',
}

export default function CompanyForm() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success } = useToast()
  const isEditing = Boolean(id)

  const [formData, setFormData] = useState(initialFormData)
  const [formErrors, setFormErrors] = useState({})
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [apiError, setApiError] = useState(null)
  const [divisions, setDivisions] = useState([])
  const [divisionsLoaded, setDivisionsLoaded] = useState(false)

  useEffect(() => {
    let cancelled = false
    divisionsService
      .list({ limit: 200 })
      .then((res) => {
        if (cancelled) return
        const list = Array.isArray(res) ? res : (res?.data ?? [])
        setDivisions(list)
      })
      .catch(() => {
        if (!cancelled) setDivisions([])
      })
      .finally(() => {
        if (!cancelled) setDivisionsLoaded(true)
      })
    return () => { cancelled = true }
  }, [])

  const loadCompany = useCallback(async () => {
    if (!id) return
    setLoading(true)
    try {
      const company = await crm.getCompany(id)
      setFormData({
        name: company.name || '',
        status: company.status || 'active',
        division_id: company.division_id ? String(company.division_id) : '',
        tax_id: company.tax_id || '',
        billing_email: company.billing_email || '',
        notes: company.notes || '',
      })
    } catch {
      setApiError('Failed to load company')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    loadCompany()
  }, [loadCompany])

  const updateField = (field) => (value) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
    if (formErrors[field]) {
      setFormErrors((prev) => ({ ...prev, [field]: null }))
    }
  }

  const validate = () => {
    const errors = {}
    if (!formData.name.trim()) {
      errors.name = 'Company name is required'
    }
    if (formData.billing_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.billing_email)) {
      errors.billing_email = 'Please enter a valid email address'
    }
    setFormErrors(errors)
    return Object.keys(errors).length === 0
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!validate()) return

    setSaving(true)
    setApiError(null)

    const payload = {
      name: formData.name.trim(),
      status: formData.status,
      tax_id: formData.tax_id || null,
      billing_email: formData.billing_email || null,
      notes: formData.notes || null,
    }
    if (formData.division_id) {
      payload.division_id = formData.division_id
    }

    try {
      let savedId = id
      if (isEditing) {
        await crm.updateCompany(id, payload)
        success('Company updated successfully')
      } else {
        const created = await crm.createCompany(payload)
        savedId = created?.id ?? created?.data?.id ?? null
        success('Company created successfully')
      }
      if (savedId) {
        navigate(`/cp/crm/companies/${savedId}`)
      } else {
        navigate('/cp/crm/companies')
      }
    } catch (err) {
      setApiError(err?.response?.data?.message || 'Failed to save company')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading company..." />
      </div>
    )
  }

  const divisionOptions = [
    { value: '', label: divisionsLoaded ? 'No division' : 'Loading...' },
    ...divisions.map((d) => ({ value: String(d.id), label: d.name })),
  ]

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Link to="/cp/crm/companies" className="text-gray-500 hover:text-gray-700">
          <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {isEditing ? 'Edit Company' : 'New Company'}
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            {isEditing ? 'Update company information' : 'Create a new B2B company'}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <Card>
          {apiError ? (
            <Alert variant="danger" className="mb-6" onClose={() => setApiError(null)}>
              {apiError}
            </Alert>
          ) : null}

          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Input
              label="Name"
              value={formData.name}
              onUpdateModelValue={updateField('name')}
              error={formErrors.name}
              placeholder="Company name"
              required
            />
            <Select
              label="Status"
              value={formData.status}
              placeholder=""
              onUpdateModelValue={updateField('status')}
              options={STATUS_OPTIONS}
            />
            <Select
              label="Division"
              value={formData.division_id}
              placeholder=""
              onUpdateModelValue={updateField('division_id')}
              options={divisionOptions}
            />
            <Input
              label="Tax ID"
              value={formData.tax_id}
              onUpdateModelValue={updateField('tax_id')}
              placeholder="EIN / VAT / etc."
            />
            <Input
              label="Billing Email"
              type="email"
              value={formData.billing_email}
              onUpdateModelValue={updateField('billing_email')}
              error={formErrors.billing_email}
              placeholder="billing@example.com"
            />
          </div>

          <div className="mt-6">
            <Textarea
              label="Notes"
              value={formData.notes}
              onUpdateModelValue={updateField('notes')}
              placeholder="Internal notes about this company..."
              rows={4}
            />
          </div>

          <div className="mt-6 flex justify-end gap-3">
            <Button type="button" variant="ghost" onClick={() => navigate('/cp/crm/companies')}>
              Cancel
            </Button>
            <Button type="submit" loading={saving}>
              {isEditing ? 'Update Company' : 'Create Company'}
            </Button>
          </div>
        </Card>
      </form>
    </div>
  )
}
