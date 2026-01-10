import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import { warrantyService } from '../../../services/warranty.service'

const STATUS_LABELS = {
  defective: 'Defective',
  rma_requested: 'RMA Requested',
  shipped: 'Shipped to Vendor',
  credit_received: 'Credit Received',
}

const STATUS_VARIANTS = {
  defective: 'danger',
  rma_requested: 'warning',
  shipped: 'info',
  credit_received: 'success',
}

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount || 0)
}

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'defective', label: STATUS_LABELS.defective },
  { value: 'rma_requested', label: STATUS_LABELS.rma_requested },
  { value: 'shipped', label: STATUS_LABELS.shipped },
  { value: 'credit_received', label: STATUS_LABELS.credit_received },
]

export default function WarrantyClaims() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [claims, setClaims] = useState([])
  const [filters, setFilters] = useState({ status: '' })
  const [error, setError] = useState('')
  const [showModal, setShowModal] = useState(false)
  const [selectedClaim, setSelectedClaim] = useState(null)
  const [form, setForm] = useState({ status: '', financial_impact: '0', credit_received_amount: '' })
  const [saving, setSaving] = useState(false)

  const loadClaims = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await warrantyService.listClaims({ status: filters.status || undefined })
      setClaims(Array.isArray(response) ? response : [])
    } catch (err) {
      console.error('Failed to load warranty claims', err)
      setError('Unable to load warranty claims.')
    } finally {
      setLoading(false)
    }
  }, [filters.status])

  useEffect(() => {
    loadClaims()
  }, [loadClaims])

  const totalsByStatus = useMemo(() => {
    return claims.reduce((acc, claim) => {
      const key = claim.status || 'unknown'
      acc[key] = (acc[key] || 0) + 1
      return acc
    }, {})
  }, [claims])

  const openModal = (claim) => {
    setSelectedClaim(claim)
    setForm({
      status: claim.status || 'defective',
      financial_impact: claim.financial_impact?.toString() ?? '0',
      credit_received_amount: claim.credit_received_amount?.toString() ?? '',
    })
    setShowModal(true)
  }

  const saveClaim = async () => {
    if (!selectedClaim) return
    setSaving(true)
    try {
      const payload = {
        status: form.status,
        financial_impact: parseFloat(form.financial_impact || '0'),
      }
      if (form.credit_received_amount !== '') {
        payload.credit_received_amount = parseFloat(form.credit_received_amount)
      }
      const updated = await warrantyService.updateClaimStatus(selectedClaim.id, payload)
      setClaims((prev) => prev.map((claim) => (claim.id === updated.id ? updated : claim)))
      setShowModal(false)
    } catch (err) {
      console.error('Failed to update warranty claim', err)
      setError('Unable to update warranty claim.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Vendor Warranty Claims</h1>
          <p className="mt-1 text-sm text-gray-500">Track defective inventory through RMA, shipping, and credit recovery.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => navigate('/cp/inventory/alerts')}>Inventory alerts</Button>
          <Button variant="outline" onClick={loadClaims}>Refresh</Button>
        </div>
      </div>

      <Card>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Status flow</h2>
            <p className="text-sm text-gray-500">Defective → RMA Requested → Shipped → Credit Received.</p>
          </div>
          <div className="flex flex-wrap gap-3">
            {statusOptions.slice(1).map((option) => (
              <div key={option.value} className="flex items-center gap-2 text-sm text-gray-600">
                <Badge variant={STATUS_VARIANTS[option.value]}>{option.label}</Badge>
                <span className="text-gray-500">{totalsByStatus[option.value] || 0}</span>
              </div>
            ))}
          </div>
        </div>
      </Card>

      <Card>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <h2 className="text-lg font-semibold text-gray-900">Claims</h2>
          <select
            value={filters.status}
            className="border rounded-md px-3 py-2 text-sm text-gray-700"
            onChange={(event) => setFilters((prev) => ({ ...prev, status: event.target.value }))}
          >
            {statusOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        {error ? (
          <div className="mt-4">
            <Alert variant="danger">{error}</Alert>
          </div>
        ) : null}

        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading claims..." />
          </div>
        ) : claims.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No warranty claims found for this filter.</div>
        ) : (
          <div className="overflow-x-auto mt-4">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Claim</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Financial Impact</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Credit Received</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Update</th>
                  <th className="px-6 py-3" />
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {claims.map((claim) => (
                  <tr key={claim.id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      <div className="font-medium">{claim.subject}</div>
                      <div className="text-xs text-gray-500">Invoice {claim.invoice_id || '—'} · Vehicle {claim.vehicle_id || '—'}</div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">#{claim.customer_id}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                      <Badge variant={STATUS_VARIANTS[claim.status] || 'secondary'}>
                        {STATUS_LABELS[claim.status] || claim.status}
                      </Badge>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {formatCurrency(claim.financial_impact)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {claim.credit_received_amount ? formatCurrency(claim.credit_received_amount) : '—'}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{formatDate(claim.updated_at)}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm">
                      <Button variant="outline" size="sm" onClick={() => openModal(claim)}>Update</Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={showModal}
        title={selectedClaim ? `Update Claim #${selectedClaim.id}` : 'Update Claim'}
        onClose={() => setShowModal(false)}
      >
        {selectedClaim ? (
          <div className="space-y-4">
            <div className="rounded-md border border-gray-200 bg-gray-50 p-4">
              <p className="text-sm font-medium text-gray-900">{selectedClaim.subject}</p>
              <p className="text-xs text-gray-500">{selectedClaim.description}</p>
            </div>

            <label className="block text-sm font-medium text-gray-700">
              Status
              <select
                value={form.status}
                onChange={(event) => setForm((prev) => ({ ...prev, status: event.target.value }))}
                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
              >
                {statusOptions.slice(1).map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>

            <Input
              label="Financial impact (required)"
              type="number"
              min="0"
              step="0.01"
              modelValue={form.financial_impact}
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, financial_impact: value }))}
            />

            <Input
              label="Credit received amount"
              type="number"
              min="0"
              step="0.01"
              modelValue={form.credit_received_amount}
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, credit_received_amount: value }))}
              placeholder="Enter credit amount if received"
            />

            <div className="flex justify-end gap-2">
              <Button variant="ghost" onClick={() => setShowModal(false)}>Cancel</Button>
              <Button variant="primary" onClick={saveClaim} disabled={saving}>
                {saving ? 'Saving...' : 'Save'}
              </Button>
            </div>
          </div>
        ) : null}
      </Modal>
    </div>
  )
}
