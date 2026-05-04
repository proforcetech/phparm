import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import ticketsService from '../../../services/tickets.service'
import { useToast } from '../../stores/toast.jsx'

const PRIORITY_OPTIONS = [
  { value: 'low', label: 'Low' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'High' },
  { value: 'urgent', label: 'Urgent' },
]

export default function TicketCreate() {
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [categories, setCategories] = useState([])
  const [queues, setQueues] = useState([])
  const [refsLoading, setRefsLoading] = useState(true)

  const [form, setForm] = useState({
    title: '',
    description: '',
    category_id: '',
    queue_id: '',
    priority: 'normal',
    site_id: '',
    site_asset_id: '',
    reporter_email: '',
    reporter_phone: '',
  })
  const [errors, setErrors] = useState({})
  const [saving, setSaving] = useState(false)
  const [apiError, setApiError] = useState('')

  useEffect(() => {
    let cancelled = false
    Promise.all([
      ticketsService.listCategories({ limit: 200 }).catch(() => null),
      ticketsService.listQueues({ limit: 200 }).catch(() => null),
    ]).then(([cRes, qRes]) => {
      if (cancelled) return
      setCategories(Array.isArray(cRes) ? cRes : cRes?.data ?? [])
      setQueues(Array.isArray(qRes) ? qRes : qRes?.data ?? [])
    }).finally(() => { if (!cancelled) setRefsLoading(false) })
    return () => { cancelled = true }
  }, [])

  const updateField = (field) => (eOrValue) => {
    const value = eOrValue && eOrValue.target !== undefined ? eOrValue.target.value : eOrValue
    setForm((prev) => ({ ...prev, [field]: value }))
    if (errors[field]) setErrors((prev) => ({ ...prev, [field]: null }))
  }

  const validate = () => {
    const errs = {}
    if (!form.title.trim()) errs.title = 'Title is required'
    setErrors(errs)
    return Object.keys(errs).length === 0
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setApiError('')
    if (!validate()) return
    setSaving(true)
    try {
      const payload = {
        title: form.title.trim(),
        subject: form.title.trim(),
        description: form.description.trim() || undefined,
        category_id: form.category_id || undefined,
        queue_id: form.queue_id || undefined,
        priority: form.priority,
        site_id: form.site_id.trim() || undefined,
        site_asset_id: form.site_asset_id.trim() || undefined,
        reporter_email: form.reporter_email.trim() || undefined,
        reporter_phone: form.reporter_phone.trim() || undefined,
      }
      const res = await ticketsService.create(payload)
      const created = res?.data ?? res
      const newId = created?.id ?? created?.ticket?.id
      success('Ticket created')
      if (newId) {
        navigate(`/cp/tickets/${newId}`)
      } else {
        navigate('/cp/tickets')
      }
    } catch (e2) {
      const msg = e2?.response?.data?.message || e2?.message || 'Failed to create ticket'
      setApiError(msg)
      error(msg)
    } finally {
      setSaving(false)
    }
  }

  if (refsLoading) {
    return (
      <div className="py-10 flex justify-center"><Loading text="Loading form..." /></div>
    )
  }

  const categoryOptions = [{ value: '', label: 'No category' }, ...categories.map((c) => ({ value: String(c.id), label: c.name || `#${c.id}` }))]
  const queueOptions = [{ value: '', label: 'No queue' }, ...queues.map((q) => ({ value: String(q.id), label: q.name || `#${q.id}` }))]

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">New ticket</h1>
          <p className="mt-1 text-sm text-gray-500">Create a new service request.</p>
        </div>
        <Button variant="ghost" onClick={() => navigate('/cp/tickets')}>Cancel</Button>
      </div>

      {apiError && <Alert variant="danger" onClose={() => setApiError('')}>{apiError}</Alert>}

      <Card>
        <form onSubmit={handleSubmit} className="space-y-4">
          <Input
            label="Title"
            required
            value={form.title}
            onChange={updateField('title')}
            error={errors.title || ''}
            placeholder="Short summary of the issue"
          />

          <Textarea
            label="Description"
            rows={5}
            value={form.description}
            onChange={updateField('description')}
            placeholder="Provide details about the issue, what was tried, expected behavior, etc."
          />

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Select
              label="Category"
              value={form.category_id}
              onChange={updateField('category_id')}
              options={categoryOptions}
              placeholder=""
            />
            <Select
              label="Queue"
              value={form.queue_id}
              onChange={updateField('queue_id')}
              options={queueOptions}
              placeholder=""
            />
            <Select
              label="Priority"
              value={form.priority}
              onChange={updateField('priority')}
              options={PRIORITY_OPTIONS}
              placeholder=""
            />
            <Input
              label="Site ID"
              value={form.site_id}
              onChange={updateField('site_id')}
              placeholder="Optional site identifier"
            />
            <Input
              label="Site asset ID"
              value={form.site_asset_id}
              onChange={updateField('site_asset_id')}
              placeholder="Optional asset identifier"
            />
            <Input
              label="Reporter email"
              type="email"
              value={form.reporter_email}
              onChange={updateField('reporter_email')}
              placeholder="reporter@example.com"
            />
            <Input
              label="Reporter phone"
              value={form.reporter_phone}
              onChange={updateField('reporter_phone')}
              placeholder="(optional)"
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" type="button" onClick={() => navigate('/cp/tickets')}>Cancel</Button>
            <Button type="submit" loading={saving} disabled={saving}>
              {saving ? 'Creating...' : 'Create ticket'}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  )
}
