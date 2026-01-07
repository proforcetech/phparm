import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import api from '../../../services/api'

const STATUS_COLORS = {
  pending: 'bg-gray-100 text-gray-800',
  in_progress: 'bg-blue-100 text-blue-800',
  passed: 'bg-green-100 text-green-800',
  passed_with_notes: 'bg-yellow-100 text-yellow-800',
  failed: 'bg-red-100 text-red-800',
}

const STATUS_LABELS = {
  pending: 'Not Started',
  in_progress: 'In Progress',
  passed: 'Passed',
  passed_with_notes: 'Passed with Notes',
  failed: 'Failed',
}

export default function QCChecklist() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [check, setCheck] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [overallNotes, setOverallNotes] = useState('')
  const [itemResponses, setItemResponses] = useState({})

  useEffect(() => {
    loadQCCheck()
  }, [id])

  async function loadQCCheck() {
    setLoading(true)
    setError('')
    try {
      const response = await api.get(`/workorders/${id}/qc-check`)
      if (response.data.status === 'not_initialized') {
        setCheck(null)
      } else {
        setCheck(response.data)
        initializeResponses(response.data.items)
      }
    } catch (err) {
      console.error(err)
      setError(err.response?.data?.error || 'Failed to load QC check')
    } finally {
      setLoading(false)
    }
  }

  function initializeResponses(items) {
    const responses = {}
    items.forEach((item) => {
      responses[item.template_item_id] = {
        passed: item.passed,
        notes: item.notes || '',
      }
    })
    setItemResponses(responses)
  }

  async function handleInitialize() {
    setSaving(true)
    setError('')
    try {
      const response = await api.post(`/workorders/${id}/qc-check/initialize`)
      setCheck(response.data)
      initializeResponses(response.data.items)
      setMessage('QC check initialized')
    } catch (err) {
      console.error(err)
      setError(err.response?.data?.error || 'Failed to initialize QC check')
    } finally {
      setSaving(false)
    }
  }

  function handleItemChange(templateItemId, field, value) {
    setItemResponses((prev) => ({
      ...prev,
      [templateItemId]: {
        ...prev[templateItemId],
        [field]: value,
      },
    }))
  }

  async function handleSave() {
    setSaving(true)
    setError('')
    try {
      const items = {}
      Object.entries(itemResponses).forEach(([itemId, data]) => {
        if (data.passed !== null) {
          items[itemId] = data
        }
      })

      await api.patch(`/qc/checks/${check.id}/items`, { items })
      setMessage('Progress saved')
      await loadQCCheck()
    } catch (err) {
      console.error(err)
      setError(err.response?.data?.error || 'Failed to save progress')
    } finally {
      setSaving(false)
    }
  }

  async function handleComplete() {
    setSaving(true)
    setError('')
    try {
      // First save all items
      const items = {}
      Object.entries(itemResponses).forEach(([itemId, data]) => {
        if (data.passed !== null) {
          items[itemId] = data
        }
      })

      await api.patch(`/qc/checks/${check.id}/items`, { items })

      // Then complete the check
      const response = await api.post(`/qc/checks/${check.id}/complete`, {
        notes: overallNotes,
      })

      setCheck(response.data)
      setMessage('QC check completed: ' + STATUS_LABELS[response.data.status])
    } catch (err) {
      console.error(err)
      setError(err.response?.data?.error || 'Failed to complete QC check')
    } finally {
      setSaving(false)
    }
  }

  // Group items by category
  function getGroupedItems() {
    if (!check?.items) return {}

    const groups = {}
    check.items.forEach((item) => {
      const category = item.category || 'General'
      if (!groups[category]) {
        groups[category] = []
      }
      groups[category].push(item)
    })
    return groups
  }

  const isCompleted = check && ['passed', 'passed_with_notes', 'failed'].includes(check.status)
  const progress = check?.items
    ? check.items.filter((i) => itemResponses[i.template_item_id]?.passed !== null).length
    : 0
  const total = check?.items?.length || 0

  if (loading) {
    return (
      <div className="p-6">
        <p className="text-gray-600">Loading QC check...</p>
      </div>
    )
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">Quality Control Checklist</h1>
          <p className="text-sm text-gray-600">Workorder #{id}</p>
        </div>
        <Button variant="outline" onClick={() => navigate(`/cp/workorders/${id}`)}>
          Back to Workorder
        </Button>
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

      {!check ? (
        <Card>
          <div className="text-center py-8">
            <h2 className="text-lg font-semibold mb-2">QC Check Not Initialized</h2>
            <p className="text-gray-600 mb-4">
              A quality control checklist has not been started for this workorder.
            </p>
            <Button onClick={handleInitialize} disabled={saving}>
              {saving ? 'Initializing...' : 'Start QC Check'}
            </Button>
          </div>
        </Card>
      ) : (
        <>
          {/* Status & Progress */}
          <Card>
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div>
                <h2 className="text-lg font-semibold">{check.template_name}</h2>
                <p className="text-sm text-gray-600">{check.template_description}</p>
              </div>
              <div className="flex items-center gap-4">
                <span className={`px-3 py-1 rounded-full text-sm font-medium ${STATUS_COLORS[check.status]}`}>
                  {STATUS_LABELS[check.status]}
                </span>
                <span className="text-sm text-gray-600">
                  {progress} of {total} items checked
                </span>
              </div>
            </div>

            {/* Progress bar */}
            <div className="mt-4">
              <div className="w-full bg-gray-200 rounded-full h-2">
                <div
                  className="bg-indigo-600 h-2 rounded-full transition-all"
                  style={{ width: `${total > 0 ? (progress / total) * 100 : 0}%` }}
                />
              </div>
            </div>
          </Card>

          {/* Checklist Items by Category */}
          {Object.entries(getGroupedItems()).map(([category, items]) => (
            <Card key={category}>
              <h3 className="text-lg font-semibold mb-4 border-b pb-2">{category}</h3>
              <div className="space-y-4">
                {items.map((item) => {
                  const response = itemResponses[item.template_item_id] || { passed: null, notes: '' }
                  return (
                    <div key={item.id} className="border rounded-lg p-4">
                      <div className="flex items-start gap-4">
                        <div className="flex-1">
                          <div className="flex items-center gap-2">
                            <span className="font-medium">{item.label}</span>
                            {item.required ? (
                              <span className="text-red-500 text-xs">*Required</span>
                            ) : null}
                          </div>
                          {item.description && (
                            <p className="text-sm text-gray-600 mt-1">{item.description}</p>
                          )}
                        </div>

                        <div className="flex gap-2">
                          <button
                            type="button"
                            disabled={isCompleted}
                            onClick={() => handleItemChange(item.template_item_id, 'passed', true)}
                            className={`px-4 py-2 rounded border ${
                              response.passed === true
                                ? 'bg-green-500 text-white border-green-500'
                                : 'bg-white text-gray-700 border-gray-300 hover:bg-green-50'
                            } ${isCompleted ? 'opacity-50 cursor-not-allowed' : ''}`}
                          >
                            Pass
                          </button>
                          <button
                            type="button"
                            disabled={isCompleted}
                            onClick={() => handleItemChange(item.template_item_id, 'passed', false)}
                            className={`px-4 py-2 rounded border ${
                              response.passed === false
                                ? 'bg-red-500 text-white border-red-500'
                                : 'bg-white text-gray-700 border-gray-300 hover:bg-red-50'
                            } ${isCompleted ? 'opacity-50 cursor-not-allowed' : ''}`}
                          >
                            Fail
                          </button>
                        </div>
                      </div>

                      {/* Notes field (shows when failed or always for optional) */}
                      {(response.passed === false || response.notes) && (
                        <div className="mt-3">
                          <input
                            type="text"
                            placeholder="Add notes..."
                            value={response.notes}
                            onChange={(e) => handleItemChange(item.template_item_id, 'notes', e.target.value)}
                            disabled={isCompleted}
                            className="w-full p-2 border rounded text-sm"
                          />
                        </div>
                      )}

                      {/* Show saved data for completed checks */}
                      {isCompleted && item.checked_at && (
                        <p className="text-xs text-gray-500 mt-2">
                          Checked at: {new Date(item.checked_at).toLocaleString()}
                        </p>
                      )}
                    </div>
                  )
                })}
              </div>
            </Card>
          ))}

          {/* Overall Notes & Actions */}
          {!isCompleted && (
            <Card>
              <h3 className="text-lg font-semibold mb-4">Complete QC Check</h3>

              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Overall Notes (optional)
                </label>
                <textarea
                  value={overallNotes}
                  onChange={(e) => setOverallNotes(e.target.value)}
                  placeholder="Add any overall observations or notes..."
                  rows={3}
                  className="w-full p-2 border rounded"
                />
              </div>

              <div className="flex gap-3">
                <Button variant="outline" onClick={handleSave} disabled={saving}>
                  {saving ? 'Saving...' : 'Save Progress'}
                </Button>
                <Button onClick={handleComplete} disabled={saving}>
                  {saving ? 'Completing...' : 'Complete QC Check'}
                </Button>
              </div>

              <p className="text-sm text-gray-500 mt-3">
                All required items must be checked before completing. Items marked as failed will
                result in a failed QC status.
              </p>
            </Card>
          )}

          {/* Completed Check Summary */}
          {isCompleted && (
            <Card>
              <h3 className="text-lg font-semibold mb-4">QC Check Summary</h3>
              <dl className="grid grid-cols-2 gap-4">
                <div>
                  <dt className="text-sm text-gray-600">Status</dt>
                  <dd className="font-medium">{STATUS_LABELS[check.status]}</dd>
                </div>
                <div>
                  <dt className="text-sm text-gray-600">Completed At</dt>
                  <dd className="font-medium">
                    {check.completed_at ? new Date(check.completed_at).toLocaleString() : 'N/A'}
                  </dd>
                </div>
              </dl>
              {check.overall_notes && (
                <div className="mt-4">
                  <dt className="text-sm text-gray-600">Notes</dt>
                  <dd className="mt-1 p-3 bg-gray-50 rounded">{check.overall_notes}</dd>
                </div>
              )}
            </Card>
          )}
        </>
      )}
    </div>
  )
}
