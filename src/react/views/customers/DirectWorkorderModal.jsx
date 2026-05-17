import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import SubjectPicker from '../../components/domain/SubjectPicker'
import serviceLineService from '../../../services/serviceLine.service'
import workorderService from '../../../services/workorder.service'
import { useToast } from '../../stores/toast.jsx'

const initialJob = () => ({
  title: '',
  items: [
    { description: '', quantity: 1, unit_price: 0 },
  ],
})

const initialState = () => ({
  service_line_id: null,
  vehicle_id: null,
  site_asset_id: null,
  type: 'corrective',
  priority: 'normal',
  customer_notes: '',
  jobs: [initialJob()],
})

const TYPE_OPTIONS = [
  { value: 'corrective', label: 'Corrective' },
  { value: 'preventive', label: 'Preventive' },
  { value: 'inspection', label: 'Inspection' },
  { value: 'install', label: 'Install' },
]

const PRIORITY_OPTIONS = [
  { value: 'low', label: 'Low' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'High' },
  { value: 'urgent', label: 'Urgent' },
]

/**
 * Compact "create direct WO" modal for B2B customer detail. Supports the
 * minimum the backend requires (service line + subject FK + at least one job
 * with one line item) and forwards anything more elaborate to /cp/workorders/{id}
 * after creation. The contract gate is server-side — we don't pre-filter
 * service lines here; if the customer's company has no active contract for
 * the chosen line, the API responds with 400 and we surface the message.
 */
