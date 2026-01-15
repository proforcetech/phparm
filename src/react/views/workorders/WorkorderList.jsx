import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import workorderService from '../../../services/workorder.service'
import userService from '../../../services/user.service'
import { useToast } from '../../stores/toast'

const pageSize = 50

const columns = [
  { key: 'number', label: 'Workorder #' },
  { key: 'status', label: 'Status' },
  { key: 'priority', label: 'Priority' },
  { key: 'assigned_technician_id', label: 'Technician' },
  { key: 'grand_total', label: 'Total' },
  { key: 'started_at', label: 'Started' },
  { key: 'created_at', label: 'Created' },
]

const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'parts_pending,awaiting_authorization', label: 'Parts Pending / Authorized' },
  { value: 'pending', label: 'Pending' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'on_hold', label: 'On Hold' },
  { value: 'parts_pending', label: 'Parts Pending' },
  { value: 'awaiting_authorization', label: 'Authorized' },
  { value: 'completed', label: 'Completed' },
  { value: 'goa', label: 'GOA' },
  { value: 'cancelled', label: 'Cancelled' },
]

const ageBucketOptions = [
  { value: '', label: 'All Ages' },
  { value: '0-2', label: '0-2 days' },
  { value: '3-7', label: '3-7 days' },
  { value: '8-14', label: '8-14 days' },
  { value: '15+', label: '15+ days' },
]

const ageBucketRanges = {
  '0-2': { min: 0, max: 2 },
  '3-7': { min: 3, max: 7 },
  '8-14': { min: 8, max: 14 },
  '15+': { min: 15, max: null },
}

const priorityOptions = [
  { value: '', label: 'All Priorities' },
  { value: 'urgent', label: 'Urgent' },
  { value: 'high', label: 'High' },
  { value: 'normal', label: 'Normal' },
  { value: 'low', label: 'Low' },
]

