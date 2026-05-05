import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import estimateService from '../../../services/estimate.service'
import { useToast } from '../../stores/toast'

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount || 0)
}

const formatDate = (date) => {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(date))
}

const formatStatus = (status) => {
  if (!status) return ''
  return status
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

const getStatusVariant = (status) => {
  const variants = {
    sent: 'info',
    pending: 'default',
    approved: 'success',
    rejected: 'danger',
    expired: 'warning',
    converted: 'success',
  }
  return variants[status?.toLowerCase()] || 'default'
}

const isExpired = (expirationDate) => {
  if (!expirationDate) return false
  return new Date(expirationDate) < new Date()
}

const isExpiringSoon = (expirationDate) => {
  if (!expirationDate) return false
  const expiry = new Date(expirationDate)
  const now = new Date()
  const daysUntilExpiry = (expiry - now) / (1000 * 60 * 60 * 24)
  return daysUntilExpiry > 0 && daysUntilExpiry <= 7
}

export default function EstimateDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [loading, setLoading] = useState(true)
  const [converting, setConverting] = useState(false)
  const [estimate, setEstimate] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [showConvertModal, setShowConvertModal] = useState(false)
  const [convertForm, setConvertForm] = useState({
    issue_date: new Date().toISOString().split('T')[0],
    due_date: '',
  })
  const [showRejectModal, setShowRejectModal] = useState(false)
  const [rejectReason, setRejectReason] = useState('')
  const [rejecting, setRejecting] = useState(false)
  const [rejectError, setRejectError] = useState(null)

  const loadEstimate = useCallback(async () => {
    if (!id) return
    setLoading(true)
    setLoadError(null)
    try {
      const response = await estimateService.getEstimate(id)
      setEstimate(response.data)
    } catch (fetchError) {
      console.error('Failed to load estimate:', fetchError)
      setLoadError(fetchError.response?.data?.message || 'Failed to load estimate')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    loadEstimate()
  }, [loadEstimate])

  const approveEstimate = async () => {
    if (!estimate) return
    try {
      await estimateService.approveEstimate(estimate.id)
      success('Estimate approved successfully')
      loadEstimate()
    } catch (approveError) {
      console.error('Failed to approve estimate:', approveError)
      error(approveError.response?.data?.message || 'Failed to approve estimate')
    }
  }

  const openRejectModal = () => {
    setRejectReason('')
    setRejectError(null)
    setShowRejectModal(true)
  }

  const submitReject = async () => {
    if (!estimate) return
    const reason = rejectReason.trim()
    if (reason.length < 5) {
      setRejectError('Rejection reason must be at least 5 characters.')
      return
    }

    setRejecting(true)
    setRejectError(null)
    try {
      await estimateService.declineEstimate(estimate.id, reason)
      success('Estimate rejected')
      setShowRejectModal(false)
      loadEstimate()
    } catch (declineError) {
      console.error('Failed to reject estimate:', declineError)
      const message = declineError.response?.data?.message || 'Failed to reject estimate'
      setRejectError(message)
      error(message)
    } finally {
      setRejecting(false)
    }
  }

  const expireEstimate = async () => {
    if (!estimate) return
    if (!window.confirm('Mark this estimate as expired?')) return

    try {
      await estimateService.expireEstimate(estimate.id)
      success('Estimate marked as expired')
      loadEstimate()
    } catch (expireError) {
      console.error('Failed to expire estimate:', expireError)
      error(expireError.response?.data?.message || 'Failed to expire estimate')
    }
  }

  const confirmConvert = async () => {
    if (!estimate) return
    setConverting(true)
    try {
      const response = await estimateService.convertToInvoice(estimate.id, {
        issue_date: convertForm.issue_date,
        due_date: convertForm.due_date || null,
      })

      success('Estimate converted to invoice successfully')
      setShowConvertModal(false)

      if (response.data?.id) {
        navigate(`/cp/invoices/${response.data.id}`)
      }
    } catch (convertError) {
      console.error('Failed to convert estimate:', convertError)
      error(convertError.response?.data?.message || 'Failed to convert estimate')
    } finally {
      setConverting(false)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-12">
        <Loading size="xl" text="Loading estimate..." />
      </div>
    )
  }

  if (loadError) {
    return (
      <Alert variant="danger" className="mb-6">
        {loadError}
      </Alert>
    )
  }

  if (!estimate) {
    return (
      <div className="text-center py-12">
        <h3 className="text-lg font-medium text-gray-900">Estimate not found</h3>
        <p className="mt-2 text-gray-500">The estimate you&apos;re looking for doesn&apos;t exist.</p>
        <div className="mt-4">
          <Link to="/cp/estimates">
            <Button>Back to Estimates</Button>
          </Link>
        </div>
      </div>
    )
  }

  const isPendingOrSent = ['pending', 'sent'].includes(estimate.status)

  return (
    <div>
      <div className="mb-6">
        <div className="flex items-center justify-between mb-2">
          <div className="flex items-center gap-4">
            <Button variant="ghost" onClick={() => navigate(-1)}>
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 19l-7-7 7-7" />
              </svg>
            </Button>
            <div>
              <h1 className="text-2xl font-bold text-gray-900">Estimate {estimate.number}</h1>
              <p className="text-sm text-gray-500">Created {formatDate(estimate.created_at)}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            {estimate.is_mobile ? (
              <Badge variant="warning" size="lg">Mobile repair</Badge>
            ) : null}
            <Badge variant={getStatusVariant(estimate.status)} size="lg">
              {formatStatus(estimate.status)}
            </Badge>
          </div>
        </div>

        <div className="flex flex-wrap gap-2 mt-4">
          {isPendingOrSent ? (
            <Button variant="primary" onClick={approveEstimate}>Approve</Button>
          ) : null}
          {isPendingOrSent ? (
            <Button variant="danger" onClick={openRejectModal}>Reject</Button>
          ) : null}
          {estimate.status === 'approved' ? (
            <Button onClick={() => setShowConvertModal(true)}>Convert to Invoice</Button>
          ) : null}
          {isPendingOrSent ? (
            <Button variant="outline" onClick={expireEstimate}>Mark as Expired</Button>
          ) : null}
          <Button
            variant="outline"
            onClick={() => navigate(`/cp/estimates/${estimate.id}/edit`)}
          >
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            Edit
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          <Card>
            <div className="mb-4">
              <h3 className="text-lg font-medium text-gray-900">Customer & Vehicle</h3>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-sm font-medium text-gray-500">Customer</label>
                <p className="mt-1 text-sm text-gray-900">
                  <Link
                    to={`/cp/customers/${estimate.customer_id}`}
                    className="text-primary-600 hover:text-primary-800"
                  >
                    Customer #{estimate.customer_id}
                  </Link>
                </p>
              </div>
              <div>
                <label className="text-sm font-medium text-gray-500">Vehicle</label>
                <p className="mt-1 text-sm text-gray-900">
                  <Link
                    to={`/cp/vehicles/${estimate.vehicle_id}`}
                    className="text-primary-600 hover:text-primary-800"
                  >
                    Vehicle #{estimate.vehicle_id}
                  </Link>
                </p>
              </div>
              {estimate.technician_id ? (
                <div>
                  <label className="text-sm font-medium text-gray-500">Technician</label>
                  <p className="mt-1 text-sm text-gray-900">Technician #{estimate.technician_id}</p>
                </div>
              ) : null}
              {estimate.expiration_date ? (
                <div>
                  <label className="text-sm font-medium text-gray-500">Expiration Date</label>
                  <p
                    className={`mt-1 text-sm ${isExpired(estimate.expiration_date) ? 'text-red-600 font-medium' : 'text-gray-900'}`}
                  >
                    {formatDate(estimate.expiration_date)}
                    {isExpired(estimate.expiration_date) ? (
                      <span className="text-xs"> (Expired)</span>
                    ) : null}
                    {!isExpired(estimate.expiration_date) && isExpiringSoon(estimate.expiration_date) ? (
                      <span className="text-xs text-amber-600"> (Expiring soon)</span>
                    ) : null}
                  </p>
                </div>
              ) : null}
            </div>
          </Card>

          {(estimate.customer_notes || estimate.internal_notes) ? (
            <Card>
              <div className="mb-4">
                <h3 className="text-lg font-medium text-gray-900">Notes</h3>
              </div>
              <div className="space-y-4">
                {estimate.customer_notes ? (
                  <div>
                    <label className="text-sm font-medium text-gray-500">Customer Notes</label>
                    <p className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{estimate.customer_notes}</p>
                  </div>
                ) : null}
                {estimate.internal_notes ? (
                  <div>
                    <label className="text-sm font-medium text-gray-500">Internal Notes</label>
                    <p className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{estimate.internal_notes}</p>
                  </div>
                ) : null}
              </div>
            </Card>
          ) : null}
        </div>

        <div>
          <Card>
            <div className="mb-4">
              <h3 className="text-lg font-medium text-gray-900">Summary</h3>
            </div>
            <div className="space-y-3">
              <div className="flex justify-between">
                <span className="text-sm text-gray-600">Subtotal</span>
                <span className="text-sm font-medium text-gray-900">{formatCurrency(estimate.subtotal)}</span>
              </div>
              {estimate.call_out_fee > 0 ? (
                <div className="flex justify-between">
                  <span className="text-sm text-gray-600">Call-out Fee</span>
                  <span className="text-sm font-medium text-gray-900">{formatCurrency(estimate.call_out_fee)}</span>
                </div>
              ) : null}
              {estimate.mileage_total > 0 ? (
                <div className="flex justify-between">
                  <span className="text-sm text-gray-600">Mileage</span>
                  <span className="text-sm font-medium text-gray-900">{formatCurrency(estimate.mileage_total)}</span>
                </div>
              ) : null}
              {estimate.discounts > 0 ? (
                <div className="flex justify-between text-green-600">
                  <span className="text-sm">Discounts</span>
                  <span className="text-sm font-medium">-{formatCurrency(estimate.discounts)}</span>
                </div>
              ) : null}
              <div className="flex justify-between">
                <span className="text-sm text-gray-600">Tax</span>
                <span className="text-sm font-medium text-gray-900">{formatCurrency(estimate.tax)}</span>
              </div>
              <div className="border-t border-gray-200 pt-3 flex justify-between">
                <span className="text-base font-medium text-gray-900">Grand Total</span>
                <span className="text-base font-bold text-gray-900">{formatCurrency(estimate.grand_total)}</span>
              </div>
            </div>
          </Card>
        </div>
      </div>

      <Modal
        open={showRejectModal}
        onClose={() => (rejecting ? null : setShowRejectModal(false))}
        title="Reject Estimate"
        content={(
          <div className="space-y-3">
            <p className="text-sm text-gray-600">
              Provide a reason for rejecting estimate #{estimate?.number}. This is recorded
              on the estimate and visible to staff.
            </p>
            <div>
              <label htmlFor="reject-reason" className="block text-sm font-medium text-gray-700">
                Reason <span className="text-red-500">*</span>
              </label>
              <textarea
                id="reject-reason"
                value={rejectReason}
                onChange={(event) => setRejectReason(event.target.value)}
                rows={4}
                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                placeholder="e.g. Customer chose to defer the work"
                disabled={rejecting}
                autoFocus
              />
              <p className="mt-1 text-xs text-gray-500">Minimum 5 characters.</p>
            </div>
            {rejectError ? (
              <Alert variant="danger">{rejectError}</Alert>
            ) : null}
          </div>
        )}
        footer={(
          <div className="flex gap-3 justify-end">
            <Button
              variant="outline"
              onClick={() => setShowRejectModal(false)}
              disabled={rejecting}
            >
              Cancel
            </Button>
            <Button
              variant="danger"
              onClick={submitReject}
              disabled={rejecting || rejectReason.trim().length < 5}
              loading={rejecting}
            >
              {rejecting ? 'Rejecting...' : 'Reject Estimate'}
            </Button>
          </div>
        )}
      />

      <Modal
        open={showConvertModal}
        onClose={() => setShowConvertModal(false)}
        title="Convert to Invoice"
        content={(
          <div className="space-y-4">
            <p className="text-sm text-gray-600">
              Convert estimate #{estimate?.number} to an invoice?
            </p>
            <div>
              <label className="block text-sm font-medium text-gray-700">Issue Date *</label>
              <Input
                value={convertForm.issue_date}
                type="date"
                className="mt-1"
                required
                onUpdateModelValue={(value) => setConvertForm((prev) => ({ ...prev, issue_date: value }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Due Date (Optional)</label>
              <Input
                value={convertForm.due_date}
                type="date"
                className="mt-1"
                onUpdateModelValue={(value) => setConvertForm((prev) => ({ ...prev, due_date: value }))}
              />
            </div>
          </div>
        )}
        footer={(
          <div className="flex gap-3 justify-end">
            <Button variant="outline" onClick={() => setShowConvertModal(false)}>Cancel</Button>
            <Button
              onClick={confirmConvert}
              disabled={!convertForm.issue_date || converting}
              loading={converting}
            >
              {converting ? 'Converting...' : 'Convert to Invoice'}
            </Button>
          </div>
        )}
      />
    </div>
  )
}
