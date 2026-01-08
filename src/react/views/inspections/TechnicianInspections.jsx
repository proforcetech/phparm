import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import CameraCapture from '../../components/CameraCapture'
import OfflineStatusBadge from '../../components/OfflineStatusBadge'
import useOfflineStatus from '../../hooks/useOfflineStatus'
import inspectionService from '../../../services/inspection.service'
import { enqueueItem, saveDraft, getDraft, deleteDraft } from '../../utils/offlineQueue'

const DRAFT_KEY = 'inspection_draft'

export default function TechnicianInspections() {
  const navigate = useNavigate()
  const { isOnline, isOffline, hasPendingItems } = useOfflineStatus()
  const [templates, setTemplates] = useState([])
  const [selectedTemplateId, setSelectedTemplateId] = useState('')
  const [customerId, setCustomerId] = useState('')
  const [vehicleId, setVehicleId] = useState('')
  const [summary, setSummary] = useState('')
  const [responses, setResponses] = useState({})
  const [mediaFiles, setMediaFiles] = useState([])
  const [loading, setLoading] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [lastReport, setLastReport] = useState(null)
  const [hasDraft, setHasDraft] = useState(false)

  const selectedTemplate = useMemo(
    () => templates.find((template) => template.id === Number(selectedTemplateId)),
    [selectedTemplateId, templates]
  )

  useEffect(() => {
    const loadTemplates = async () => {
      try {
        const data = await inspectionService.listTemplates()
        setTemplates(data)
      } catch (err) {
        console.error(err)
        setError('Unable to load templates')
      }
    }

    loadTemplates()
  }, [])

  // Load draft on mount
  useEffect(() => {
    const loadDraft = async () => {
      try {
        const draft = await getDraft(DRAFT_KEY)
        if (draft?.data) {
          setSelectedTemplateId(draft.data.selectedTemplateId || '')
          setCustomerId(draft.data.customerId || '')
          setVehicleId(draft.data.vehicleId || '')
          setSummary(draft.data.summary || '')
          if (draft.data.responses) {
            setResponses(draft.data.responses)
          }
          setHasDraft(true)
        }
      } catch (err) {
        console.error('Failed to load draft:', err)
      }
    }
    loadDraft()
  }, [])

  // Auto-save draft when form changes (debounced)
  useEffect(() => {
    if (!selectedTemplateId && !customerId && !summary) return

    const saveTimeout = setTimeout(async () => {
      try {
        await saveDraft(DRAFT_KEY, 'inspection', {
          selectedTemplateId,
          customerId,
          vehicleId,
          summary,
          responses,
        })
        setHasDraft(true)
      } catch (err) {
        console.error('Failed to save draft:', err)
      }
    }, 1000)

    return () => clearTimeout(saveTimeout)
  }, [selectedTemplateId, customerId, vehicleId, summary, responses])

  const clearDraft = async () => {
    try {
      await deleteDraft(DRAFT_KEY)
      setHasDraft(false)
    } catch (err) {
      console.error('Failed to clear draft:', err)
    }
  }

  useEffect(() => {
    if (selectedTemplateId) {
      prepareResponses()
    }
  }, [selectedTemplateId, selectedTemplate])

  function prepareResponses() {
    if (!selectedTemplate) return

    const nextResponses = {}
    selectedTemplate.sections.forEach((section) => {
      section.items.forEach((item) => {
        let defaultResponse = item.default_value || ''

        if (item.input_type === 'number_scale' && item.options) {
          const min = item.options.min || 0
          const max = item.options.max || 10
          defaultResponse = Math.floor((min + max) / 2)
        } else if (item.input_type === 'select_scale' && item.options?.choices?.length) {
          defaultResponse = ''
        } else if (item.input_type === 'boolean_na') {
          defaultResponse = 'na'
        }

        nextResponses[item.id] = {
          template_item_id: item.id,
          label: item.name,
          response: defaultResponse,
          note: '',
        }
      })
    })

    setResponses(nextResponses)
  }

  const handleMediaCapture = useCallback((files) => {
    setMediaFiles(files)
  }, [])

  const uploadMedia = async (reportId) => {
    for (const file of mediaFiles) {
      await inspectionService.uploadMedia(reportId, file)
    }
  }

  const submit = async () => {
    setError('')
    setMessage('')
    setLoading(true)

    const inspectionData = {
      template_id: Number(selectedTemplateId),
      customer_id: Number(customerId),
      vehicle_id: vehicleId ? Number(vehicleId) : null,
      summary,
    }

    const responsePayload = {
      responses: Object.values(responses),
    }

    try {
      if (isOffline) {
        // Queue inspection for later sync
        const clientToken = crypto.randomUUID()

        // Queue the inspection start
        await enqueueItem('inspection_start', {
          ...inspectionData,
          clientToken,
        })

        // Queue media uploads with the same client token reference
        for (const file of mediaFiles) {
          await enqueueItem('inspection_media', {
            reportId: `pending_${clientToken}`,
            file,
            clientToken: crypto.randomUUID(),
          })
        }

        // Queue the completion
        await enqueueItem('inspection_complete', {
          reportId: `pending_${clientToken}`,
          payload: responsePayload,
          clientToken,
        })

        setMessage('Inspection saved offline. It will be submitted when you reconnect.')
        await clearDraft()
        resetForm()
      } else {
        // Online - submit directly
        const report = await inspectionService.startInspection(inspectionData)

        if (mediaFiles.length) {
          await uploadMedia(report.id)
        }

        const completed = await inspectionService.completeInspection(report.id, responsePayload)
        setLastReport(completed)
        setMessage('Inspection completed successfully')
        await clearDraft()
        resetForm()
      }
    } catch (err) {
      console.error(err)

      // If we failed due to network, queue for offline
      if (err.code === 'ERR_NETWORK' || err.message?.includes('Network Error')) {
        const clientToken = crypto.randomUUID()

        await enqueueItem('inspection_start', {
          ...inspectionData,
          clientToken,
        })

        for (const file of mediaFiles) {
          await enqueueItem('inspection_media', {
            reportId: `pending_${clientToken}`,
            file,
            clientToken: crypto.randomUUID(),
          })
        }

        await enqueueItem('inspection_complete', {
          reportId: `pending_${clientToken}`,
          payload: responsePayload,
          clientToken,
        })

        setMessage('Network error - inspection queued for later submission.')
      } else {
        setError(err.response?.data?.message || 'Unable to complete inspection')
      }
    } finally {
      setLoading(false)
    }
  }

  const resetForm = () => {
    setSelectedTemplateId('')
    setCustomerId('')
    setVehicleId('')
    setSummary('')
    setResponses({})
    setMediaFiles([])
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">Technician Inspections</h1>
          <p className="text-sm text-gray-600">Complete inspections and upload supporting media.</p>
          <div className="mt-2 flex items-center gap-2">
            <OfflineStatusBadge showDetails />
            {hasDraft && (
              <span className="text-xs text-amber-600 flex items-center gap-1">
                <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z" />
                  <path fillRule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clipRule="evenodd" />
                </svg>
                Draft saved
              </span>
            )}
          </div>
        </div>
        <div className="flex gap-2">
          {hasDraft && (
            <Button variant="outline" size="sm" onClick={clearDraft}>
              Clear Draft
            </Button>
          )}
          <Button variant="outline" onClick={() => navigate('/cp/inspections/templates')}>
            View Inspection Templates
          </Button>
        </div>
      </div>

      {isOffline && (
        <div className="bg-amber-50 border border-amber-200 rounded-lg p-4">
          <div className="flex items-start gap-3">
            <svg className="w-5 h-5 text-amber-600 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <div>
              <h3 className="font-medium text-amber-800">Offline Mode</h3>
              <p className="text-sm text-amber-700 mt-1">
                You're currently offline. Inspections will be saved locally and automatically synced when you reconnect.
                Your work is being auto-saved as a draft.
              </p>
            </div>
          </div>
        </div>
      )}

      <Card className="space-y-4">
        <div className="grid gap-4 md:grid-cols-2">
          <div>
            <label className="block text-sm font-medium text-gray-700">Template</label>
            <select
              value={selectedTemplateId}
              onChange={(event) => {
                setSelectedTemplateId(event.target.value)
              }}
              className="w-full p-2 border rounded"
            >
              <option disabled value="">Select template</option>
              {templates.map((template) => (
                <option key={template.id} value={template.id}>{template.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Customer ID</label>
            <input
              value={customerId}
              onChange={(event) => setCustomerId(event.target.value)}
              type="number"
              className="w-full p-2 border rounded"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Vehicle ID (optional)</label>
            <input
              value={vehicleId}
              onChange={(event) => setVehicleId(event.target.value)}
              type="number"
              className="w-full p-2 border rounded"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Summary</label>
            <input
              value={summary}
              onChange={(event) => setSummary(event.target.value)}
              type="text"
              className="w-full p-2 border rounded"
            />
          </div>
        </div>

        {selectedTemplate ? (
          <div className="space-y-4">
            {selectedTemplate.sections.map((section) => (
              <div key={section.id} className="p-3 border rounded">
                <h3 className="font-semibold">{section.name}</h3>
                <div className="mt-2 space-y-3">
                  {section.items.map((item) => (
                    <div key={item.id} className="space-y-1">
                      <label className="block text-sm font-medium">{item.name}</label>

                      {item.input_type === 'boolean' ? (
                        <select
                          value={responses[item.id]?.response || ''}
                          onChange={(event) => setResponses((prev) => ({
                            ...prev,
                            [item.id]: { ...prev[item.id], response: event.target.value },
                          }))}
                          className="w-full p-2 border rounded"
                        >
                          <option value="yes">Yes</option>
                          <option value="no">No</option>
                        </select>
                      ) : null}

                      {item.input_type === 'boolean_na' ? (
                        <select
                          value={responses[item.id]?.response || ''}
                          onChange={(event) => setResponses((prev) => ({
                            ...prev,
                            [item.id]: { ...prev[item.id], response: event.target.value },
                          }))}
                          className="w-full p-2 border rounded"
                        >
                          <option value="yes">Yes</option>
                          <option value="no">No</option>
                          <option value="na">N/A</option>
                        </select>
                      ) : null}

                      {item.input_type === 'textarea' ? (
                        <textarea
                          value={responses[item.id]?.response || ''}
                          onChange={(event) => setResponses((prev) => ({
                            ...prev,
                            [item.id]: { ...prev[item.id], response: event.target.value },
                          }))}
                          className="w-full p-2 border rounded"
                          rows={3}
                        />
                      ) : null}

                      {item.input_type === 'number_scale' ? (
                        <div className="space-y-2">
                          <div className="flex items-center space-x-4">
                            <input
                              value={responses[item.id]?.response ?? 0}
                              onChange={(event) => setResponses((prev) => ({
                                ...prev,
                                [item.id]: { ...prev[item.id], response: Number(event.target.value) },
                              }))}
                              type="range"
                              className="flex-1"
                              min={item.options?.min || 0}
                              max={item.options?.max || 10}
                              step={item.options?.step || 1}
                            />
                            <span className="text-lg font-semibold text-indigo-600 min-w-[3rem] text-center">
                              {responses[item.id]?.response ?? 0}
                            </span>
                          </div>
                          <div className="flex justify-between text-xs text-gray-500">
                            <span>{item.options?.min || 0}</span>
                            <span>{item.options?.max || 10}</span>
                          </div>
                        </div>
                      ) : null}

                      {item.input_type === 'select_scale' ? (
                        <select
                          value={responses[item.id]?.response || ''}
                          onChange={(event) => setResponses((prev) => ({
                            ...prev,
                            [item.id]: { ...prev[item.id], response: event.target.value },
                          }))}
                          className="w-full p-2 border rounded"
                        >
                          <option value="" disabled>Select...</option>
                          {(item.options?.choices || []).map((choice) => (
                            <option key={choice} value={choice}>{choice}</option>
                          ))}
                        </select>
                      ) : null}

                      {!['boolean', 'boolean_na', 'textarea', 'number_scale', 'select_scale'].includes(item.input_type) ? (
                        <input
                          value={responses[item.id]?.response || ''}
                          onChange={(event) => setResponses((prev) => ({
                            ...prev,
                            [item.id]: { ...prev[item.id], response: event.target.value },
                          }))}
                          className="w-full p-2 border rounded"
                          type={item.input_type === 'number' ? 'number' : 'text'}
                        />
                      ) : null}

                      <textarea
                        value={responses[item.id]?.note || ''}
                        onChange={(event) => setResponses((prev) => ({
                          ...prev,
                          [item.id]: { ...prev[item.id], note: event.target.value },
                        }))}
                        className="w-full p-2 border rounded text-sm"
                        rows={2}
                        placeholder="Notes (optional)"
                      />
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        ) : null}

        <div className="border-t pt-4">
          <h3 className="text-lg font-medium text-gray-900 mb-2">Visual Evidence</h3>
          <p className="text-sm text-gray-600 mb-4">
            Capture photos or videos of mechanical failures to increase estimate approval rates.
            High-resolution visual proof significantly helps customers understand repair needs.
          </p>
          <CameraCapture onCapture={handleMediaCapture} maxVideoDuration={120} />
        </div>

        <div className="flex space-x-2">
          <Button onClick={submit} disabled={loading}>
            {loading ? 'Submitting...' : 'Complete Inspection'}
          </Button>
          {message ? <p className="text-sm text-green-600">{message}</p> : null}
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
        </div>
      </Card>

      {lastReport ? (
        <Card>
          <h2 className="text-lg font-semibold mb-2">Last Submitted Report</h2>
          <pre className="text-sm bg-gray-50 p-3 rounded overflow-auto">{JSON.stringify(lastReport, null, 2)}</pre>
        </Card>
      ) : null}
    </div>
  )
}
