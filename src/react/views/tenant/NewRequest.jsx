import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import { tenantPortalService } from '../../../services/propertyManagement.service'
import { useToast } from '../../stores/toast'

const PRIORITY_OPTIONS = [
  { value: 'low', label: 'Low — non-urgent, can wait a week or two' },
  { value: 'normal', label: 'Normal — fix when convenient' },
  { value: 'high', label: 'High — please prioritize' },
  { value: 'urgent', label: 'Urgent — habitability or safety issue' },
]

const CATEGORY_OPTIONS = [
  { value: '', label: '— Not sure / Other —' },
  { value: 'plumbing', label: 'Plumbing' },
  { value: 'electrical', label: 'Electrical' },
  { value: 'hvac', label: 'HVAC / heating / cooling' },
  { value: 'appliance', label: 'Appliance' },
  { value: 'structural', label: 'Structural / doors / windows' },
  { value: 'pest', label: 'Pest control' },
  { value: 'general', label: 'General maintenance' },
]

export default function NewRequest() {
  const navigate = useNavigate()
  const { success, error: toastError } = useToast()

  const [loading, setLoading] = useState(true)
  const [units, setUnits] = useState([])
  const [tenant, setTenant] = useState(null)
  const [loadError, setLoadError] = useState(null)

  const [submitting, setSubmitting] = useState(false)
  const [form, setForm] = useState({
    unit_id: '',
    category: '',
    priority: 'normal',
    title: '',
    description: '',
  })
  const [formError, setFormError] = useState('')

  useEffect(() => {
    let cancelled = false
    tenantPortalService.me()
      .then((data) => {
        if (cancelled) return
        const list = data.units ?? []
        setTenant(data.tenant ?? null)
        setUnits(list)
        if (list.length === 1) {
          setForm((prev) => ({ ...prev, unit_id: String(list[0].id) }))
        }
      })
      .catch((err) => {
        if (cancelled) return
        const message = err.response?.data?.error || 'Failed to load your units'
        setLoadError(message)
        toastError(message)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => { cancelled = true }
  }, [toastError])

  const updateForm = (key, value) => {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    setFormError('')

    if (!form.unit_id) {
      setFormError('Please choose which unit this request is for.')
      return
    }
    if (!form.title.trim()) {
      setFormError('A short title is required so we know what to look at.')
      return
    }

    setSubmitting(true)
    try {
      await tenantPortalService.createRequest({
        unit_id: Number(form.unit_id),
        category: form.category || null,
        priority: form.priority,
        title: form.title.trim(),
        description: form.description.trim() || null,
      })
      success('Maintenance request submitted')
      navigate('/tenant/requests')
    } catch (err) {
      const message = err.response?.data?.error || 'Failed to submit request'
      setFormError(message)
      toastError(message)
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <Loading />

  if (loadError) {
    return <Alert variant="error">{loadError}</Alert>
  }

  if (units.length === 0) {
    return (
      <Card>
        <p className="text-sm text-gray-700">
          You don't have any active leases on file, so there's nothing to
          submit a maintenance request against. Contact your property
          manager if you believe this is wrong.
        </p>
        <div className="mt-4">
          <Link to="/tenant">
            <Button variant="outline">Back to my units</Button>
          </Link>
        </div>
      </Card>
    )
  }

  const unitOptions = units.map((unit) => ({
    value: String(unit.id),
    label: unit.code
      ? (unit.name ? `${unit.code} — ${unit.name}` : unit.code)
      : `Unit #${unit.id}`,
  }))

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">New Maintenance Request</h1>
        {tenant ? (
          <p className="mt-1 text-sm text-gray-500">
            Submitting as {tenant.display_name}.
          </p>
        ) : null}
      </div>

      <Card>
        <form className="space-y-4" onSubmit={handleSubmit}>
          <Select
            label="Unit"
            value={form.unit_id}
            options={unitOptions}
            placeholder="Choose a unit"
            onUpdateModelValue={(value) => updateForm('unit_id', value)}
            required
          />

          <Select
            label="Category"
            value={form.category}
            options={CATEGORY_OPTIONS}
            placeholder=""
            onUpdateModelValue={(value) => updateForm('category', value)}
            helperText="Helps us route this to the right person."
          />

          <Select
            label="Priority"
            value={form.priority}
            options={PRIORITY_OPTIONS}
            placeholder=""
            onUpdateModelValue={(value) => updateForm('priority', value)}
          />

          <Input
            label="Title"
            value={form.title}
            onChange={(event) => updateForm('title', event.target.value)}
            placeholder="e.g. Kitchen sink is leaking"
            required
            maxLength={200}
          />

          <Textarea
            label="Description"
            value={form.description}
            rows={5}
            onChange={(event) => updateForm('description', event.target.value)}
            placeholder="Describe the issue, when it started, and any access notes (gate codes, pets, best times to enter)."
          />

          {formError ? (
            <Alert variant="error">{formError}</Alert>
          ) : null}

          <div className="flex justify-between items-center">
            <Link to="/tenant/requests">
              <Button variant="outline" type="button">Cancel</Button>
            </Link>
            <Button type="submit" loading={submitting}>Submit Request</Button>
          </div>
        </form>
      </Card>
    </div>
  )
}
