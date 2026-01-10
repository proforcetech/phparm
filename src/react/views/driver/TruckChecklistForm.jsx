import { useCallback, useEffect, useMemo, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'
import truckChecklistService from '../../../services/truck-checklist.service'
import { useToast } from '../../stores/toast'

const checklistLabels = {
  pre_trip: 'Pre-trip',
  post_trip: 'Post-trip',
}

const responseOptions = [
  { value: '', label: 'Select response' },
  { value: 'pass', label: 'Pass' },
  { value: 'fail', label: 'Fail' },
  { value: 'na', label: 'N/A' },
]

export default function TruckChecklistForm() {
  const toast = useToast()
  const [loading, setLoading] = useState(false)
  const [activeShift, setActiveShift] = useState(null)
  const [templates, setTemplates] = useState([])
  const [selectedTemplateId, setSelectedTemplateId] = useState('')
  const [responses, setResponses] = useState({})
  const [notes, setNotes] = useState('')
  const [shiftEnd, setShiftEnd] = useState('')
  const [error, setError] = useState('')

  const checklistType = activeShift ? 'post_trip' : 'pre_trip'

  const loadTemplates = useCallback(async () => {
    const response = await truckChecklistService.listTemplates({ checklist_type: checklistType })
    setTemplates(response.data ?? [])
  }, [checklistType])

  const loadActiveShift = useCallback(async () => {
    const response = await truckChecklistService.getActiveShift()
    setActiveShift(response.data ?? null)
  }, [])

  useEffect(() => {
    loadActiveShift().catch(() => {})
  }, [loadActiveShift])

  useEffect(() => {
    loadTemplates().catch(() => {})
  }, [loadTemplates])

  useEffect(() => {
    const defaultTemplate = templates.find((template) => template.is_default) || templates[0]
    if (defaultTemplate) {
      setSelectedTemplateId(String(defaultTemplate.id))
      const initialResponses = {}
      defaultTemplate.items?.forEach((item) => {
        initialResponses[item.id] = ''
      })
      setResponses(initialResponses)
    } else {
      setSelectedTemplateId('')
      setResponses({})
    }
  }, [templates])

  useEffect(() => {
    if (!activeShift) {
      const defaultEnd = new Date()
      defaultEnd.setHours(defaultEnd.getHours() + 8)
      setShiftEnd(defaultEnd.toISOString().slice(0, 16))
    }
  }, [activeShift])

  const selectedTemplate = useMemo(
    () => templates.find((template) => String(template.id) === String(selectedTemplateId)),
    [templates, selectedTemplateId]
  )

  const handleResponseChange = (itemId, value) => {
    setResponses((prev) => ({ ...prev, [itemId]: value }))
  }

  const validateChecklist = () => {
    if (!selectedTemplate) {
      setError('Select a checklist template to continue.')
      return false
    }

    const missingRequired = (selectedTemplate.items || []).some(
      (item) => item.required && !responses[item.id]
    )

    if (missingRequired) {
      setError('Complete all required checklist items.')
      return false
    }

    if (!activeShift && !shiftEnd) {
      setError('Provide an expected shift end time.')
      return false
    }

    return true
  }

  const buildItemsPayload = () =>
    (selectedTemplate.items || []).map((item) => ({
      template_item_id: item.id,
      response: responses[item.id] || null,
    }))

  const submitChecklist = async () => {
    if (!validateChecklist()) {
      return
    }

    setLoading(true)
    setError('')

    try {
      const payload = {
        template_id: Number(selectedTemplateId),
        items: buildItemsPayload(),
        notes: notes || null,
      }

      if (activeShift) {
        await truckChecklistService.endShift(activeShift.id, payload)
        toast.success('Post-trip checklist submitted. Shift ended.')
      } else {
        await truckChecklistService.startShift({
          ...payload,
          shift_end: shiftEnd,
        })
        toast.success('Pre-trip checklist submitted. Shift started.')
      }

      setNotes('')
      setResponses({})
      await loadActiveShift()
      await loadTemplates()
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to submit checklist.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Truck Checklists</h1>
        <p className="text-sm text-gray-600">
          {activeShift
            ? 'Complete your post-trip checklist to end the shift.'
            : 'Complete your pre-trip checklist before starting the shift.'}
        </p>
      </div>

      {error ? <div className="text-sm text-red-600">{error}</div> : null}

      <Card title={`${checklistLabels[checklistType]} Checklist`}>
        <div className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Template</label>
              <select
                className="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                value={selectedTemplateId}
                onChange={(event) => setSelectedTemplateId(event.target.value)}
              >
                <option value="">Select a template</option>
                {templates.map((template) => (
                  <option key={template.id} value={template.id}>
                    {template.name}
                  </option>
                ))}
              </select>
            </div>
            {!activeShift ? (
              <Input
                label="Expected shift end"
                type="datetime-local"
                value={shiftEnd}
                onChange={(event) => setShiftEnd(event.target.value)}
              />
            ) : null}
          </div>

          {selectedTemplate ? (
            <div className="space-y-3">
              {selectedTemplate.items?.map((item) => (
                <div key={item.id} className="rounded border border-gray-200 p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="text-sm font-semibold text-gray-900">{item.label}</div>
                      {item.description ? <div className="text-xs text-gray-500">{item.description}</div> : null}
                    </div>
                    {item.required ? <span className="text-xs text-red-500">Required</span> : null}
                  </div>
                  <div className="mt-3">
                    <select
                      className="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                      value={responses[item.id] || ''}
                      onChange={(event) => handleResponseChange(item.id, event.target.value)}
                    >
                      {responseOptions.map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className="text-sm text-gray-500">No checklist template found for this shift.</div>
          )}

          <Textarea
            label="Notes"
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
            placeholder="Optional notes for this checklist"
          />

          <div className="flex justify-end">
            <Button onClick={submitChecklist} disabled={loading || !selectedTemplateId}>
              {activeShift ? 'Submit Post-trip Checklist' : 'Submit Pre-trip Checklist'}
            </Button>
          </div>
        </div>
      </Card>
    </div>
  )
}