export default function DirectWorkorderModal({ open, customer, onClose, onCreated }) {
  const navigate = useNavigate()
  const toast = useToast()
  const [serviceLines, setServiceLines] = useState([])
  const [linesLoading, setLinesLoading] = useState(false)
  const [form, setForm] = useState(initialState)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!open) return
    setForm(initialState())
    setError('')
    let cancelled = false
    setLinesLoading(true)
    serviceLineService
      .list()
      .then((data) => {
        if (cancelled) return
        setServiceLines(Array.isArray(data) ? data : [])
      })
      .catch(() => {
        if (!cancelled) setServiceLines([])
      })
      .finally(() => {
        if (!cancelled) setLinesLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [open])

  const activeLine = useMemo(
    () => serviceLines.find((line) => line.id === form.service_line_id) ?? null,
    [serviceLines, form.service_line_id]
  )

  const handleSubjectChange = (next) => {
    setForm((prev) => ({
      ...prev,
      service_line_id: next.service_line_id ?? prev.service_line_id,
      vehicle_id: next.vehicle_id ?? null,
      site_asset_id: next.site_asset_id ?? null,
    }))
  }

  const updateJob = (index, patch) => {
    setForm((prev) => {
      const jobs = prev.jobs.map((job, i) => (i === index ? { ...job, ...patch } : job))
      return { ...prev, jobs }
    })
  }

  const updateItem = (jobIndex, itemIndex, patch) => {
    setForm((prev) => {
      const jobs = prev.jobs.map((job, i) => {
        if (i !== jobIndex) return job
        const items = job.items.map((item, j) => (j === itemIndex ? { ...item, ...patch } : item))
        return { ...job, items }
      })
      return { ...prev, jobs }
    })
  }

  const addItem = (jobIndex) => {
    setForm((prev) => {
      const jobs = prev.jobs.map((job, i) =>
        i === jobIndex
          ? { ...job, items: [...job.items, { description: '', quantity: 1, unit_price: 0 }] }
          : job
      )
      return { ...prev, jobs }
    })
  }

  const handleSubmit = async (event) => {
    event?.preventDefault()
    setError('')

    if (!form.service_line_id) {
      setError('Pick a service line.')
      return
    }
    if (!form.jobs.length || !form.jobs[0].title.trim()) {
      setError('At least one job with a title is required.')
      return
    }

    const payload = {
      customer_id: customer.id,
      service_line_id: form.service_line_id,
      type: form.type,
      priority: form.priority,
      customer_notes: form.customer_notes || null,
      jobs: form.jobs.map((job) => ({
        title: job.title.trim(),
        items: job.items
          .filter((item) => item.description.trim() !== '' || Number(item.quantity) > 0)
          .map((item) => ({
            description: item.description.trim(),
            quantity: Number(item.quantity) || 0,
            unit_price: Number(item.unit_price) || 0,
            taxable: true,
          })),
      })),
    }
    if (form.vehicle_id) payload.vehicle_id = form.vehicle_id
    if (form.site_asset_id) payload.site_asset_id = form.site_asset_id

    setSubmitting(true)
    try {
      const response = await workorderService.createDirect(payload)
      const data = response?.data?.data ?? response?.data ?? null
      const id = data?.id
      toast.success?.('Work order created.')
      onCreated?.(data)
      if (id) {
        navigate(`/cp/workorders/${id}`)
      } else {
        onClose?.()
      }
    } catch (submitError) {
      setError(
        submitError?.response?.data?.message
          || submitError?.message
          || 'Failed to create work order.'
      )
    } finally {
      setSubmitting(false)
    }
  }

  const lineOptions = useMemo(
    () =>
      (serviceLines || [])
        .filter((line) => line.is_active !== false)
        .map((line) => ({
          value: line.id,
          label: `${line.icon ? line.icon + ' ' : ''}${line.name}`,
        })),
    [serviceLines]
  )

  return (
    <Modal
      open={open}
      title="Create direct work order"
      size="lg"
      onClose={() => (submitting ? null : onClose?.())}
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <p className="text-sm text-gray-500">
          For B2B customers under an active contract. Skips the estimate stage.
        </p>

        {error ? <Alert variant="danger">{error}</Alert> : null}

        <div>
          <label className="block text-sm font-medium text-gray-700">Service line *</label>
          <Select
            value={form.service_line_id ?? ''}
            onChange={(event) =>
              setForm((prev) => ({
                ...prev,
                service_line_id: event.target.value ? Number(event.target.value) : null,
                vehicle_id: null,
                site_asset_id: null,
              }))
            }
            disabled={linesLoading}
            options={lineOptions}
            placeholder={linesLoading ? 'Loading…' : 'Pick a service line'}
          />
        </div>

        {activeLine ? (
          <SubjectPicker
            availableServiceLines={serviceLines}
            serviceLineId={form.service_line_id}
            vehicleId={form.vehicle_id}
            siteAssetId={form.site_asset_id}
            customerId={customer.id}
            showServiceLineSelector={false}
            onChange={handleSubjectChange}
          />
        ) : null}

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Type</label>
            <Select
              value={form.type}
              onChange={(event) => setForm((prev) => ({ ...prev, type: event.target.value }))}
              options={TYPE_OPTIONS}
              placeholder=""
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Priority</label>
            <Select
              value={form.priority}
              onChange={(event) => setForm((prev) => ({ ...prev, priority: event.target.value }))}
              options={PRIORITY_OPTIONS}
              placeholder=""
            />
          </div>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700">Customer notes</label>
          <Textarea
            value={form.customer_notes}
            rows={2}
            onChange={(event) =>
              setForm((prev) => ({ ...prev, customer_notes: event.target.value }))
            }
          />
        </div>

        {form.jobs.map((job, jobIndex) => (
          <fieldset key={jobIndex} className="rounded-md border border-gray-200 p-4">
            <legend className="px-2 text-sm font-medium text-gray-700">
              Job {jobIndex + 1}
            </legend>
            <div className="space-y-3">
              <div>
                <label className="block text-sm font-medium text-gray-700">Title *</label>
                <Input
                  value={job.title}
                  required
                  placeholder="e.g. Quarterly preventive maintenance"
                  onChange={(event) => updateJob(jobIndex, { title: event.target.value })}
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700">Line items</label>
                <div className="space-y-2">
                  {job.items.map((item, itemIndex) => (
                    <div
                      key={itemIndex}
                      className="grid grid-cols-12 gap-2 items-start"
                    >
                      <div className="col-span-7">
                        <Input
                          value={item.description}
                          placeholder="Description"
                          onChange={(event) =>
                            updateItem(jobIndex, itemIndex, { description: event.target.value })
                          }
                        />
                      </div>
                      <div className="col-span-2">
                        <Input
                          type="number"
                          min="0"
                          step="0.01"
                          value={item.quantity}
                          onChange={(event) =>
                            updateItem(jobIndex, itemIndex, { quantity: event.target.value })
                          }
                        />
                      </div>
                      <div className="col-span-3">
                        <Input
                          type="number"
                          min="0"
                          step="0.01"
                          value={item.unit_price}
                          onChange={(event) =>
                            updateItem(jobIndex, itemIndex, { unit_price: event.target.value })
                          }
                        />
                      </div>
                    </div>
                  ))}
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="mt-2"
                  onClick={() => addItem(jobIndex)}
                >
                  + Add item
                </Button>
              </div>
            </div>
          </fieldset>
        ))}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" loading={submitting}>
            Create work order
          </Button>
        </div>
      </form>
    </Modal>
  )
}
