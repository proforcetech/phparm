import { useEffect, useMemo, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import inspectionService from '../../../services/inspection.service'

export default function TechnicianInspections() {
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

  const onFiles = (event) => {
    setMediaFiles(Array.from(event.target.files || []))
  }

  const uploadMedia = async (reportId) => {
    for (const file of mediaFiles) {
      await inspectionService.uploadMedia(reportId, file)
    }
  }

  const submit = async () => {
    setError('')
    setMessage('')
    setLoading(true)
    try {
      const report = await inspectionService.startInspection({
        template_id: Number(selectedTemplateId),
        customer_id: Number(customerId),
        vehicle_id: vehicleId ? Number(vehicleId) : null,
        summary,
      })

      if (mediaFiles.length) {
        await uploadMedia(report.id)
      }

      const payload = {
        responses: Object.values(responses),
      }
      const completed = await inspectionService.completeInspection(report.id, payload)
      setLastReport(completed)
      setMessage('Inspection completed')
    } catch (err) {
      console.error(err)
      setError(err.response?.data?.message || 'Unable to complete inspection')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Technician Inspections</h1>
        <p className="text-sm text-gray-600">Complete inspections and upload supporting media.</p>
      </div>

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

        <div>
          <label className="block text-sm font-medium text-gray-700">Attach photos/videos</label>
          <input type="file" multiple accept="image/*,video/*" onChange={onFiles} />
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
