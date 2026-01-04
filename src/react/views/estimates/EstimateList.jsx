import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import estimateService from '../../../services/estimate.service'
import { useToast } from '../../stores/toast'

const pageSize = 50

const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'sent', label: 'Sent' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'expired', label: 'Expired' },
  { value: 'converted', label: 'Converted' },
]

const columns = [
  { key: 'number', label: 'Estimate #' },
  { key: 'customer_id', label: 'Customer' },
  { key: 'vehicle_id', label: 'Vehicle' },
  { key: 'status', label: 'Status' },
  { key: 'grand_total', label: 'Total' },
  { key: 'expiration_date', label: 'Expires' },
  { key: 'created_at', label: 'Created' },
]

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
    pending: 'default',
    sent: 'info',
    approved: 'success',
    rejected: 'danger',
    expired: 'warning',
    converted: 'success',
  }
  return variants[status?.toLowerCase()] || 'default'
}

const isExpiringSoon = (expirationDate) => {
  if (!expirationDate) return false
  const expiry = new Date(expirationDate)
  const now = new Date()
  const daysUntilExpiry = (expiry - now) / (1000 * 60 * 60 * 24)
  return daysUntilExpiry > 0 && daysUntilExpiry <= 7
}

export default function EstimateList() {
  const navigate = useNavigate()
  const { error, success } = useToast()

  const [loading, setLoading] = useState(false)
  const [estimates, setEstimates] = useState([])
  const [currentPage, setCurrentPage] = useState(1)
  const [filters, setFilters] = useState({
    status: '',
    customer_id: '',
    vehicle_id: '',
    term: '',
  })
  const [showConvertModal, setShowConvertModal] = useState(false)
  const [selectedEstimate, setSelectedEstimate] = useState(null)
  const [converting, setConverting] = useState(false)
  const [convertForm, setConvertForm] = useState({
    issue_date: new Date().toISOString().split('T')[0],
    due_date: '',
  })

  const debounceRef = useRef(null)

  const loadEstimates = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        ...filters,
        limit: pageSize,
        offset: (currentPage - 1) * pageSize,
      }

      Object.keys(params).forEach((key) => {
        if (params[key] === '' || params[key] === null) {
          delete params[key]
        }
      })

      const response = await estimateService.getEstimates(params)
      setEstimates(response.data || [])
    } catch (loadError) {
      console.error('Failed to load estimates:', loadError)
      error('Failed to load estimates')
    } finally {
      setLoading(false)
    }
  }, [currentPage, error, filters])

  useEffect(() => {
    loadEstimates()
  }, [loadEstimates])

  useEffect(() => () => {
    if (debounceRef.current) {
      clearTimeout(debounceRef.current)
    }
  }, [])

  const scheduleLoad = (delay = 500) => {
    if (debounceRef.current) {
      clearTimeout(debounceRef.current)
    }
    debounceRef.current = setTimeout(() => {
      loadEstimates()
    }, delay)
  }

  const updateFilter = (key, value, { immediate = false } = {}) => {
    setCurrentPage(1)
    setFilters((prev) => ({
      ...prev,
      [key]: value,
    }))
    scheduleLoad(immediate ? 0 : 500)
  }

  const clearFilters = () => {
    setFilters({
      status: '',
      customer_id: '',
      vehicle_id: '',
      term: '',
    })
    setCurrentPage(1)
    scheduleLoad(0)
  }

  const openConvertModal = (estimate) => {
    setSelectedEstimate(estimate)
    setConvertForm({
      issue_date: new Date().toISOString().split('T')[0],
      due_date: '',
    })
    setShowConvertModal(true)
  }

  const confirmConvert = async () => {
    if (!selectedEstimate) return
    setConverting(true)
    try {
      const response = await estimateService.convertToInvoice(selectedEstimate.id, {
        issue_date: convertForm.issue_date,
        due_date: convertForm.due_date || null,
      })

      success('Estimate converted to invoice successfully')
      setShowConvertModal(false)

      if (response.data?.id) {
        navigate(`/cp/invoices/${response.data.id}`)
      } else {
        loadEstimates()
      }
    } catch (conversionError) {
      console.error('Failed to convert estimate:', conversionError)
      error(conversionError.response?.data?.message || 'Failed to convert estimate')
    } finally {
      setConverting(false)
    }
  }

  const hasNext = estimates.length === pageSize

  const cellRenderers = useMemo(() => ({
    number: ({ row }) => (
      <Link
        to={`/cp/estimates/${row.id}`}
        className="text-primary-600 hover:text-primary-800 font-medium"
        onClick={(event) => event.stopPropagation()}
      >
        {row.number}
      </Link>
    ),
    status: ({ row }) => (
      <Badge variant={getStatusVariant(row.status)}>
        {formatStatus(row.status)}
      </Badge>
    ),
    grand_total: ({ row }) => formatCurrency(row.grand_total),
    expiration_date: ({ row }) => (
      <span className={isExpiringSoon(row.expiration_date) ? 'text-red-600' : 'text-gray-900'}>
        {row.expiration_date ? formatDate(row.expiration_date) : '-'}
      </span>
    ),
    created_at: ({ row }) => formatDate(row.created_at),
  }), [])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Estimates</h1>
          <p className="mt-1 text-sm text-gray-500">Manage customer estimates and quotes</p>
        </div>
        <Button onClick={() => navigate('/cp/estimates/create')}>
          <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
          </svg>
          New Estimate
        </Button>
      </div>

      <Card className="mb-6">
        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Status</label>
            <Select
              value={filters.status}
              options={statusOptions}
              placeholder="All statuses"
              className="mt-1"
              onChange={(event) => updateFilter('status', event.target.value, { immediate: true })}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Customer ID</label>
            <Input
              value={filters.customer_id}
              type="number"
              placeholder="Filter by customer"
              className="mt-1"
              onUpdateModelValue={(value) => updateFilter('customer_id', value)}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Vehicle ID</label>
            <Input
              value={filters.vehicle_id}
              type="number"
              placeholder="Filter by vehicle"
              className="mt-1"
              onUpdateModelValue={(value) => updateFilter('vehicle_id', value)}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Search</label>
            <Input
              value={filters.term}
              placeholder="Estimate number..."
              className="mt-1"
              onUpdateModelValue={(value) => updateFilter('term', value)}
            />
          </div>
          <div className="flex items-end">
            <Button variant="outline" className="w-full" onClick={clearFilters}>
              Clear Filters
            </Button>
          </div>
        </div>
      </Card>

      <Card>
        <Table
          columns={columns}
          data={estimates}
          loading={loading}
          hoverable
          cellRenderers={cellRenderers}
          renderActions={(row) => (
            <div className="flex gap-2 justify-end">
              <Button
                variant="ghost"
                size="sm"
                onClick={(event) => {
                  event.stopPropagation()
                  navigate(`/cp/estimates/${row.id}`)
                }}
                title="View details"
              >
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
              </Button>
              {row.status === 'approved' ? (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={(event) => {
                    event.stopPropagation()
                    openConvertModal(row)
                  }}
                  title="Convert to Invoice"
                >
                  <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                  </svg>
                </Button>
              ) : null}
            </div>
          )}
        />

        {!loading && estimates.length > 0 ? (
          <div className="mt-4 flex items-center justify-between border-t border-gray-200 pt-4">
            <div className="text-sm text-gray-700">
              Showing {((currentPage - 1) * pageSize) + 1} to {Math.min(currentPage * pageSize, (currentPage - 1) * pageSize + estimates.length)}
            </div>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage === 1}
                onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
              >
                Previous
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={!hasNext}
                onClick={() => setCurrentPage((prev) => prev + 1)}
              >
                Next
              </Button>
            </div>
          </div>
        ) : null}
      </Card>

      <Modal
        open={showConvertModal}
        onClose={() => setShowConvertModal(false)}
        title="Convert to Invoice"
        content={(
          <div className="space-y-4">
            <p className="text-sm text-gray-600">
              Convert estimate #{selectedEstimate?.number} to an invoice?
            </p>
            <div>
              <label className="block text-sm font-medium text-gray-700">Issue Date</label>
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
            <Button onClick={confirmConvert} disabled={!convertForm.issue_date || converting} loading={converting}>
              {converting ? 'Converting...' : 'Convert to Invoice'}
            </Button>
          </div>
        )}
      />
    </div>
  )
}
