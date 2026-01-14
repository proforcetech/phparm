import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import api from '../../../services/api'
import inspectionService from '../../../services/inspection.service'

const SEVERITY_COLORS = {
  critical: 'bg-red-100 text-red-800 border-red-300',
  high: 'bg-orange-100 text-orange-800 border-orange-300',
  medium: 'bg-yellow-100 text-yellow-800 border-yellow-300',
  low: 'bg-green-100 text-green-800 border-green-300',
}

const SEVERITY_LABELS = {
  critical: 'Critical',
  high: 'High Priority',
  medium: 'Medium Priority',
  low: 'Low Priority',
}

export default function InspectionRecommendations() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [failedItems, setFailedItems] = useState([])
  const [selectedItems, setSelectedItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [processing, setProcessing] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [mode, setMode] = useState('new') // 'new' | 'existing' | 'workorder'
  const [estimateId, setEstimateId] = useState('')
  const [workorderId, setWorkorderId] = useState('')
  const [laborRate, setLaborRate] = useState('0')
  const [taxRate, setTaxRate] = useState('0.0')

  const loadPricingSettings = useCallback(async () => {
    try {
      const response = await api.get('/settings')
      const settings = response.data || {}
      const rate = Number(settings['pricing.labor_rate']) || 0
      setLaborRate(rate.toFixed(2))
    } catch (err) {
      console.error('Failed to load pricing settings', err)
    }
  }, [])

  useEffect(() => {
    loadFailedItems()
    loadPricingSettings()
  }, [id, loadPricingSettings])

  async function loadFailedItems() {
    setLoading(true)
    setError('')
    try {
      const data = await inspectionService.getFailedItems(id)
      setFailedItems(data.failed_items || [])
    } catch (err) {
      console.error(err)
      setError(err.response?.data?.error || 'Failed to load inspection findings')
    } finally {
      setLoading(false)
    }
  }

  function toggleItem(itemId) {
    setSelectedItems((prev) =>
      prev.includes(itemId)
        ? prev.filter((i) => i !== itemId)
        : [...prev, itemId]
    )
  }

  function selectAll() {
    const pendingItems = failedItems
      .filter((item) => !item.already_converted)
      .map((item) => item.id)
    setSelectedItems(pendingItems)
  }

  function deselectAll() {
    setSelectedItems([])
  }

  async function handleSubmit() {
    if (selectedItems.length === 0) {
      setError('Please select at least one item')
      return
    }

    setProcessing(true)
    setError('')
    setMessage('')

    const payload = {
      item_ids: selectedItems,
      labor_rate: parseFloat(laborRate) || 100.0,
      tax_rate: parseFloat(taxRate) || 0.0,
    }

    try {
      let result
      if (mode === 'new') {
        result = await inspectionService.createEstimateFromInspection(id, payload)
        setMessage(`Estimate #${result.data.estimate_number} created with ${result.data.items_added} items`)
      } else if (mode === 'existing') {
        if (!estimateId) {
          setError('Please enter an estimate ID')
          setProcessing(false)
          return
        }
        payload.estimate_id = parseInt(estimateId, 10)
        result = await inspectionService.addToEstimate(id, payload)
        setMessage(`${result.data.items_added} items added to estimate`)
      } else if (mode === 'workorder') {
        if (!workorderId) {
          setError('Please enter a workorder ID')
          setProcessing(false)
          return
        }
        payload.workorder_id = parseInt(workorderId, 10)
        result = await inspectionService.addToWorkorder(id, payload)
        setMessage(`Sub-estimate #${result.data.sub_estimate_number} created for workorder`)
      }

      // Reload to show updated status
      await loadFailedItems()
      setSelectedItems([])
    } catch (err) {
      console.error(err)
      setError(err.response?.data?.error || 'Failed to process items')
    } finally {
      setProcessing(false)
    }
  }

  async function handleDecline(itemId) {
    try {
      await inspectionService.declineRecommendation(id, itemId)
      await loadFailedItems()
    } catch (err) {
      console.error(err)
      setError('Failed to decline recommendation')
    }
  }

  async function handleDefer(itemId) {
    try {
      await inspectionService.deferRecommendation(id, itemId)
      await loadFailedItems()
    } catch (err) {
      console.error(err)
      setError('Failed to defer recommendation')
    }
  }

  const pendingItems = failedItems.filter((item) => !item.already_converted)
  const convertedItems = failedItems.filter((item) => item.already_converted)

  if (loading) {
    return (
      <div className="p-6">
        <p className="text-gray-600">Loading inspection findings...</p>
      </div>
    )
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">Inspection Findings</h1>
          <p className="text-sm text-gray-600">
            Review failed items and add them to estimates or workorders.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => navigate(`/cp/inspections/${id}`)}>
            View Full Report
          </Button>
          <Button variant="outline" onClick={() => navigate('/cp/inspections')}>
            Back to Inspections
          </Button>
        </div>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
          {error}
        </div>
      )}

      {message && (
        <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded">
          {message}
        </div>
      )}

      {failedItems.length === 0 ? (
        <Card>
          <div className="text-center py-8">
            <p className="text-gray-600">No failed items found in this inspection.</p>
            <p className="text-sm text-gray-500 mt-2">
              All inspection items passed or have already been addressed.
            </p>
          </div>
        </Card>
      ) : (
        <>
          {/* Summary */}
          <Card>
            <div className="flex flex-wrap gap-4 items-center justify-between">
              <div>
                <h2 className="text-lg font-semibold">Summary</h2>
                <p className="text-sm text-gray-600">
                  {pendingItems.length} pending items, {convertedItems.length} already added to estimates
                </p>
              </div>
              <div className="flex gap-2">
                <span className={`px-2 py-1 text-xs rounded ${SEVERITY_COLORS.critical}`}>
                  {failedItems.filter((i) => i.severity === 'critical').length} Critical
                </span>
                <span className={`px-2 py-1 text-xs rounded ${SEVERITY_COLORS.high}`}>
                  {failedItems.filter((i) => i.severity === 'high').length} High
                </span>
                <span className={`px-2 py-1 text-xs rounded ${SEVERITY_COLORS.medium}`}>
                  {failedItems.filter((i) => i.severity === 'medium').length} Medium
                </span>
                <span className={`px-2 py-1 text-xs rounded ${SEVERITY_COLORS.low}`}>
                  {failedItems.filter((i) => i.severity === 'low').length} Low
                </span>
              </div>
            </div>
          </Card>

          {/* Failed Items List */}
          <Card>
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold">Failed Items</h2>
              <div className="flex gap-2">
                <Button size="sm" variant="outline" onClick={selectAll}>
                  Select All Pending
                </Button>
                <Button size="sm" variant="outline" onClick={deselectAll}>
                  Deselect All
                </Button>
              </div>
            </div>

            <div className="space-y-3">
              {failedItems.map((item) => (
                <div
                  key={item.id}
                  className={`border rounded-lg p-4 ${
                    item.already_converted ? 'bg-gray-50 opacity-60' : ''
                  } ${selectedItems.includes(item.id) ? 'ring-2 ring-indigo-500' : ''}`}
                >
                  <div className="flex items-start gap-3">
                    {!item.already_converted && (
                      <input
                        type="checkbox"
                        checked={selectedItems.includes(item.id)}
                        onChange={() => toggleItem(item.id)}
                        className="mt-1 h-4 w-4 text-indigo-600 rounded"
                      />
                    )}
                    <div className="flex-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-medium">{item.label}</span>
                        <span className={`px-2 py-0.5 text-xs rounded ${SEVERITY_COLORS[item.severity]}`}>
                          {SEVERITY_LABELS[item.severity]}
                        </span>
                        {item.already_converted && (
                          <span className="px-2 py-0.5 text-xs rounded bg-blue-100 text-blue-800">
                            Already Added
                          </span>
                        )}
                      </div>
                      <p className="text-sm text-gray-600 mt-1">
                        Section: {item.section_name} | Response: <strong>{item.response}</strong>
                      </p>
                      {item.failure_reason && (
                        <p className="text-sm text-red-600 mt-1">{item.failure_reason}</p>
                      )}
                      {item.note && (
                        <p className="text-sm text-gray-500 mt-1 italic">Note: {item.note}</p>
                      )}
                      {item.estimated_labor_hours && (
                        <p className="text-xs text-gray-500 mt-1">
                          Est. Labor: {item.estimated_labor_hours}h |
                          Est. Parts: ${item.estimated_parts_cost || '0.00'}
                        </p>
                      )}
                    </div>
                    {!item.already_converted && (
                      <div className="flex gap-1">
                        <button
                          onClick={() => handleDefer(item.id)}
                          className="text-xs text-gray-500 hover:text-gray-700 px-2 py-1"
                        >
                          Defer
                        </button>
                        <button
                          onClick={() => handleDecline(item.id)}
                          className="text-xs text-red-500 hover:text-red-700 px-2 py-1"
                        >
                          Decline
                        </button>
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </Card>

          {/* Action Panel */}
          {pendingItems.length > 0 && (
            <Card>
              <h2 className="text-lg font-semibold mb-4">Add Selected Items</h2>

              <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 mb-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Destination
                  </label>
                  <select
                    value={mode}
                    onChange={(e) => setMode(e.target.value)}
                    className="w-full p-2 border rounded"
                  >
                    <option value="new">Create New Estimate</option>
                    <option value="existing">Add to Existing Estimate</option>
                    <option value="workorder">Add to Workorder (Sub-estimate)</option>
                  </select>
                </div>

                {mode === 'existing' && (
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      Estimate ID
                    </label>
                    <input
                      type="number"
                      value={estimateId}
                      onChange={(e) => setEstimateId(e.target.value)}
                      placeholder="Enter estimate ID"
                      className="w-full p-2 border rounded"
                    />
                  </div>
                )}

                {mode === 'workorder' && (
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      Workorder ID
                    </label>
                    <input
                      type="number"
                      value={workorderId}
                      onChange={(e) => setWorkorderId(e.target.value)}
                      placeholder="Enter workorder ID"
                      className="w-full p-2 border rounded"
                    />
                  </div>
                )}

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Labor Rate ($/hr)
                  </label>
                  <input
                    type="number"
                    step="0.01"
                    value={laborRate}
                    onChange={(e) => setLaborRate(e.target.value)}
                    className="w-full p-2 border rounded"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Tax Rate
                  </label>
                  <input
                    type="number"
                    step="0.001"
                    value={taxRate}
                    onChange={(e) => setTaxRate(e.target.value)}
                    placeholder="e.g., 0.08 for 8%"
                    className="w-full p-2 border rounded"
                  />
                </div>
              </div>

              <div className="flex items-center gap-4">
                <Button
                  onClick={handleSubmit}
                  disabled={processing || selectedItems.length === 0}
                >
                  {processing
                    ? 'Processing...'
                    : `Add ${selectedItems.length} Item${selectedItems.length !== 1 ? 's' : ''}`}
                </Button>
                <span className="text-sm text-gray-600">
                  {selectedItems.length} of {pendingItems.length} items selected
                </span>
              </div>
            </Card>
          )}
        </>
      )}
    </div>
  )
}
