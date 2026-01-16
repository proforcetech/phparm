import { useCallback, useEffect, useMemo, useState } from 'react'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import Textarea from '../../components/ui/Textarea'
import leaveRequestsService from '../../../services/leave-requests.service'

const perPage = 25

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
]

const statusVariant = (status) => {
  if (status === 'approved') return 'success'
  if (status === 'rejected') return 'danger'
  return 'warning'
}

const formatDate = (value, withTime = false) => {
  if (!value) return '—'
  const date = new Date(value)
  return withTime ? date.toLocaleString() : date.toLocaleDateString()
}

export default function LeaveRequestsAdmin() {
  const [requests, setRequests] = useState([])
  const [total, setTotal] = useState(0)
  const [currentPage, setCurrentPage] = useState(1)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [filters, setFilters] = useState({
    search: '',
    status: '',
  })

  const [reviewModalOpen, setReviewModalOpen] = useState(false)
  const [selectedRequest, setSelectedRequest] = useState(null)
  const [reviewAction, setReviewAction] = useState('approve')
  const [reviewNotes, setReviewNotes] = useState('')
  const [reviewError, setReviewError] = useState('')
  const [reviewing, setReviewing] = useState(false)

  const columns = useMemo(() => ([
    { key: 'employee', label: 'Employee' },
    { key: 'dates', label: 'Dates' },
    { key: 'type', label: 'Type' },
    { key: 'reason', label: 'Reason' },
    { key: 'status', label: 'Status' },
    { key: 'submitted', label: 'Submitted' },
  ]), [])

  const fetchRequests = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await leaveRequestsService.list({
        search: filters.search || undefined,
        status: filters.status || undefined,
        page: currentPage,
        per_page: perPage,
      })
      setRequests(response.data)
      setTotal(response.pagination.total)
    } catch (fetchError) {
      setError(fetchError.response?.data?.message || 'Unable to load leave requests.')
    } finally {
      setLoading(false)
    }
  }, [currentPage, filters.search, filters.status])

  useEffect(() => {
    fetchRequests()
  }, [fetchRequests])

  const updateFilter = (key, value) => {
    setCurrentPage(1)
    setFilters((prev) => ({ ...prev, [key]: value }))
  }

  const openReviewModal = (action, request) => {
    setSelectedRequest(request)
    setReviewAction(action)
    setReviewNotes('')
    setReviewError('')
    setReviewModalOpen(true)
  }

  const closeReviewModal = () => {
    if (reviewing) return
    setReviewModalOpen(false)
    setSelectedRequest(null)
  }

  const submitReview = async () => {
    if (!selectedRequest) return

    setReviewing(true)
    setReviewError('')
    try {
      if (reviewAction === 'approve') {
        await leaveRequestsService.approve(selectedRequest.id, { notes: reviewNotes })
      } else {
        await leaveRequestsService.reject(selectedRequest.id, { notes: reviewNotes })
      }
      setReviewModalOpen(false)
      setSelectedRequest(null)
      fetchRequests()
    } catch (submitError) {
      setReviewError(submitError.response?.data?.message || 'Unable to submit review.')
    } finally {
      setReviewing(false)
    }
  }

  const cellRenderers = {
    employee: (row) => (
      <div>
        <p className="text-sm font-medium text-gray-900">{row.user_name || '—'}</p>
        <p className="text-xs text-gray-500">{row.reviewer_name ? `Reviewed by ${row.reviewer_name}` : 'Awaiting review'}</p>
      </div>
    ),
    dates: (row) => (
      <span className="text-sm text-gray-900">
        {formatDate(row.start_date)} → {formatDate(row.end_date)}
      </span>
    ),
    type: (row) => (
      <span className="text-sm text-gray-900">{row.type ? row.type.charAt(0).toUpperCase() + row.type.slice(1) : '—'}</span>
    ),
    reason: (row) => (
      <span className="text-sm text-gray-600">{row.reason || '—'}</span>
    ),
    status: (row) => (
      <Badge variant={statusVariant(row.status)}>
        {row.status ? row.status.charAt(0).toUpperCase() + row.status.slice(1) : 'Pending'}
      </Badge>
    ),
    submitted: (row) => (
      <span className="text-sm text-gray-600">{formatDate(row.created_at, true)}</span>
    ),
  }

  const renderActions = (row) => {
    if (row.status !== 'pending') {
      return <span className="text-xs text-gray-400">Reviewed</span>
    }

    return (
      <div className="flex items-center gap-2">
        <Button size="xs" onClick={(event) => { event.stopPropagation(); openReviewModal('approve', row) }}>
          Approve
        </Button>
        <Button
          size="xs"
          variant="danger"
          onClick={(event) => { event.stopPropagation(); openReviewModal('reject', row) }}
        >
          Reject
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Leave Requests</h1>
        <p className="mt-1 text-sm text-gray-500">Review employee leave submissions and record decisions.</p>
      </div>

      <Card title="Filters">
        <div className="grid gap-4 md:grid-cols-2">
          <Input
            label="Search"
            value={filters.search}
            placeholder="Search employee or reason"
            onChange={(event) => updateFilter('search', event.target.value)}
          />
          <Select
            label="Status"
            value={filters.status}
            options={statusOptions}
            onUpdateModelValue={(value) => updateFilter('status', value)}
            placeholder={null}
          />
        </div>
      </Card>

      <Card title="Requests">
        {error ? <p className="mb-4 text-sm text-red-600">{error}</p> : null}
        <Table
          columns={columns}
          data={requests}
          loading={loading}
          currentPage={currentPage}
          perPage={perPage}
          total={total}
          pagination
          onPageChange={setCurrentPage}
          cellRenderers={cellRenderers}
          renderActions={renderActions}
          hoverable={false}
        />
      </Card>

      <Modal
        open={reviewModalOpen}
        onClose={closeReviewModal}
        title={reviewAction === 'approve' ? 'Approve leave request' : 'Reject leave request'}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="ghost" onClick={closeReviewModal} disabled={reviewing}>Cancel</Button>
            <Button
              variant={reviewAction === 'approve' ? 'primary' : 'danger'}
              onClick={submitReview}
              loading={reviewing}
            >
              {reviewAction === 'approve' ? 'Approve' : 'Reject'}
            </Button>
          </div>
        )}
      >
        <div className="space-y-4">
          <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
            <div className="font-semibold text-gray-900">{selectedRequest?.user_name || 'Employee'}</div>
            <div className="mt-1">{formatDate(selectedRequest?.start_date)} → {formatDate(selectedRequest?.end_date)}</div>
            <div className="mt-1">{selectedRequest?.type ? selectedRequest.type.charAt(0).toUpperCase() + selectedRequest.type.slice(1) : 'Leave'}</div>
            <div className="mt-2 text-gray-600">{selectedRequest?.reason || 'No reason provided.'}</div>
          </div>

          <Textarea
            label={reviewAction === 'approve' ? 'Approval notes (optional)' : 'Rejection reason'}
            value={reviewNotes}
            rows={4}
            onChange={(event) => setReviewNotes(event.target.value)}
            placeholder={reviewAction === 'approve' ? 'Add any notes for the employee.' : 'Share why this request cannot be approved.'}
          />
          {reviewError ? <p className="text-sm text-red-600">{reviewError}</p> : null}
        </div>
      </Modal>
    </div>
  )
}
