import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Textarea from '../../components/ui/Textarea'
import { warrantyService } from '../../../services/warranty.service'

const STATUS_LABELS = {
  defective: 'Defective',
  rma_requested: 'RMA Requested',
  shipped: 'Shipped to Vendor',
  credit_received: 'Credit Received',
  open: 'Open',
  in_review: 'In Review',
  resolved: 'Resolved',
  rejected: 'Rejected',
}

const statusClass = (status) => {
  switch (status) {
    case 'credit_received':
      return 'bg-green-100 text-green-800'
    case 'shipped':
      return 'bg-blue-100 text-blue-800'
    case 'rma_requested':
      return 'bg-yellow-100 text-yellow-800'
    case 'defective':
      return 'bg-red-100 text-red-800'
    default:
      return 'bg-gray-100 text-gray-700'
  }
}

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

const initialForm = { subject: '', description: '', invoice_id: '', vehicle_id: '' }

export default function WarrantyClaims() {
  const navigate = useNavigate()
  const [claims, setClaims] = useState([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [filters, setFilters] = useState({ status: '' })
  const [form, setForm] = useState(initialForm)

  const loadClaims = useCallback(async () => {
    setLoading(true)
    try {
      const response = await warrantyService.listCustomerClaims({ status: filters.status || undefined })
      setClaims(response || [])
    } finally {
      setLoading(false)
    }
  }, [filters.status])

  useEffect(() => {
    loadClaims()
  }, [loadClaims])

  const resetForm = () => setForm(initialForm)

  const submitClaim = async (event) => {
    event.preventDefault()
    setSubmitting(true)
    try {
      await warrantyService.submitClaim({
        subject: form.subject,
        description: form.description,
        invoice_id: form.invoice_id || null,
        vehicle_id: form.vehicle_id || null,
      })
      resetForm()
      await loadClaims()
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Warranty Claims</h1>
        <p className="mt-1 text-sm text-gray-500">Submit and track your warranty claims.</p>
      </div>

      <Card>
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Submit a new claim</h2>
        <form className="grid grid-cols-1 md:grid-cols-2 gap-4" onSubmit={submitClaim}>
          <Input
            modelValue={form.subject}
            label="Subject"
            placeholder="Brake job warranty issue"
            required
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, subject: value }))}
          />
          <Input
            modelValue={form.invoice_id}
            label="Related Invoice (optional)"
            placeholder="Invoice ID"
            type="number"
            min="1"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, invoice_id: value }))}
          />
          <Input
            modelValue={form.vehicle_id}
            label="Vehicle (optional)"
            placeholder="Vehicle ID"
            type="number"
            min="1"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, vehicle_id: value }))}
          />
          <div className="md:col-span-2">
            <Textarea
              modelValue={form.description}
              label="Description"
              placeholder="Describe the issue you're seeing"
              required
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, description: value }))}
            />
          </div>
          <div className="md:col-span-2 flex justify-end gap-2">
            <Button variant="primary" type="submit" disabled={submitting}>
              {submitting ? 'Submitting...' : 'Submit Claim'}
            </Button>
          </div>
        </form>
      </Card>

      <Card>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-900">My claims</h2>
          <select
            value={filters.status}
            className="border rounded-md px-3 py-2 text-sm text-gray-700"
            onChange={(event) => setFilters((prev) => ({ ...prev, status: event.target.value }))}
          >
            <option value="">All Statuses</option>
            <option value="defective">Defective</option>
            <option value="rma_requested">RMA Requested</option>
            <option value="shipped">Shipped to Vendor</option>
            <option value="credit_received">Credit Received</option>
          </select>
        </div>

        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading claims..." />
          </div>
        ) : claims.length === 0 ? (
          <div className="text-center py-12">
            <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
            <h3 className="mt-2 text-sm font-medium text-gray-900">No warranty claims</h3>
            <p className="mt-1 text-sm text-gray-500">Submit a claim using the form above.</p>
          </div>
        ) : (
          <div className="divide-y divide-gray-200">
            {claims.map((claim) => (
              <button
                key={claim.id}
                type="button"
                className="w-full text-left py-4 flex items-start justify-between cursor-pointer hover:bg-gray-50 px-2 rounded"
                onClick={() => navigate(`/portal/warranty-claims/${claim.id}`)}
              >
                <div>
                  <p className="text-sm font-medium text-gray-900">{claim.subject}</p>
                  <p className="text-xs text-gray-500 mt-1">Updated {formatDate(claim.updated_at)}</p>
                  <p className="text-xs text-gray-500">
                    Invoice: {claim.invoice_id || '—'} · Vehicle: {claim.vehicle_id || '—'}
                  </p>
                </div>
                <span className={`px-3 py-1 text-xs rounded-full ${statusClass(claim.status)}`}>
                  {STATUS_LABELS[claim.status] || claim.status}
                </span>
              </button>
            ))}
          </div>
        )}
      </Card>
    </div>
  )
}
