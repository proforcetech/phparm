import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Autocomplete from '../../components/ui/Autocomplete'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import ChatWidget from '../../components/chat/ChatWidget'
import Timeline from '../../components/Timeline'
import workorderService from '../../../services/workorder.service'
import userService from '../../../services/user.service'
import pullRequestService from '../../../services/pull-request.service'
import inventoryService from '../../../services/inventory.service'
import PartsCart from '../inventory/PartsCart'
import { useToast } from '../../stores/toast'

const createSubEstimateItem = () => ({
  type: 'LABOR',
  sku: '',
  inventory_item_id: null,
  description: '',
  quantity: 1,
  unit_price: 0,
  list_price: 0,
  taxable: true,
})

const createSubEstimateJob = () => ({
  title: '',
  notes: '',
  items: [createSubEstimateItem()],
})

const priorityOptions = [
  { value: 'urgent', label: 'Urgent' },
  { value: 'high', label: 'High' },
  { value: 'normal', label: 'Normal' },
  { value: 'low', label: 'Low' },
]

const goaBillingOptions = [
  { value: 'customer', label: 'Customer' },
  { value: 'motor_club', label: 'Motor Club' },
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

const formatDateTime = (date) => {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(date))
}

const getItemLineTotal = (item) => {
  if (item?.line_total !== undefined && item?.line_total !== null) {
    return item.line_total
  }
  const quantity = Number(item?.quantity) || 0
  const unitPrice = Number(item?.unit_price) || 0
  return quantity * unitPrice
}

const getStatusVariant = (status) => {
  const variants = {
    pending: 'default',
    in_progress: 'info',
    on_hold: 'warning',
    completed: 'success',
    goa: 'danger',
    cancelled: 'danger',
  }
  return variants[status?.toLowerCase()] || 'default'
}