const formatStatus = (status) => {
  if (!status) return ''
  if (status.toLowerCase() === 'goa') return 'GOA'
  return status
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

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

const getStatusVariant = (status) => {
  const variants = {
    pending: 'default',
    in_progress: 'info',
    on_hold: 'warning',
    parts_pending: 'warning',
    awaiting_authorization: 'info',
    completed: 'success',
    goa: 'danger',
    cancelled: 'danger',
  }
  return variants[status?.toLowerCase()] || 'default'
}

const getPriorityVariant = (priority) => {
  const variants = {
    urgent: 'danger',
    high: 'warning',
    normal: 'default',
    low: 'secondary',
  }
  return variants[priority?.toLowerCase()] || 'default'
}

export default function WorkorderList() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { success, error } = useToast()

  const [loading, setLoading] = useState(false)
  const [workorders, setWorkorders] = useState([])
  const [technicians, setTechnicians] = useState([])
  const [stats, setStats] = useState({
    pending: 0,
    in_progress: 0,
    on_hold: 0,
    completed: 0,
    total_active: 0,
  })
  const [meta, setMeta] = useState({
    total: 0,
    limit: pageSize,
    offset: 0,
  })
  const [filters, setFilters] = useState({
    status: '',
    priority: '',
    technician_id: '',
    customer_id: '',
    term: '',
    age_bucket: '',
  })
  const [currentPage, setCurrentPage] = useState(1)
  const [showConvertModal, setShowConvertModal] = useState(false)
  const [selectedWorkorder, setSelectedWorkorder] = useState(null)
  const [converting, setConverting] = useState(false)
  const [convertForm, setConvertForm] = useState({ due_date: '' })

  const debounceRef = useRef(null)

  const displayWorkorders = useMemo(() => {
    const rows = []
    workorders.forEach((workorder) => {
      rows.push({
        ...workorder,
        row_key: `workorder-${workorder.id}`,
        is_sub_workorder: false,
      })

      if (Array.isArray(workorder.sub_estimates)) {
        workorder.sub_estimates.forEach((subEstimate) => {
          rows.push({
            ...subEstimate,
            row_key: `sub-${workorder.id}-${subEstimate.id}`,
            is_sub_workorder: true,
            parent_workorder_id: workorder.id,
            assigned_technician_id: subEstimate.technician_id ?? null,
            grand_total: subEstimate.grand_total ?? 0,
            created_at: subEstimate.created_at ?? null,
          })
        })
      }
    })
    return rows
  }, [workorders])

  const technicianOptions = useMemo(() => {
    return [
      { value: '', label: 'All Technicians' },
      ...technicians
        .filter((user) => user.role === 'technician')
        .map((user) => ({ value: user.id, label: user.name })),
    ]
  }, [technicians])

  const loadWorkorders = useCallback(async () => {
    setLoading(true)
    try {
      const { age_bucket: ageBucket, ...filterParams } = filters
      const params = {
        ...filterParams,
        limit: pageSize,
        offset: (currentPage - 1) * pageSize,
      }

      if (ageBucket) {
        const range = ageBucketRanges[ageBucket]
        if (range) {
          params.status_age_min_days = range.min
          if (range.max !== null) {
            params.status_age_max_days = range.max
          }
        }
      }

      Object.keys(params).forEach((key) => {
        if (params[key] === '' || params[key] === null) {
          delete params[key]
        }
      })

      const response = await workorderService.getWorkorders(params)
      setWorkorders(response.data?.data || response.data || [])
      if (response.data?.meta) {
        setMeta(response.data.meta)
      }
    } catch (loadError) {
      console.error('Failed to load workorders:', loadError)
      error('Failed to load workorders')
    } finally {
      setLoading(false)
    }
  }, [currentPage, error, filters])

  const loadStats = useCallback(async () => {
    try {
      const response = await workorderService.getStats()
      setStats((prev) => ({
        ...prev,
        ...(response.data || {}),
      }))
    } catch (statsError) {
      console.error('Failed to load stats:', statsError)
    }
  }, [])

  const loadTechnicians = useCallback(async () => {
    try {
      const users = await userService.listUsers()
      setTechnicians(Array.isArray(users) ? users : users?.data || [])
    } catch (techError) {
      console.error('Failed to load technicians:', techError)
    }
  }, [])

  useEffect(() => {
    loadWorkorders()
  }, [loadWorkorders])

  useEffect(() => {
    const statusParam = searchParams.get('status') || ''
    const minDays = searchParams.get('status_age_min_days')
    const maxDays = searchParams.get('status_age_max_days')
    const ageBucket =
      Object.entries(ageBucketRanges).find(
        ([, range]) =>
          String(range.min) === String(minDays ?? '') &&
          String(range.max ?? '') === String(maxDays ?? '')
      )?.[0] || ''

    setFilters((prev) => ({
      ...prev,
      status: statusParam,
      age_bucket: ageBucket,
    }))
    setCurrentPage(1)
  }, [searchParams])

  useEffect(() => {
    loadStats()
    loadTechnicians()
  }, [loadStats, loadTechnicians])

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
      loadWorkorders()
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
      priority: '',
      technician_id: '',
      customer_id: '',
      term: '',
      age_bucket: '',
    })
    setCurrentPage(1)
    scheduleLoad(0)
  }

  const getTechnicianName = (id) => {
    if (!id) return 'Unassigned'
    const numericId = Number(id)
    const tech = technicians.find((entry) => Number(entry.id) === numericId)
    return tech?.name || `Unknown (ID: ${id})`
  }

  const onRowClick = (row) => {
    if (row.is_sub_workorder) {
      navigate(`/cp/estimates/${row.id}`)
      return
    }
    navigate(`/cp/workorders/${row.id}`)
  }

  const startWorkorder = async (workorder) => {
    try {
      await workorderService.updateStatus(workorder.id, 'in_progress', 'Work started')
      success('Workorder started')
      loadWorkorders()
      loadStats()
    } catch (startError) {
      console.error('Failed to start workorder:', startError)
      error(startError.response?.data?.error || 'Failed to start workorder')
    }
  }

  const completeWorkorder = async (workorder) => {
    try {
      await workorderService.updateStatus(workorder.id, 'completed', 'Work completed')
      success('Workorder marked as complete')
      loadWorkorders()
      loadStats()
    } catch (completeError) {
      console.error('Failed to complete workorder:', completeError)
      error(completeError.response?.data?.error || 'Failed to complete workorder')
    }
  }

  const openConvertModal = (workorder) => {
    setSelectedWorkorder(workorder)
    setConvertForm({ due_date: '' })
    setShowConvertModal(true)
  }

  const confirmConvert = async () => {
    if (!selectedWorkorder) return
    setConverting(true)
    try {
      const response = await workorderService.convertToInvoice(
        selectedWorkorder.id,
        convertForm.due_date || null
      )

      success('Workorder converted to invoice successfully')
      setShowConvertModal(false)

      if (response.data?.data?.id) {
        navigate(`/cp/invoices/${response.data.data.id}`)
      } else {
        loadWorkorders()
        loadStats()
      }
    } catch (convertError) {
      console.error('Failed to convert workorder:', convertError)
      error(convertError.response?.data?.error || 'Failed to convert workorder')
    } finally {
      setConverting(false)
    }
  }

  const hasNext = workorders.length >= pageSize

  const cellRenderers = useMemo(() => ({
    number: ({ row }) => (
      <div className={`flex items-center gap-2 ${row.is_sub_workorder ? 'pl-8 text-gray-600' : ''}`}>
        {row.is_sub_workorder ? <span className="text-gray-400 mr-1">└─</span> : null}
        <Link
          to={row.is_sub_workorder ? `/cp/estimates/${row.id}` : `/cp/workorders/${row.id}`}
          className={`font-medium ${row.is_sub_workorder ? 'text-blue-600 hover:text-blue-800' : 'text-primary-600 hover:text-primary-800'}`}
          onClick={(event) => event.stopPropagation()}
        >
          {row.number}
        </Link>
        {row.is_sub_workorder ? (
          <span className="text-xs text-gray-500 italic">(Sub)</span>
        ) : null}
      </div>
    ),
    status: ({ row }) => (
      <Badge variant={getStatusVariant(row.status)}>
        {formatStatus(row.status)}
      </Badge>
    ),
    priority: ({ row }) => (
      row.is_sub_workorder
        ? <span className="text-gray-400">-</span>
        : (
          <Badge variant={getPriorityVariant(row.priority)}>
            {formatStatus(row.priority)}
          </Badge>
        )
    ),
    grand_total: ({ row }) => formatCurrency(row.grand_total),
    assigned_technician_id: ({ row }) => (
      row.assigned_technician_id
        ? getTechnicianName(row.assigned_technician_id)
        : <span className="text-gray-400 italic">Unassigned</span>
    ),
    started_at: ({ row }) => (row.started_at ? formatDate(row.started_at) : '-'),
    created_at: ({ row }) => formatDate(row.created_at),
  }), [technicians])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Workorders</h1>
          <p className="mt-1 text-sm text-gray-500">Manage active repair workorders</p>
        </div>
      </div>

      <div className="mb-6 grid grid-cols-2 md:grid-cols-5 gap-4">
        <Card className="text-center">
          <div className="text-2xl font-bold text-gray-600">{stats.pending}</div>
          <div className="text-sm text-gray-500">Pending</div>
        </Card>
        <Card className="text-center">
          <div className="text-2xl font-bold text-blue-600">{stats.in_progress}</div>
          <div className="text-sm text-gray-500">In Progress</div>
        </Card>
        <Card className="text-center">
          <div className="text-2xl font-bold text-yellow-600">{stats.on_hold}</div>
          <div className="text-sm text-gray-500">On Hold</div>
        </Card>
        <Card className="text-center">
          <div className="text-2xl font-bold text-green-600">{stats.completed}</div>
          <div className="text-sm text-gray-500">Completed</div>
        </Card>
        <Card className="text-center">
          <div className="text-2xl font-bold text-primary-600">{stats.total_active}</div>
          <div className="text-sm text-gray-500">Active Total</div>
        </Card>
      </div>

      <Card className="mb-6">
        <div className="grid grid-cols-1 md:grid-cols-7 gap-4">
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
            <label className="block text-sm font-medium text-gray-700">Age</label>
            <Select
              value={filters.age_bucket}
              options={ageBucketOptions}
              placeholder="All ages"
              className="mt-1"
              onChange={(event) => updateFilter('age_bucket', event.target.value, { immediate: true })}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Priority</label>
            <Select
              value={filters.priority}
              options={priorityOptions}
              placeholder="All priorities"
              className="mt-1"
              onChange={(event) => updateFilter('priority', event.target.value, { immediate: true })}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Technician</label>
            <Select
              value={filters.technician_id}
              options={technicianOptions}
              placeholder="All technicians"
              className="mt-1"
              onChange={(event) => updateFilter('technician_id', event.target.value, { immediate: true })}
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
            <label className="block text-sm font-medium text-gray-700">Search</label>
            <Input
              value={filters.term}
              placeholder="Workorder number..."
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
          data={displayWorkorders}
          loading={loading}
          rowKey="row_key"
          hoverable
          onRowClick={onRowClick}
          cellRenderers={cellRenderers}
          renderActions={(row) => (
            !row.is_sub_workorder ? (
              <div className="flex gap-2 justify-end" onClick={(event) => event.stopPropagation()}>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => navigate(`/cp/workorders/${row.id}`)}
                  title="View details"
                >
                  <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                  </svg>
                </Button>
                {row.status === 'pending' ? (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => startWorkorder(row)}
                    title="Start Work"
                  >
                    <svg className="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </Button>
                ) : null}
                {row.status === 'in_progress' ? (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => completeWorkorder(row)}
                    title="Mark Complete"
                  >
                    <svg className="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </Button>
                ) : null}
                {row.status === 'completed' ? (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => openConvertModal(row)}
                    title="Convert to Invoice"
                  >
                    <svg className="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                  </Button>
                ) : null}
              </div>
            ) : null
          )}
        />

        {!loading && workorders.length > 0 ? (
          <div className="mt-4 flex items-center justify-between border-t border-gray-200 pt-4">
            <div className="text-sm text-gray-700">
              Showing {((currentPage - 1) * pageSize) + 1} to {Math.min(currentPage * pageSize, meta.total)} of {meta.total}
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
              Convert workorder #{selectedWorkorder?.number} to an invoice?
            </p>
            <Alert variant="info">
              This will create an invoice with all completed work from this workorder.
            </Alert>
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
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowConvertModal(false)}>Cancel</Button>
            <Button onClick={confirmConvert} disabled={converting} loading={converting}>
              {converting ? 'Converting...' : 'Convert to Invoice'}
            </Button>
          </div>
        )}
      />
    </div>
  )
}
