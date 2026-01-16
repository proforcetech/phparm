import { useEffect, useMemo, useState } from 'react'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import Textarea from '../../components/ui/Textarea'
import leaveRequestsService from '../../../services/leave-requests.service'

const leaveTypes = [
  { value: 'vacation', label: 'Vacation' },
  { value: 'sick', label: 'Sick' },
  { value: 'unpaid', label: 'Unpaid' },
  { value: 'bereavement', label: 'Bereavement' },
]

const statusVariant = (status) => {
  if (status === 'approved') return 'success'
  if (status === 'rejected') return 'danger'
  return 'warning'
}

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleDateString()
}

export default function LeaveRequests() {
  const [requests, setRequests] = useState([])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [form, setForm] = useState({
    start_date: '',
    end_date: '',
    type: 'vacation',
    reason: '',
  })

  const columns = useMemo(() => ([
    { key: 'dates', label: 'Dates' },
    { key: 'type', label: 'Type' },
    { key: 'status', label: 'Status' },
    { key: 'reviewer', label: 'Reviewer' },
    { key: 'notes', label: 'Notes' },
  ]), [])

  const fetchRequests = async () => {
    setLoading(true)
    setError('')
    try {
      const response = await leaveRequestsService.listMine({ per_page: 50 })
      setRequests(response.data)
    } catch (fetchError) {
      setError(fetchError.response?.data?.message || 'Unable to load leave requests.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchRequests()
  }, [])

  const updateForm = (key, value) => {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    setFormError('')

    if (!form.start_date || !form.end_date || !form.type) {
      setFormError('Please fill in the start date, end date, and leave type.')
      return
    }

    setSaving(true)
    try {
      await leaveRequestsService.create({
        start_date: form.start_date,
        end_date: form.end_date,
        type: form.type,
        reason: form.reason || null,
      })
      setForm({ start_date: '', end_date: '', type: 'vacation', reason: '' })
      fetchRequests()
    } catch (submitError) {
      setFormError(submitError.response?.data?.message || 'Unable to submit leave request.')
    } finally {
      setSaving(false)
    }
  }

  const cellRenderers = {
    dates: (row) => (
      <div className="text-sm text-gray-900">
        {formatDate(row.start_date)} → {formatDate(row.end_date)}
      </div>
    ),
    type: (row) => (
      <span className="text-sm text-gray-900">{row.type ? row.type.charAt(0).toUpperCase() + row.type.slice(1) : '—'}</span>
    ),
    status: (row) => (
      <Badge variant={statusVariant(row.status)}>
        {row.status ? row.status.charAt(0).toUpperCase() + row.status.slice(1) : 'Pending'}
      </Badge>
    ),
    reviewer: (row) => (
      <span className="text-sm text-gray-600">{row.reviewer_name || '—'}</span>
    ),
    notes: (row) => (
      <span className="text-sm text-gray-600">{row.reviewer_notes || row.reason || '—'}</span>
    ),
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Leave Requests</h1>
        <p className="mt-1 text-sm text-gray-500">
          Submit time off requests and track approval status.
        </p>
      </div>

      <Card title="Request time off">
        <form className="space-y-4" onSubmit={handleSubmit}>
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label="Start date"
              type="date"
              value={form.start_date}
              onChange={(event) => updateForm('start_date', event.target.value)}
              required
            />
            <Input
              label="End date"
              type="date"
              value={form.end_date}
              onChange={(event) => updateForm('end_date', event.target.value)}
              required
            />
          </div>
          <Select
            label="Leave type"
            value={form.type}
            options={leaveTypes}
            onUpdateModelValue={(value) => updateForm('type', value)}
          />
          <Textarea
            label="Reason (optional)"
            value={form.reason}
            rows={4}
            onChange={(event) => updateForm('reason', event.target.value)}
            placeholder="Share any context for your manager."
          />
          {formError ? <p className="text-sm text-red-600">{formError}</p> : null}
          <div className="flex justify-end">
            <Button type="submit" loading={saving}>Submit request</Button>
          </div>
        </form>
      </Card>

      <Card title="My requests">
        {error ? <p className="mb-4 text-sm text-red-600">{error}</p> : null}
        <Table
          columns={columns}
          data={requests}
          loading={loading}
          cellRenderers={cellRenderers}
          hoverable={false}
        />
      </Card>
    </div>
  )
}
