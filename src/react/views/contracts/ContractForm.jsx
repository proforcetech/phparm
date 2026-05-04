import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import contractsService from '../../../services/contracts.service'
import crmService from '../../../services/crm.service'
import { useToast } from '../../stores/toast.jsx'

const initialFormData = {
  name: '',
  company_id: '',
  start_date: '',
  end_date: '',
  term_months: '',
  auto_renew: false,
  billing_terms: '',
  notes: '',
}

function unwrap(res) {
  if (Array.isArray(res)) return res
  if (Array.isArray(res?.data)) return res.data
  if (Array.isArray(res?.items)) return res.items
  return []
}

export default function ContractForm() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()
  const isEditing = Boolean(id)

  const [formData, setFormData] = useState(initialFormData)
  const [formErrors, setFormErrors] = useState({})
  const [companies, setCompanies] = useState([])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [apiError, setApiError] = useState(null)

  useEffect(() => {
    crmService
      .listCompanies({ limit: 500 })
      .then((res) => setCompanies(unwrap(res)))
      .catch(() => setCompanies([]))
  }, [])

  const loadContract = useCallback(async () => {
    if (!id) return
    setLoading(true)
    try {
      const c = await contractsService.get(id)
      setFormData({
        name: c.name || '',
        company_id: c.company_id ? String(c.company_id) : '',
        start_date: c.start_date ? String(c.start_date).slice(0, 10) : '',
        end_date: c.end_date ? String(c.end_date).slice(0, 10) : '',
        term_months: c.term_months ?? '',
        auto_renew: Boolean(c.auto_renew),
        billing_terms: c.billing_terms || '',
        notes: c.notes || '',
      })
    } catch {
      error('Failed to load contract')
      navigate('/cp/contracts')
    } finally {
      setLoading(false)
    }
  }, [id, error, navigate])

  useEffect(() => {
    loadContract()
  }, [loadContract])

  const updateField = (field) => (value) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
    if (formErrors[field]) {
      setFormErrors((prev) => ({ ...prev, [field]: null }))
    }
  }

  const updateCheckbox = (field) => (e) => {
    const checked = e?.target ? e.target.checked : Boolean(e)
    setFormData((prev) => ({ ...prev, [field]: checked }))
  }

  const validate = () => {
    const errors = {}
    if (!formData.name.trim()) errors.name = 'Name is required'
    if (!formData.company_id) errors.company_id = 'Company is required'
    if (formData.term_months !== '' && Number.isNaN(Number(formData.term_months))) {
      errors.term_months = 'Term must be a number'
    }
    if (formData.start_date && formData.end_date && formData.start_date > formData.end_date) {
      errors.end_date = 'End date must be after start date'
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
      company_id: Number(formData.company_id),
      start_date: formData.start_date || null,
      end_date: formData.end_date || null,
      term_months: formData.term_months === '' ? null : Number(formData.term_months),
      auto_renew: Boolean(formData.auto_renew),
      billing_terms: formData.billing_terms,
      notes: formData.notes,
    }

    try {
      if (isEditing) {
        await contractsService.update(id, payload)
        success('Contract updated')
        navigate(`/cp/contracts/${id}`)
      } else {
        const res = await contractsService.create(payload)
        const newId = res?.id ?? res?.data?.id
        success('Contract created')
        if (newId) {
          navigate(`/cp/contracts/${newId}`)
        } else {
          navigate('/cp/contracts')
        }
      }
    } catch (err) {
      setApiError(err?.response?.data?.message || 'Failed to save contract')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading contract..." />
      </div>
    )
  }

  const cancelTo = isEditing ? `/cp/contracts/${id}` : '/cp/contracts'

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Link to={cancelTo} className="text-gray-500 hover:text-gray-700">
          <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {isEditing ? 'Edit Contract' : 'New Contract'}
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            {isEditing ? 'Update contract details' : 'Create a new master service agreement'}
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
            <div className="md:col-span-2">
              <Input
                label="Contract Name"
                value={formData.name}
                onUpdateModelValue={updateField('name')}
                error={formErrors.name}
                placeholder="e.g. Acme Corp 2026 MSA"
                required
              />
            </div>

            <Select
              label="Company"
              value={formData.company_id}
              onUpdateModelValue={updateField('company_id')}
              error={formErrors.company_id}
              placeholder="Select a company"
              required
              options={companies.map((c) => ({ value: String(c.id), label: c.name }))}
            />

            <Input
              label="Term (months)"
              type="number"
              value={formData.term_months}
              onUpdateModelValue={updateField('term_months')}
              error={formErrors.term_months}
              placeholder="e.g. 12"
            />

            <Input
              label="Start Date"
              type="date"
              value={formData.start_date}
              onUpdateModelValue={updateField('start_date')}
              error={formErrors.start_date}
            />

            <Input
              label="End Date"
              type="date"
              value={formData.end_date}
              onUpdateModelValue={updateField('end_date')}
              error={formErrors.end_date}
            />

            <div className="md:col-span-2">
              <label className="flex items-center cursor-pointer">
                <input
                  type="checkbox"
                  checked={formData.auto_renew}
                  onChange={updateCheckbox('auto_renew')}
                  className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                />
                <span className="ml-2 text-sm font-medium text-gray-900">Auto-renew at end of term</span>
              </label>
            </div>

            <div className="md:col-span-2">
              <Textarea
                label="Billing Terms"
                value={formData.billing_terms}
                onUpdateModelValue={updateField('billing_terms')}
                placeholder="Net 30, monthly invoicing, etc."
                rows={3}
              />
            </div>

            <div className="md:col-span-2">
              <Textarea
                label="Notes"
                value={formData.notes}
                onUpdateModelValue={updateField('notes')}
                placeholder="Internal notes about this contract..."
                rows={4}
              />
            </div>
          </div>

          <div className="mt-6 flex justify-end gap-3">
            <Button type="button" variant="ghost" onClick={() => navigate(cancelTo)}>
              Cancel
            </Button>
            <Button type="submit" loading={saving}>
              {isEditing ? 'Update Contract' : 'Create Contract'}
            </Button>
          </div>
        </Card>
      </form>
    </div>
  )
}