const getJobStatusVariant = (status) => {
  const variants = {
    pending: 'default',
    in_progress: 'info',
    hooked: 'info',
    completed: 'success',
    goa: 'danger',
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

const getEstimateStatusVariant = (status) => {
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

const getPullRequestStatusVariant = (status) => {
  const variants = {
    pending: 'default',
    pulled: 'success',
    ordered: 'info',
    received: 'success',
    cancelled: 'danger',
  }
  return variants[status] || 'default'
}

const getTimelineIconBg = (status) => {
  const colors = {
    pending: 'bg-gray-400',
    in_progress: 'bg-blue-500',
    on_hold: 'bg-yellow-500',
    completed: 'bg-green-500',
    goa: 'bg-red-500',
    cancelled: 'bg-red-500',
  }
  return colors[status] || 'bg-gray-400'
}

export default function WorkorderDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error: toastError } = useToast()

  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(null)
  const [workorder, setWorkorder] = useState(null)
  const [jobs, setJobs] = useState([])
  const [subEstimates, setSubEstimates] = useState([])
  const [timelineEvents, setTimelineEvents] = useState([])
  const [technicians, setTechnicians] = useState([])
  const [pullRequests, setPullRequests] = useState([])

  const [showConvertModal, setShowConvertModal] = useState(false)
  const [showAssignModal, setShowAssignModal] = useState(false)
  const [showJobAssign, setShowJobAssign] = useState(false)
  const [showPriorityModal, setShowPriorityModal] = useState(false)
  const [showSubEstimateModal, setShowSubEstimateModal] = useState(false)
  const [showPullRequestModal, setShowPullRequestModal] = useState(false)
  const [showGoaModal, setShowGoaModal] = useState(false)
  const [selectedJob, setSelectedJob] = useState(null)

  const [converting, setConverting] = useState(false)
  const [assigning, setAssigning] = useState(false)
  const [updatingPriority, setUpdatingPriority] = useState(false)
  const [creatingSubEstimate, setCreatingSubEstimate] = useState(false)
  const [creatingPullRequest, setCreatingPullRequest] = useState(false)
  const [markingGoa, setMarkingGoa] = useState(false)

  const [convertForm, setConvertForm] = useState({ due_date: '' })
  const [assignForm, setAssignForm] = useState({ technician_id: '' })
  const [jobAssignForm, setJobAssignForm] = useState({ technician_id: '' })
  const [priorityForm, setPriorityForm] = useState({ priority: '' })
  const [pullRequestForm, setPullRequestForm] = useState({
    selectedItem: null,
    inventory_item_id: null,
    description: '',
    sku: '',
    quantity_requested: 1,
    unit_cost: 0,
    unit_price: 0,
    vendor: '',
    workorder_job_id: '',
    notes: '',
  })
  const [goaForm, setGoaForm] = useState({
    goa_fee: 0,
    goa_billing_party: 'customer',
    notes: '',
  })
  const [subEstimateForm, setSubEstimateForm] = useState({
    tax_rate: 0,
    jobs: [createSubEstimateJob()],
  })

  const completedJobsCount = useMemo(() => {
    return jobs.filter((job) => ['completed', 'goa'].includes(job.status)).length
  }, [jobs])

  const isSubEstimateValid = useMemo(() => {
    return subEstimateForm.jobs.every((job) => {
      if (!job.title || !job.title.trim()) return false
      if (!job.items.length) return false
      return job.items.every((item) => item.description && item.description.trim() !== '')
    })
  }, [subEstimateForm.jobs])

  const jobOptions = useMemo(() => {
    return [
      { value: '', label: 'No specific job' },
      ...jobs.map((job) => ({ value: job.id, label: job.title || job.description })),
    ]
  }, [jobs])

  const technicianOptions = useMemo(() => {
    return [
      { value: '', label: 'Unassigned' },
      ...technicians.map((tech) => ({ value: tech.id, label: tech.name })),
    ]
  }, [technicians])

  const getTechnicianName = useCallback((techId) => {
    const tech = technicians.find((entry) => entry.id === techId)
    return tech?.name || `Tech #${techId}`
  }, [technicians])

  const loadTimeline = useCallback(async () => {
    try {
      const response = await workorderService.getTimeline(id)
      setTimelineEvents(response.data?.timeline || [])
    } catch (timelineError) {
      console.error('Failed to load timeline:', timelineError)
    }
  }, [id])

  const loadWorkorder = useCallback(async () => {
    setLoading(true)
    setLoadError(null)
    try {
      const response = await workorderService.getWorkorder(id)
      const data = response.data
      setWorkorder(data)
      setJobs(data.jobs || [])
      setSubEstimates(data.sub_estimates || [])
      setPriorityForm({ priority: data.priority || 'normal' })
      setAssignForm({ technician_id: data.assigned_technician_id || '' })
      loadTimeline()
    } catch (fetchError) {
      console.error('Failed to load workorder:', fetchError)
      setLoadError(fetchError.response?.data?.message || 'Failed to load workorder')
    } finally {
      setLoading(false)
    }
  }, [id, loadTimeline])

  const loadTechnicians = useCallback(async () => {
    try {
      const users = await userService.listUsers({ role: 'technician' })
      setTechnicians(Array.isArray(users) ? users : users?.data || [])
    } catch (techError) {
      console.error('Failed to load technicians:', techError)
    }
  }, [])

  const loadPullRequests = useCallback(async () => {
    try {
      const response = await pullRequestService.getByWorkorder(id)
      setPullRequests(response.data?.items || [])
    } catch (pullError) {
      console.error('Failed to load pull requests:', pullError)
    }
  }, [id])

  useEffect(() => {
    loadWorkorder()
    loadTechnicians()
    loadPullRequests()
  }, [loadPullRequests, loadTechnicians, loadWorkorder])

  const updateStatus = async (status, notes = null) => {
    if (!workorder) return
    try {
      await workorderService.updateStatus(workorder.id, status, notes)
      success(`Workorder status updated to ${formatStatus(status)}`)
      loadWorkorder()
    } catch (statusError) {
      console.error('Failed to update status:', statusError)
      toastError(statusError.response?.data?.error || 'Failed to update status')
    }
  }

  const updateJobStatus = async (jobId, status) => {
    if (!workorder) return
    try {
      await workorderService.updateJobStatus(workorder.id, jobId, status)
      success(`Job status updated to ${formatStatus(status)}`)
      loadWorkorder()
    } catch (jobError) {
      console.error('Failed to update job status:', jobError)
      toastError(jobError.response?.data?.error || 'Failed to update job status')
    }
  }

  const showJobAssignModal = (job) => {
    setSelectedJob(job)
    setJobAssignForm({ technician_id: job.technician_id || '' })
    setShowJobAssign(true)
  }

  const openGoaModal = () => {
    if (!workorder) return
    setGoaForm({
      goa_fee: workorder.goa_fee ?? workorder.call_out_fee ?? 0,
      goa_billing_party: workorder.goa_billing_party || 'customer',
      notes: '',
    })
    setShowGoaModal(true)
  }

  const markGoa = async () => {
    if (!workorder) return
    setMarkingGoa(true)
    try {
      await workorderService.updateStatus(workorder.id, 'goa', goaForm.notes || 'Marked GOA', {
        payload: {
          goa_fee: Number(goaForm.goa_fee) || 0,
          goa_billing_party: goaForm.goa_billing_party,
        },
      })
      success('Workorder marked GOA')
      setShowGoaModal(false)
      loadWorkorder()
    } catch (err) {
      console.error('Failed to mark GOA:', err)
      toastError(err.response?.data?.error || 'Failed to mark workorder GOA')
    } finally {
      setMarkingGoa(false)
    }
  }

  const confirmJobAssign = async () => {
    if (!workorder || !selectedJob) return
    setAssigning(true)
    try {
      await workorderService.assignJobTechnician(
        workorder.id,
        selectedJob.id,
        jobAssignForm.technician_id || null
      )
      success('Technician assigned to job')
      setShowJobAssign(false)
      loadWorkorder()
    } catch (assignError) {
      console.error('Failed to assign technician:', assignError)
      toastError(assignError.response?.data?.error || 'Failed to assign technician')
    } finally {
      setAssigning(false)
    }
  }

  const confirmAssign = async () => {
    if (!workorder) return
    setAssigning(true)
    try {
      await workorderService.assignTechnician(workorder.id, assignForm.technician_id || null)
      success('Technician assigned')
      setShowAssignModal(false)
      loadWorkorder()
    } catch (assignError) {
      console.error('Failed to assign technician:', assignError)
      toastError(assignError.response?.data?.error || 'Failed to assign technician')
    } finally {
      setAssigning(false)
    }
  }

  const confirmPriority = async () => {
    if (!workorder) return
    setUpdatingPriority(true)
    try {
      await workorderService.updatePriority(workorder.id, priorityForm.priority)
      success('Priority updated')
      setShowPriorityModal(false)
      loadWorkorder()
    } catch (priorityError) {
      console.error('Failed to update priority:', priorityError)
      toastError(priorityError.response?.data?.error || 'Failed to update priority')
    } finally {
      setUpdatingPriority(false)
    }
  }

  const confirmConvert = async () => {
    if (!workorder) return
    setConverting(true)
    try {
      const response = await workorderService.convertToInvoice(
        workorder.id,
        convertForm.due_date || null
      )

      success('Workorder converted to invoice successfully')
      setShowConvertModal(false)

      if (response.data?.data?.id) {
        navigate(`/cp/invoices/${response.data.data.id}`)
      }
    } catch (convertError) {
      console.error('Failed to convert workorder:', convertError)
      toastError(convertError.response?.data?.error || 'Failed to convert workorder')
    } finally {
      setConverting(false)
    }
  }

  const addSubEstimateJob = () => {
    setSubEstimateForm((prev) => ({
      ...prev,
      jobs: [...prev.jobs, createSubEstimateJob()],
    }))
  }

  const removeSubEstimateJob = (index) => {
    setSubEstimateForm((prev) => ({
      ...prev,
      jobs: prev.jobs.filter((_, idx) => idx !== index),
    }))
  }

  const addSubEstimateLineItem = (jobIndex) => {
    setSubEstimateForm((prev) => {
      const jobsNext = prev.jobs.map((job, idx) => {
        if (idx !== jobIndex) return job
        return {
          ...job,
          items: [...job.items, createSubEstimateItem()],
        }
      })
      return { ...prev, jobs: jobsNext }
    })
  }

  const removeSubEstimateLineItem = (jobIndex, itemIndex) => {
    setSubEstimateForm((prev) => {
      const jobsNext = prev.jobs.map((job, idx) => {
        if (idx !== jobIndex) return job
        return {
          ...job,
          items: job.items.filter((_, itemIdx) => itemIdx !== itemIndex),
        }
      })
      return { ...prev, jobs: jobsNext }
    })
  }

  const updateSubEstimateJob = (index, updater) => {
    setSubEstimateForm((prev) => {
      const jobsNext = prev.jobs.map((job, idx) => (idx === index ? updater(job) : job))
      return { ...prev, jobs: jobsNext }
    })
  }

  const updateSubEstimateItem = (jobIndex, itemIndex, updater) => {
    setSubEstimateForm((prev) => {
      const jobsNext = prev.jobs.map((job, idx) => {
        if (idx !== jobIndex) return job
        const itemsNext = job.items.map((item, itemIdx) => (itemIdx === itemIndex ? updater(item) : item))
        return { ...job, items: itemsNext }
      })
      return { ...prev, jobs: jobsNext }
    })
  }

  const onSubEstimateItemTypeChange = (jobIndex, itemIndex, value) => {
    updateSubEstimateItem(jobIndex, itemIndex, (item) => {
      if (value === 'LABOR') {
        return {
          ...item,
          type: value,
          sku: '',
          inventory_item_id: null,
          list_price: 0,
        }
      }
      return { ...item, type: value }
    })
  }

  const lookupSubEstimateBySku = async (jobIndex, itemIndex) => {
    const item = subEstimateForm.jobs[jobIndex]?.items[itemIndex]
    if (!item?.sku || item.sku.trim() === '') return

    try {
      const inventoryItem = await inventoryService.findBySku(item.sku.trim())
      if (inventoryItem) {
        populateSubEstimateFromInventory(jobIndex, itemIndex, inventoryItem)
      }
    } catch {
      console.log('SKU not found in inventory')
    }
  }

  const searchSubEstimateParts = async (query) => {
    if (!query || query.length < 2) return []
    try {
      const vehicleMasterId = workorder?.vehicle?.vehicle_master_id || null
      // Use searchWithCompatibility to highlight compatible parts
      if (vehicleMasterId) {
        const results = await inventoryService.searchWithCompatibility(query, vehicleMasterId)
        if (!results) return []
        return Array.isArray(results) ? results : (results.data || [])
      }
      const results = await inventoryService.searchParts(query, null)
      if (!results) return []
      return Array.isArray(results) ? results : (results.data || [])
    } catch (err) {
      console.error('Failed to search inventory:', err)
      return []
    }
  }

  const populateSubEstimateFromInventory = (jobIndex, itemIndex, inventoryItem) => {
    updateSubEstimateItem(jobIndex, itemIndex, (item) => {
      const description = inventoryItem.description?.trim()
      return {
        ...item,
        sku: inventoryItem.sku || '',
        inventory_item_id: inventoryItem.id,
        description: description ? description : inventoryItem.name,
        unit_price: inventoryItem.sale_price || 0,
        list_price: inventoryItem.list_price || 0,
      }
    })
  }

  const calculateSubEstimateLineTotal = (item) => {
    const quantity = Number(item.quantity) || 0
    const unitPrice = Number(item.unit_price) || 0
    return quantity * unitPrice
  }

  const calculateSubEstimateJobSubtotal = (job) => {
    return job.items.reduce((sum, item) => sum + calculateSubEstimateLineTotal(item), 0)
  }

  const confirmSubEstimate = async () => {
    if (!workorder) return
    setCreatingSubEstimate(true)
    try {
      await workorderService.createSubEstimate(workorder.id, {
        jobs: subEstimateForm.jobs.map((job) => ({
          title: job.title,
          notes: job.notes,
          items: job.items.map((item) => ({
            type: item.type,
            sku: item.sku || null,
            inventory_item_id: item.inventory_item_id || null,
            description: item.description,
            quantity: Number(item.quantity) || 0,
            unit_price: Number(item.unit_price) || 0,
            list_price: item.list_price !== null && item.list_price !== '' ? Number(item.list_price) : null,
            taxable: item.taxable !== false,
          })),
        })),
        tax_rate: (Number(subEstimateForm.tax_rate) || 0) / 100,
      })

      success('Sub-estimate created successfully')
      setShowSubEstimateModal(false)
      setSubEstimateForm({ tax_rate: 0, jobs: [createSubEstimateJob()] })
      loadWorkorder()
    } catch (subEstimateError) {
      console.error('Failed to create sub-estimate:', subEstimateError)
      toastError(subEstimateError.response?.data?.error || 'Failed to create sub-estimate')
    } finally {
      setCreatingSubEstimate(false)
    }
  }

  const addSubEstimateJobs = async (subEstimateId) => {
    if (!workorder) return
    try {
      await workorderService.addSubEstimateJobs(workorder.id, subEstimateId)
      success('Sub-estimate jobs added to workorder')
      loadWorkorder()
    } catch (addError) {
      console.error('Failed to add sub-estimate jobs:', addError)
      toastError(addError.response?.data?.error || 'Failed to add sub-estimate jobs')
    }
  }

  const searchInventory = async (query) => {
    if (!query || query.length < 2) return []
    try {
      const results = await inventoryService.searchParts(query, null, 10)
      if (!results) return []
      return Array.isArray(results) ? results : (results.data || [])
    } catch (searchError) {
      console.error('Failed to search inventory:', searchError)
      return []
    }
  }

  const selectInventoryItem = (item) => {
    if (!item) return
    setPullRequestForm((prev) => ({
      ...prev,
      selectedItem: item.id,
      inventory_item_id: item.id,
      description: item.name,
      sku: item.sku || '',
      unit_cost: item.cost || 0,
      unit_price: item.sale_price || 0,
      vendor: item.vendor || '',
    }))
  }

  const resetPullRequestForm = () => {
    setPullRequestForm({
      selectedItem: null,
      inventory_item_id: null,
      description: '',
      sku: '',
      quantity_requested: 1,
      unit_cost: 0,
      unit_price: 0,
      vendor: '',
      workorder_job_id: '',
      notes: '',
    })
  }

  const createPullRequest = async () => {
    if (!workorder) return
    setCreatingPullRequest(true)
    try {
      await pullRequestService.create({
        workorder_id: workorder.id,
        workorder_job_id: pullRequestForm.workorder_job_id || null,
        inventory_item_id: pullRequestForm.inventory_item_id || null,
        description: pullRequestForm.description,
        sku: pullRequestForm.sku || null,
        quantity_requested: Number(pullRequestForm.quantity_requested) || 0,
        unit_cost: Number(pullRequestForm.unit_cost) || 0,
        unit_price: Number(pullRequestForm.unit_price) || 0,
        vendor: pullRequestForm.vendor || null,
        notes: pullRequestForm.notes || null,
      })
      success('Part request created')
      setShowPullRequestModal(false)
      resetPullRequestForm()
      loadPullRequests()
    } catch (pullError) {
      console.error('Failed to create pull request:', pullError)
      toastError(pullError.response?.data?.error || 'Failed to create request')
    } finally {
      setCreatingPullRequest(false)
    }
  }

  const pullFromStock = async (request) => {
    try {
      await pullRequestService.markAsPulled(request.id, request.quantity_requested - request.quantity_fulfilled)
      success('Item pulled from inventory')
      loadPullRequests()
    } catch (pullError) {
      console.error('Failed to pull from stock:', pullError)
      toastError('Failed to pull from inventory')
    }
  }

  const markAsOrdered = async (request) => {
    try {
      await pullRequestService.markAsOrdered(request.id)
      success('Item marked as ordered')
      loadPullRequests()
    } catch (orderError) {
      console.error('Failed to mark as ordered:', orderError)
      toastError('Failed to mark as ordered')
    }
  }

  const markAsReceived = async (request) => {
    try {
      await pullRequestService.markAsReceived(request.id, request.quantity_requested - request.quantity_fulfilled)
      success('Item marked as received')
      loadPullRequests()
    } catch (receiveError) {
      console.error('Failed to mark as received:', receiveError)
      toastError('Failed to mark as received')
    }
  }

  const cancelPullRequest = async (request) => {
    if (!window.confirm('Cancel this part request?')) return
    try {
      await pullRequestService.cancel(request.id)
      success('Request cancelled')
      loadPullRequests()
    } catch (cancelError) {
      console.error('Failed to cancel request:', cancelError)
      toastError('Failed to cancel request')
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-12">
        <Loading size="xl" text="Loading workorder..." />
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

  if (!workorder) {
    return null
  }

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
              <h1 className="text-2xl font-bold text-gray-900">Workorder {workorder.number}</h1>
              <p className="text-sm text-gray-500">Created {formatDate(workorder.created_at)}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Badge variant={getPriorityVariant(workorder.priority)} size="lg">
              {formatStatus(workorder.priority)}
            </Badge>
            <Badge variant={getStatusVariant(workorder.status)} size="lg">
              {formatStatus(workorder.status)}
            </Badge>
          </div>
        </div>

        <div className="flex flex-wrap gap-2 mt-4">
          {workorder.status === 'pending' ? (
            <Button variant="primary" onClick={() => updateStatus('in_progress', 'Work started')}>
              <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Start Work
            </Button>
          ) : null}
          {workorder.status === 'in_progress' ? (
            <Button variant="secondary" onClick={() => updateStatus('on_hold', 'Work paused')}>
              <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Put On Hold
            </Button>
          ) : null}
          {workorder.status === 'on_hold' ? (
            <Button variant="primary" onClick={() => updateStatus('in_progress', 'Work resumed')}>
              Resume Work
            </Button>
          ) : null}
          {workorder.status === 'in_progress' ? (
            <Button variant="primary" onClick={() => updateStatus('completed', 'All work completed')}>
              <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Mark Complete
            </Button>
          ) : null}
          {['completed', 'goa'].includes(workorder.status) ? (
            <Button onClick={() => setShowConvertModal(true)}>
              <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              Convert to Invoice
            </Button>
          ) : null}
          {['pending', 'in_progress', 'on_hold'].includes(workorder.status) ? (
            <Button variant="danger" onClick={openGoaModal}>
              Mark GOA
            </Button>
          ) : null}
          {['pending', 'in_progress', 'on_hold'].includes(workorder.status) ? (
            <Button variant="outline" onClick={() => setShowSubEstimateModal(true)}>
              <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
              </svg>
              Create Sub-Estimate
            </Button>
          ) : null}
          {['pending', 'in_progress', 'on_hold'].includes(workorder.status) ? (
            <Button variant="outline" onClick={() => setShowAssignModal(true)}>
              <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
              {workorder.assigned_technician_id ? 'Reassign' : 'Assign Technician'}
            </Button>
          ) : null}
          {['pending', 'in_progress', 'on_hold'].includes(workorder.status) ? (
            <Button variant="outline" onClick={() => setShowPriorityModal(true)}>
              Change Priority
            </Button>
          ) : null}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          <Card>
            <div className="mb-4">
              <h3 className="text-lg font-medium text-gray-900">Customer & Vehicle</h3>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div>
                <label className="text-sm font-medium text-gray-500">Customer</label>
                <p className="mt-1 text-sm text-gray-900">
                  <Link
                    to={`/cp/customers/${workorder.customer_id}`}
                    className="text-primary-600 hover:text-primary-800"
                  >
                    {workorder.customer?.name || `Customer #${workorder.customer_id}`}
                  </Link>
                </p>
              </div>
              <div>
                <label className="text-sm font-medium text-gray-500">Vehicle</label>
                <p className="mt-1 text-sm text-gray-900">
                  <Link
                    to={`/cp/vehicles/${workorder.vehicle_id}`}
                    className="text-primary-600 hover:text-primary-800"
                  >
                    {workorder.vehicle?.display_name || `Vehicle #${workorder.vehicle_id}`}
                  </Link>
                </p>
              </div>
              <div>
                <label className="text-sm font-medium text-gray-500">Assigned Technician</label>
                <p className="mt-1 text-sm text-gray-900">
                  {workorder.assigned_technician_id ? (
                    getTechnicianName(workorder.assigned_technician_id)
                  ) : (
                    <span className="text-gray-400 italic">Unassigned</span>
                  )}
                </p>
              </div>
              <div>
                <label className="text-sm font-medium text-gray-500">Source Estimate</label>
                <p className="mt-1 text-sm text-gray-900">
                  <Link
                    to={`/cp/estimates/${workorder.estimate_id}`}
                    className="text-primary-600 hover:text-primary-800"
                  >
                    View Estimate
                  </Link>
                </p>
              </div>
            </div>
            {workorder.started_at || workorder.completed_at ? (
              <div className="grid grid-cols-2 gap-4 mt-4 pt-4 border-t border-gray-200">
                {workorder.started_at ? (
                  <div>
                    <label className="text-sm font-medium text-gray-500">Started</label>
                    <p className="mt-1 text-sm text-gray-900">{formatDateTime(workorder.started_at)}</p>
                  </div>
                ) : null}
                {workorder.completed_at ? (
                  <div>
                    <label className="text-sm font-medium text-gray-500">Completed</label>
                    <p className="mt-1 text-sm text-gray-900">{formatDateTime(workorder.completed_at)}</p>
                  </div>
                ) : null}
              </div>
            ) : null}
          </Card>

          <Card>
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900">Jobs</h3>
              <div className="text-sm text-gray-500">
                {completedJobsCount} / {jobs.length} completed
              </div>
            </div>

            {jobs.length === 0 ? (
              <div className="text-center py-8 text-gray-500">No jobs found</div>
            ) : (
              <div className="space-y-4">
                {jobs.map((job) => (
                  <div
                    key={job.id}
                    className={`border border-gray-200 rounded-lg p-4 ${job.status === 'completed' ? 'bg-green-50 border-green-200' : ''} ${['in_progress', 'hooked'].includes(job.status) ? 'bg-blue-50 border-blue-200' : ''}`}
                  >
                    <div className="flex items-start justify-between mb-2">
                      <div>
                        <h4 className="font-medium text-gray-900">{job.title || job.description}</h4>
                        {job.notes ? (
                          <p className="text-sm text-gray-500 mt-1">{job.notes}</p>
                        ) : null}
                      </div>
                      <div className="flex items-center gap-2">
                        <Badge variant={getJobStatusVariant(job.status)}>
                          {formatStatus(job.status)}
                        </Badge>
                        <span className="font-medium text-gray-900">{formatCurrency(job.total)}</span>
                      </div>
                    </div>

                    {job.items && job.items.length > 0 ? (
                      <div className="mt-3 border-t border-gray-200 pt-3">
                        <table className="min-w-full text-sm">
                          <thead>
                            <tr className="text-gray-500 text-left">
                              <th className="font-medium pb-1">Item</th>
                              <th className="font-medium pb-1 text-right">Qty</th>
                              <th className="font-medium pb-1 text-right">Unit Price</th>
                              <th className="font-medium pb-1 text-right">Total</th>
                            </tr>
                          </thead>
                          <tbody>
                            {job.items.map((item) => (
                              <tr key={item.id}>
                                <td className="py-1">
                                  <span className="capitalize">{item.type}</span>: {item.description}
                                </td>
                                <td className="py-1 text-right">{item.quantity}</td>
                                <td className="py-1 text-right">{formatCurrency(item.unit_price)}</td>
                                <td className="py-1 text-right">{formatCurrency(getItemLineTotal(item))}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    ) : null}

                    <div className="mt-3 pt-3 border-t border-gray-200 flex items-center justify-between">
                      <div className="flex items-center gap-2 text-sm text-gray-500">
                        {job.technician_id ? (
                          <span>Assigned to: {getTechnicianName(job.technician_id)}</span>
                        ) : (
                          <span className="italic">Unassigned</span>
                        )}
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => showJobAssignModal(job)}
                          title="Assign technician"
                        >
                          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                          </svg>
                        </Button>
                      </div>
                      <div className="flex gap-2">
                        {job.status === 'pending' ? (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => updateJobStatus(job.id, 'in_progress')}
                          >
                            Start
                          </Button>
                        ) : null}
                        {job.status === 'in_progress' ? (
                          <Button
                            variant="primary"
                            size="sm"
                            onClick={() => updateJobStatus(job.id, 'completed')}
                          >
                            Complete
                          </Button>
                        ) : null}
                        {job.status === 'completed' ? (
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => updateJobStatus(job.id, 'in_progress')}
                          >
                            Reopen
                          </Button>
                        ) : null}
                        {['pending', 'in_progress', 'arrived'].includes(job.status) ? (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => updateJobStatus(job.id, 'goa')}
                          >
                            Mark GOA
                          </Button>
                        ) : null}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>

          <PartsCart
            workorderId={workorder.id}
            vehicle={workorder.vehicle}
            jobs={jobs}
            onUpdate={loadWorkorder}
          />

          <Card>
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900">Parts & Supplies</h3>
              <Button variant="outline" size="sm" onClick={() => setShowPullRequestModal(true)}>
                <svg className="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                </svg>
                Request Part
              </Button>
            </div>

            {pullRequests.length === 0 ? (
              <div className="text-center py-4 text-gray-500">No parts requested yet</div>
            ) : (
              <div className="space-y-3">
                {pullRequests.map((request) => (
                  <div
                    key={request.id}
                    className={`flex items-center justify-between p-3 border border-gray-200 rounded-lg ${['pulled', 'received'].includes(request.status) ? 'bg-green-50 border-green-200' : ''} ${request.status === 'ordered' ? 'bg-yellow-50 border-yellow-200' : ''} ${request.status === 'cancelled' ? 'bg-red-50 border-red-200' : ''}`}
                  >
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <span className="font-medium text-gray-900">{request.description}</span>
                        <Badge variant={request.request_type === 'pull' ? 'success' : 'warning'} size="sm">
                          {request.request_type === 'pull' ? 'In Stock' : 'Order'}
                        </Badge>
                        <Badge variant={getPullRequestStatusVariant(request.status)} size="sm">
                          {formatStatus(request.status)}
                        </Badge>
                      </div>
                      <p className="text-sm text-gray-500 mt-1">
                        Qty: {request.quantity_fulfilled}/{request.quantity_requested}
                        {request.sku ? <span className="ml-2">SKU: {request.sku}</span> : null}
                        <span className="ml-2">{formatCurrency(request.unit_price)} each</span>
                      </p>
                    </div>
                    <div className="flex gap-2">
                      {request.status === 'pending' && request.request_type === 'pull' ? (
                        <Button size="sm" variant="primary" onClick={() => pullFromStock(request)}>
                          Pull
                        </Button>
                      ) : null}
                      {request.status === 'pending' && request.request_type === 'order' ? (
                        <Button size="sm" variant="primary" onClick={() => markAsOrdered(request)}>
                          Order
                        </Button>
                      ) : null}
                      {request.status === 'ordered' ? (
                        <Button size="sm" variant="primary" onClick={() => markAsReceived(request)}>
                          Received
                        </Button>
                      ) : null}
                      {['pending', 'ordered'].includes(request.status) ? (
                        <Button size="sm" variant="ghost" onClick={() => cancelPullRequest(request)}>
                          <svg className="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                          </svg>
                        </Button>
                      ) : null}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>

          {subEstimates.length > 0 ? (
            <Card>
              <div className="mb-4">
                <h3 className="text-lg font-medium text-gray-900">Sub-Estimates (Additional Work)</h3>
              </div>
              <div className="space-y-3">
                {subEstimates.map((subEst) => (
                  <div key={subEst.id} className="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                    <div>
                      <div className="flex items-center gap-2">
                        <Link
                          to={`/cp/estimates/${subEst.id}`}
                          className="font-medium text-primary-600 hover:text-primary-800"
                        >
                          {subEst.number}
                        </Link>
                        <Badge variant={getEstimateStatusVariant(subEst.status)}>
                          {formatStatus(subEst.status)}
                        </Badge>
                      </div>
                      <p className="text-sm text-gray-500 mt-1">
                        {formatCurrency(subEst.grand_total)}
                      </p>
                    </div>
                    {subEst.status === 'approved' ? (
                      <Button variant="primary" size="sm" onClick={() => addSubEstimateJobs(subEst.id)}>
                        Add Jobs to Workorder
                      </Button>
                    ) : null}
                  </div>
                ))}
              </div>
            </Card>
          ) : null}

          {workorder.notes ? (
            <Card>
              <div className="mb-4">
                <h3 className="text-lg font-medium text-gray-900">Notes</h3>
              </div>
              <p className="text-sm text-gray-900 whitespace-pre-wrap">{workorder.notes}</p>
            </Card>
          ) : null}
        </div>

        <div className="space-y-6">
          <Card>
            <div className="mb-4">
              <h3 className="text-lg font-medium text-gray-900">Summary</h3>
            </div>
            <div className="space-y-3">
              <div className="flex justify-between">
                <span className="text-sm text-gray-600">Subtotal</span>
                <span className="text-sm font-medium text-gray-900">{formatCurrency(workorder.subtotal)}</span>
              </div>
              {workorder.shop_fee > 0 ? (
                <div className="flex justify-between">
                  <span className="text-sm text-gray-600">Shop Fee</span>
                  <span className="text-sm font-medium text-gray-900">{formatCurrency(workorder.shop_fee)}</span>
                </div>
              ) : null}
              {workorder.hazmat_disposal_fee > 0 ? (
                <div className="flex justify-between">
                  <span className="text-sm text-gray-600">Hazmat Disposal</span>
                  <span className="text-sm font-medium text-gray-900">{formatCurrency(workorder.hazmat_disposal_fee)}</span>
                </div>
              ) : null}
              {workorder.discounts > 0 ? (
                <div className="flex justify-between text-green-600">
                  <span className="text-sm">Discounts</span>
                  <span className="text-sm font-medium">-{formatCurrency(workorder.discounts)}</span>
                </div>
              ) : null}
              <div className="flex justify-between">
                <span className="text-sm text-gray-600">Tax</span>
                <span className="text-sm font-medium text-gray-900">{formatCurrency(workorder.tax)}</span>
              </div>
              <div className="border-t border-gray-200 pt-3 flex justify-between">
                <span className="text-base font-medium text-gray-900">Grand Total</span>
                <span className="text-base font-bold text-gray-900">{formatCurrency(workorder.grand_total)}</span>
              </div>
            </div>
          </Card>

          <div>
            <ChatWidget
              variant="embedded"
              title="Dispatch Chat"
              subtitle="Coordinate updates with dispatch"
            />
          </div>

          <Card>
            <div className="mb-4">
              <h3 className="text-lg font-medium text-gray-900">Customer Communication Hub</h3>
            </div>
            <Timeline events={timelineEvents} />
          </Card>
        </div>
      </div>

      <Modal
        open={showConvertModal}
        onClose={() => setShowConvertModal(false)}
        title="Convert to Invoice"
        content={(
          <div className="space-y-4">
            <p className="text-sm text-gray-600">
              Convert workorder #{workorder?.number} to an invoice?
            </p>
            <Alert variant="info">
              {workorder?.status === 'goa'
                ? 'This will create an invoice for the GOA fee only.'
                : 'This will create an invoice with all completed work from this workorder.'}
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

      <Modal
        open={showGoaModal}
        onClose={() => setShowGoaModal(false)}
        title="Mark Gone On Arrival (GOA)"
        content={(
          <div className="space-y-4">
            <p className="text-sm text-gray-600">
              Record the GOA fee and billing party before closing this workorder.
            </p>
            <div>
              <label className="block text-sm font-medium text-gray-700">GOA Fee</label>
              <Input
                type="number"
                min="0"
                step="0.01"
                className="mt-1"
                value={goaForm.goa_fee}
                onUpdateModelValue={(value) => setGoaForm((prev) => ({ ...prev, goa_fee: value }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Bill To</label>
              <Select
                value={goaForm.goa_billing_party}
                options={goaBillingOptions}
                className="mt-1"
                onChange={(event) =>
                  setGoaForm((prev) => ({ ...prev, goa_billing_party: event.target.value }))
                }
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Notes (Optional)</label>
              <Textarea
                rows={3}
                modelValue={goaForm.notes}
                onUpdateModelValue={(value) => setGoaForm((prev) => ({ ...prev, notes: value }))}
              />
            </div>
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowGoaModal(false)}>Cancel</Button>
            <Button variant="danger" onClick={markGoa} loading={markingGoa} disabled={markingGoa}>
              {markingGoa ? 'Marking...' : 'Mark GOA'}
            </Button>
          </div>
        )}
      />

      <Modal
        open={showAssignModal}
        onClose={() => setShowAssignModal(false)}
        title="Assign Technician"
        content={(
          <div>
            <label className="block text-sm font-medium text-gray-700">Technician</label>
            <Select
              value={assignForm.technician_id}
              options={technicianOptions}
              placeholder="Select technician"
              className="mt-1"
              onChange={(event) => setAssignForm({ technician_id: event.target.value })}
            />
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowAssignModal(false)}>Cancel</Button>
            <Button onClick={confirmAssign} disabled={assigning} loading={assigning}>
              {assigning ? 'Assigning...' : 'Assign'}
            </Button>
          </div>
        )}
      />

      <Modal
        open={showJobAssign}
        onClose={() => setShowJobAssign(false)}
        title="Assign Technician to Job"
        content={(
          <div>
            <p className="text-sm text-gray-600 mb-4">
              Assign a technician to: <strong>{selectedJob?.description}</strong>
            </p>
            <label className="block text-sm font-medium text-gray-700">Technician</label>
            <Select
              value={jobAssignForm.technician_id}
              options={technicianOptions}
              placeholder="Select technician"
              className="mt-1"
              onChange={(event) => setJobAssignForm({ technician_id: event.target.value })}
            />
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowJobAssign(false)}>Cancel</Button>
            <Button onClick={confirmJobAssign} disabled={assigning} loading={assigning}>
              {assigning ? 'Assigning...' : 'Assign'}
            </Button>
          </div>
        )}
      />

      <Modal
        open={showPriorityModal}
        onClose={() => setShowPriorityModal(false)}
        title="Change Priority"
        content={(
          <div>
            <label className="block text-sm font-medium text-gray-700">Priority</label>
            <Select
              value={priorityForm.priority}
              options={priorityOptions}
              className="mt-1"
              onChange={(event) => setPriorityForm({ priority: event.target.value })}
            />
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowPriorityModal(false)}>Cancel</Button>
            <Button onClick={confirmPriority} disabled={updatingPriority} loading={updatingPriority}>
              {updatingPriority ? 'Updating...' : 'Update Priority'}
            </Button>
          </div>
        )}
      />

      <Modal
        open={showSubEstimateModal}
        onClose={() => setShowSubEstimateModal(false)}
        title="Create Sub-Estimate for Additional Work"
        size="lg"
        content={(
          <div className="space-y-6">
            <Alert variant="info">
              Create a sub-estimate for additional work discovered during repair.
              The customer will need to approve this before work can proceed.
            </Alert>

            <div className="flex items-center justify-between">
              <h4 className="text-sm font-medium text-gray-700">Jobs</h4>
              <Button variant="outline" size="sm" onClick={addSubEstimateJob} type="button">
                <svg className="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Job
              </Button>
            </div>

            {subEstimateForm.jobs.map((job, jobIndex) => (
              <div key={jobIndex} className="border border-gray-200 rounded-lg p-4">
                <div className="flex items-start justify-between mb-3">
                  <h4 className="font-medium text-gray-900">Job {jobIndex + 1}</h4>
                  {subEstimateForm.jobs.length > 1 ? (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => removeSubEstimateJob(jobIndex)}
                      type="button"
                    >
                      <svg className="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </Button>
                  ) : null}
                </div>

                <div className="space-y-3">
                  <Input
                    value={job.title}
                    label="Job Title *"
                    placeholder="e.g., Replace brake pads"
                    required
                    onUpdateModelValue={(value) => updateSubEstimateJob(jobIndex, (current) => ({ ...current, title: value }))}
                  />

                  <Textarea
                    value={job.notes}
                    label="Job Notes"
                    placeholder="Additional notes for this job (optional)"
                    rows={2}
                    onUpdateModelValue={(value) => updateSubEstimateJob(jobIndex, (current) => ({ ...current, notes: value }))}
                  />

                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <h5 className="text-sm font-medium text-gray-700">Line Items</h5>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => addSubEstimateLineItem(jobIndex)}
                        type="button"
                      >
                        <svg className="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add Item
                      </Button>
                    </div>

                    {job.items.map((item, itemIndex) => (
                      <div key={itemIndex} className="bg-white border border-gray-200 rounded p-3">
                        <div className="grid grid-cols-12 gap-3">
                          <div className="col-span-12 md:col-span-2">
                            <Select
                              value={item.type}
                              label="Type"
                              options={[
                                { value: 'LABOR', label: 'Labor' },
                                { value: 'PART', label: 'Part' },
                              ]}
                              required
                              onChange={(event) => onSubEstimateItemTypeChange(jobIndex, itemIndex, event.target.value)}
                            />
                          </div>

                          {item.type === 'PART' ? (
                            <div className="col-span-12 md:col-span-2">
                              <Input
                                value={item.sku}
                                label="SKU"
                                placeholder="SKU / Part #"
                                onUpdateModelValue={(value) => updateSubEstimateItem(jobIndex, itemIndex, (current) => ({ ...current, sku: value }))}
                                onBlur={() => lookupSubEstimateBySku(jobIndex, itemIndex)}
                              />
                            </div>
                          ) : null}

                          <div className={item.type === 'PART' ? 'col-span-12 md:col-span-2' : 'col-span-12 md:col-span-4'}>
                            {item.type === 'PART' ? (
                              <Autocomplete
                                modelValue={item.inventory_item_id}
                                label="Search Parts"
                                placeholder="Search inventory..."
                                searchFn={searchSubEstimateParts}
                                itemValue={(inv) => inv.id}
                                itemLabel={(inv) => inv.name}
                                itemSubtext={(inv) => inv.sku ? `SKU: ${inv.sku}` : ''}
                                onSelect={(inv) => populateSubEstimateFromInventory(jobIndex, itemIndex, inv)}
                                onUpdateModelValue={(value) => updateSubEstimateItem(jobIndex, itemIndex, (current) => ({ ...current, inventory_item_id: value }))}
                              />
                            ) : null}
                          </div>

                          <div className={item.type === 'PART' ? 'col-span-12 md:col-span-4' : 'col-span-12 md:col-span-4'}>
                            <Input
                              value={item.description}
                              label="Description"
                              placeholder="Describe the work or part"
                              required
                              onUpdateModelValue={(value) => updateSubEstimateItem(jobIndex, itemIndex, (current) => ({ ...current, description: value }))}
                            />
                          </div>

                          <div className="col-span-6 md:col-span-1">
                            <Input
                              value={item.quantity}
                              type="number"
                              label="Qty"
                              min="0"
                              step="0.01"
                              required
                              onUpdateModelValue={(value) => updateSubEstimateItem(jobIndex, itemIndex, (current) => ({ ...current, quantity: value }))}
                            />
                          </div>

                          <div className="col-span-6 md:col-span-2">
                            <Input
                              value={item.unit_price}
                              type="number"
                              label="Unit Price"
                              min="0"
                              step="0.01"
                              required
                              onUpdateModelValue={(value) => updateSubEstimateItem(jobIndex, itemIndex, (current) => ({ ...current, unit_price: value }))}
                            />
                          </div>

                          {item.type === 'PART' ? (
                            <div className="col-span-6 md:col-span-1">
                              <Input
                                value={item.list_price}
                                type="number"
                                label="List"
                                min="0"
                                step="0.01"
                                onUpdateModelValue={(value) => updateSubEstimateItem(jobIndex, itemIndex, (current) => ({ ...current, list_price: value }))}
                              />
                            </div>
                          ) : null}

                          <div className="col-span-6 md:col-span-1 flex items-end">
                            <label className="flex items-center gap-2 text-xs">
                              <input
                                type="checkbox"
                                checked={item.taxable}
                                onChange={(event) => updateSubEstimateItem(jobIndex, itemIndex, (current) => ({ ...current, taxable: event.target.checked }))}
                                className="h-4 w-4 text-indigo-600 rounded"
                              />
                              <span>Tax</span>
                            </label>
                          </div>

                          <div className="col-span-6 md:col-span-1 flex items-end justify-end">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => removeSubEstimateLineItem(jobIndex, itemIndex)}
                              type="button"
                              disabled={job.items.length === 1}
                            >
                              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                              </svg>
                            </Button>
                          </div>

                          <div className="col-span-12 flex items-center justify-between text-sm">
                            <span className="text-gray-600">Line Total:</span>
                            <span className="font-semibold">{formatCurrency(calculateSubEstimateLineTotal(item))}</span>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>

                  <div className="mt-4 pt-3 border-t border-gray-300 flex justify-between text-sm font-medium">
                    <span>Job Subtotal:</span>
                    <span>{formatCurrency(calculateSubEstimateJobSubtotal(job))}</span>
                  </div>
                </div>
              </div>
            ))}

            <div className="border border-gray-200 rounded-lg p-4">
              <label className="block text-sm font-medium text-gray-700">Tax Rate (%)</label>
              <Input
                value={subEstimateForm.tax_rate}
                type="number"
                min="0"
                step="0.01"
                className="mt-1"
                onUpdateModelValue={(value) => setSubEstimateForm((prev) => ({ ...prev, tax_rate: value }))}
              />
            </div>

            <Button variant="outline" onClick={addSubEstimateJob} className="w-full" type="button">
              <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
              </svg>
              Add Another Job
            </Button>
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowSubEstimateModal(false)}>Cancel</Button>
            <Button onClick={confirmSubEstimate} disabled={creatingSubEstimate || !isSubEstimateValid} loading={creatingSubEstimate}>
              {creatingSubEstimate ? 'Creating...' : 'Create Sub-Estimate'}
            </Button>
          </div>
        )}
      />

      <Modal
        open={showPullRequestModal}
        onClose={() => setShowPullRequestModal(false)}
        title="Request Part/Supply"
        size="lg"
        content={(
          <div className="space-y-4">
            <Alert variant="info">
              Search for an existing inventory item or enter details for a new part.
            </Alert>

            <div>
              <label className="block text-sm font-medium text-gray-700">Search Inventory</label>
              <Autocomplete
                modelValue={pullRequestForm.selectedItem}
                placeholder="Search by name, SKU, or description..."
                searchFn={searchInventory}
                itemValue={(item) => item.id}
                itemLabel={(item) => item.name}
                itemSubtext={(item) => `SKU: ${item.sku || 'N/A'} | ${item.is_tracked ? `Stock: ${item.stock_quantity}` : 'Catalog Item'} | $${item.sale_price}`}
                onSelect={selectInventoryItem}
                className="mt-1"
                onUpdateModelValue={(value) => setPullRequestForm((prev) => ({ ...prev, selectedItem: value }))}
              />
            </div>

            <div className="border-t border-gray-200 pt-4">
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="md:col-span-2">
                  <label className="block text-sm font-medium text-gray-700">Description *</label>
                  <Input
                    value={pullRequestForm.description}
                    placeholder="Part description"
                    className="mt-1"
                    required
                    onUpdateModelValue={(value) => setPullRequestForm((prev) => ({ ...prev, description: value }))}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">SKU</label>
                  <Input
                    value={pullRequestForm.sku}
                    placeholder="SKU-1234"
                    className="mt-1"
                    onUpdateModelValue={(value) => setPullRequestForm((prev) => ({ ...prev, sku: value }))}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Quantity *</label>
                  <Input
                    value={pullRequestForm.quantity_requested}
                    type="number"
                    min="1"
                    className="mt-1"
                    onUpdateModelValue={(value) => setPullRequestForm((prev) => ({ ...prev, quantity_requested: value }))}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Unit Cost</label>
                  <Input
                    value={pullRequestForm.unit_cost}
                    type="number"
                    step="0.01"
                    min="0"
                    className="mt-1"
                    onUpdateModelValue={(value) => setPullRequestForm((prev) => ({ ...prev, unit_cost: value }))}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Unit Price</label>
                  <Input
                    value={pullRequestForm.unit_price}
                    type="number"
                    step="0.01"
                    min="0"
                    className="mt-1"
                    onUpdateModelValue={(value) => setPullRequestForm((prev) => ({ ...prev, unit_price: value }))}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Vendor</label>
                  <Input
                    value={pullRequestForm.vendor}
                    placeholder="Vendor name"
                    className="mt-1"
                    onUpdateModelValue={(value) => setPullRequestForm((prev) => ({ ...prev, vendor: value }))}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Job (Optional)</label>
                  <Select
                    value={pullRequestForm.workorder_job_id}
                    options={jobOptions}
                    placeholder="Select a job"
                    className="mt-1"
                    onChange={(event) => setPullRequestForm((prev) => ({ ...prev, workorder_job_id: event.target.value }))}
                  />
                </div>
                <div className="md:col-span-2">
                  <label className="block text-sm font-medium text-gray-700">Notes</label>
                  <Textarea
                    value={pullRequestForm.notes}
                    rows={2}
                    className="mt-1"
                    placeholder="Additional notes..."
                    onUpdateModelValue={(value) => setPullRequestForm((prev) => ({ ...prev, notes: value }))}
                  />
                </div>
              </div>
            </div>
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowPullRequestModal(false)}>Cancel</Button>
            <Button onClick={createPullRequest} disabled={creatingPullRequest || !pullRequestForm.description} loading={creatingPullRequest}>
              {creatingPullRequest ? 'Creating...' : 'Create Request'}
            </Button>
          </div>
        )}
      />
    </div>
  )
}
