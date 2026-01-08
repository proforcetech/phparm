import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import coreReturnService from '../../../services/core-return.service'
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

const getStatusVariant = (status) => {
  const variants = {
    pending_from_customer: 'warning',
    received_from_customer: 'info',
    pending_to_vendor: 'primary',
    returned_to_vendor: 'info',
    credit_received: 'success',
    expired: 'danger',
    waived: 'secondary',
  }
  return variants[status] || 'default'
}

const formatStatus = (status) => {
  const labels = {
    pending_from_customer: 'Pending from Customer',
    received_from_customer: 'Received from Customer',
    pending_to_vendor: 'Pending to Vendor',
    returned_to_vendor: 'Returned to Vendor',
    credit_received: 'Credit Received',
    expired: 'Expired',
    waived: 'Waived',
  }
  return labels[status] || status
}

const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'pending_from_customer', label: 'Pending from Customer' },
  { value: 'received_from_customer', label: 'Received from Customer' },
  { value: 'pending_to_vendor', label: 'Pending to Vendor' },
  { value: 'returned_to_vendor', label: 'Returned to Vendor' },
  { value: 'credit_received', label: 'Credit Received' },
  { value: 'expired', label: 'Expired' },
  { value: 'waived', label: 'Waived' },
]

export default function CoreReturns() {
  const { success, error: toastError } = useToast()

  const [loading, setLoading] = useState(true)
  const [coreReturns, setCoreReturns] = useState([])
  const [summary, setSummary] = useState(null)
  const [total, setTotal] = useState(0)

  const [filters, setFilters] = useState({
    status: '',
    overdue_customer: false,
    overdue_vendor: false,
  })

  const [selectedCore, setSelectedCore] = useState(null)
  const [showDetailModal, setShowDetailModal] = useState(false)
  const [showActionModal, setShowActionModal] = useState(false)
  const [actionType, setActionType] = useState(null)
  const [actionNotes, setActionNotes] = useState('')
  const [creditAmount, setCreditAmount] = useState('')
  const [processing, setProcessing] = useState(false)

  const loadCoreReturns = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (filters.status) params.status = filters.status
      if (filters.overdue_customer) params.overdue_customer = 'true'
      if (filters.overdue_vendor) params.overdue_vendor = 'true'

      const response = await coreReturnService.list(params)
      setCoreReturns(response.items || [])
      setTotal(response.total || 0)
    } catch (loadError) {
      console.error('Failed to load core returns:', loadError)
      toastError('Failed to load core returns')
    } finally {
      setLoading(false)
    }
  }, [filters, toastError])

  const loadSummary = useCallback(async () => {
    try {
      const data = await coreReturnService.getSummary()
      setSummary(data)
    } catch (summaryError) {
      console.error('Failed to load summary:', summaryError)
    }
  }, [])

  useEffect(() => {
    loadCoreReturns()
    loadSummary()
  }, [loadCoreReturns, loadSummary])

  const openDetailModal = async (core) => {
    try {
      const detail = await coreReturnService.get(core.id)
      setSelectedCore(detail)
      setShowDetailModal(true)
    } catch (detailError) {
      console.error('Failed to load core details:', detailError)
      toastError('Failed to load details')
    }
  }

  const openActionModal = (core, action) => {
    setSelectedCore(core)
    setActionType(action)
    setActionNotes('')
    setCreditAmount(core.core_cost?.toString() || '')
    setShowActionModal(true)
  }

  const performAction = async () => {
    if (!selectedCore || !actionType) return
    setProcessing(true)
    try {
      switch (actionType) {
        case 'receive':
          await coreReturnService.receiveFromCustomer(selectedCore.id, actionNotes || null)
          success('Core marked as received from customer')
          break
        case 'credit_customer':
          await coreReturnService.creditCustomer(selectedCore.id, actionNotes || null)
          success('Customer credited for core')
          break
        case 'return_vendor':
          await coreReturnService.returnToVendor(selectedCore.id, actionNotes || null)
          success('Core marked as returned to vendor')
          break
        case 'vendor_credit':
          await coreReturnService.recordVendorCredit(selectedCore.id, parseFloat(creditAmount) || 0, actionNotes || null)
          success('Vendor credit recorded')
          break
        case 'waive':
          await coreReturnService.waive(selectedCore.id, actionNotes || null)
          success('Core waived')
          break
        case 'expire':
          await coreReturnService.expire(selectedCore.id, actionNotes || null)
          success('Core marked as expired')
          break
        default:
          break
      }
      setShowActionModal(false)
      loadCoreReturns()
      loadSummary()
    } catch (actionError) {
      console.error('Failed to perform action:', actionError)
      toastError(actionError.response?.data?.error || 'Failed to perform action')
    } finally {
      setProcessing(false)
    }
  }

  const getActionModalTitle = () => {
    const titles = {
      receive: 'Receive Core from Customer',
      credit_customer: 'Credit Customer',
      return_vendor: 'Return Core to Vendor',
      vendor_credit: 'Record Vendor Credit',
      waive: 'Waive Core',
      expire: 'Mark Core as Expired',
    }
    return titles[actionType] || 'Action'
  }

  const isOverdue = (core) => {
    if (core.status === 'pending_from_customer' && core.customer_core_due_date) {
      return new Date(core.customer_core_due_date) < new Date()
    }
    if (core.status === 'pending_to_vendor' && core.vendor_core_due_date) {
      return new Date(core.vendor_core_due_date) < new Date()
    }
    return false
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Core Returns</h1>
        <p className="text-sm text-gray-500">Track core charges and returns for parts</p>
      </div>

      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <Card className="p-4">
            <div className="text-sm text-gray-500">Pending from Customers</div>
            <div className="text-2xl font-bold text-yellow-600">{summary.pending_from_customer || 0}</div>
            {summary.overdue_customer > 0 && (
              <div className="text-sm text-red-600">{summary.overdue_customer} overdue</div>
            )}
            <div className="text-sm text-gray-500">{formatCurrency(summary.pending_customer_value)}</div>
          </Card>
          <Card className="p-4">
            <div className="text-sm text-gray-500">Pending to Vendors</div>
            <div className="text-2xl font-bold text-blue-600">{summary.pending_to_vendor || 0}</div>
            {summary.overdue_vendor > 0 && (
              <div className="text-sm text-red-600">{summary.overdue_vendor} overdue</div>
            )}
            <div className="text-sm text-gray-500">{formatCurrency(summary.pending_vendor_value)}</div>
          </Card>
          <Card className="p-4">
            <div className="text-sm text-gray-500">Received (Not Returned)</div>
            <div className="text-2xl font-bold text-purple-600">{summary.received_not_returned || 0}</div>
          </Card>
          <Card className="p-4">
            <div className="text-sm text-gray-500">Total Credits Received</div>
            <div className="text-2xl font-bold text-green-600">{formatCurrency(summary.total_credits_received)}</div>
          </Card>
        </div>
      )}

      <Card className="mb-6">
        <div className="flex flex-wrap gap-4 mb-4">
          <div className="w-48">
            <Select
              value={filters.status}
              options={statusOptions}
              onChange={(e) => setFilters((prev) => ({ ...prev, status: e.target.value }))}
            />
          </div>
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={filters.overdue_customer}
              onChange={(e) => setFilters((prev) => ({ ...prev, overdue_customer: e.target.checked }))}
              className="h-4 w-4 text-indigo-600 rounded"
            />
            <span className="text-sm">Overdue (Customer)</span>
          </label>
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={filters.overdue_vendor}
              onChange={(e) => setFilters((prev) => ({ ...prev, overdue_vendor: e.target.checked }))}
              className="h-4 w-4 text-indigo-600 rounded"
            />
            <span className="text-sm">Overdue (Vendor)</span>
          </label>
        </div>

        {loading ? (
          <div className="flex justify-center py-8">
            <Loading size="lg" text="Loading core returns..." />
          </div>
        ) : coreReturns.length === 0 ? (
          <div className="text-center py-8 text-gray-500">No core returns found</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Part</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Core Price</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Due Date</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {coreReturns.map((core) => (
                  <tr
                    key={core.id}
                    className={`${isOverdue(core) ? 'bg-red-50' : ''}`}
                  >
                    <td className="px-4 py-3">
                      <div className="font-medium text-gray-900">{core.part_description}</div>
                      {core.sku && <div className="text-sm text-gray-500">SKU: {core.sku}</div>}
                      {core.workorder_number && (
                        <Link
                          to={`/cp/workorders/${core.workorder_id}`}
                          className="text-sm text-primary-600 hover:text-primary-800"
                        >
                          WO #{core.workorder_number}
                        </Link>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      {core.customer_name && (
                        <Link
                          to={`/cp/customers/${core.customer_id}`}
                          className="text-primary-600 hover:text-primary-800"
                        >
                          {core.customer_name}
                        </Link>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={getStatusVariant(core.status)}>
                        {formatStatus(core.status)}
                      </Badge>
                      {isOverdue(core) && (
                        <Badge variant="danger" size="sm" className="ml-1">OVERDUE</Badge>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <div className="text-sm">Customer: {formatCurrency(core.core_price)}</div>
                      <div className="text-sm text-gray-500">Vendor: {formatCurrency(core.core_cost)}</div>
                    </td>
                    <td className="px-4 py-3 text-sm">
                      {core.status === 'pending_from_customer' && core.customer_core_due_date && (
                        <span className={isOverdue(core) ? 'text-red-600 font-medium' : ''}>
                          {formatDate(core.customer_core_due_date)}
                        </span>
                      )}
                      {core.status === 'pending_to_vendor' && core.vendor_core_due_date && (
                        <span className={isOverdue(core) ? 'text-red-600 font-medium' : ''}>
                          {formatDate(core.vendor_core_due_date)}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex gap-2">
                        <Button variant="ghost" size="sm" onClick={() => openDetailModal(core)}>
                          View
                        </Button>
                        {core.status === 'pending_from_customer' && (
                          <>
                            <Button variant="primary" size="sm" onClick={() => openActionModal(core, 'receive')}>
                              Receive
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => openActionModal(core, 'waive')}>
                              Waive
                            </Button>
                          </>
                        )}
                        {core.status === 'received_from_customer' && (
                          <Button variant="primary" size="sm" onClick={() => openActionModal(core, 'credit_customer')}>
                            Credit Customer
                          </Button>
                        )}
                        {core.status === 'pending_to_vendor' && (
                          <Button variant="primary" size="sm" onClick={() => openActionModal(core, 'return_vendor')}>
                            Return to Vendor
                          </Button>
                        )}
                        {core.status === 'returned_to_vendor' && (
                          <Button variant="primary" size="sm" onClick={() => openActionModal(core, 'vendor_credit')}>
                            Record Credit
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {total > 0 && (
          <div className="mt-4 text-sm text-gray-500">
            Showing {coreReturns.length} of {total} core returns
          </div>
        )}
      </Card>

      <Modal
        open={showDetailModal}
        onClose={() => setShowDetailModal(false)}
        title="Core Return Details"
        size="lg"
        content={selectedCore && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-sm font-medium text-gray-500">Part</label>
                <p className="text-gray-900">{selectedCore.part_description}</p>
                {selectedCore.sku && <p className="text-sm text-gray-500">SKU: {selectedCore.sku}</p>}
              </div>
              <div>
                <label className="text-sm font-medium text-gray-500">Status</label>
                <p>
                  <Badge variant={getStatusVariant(selectedCore.status)}>
                    {formatStatus(selectedCore.status)}
                  </Badge>
                </p>
              </div>
              <div>
                <label className="text-sm font-medium text-gray-500">Customer Core Charge</label>
                <p className="text-gray-900">{formatCurrency(selectedCore.core_price)}</p>
              </div>
              <div>
                <label className="text-sm font-medium text-gray-500">Vendor Core Cost</label>
                <p className="text-gray-900">{formatCurrency(selectedCore.core_cost)}</p>
              </div>
              {selectedCore.customer_name && (
                <div>
                  <label className="text-sm font-medium text-gray-500">Customer</label>
                  <p className="text-gray-900">{selectedCore.customer_name}</p>
                </div>
              )}
              {selectedCore.vendor && (
                <div>
                  <label className="text-sm font-medium text-gray-500">Vendor</label>
                  <p className="text-gray-900">{selectedCore.vendor}</p>
                </div>
              )}
              {selectedCore.customer_core_due_date && (
                <div>
                  <label className="text-sm font-medium text-gray-500">Customer Due Date</label>
                  <p className="text-gray-900">{formatDate(selectedCore.customer_core_due_date)}</p>
                </div>
              )}
              {selectedCore.vendor_core_due_date && (
                <div>
                  <label className="text-sm font-medium text-gray-500">Vendor Due Date</label>
                  <p className="text-gray-900">{formatDate(selectedCore.vendor_core_due_date)}</p>
                </div>
              )}
              {selectedCore.vendor_credit_amount && (
                <div>
                  <label className="text-sm font-medium text-gray-500">Vendor Credit Received</label>
                  <p className="text-green-600 font-medium">{formatCurrency(selectedCore.vendor_credit_amount)}</p>
                </div>
              )}
            </div>

            {selectedCore.history && selectedCore.history.length > 0 && (
              <div className="mt-6">
                <h4 className="text-sm font-medium text-gray-900 mb-2">History</h4>
                <div className="space-y-2">
                  {selectedCore.history.map((entry, index) => (
                    <div key={index} className="flex justify-between text-sm border-b border-gray-100 pb-2">
                      <div>
                        <span className="text-gray-900">{formatStatus(entry.new_status)}</span>
                        {entry.notes && <span className="text-gray-500 ml-2">- {entry.notes}</span>}
                      </div>
                      <div className="text-gray-500">
                        {formatDateTime(entry.created_at)}
                        {entry.created_by_name && <span className="ml-2">by {entry.created_by_name}</span>}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}
        footer={(
          <Button variant="outline" onClick={() => setShowDetailModal(false)}>Close</Button>
        )}
      />

      <Modal
        open={showActionModal}
        onClose={() => setShowActionModal(false)}
        title={getActionModalTitle()}
        content={(
          <div className="space-y-4">
            {selectedCore && (
              <Alert variant="info">
                Part: {selectedCore.part_description}
                {selectedCore.sku && ` (SKU: ${selectedCore.sku})`}
              </Alert>
            )}

            {actionType === 'vendor_credit' && (
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Credit Amount</label>
                <Input
                  value={creditAmount}
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="Enter credit amount"
                  onUpdateModelValue={setCreditAmount}
                />
              </div>
            )}

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Notes (Optional)</label>
              <Textarea
                value={actionNotes}
                rows={3}
                placeholder="Add notes..."
                onUpdateModelValue={setActionNotes}
              />
            </div>
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowActionModal(false)}>Cancel</Button>
            <Button
              onClick={performAction}
              loading={processing}
              disabled={processing || (actionType === 'vendor_credit' && !creditAmount)}
            >
              Confirm
            </Button>
          </div>
        )}
      />
    </div>
  )
}
