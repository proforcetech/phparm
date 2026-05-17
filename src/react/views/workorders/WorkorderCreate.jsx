import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Autocomplete from '../../components/ui/Autocomplete'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import SubjectPicker from '../../components/domain/SubjectPicker'
import crmService from '../../../services/crm.service'
import customerService from '../../../services/customer.service'
import fleetService from '../../../services/fleet.service'
import serviceLineService from '../../../services/serviceLine.service'
import userService from '../../../services/user.service'
import workorderService from '../../../services/workorder.service'
import { useAuthStore } from '../../stores/auth'
import { useToast } from '../../stores/toast'

const TYPE_OPTIONS = [
  { value: 'corrective', label: 'Corrective' },
  { value: 'preventive', label: 'Preventive' },
  { value: 'inspection', label: 'Inspection' },
  { value: 'install', label: 'Install' },
  { value: 'project', label: 'Project' },
  { value: 'recurring_visit', label: 'Recurring Visit' },
  { value: 'change_request', label: 'Change Request' },
]

const PRIORITY_OPTIONS = [
  { value: 'low', label: 'Low' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'High' },
  { value: 'urgent', label: 'Urgent' },
]

const WORK_CONTEXT_OPTIONS = [
  { value: 'contract', label: 'Contract customer' },
  { value: 'internal', label: 'Internal company' },
]

const ITEM_TYPE_OPTIONS = [
  { value: 'LABOR', label: 'Labor' },
  { value: 'PART', label: 'Part' },
  { value: 'OTHER', label: 'Other' },
]

const initialJob = () => ({
  title: '',
  notes: '',
  items: [
    { type: 'LABOR', description: '', quantity: 1, unit_price: 0, taxable: true },
  ],
})

const initialForm = () => ({
  work_context: 'contract',
  customer_id: null,
  service_line_id: null,
  vehicle_id: null,
  site_asset_id: null,
  internal_company_id: '',
  fleet_unit_id: '',
  type: 'corrective',
  priority: 'normal',
  assigned_technician_id: '',
  mileage_in: '',
  customer_notes: '',
  internal_notes: '',
  tax_rate: 0,
  jobs: [initialJob()],
})

function customerLabel(customer) {
  return customer?.business_name || customer?.name || `Customer #${customer?.id}`
}

function customerSubtext(customer) {
  return [customer?.email, customer?.phone, customer?.company_id ? `Company #${customer.company_id}` : null]
    .filter(Boolean)
    .join(' - ')
}

function fleetUnitLabel(unit) {
  const vehicle = [unit.year, unit.make, unit.model].filter(Boolean).join(' ')
  const suffix = vehicle ? ` - ${vehicle}` : ''
  return `${unit.unit_number || `Unit #${unit.id}`}${suffix}`
}

export default function WorkorderCreate() {
  const navigate = useNavigate()
  const toast = useToast()
  const { hasPermission } = useAuthStore()
  const canCreateDirect = hasPermission('workorders.create_direct')

  const [form, setForm] = useState(initialForm)
  const [serviceLines, setServiceLines] = useState([])
  const [technicians, setTechnicians] = useState([])
  const [companies, setCompanies] = useState([])
  const [fleetUnits, setFleetUnits] = useState([])
  const [loadingLookups, setLoadingLookups] = useState(true)
  const [fleetLoading, setFleetLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoadingLookups(true)
    Promise.all([
      serviceLineService.list(),
      userService.listUsers({ role: 'technician' }).catch(() => []),
      crmService.listCompanies({ status: 'active', limit: 200 }).catch(() => ({ data: [] })),
    ])
      .then(([lines, users, companyPayload]) => {
        if (cancelled) return
        const activeLines = (Array.isArray(lines) ? lines : []).filter((line) => line.is_active !== false)
        setServiceLines(activeLines)
        setTechnicians((Array.isArray(users) ? users : users?.data || []).filter((user) => user.role === 'technician'))
        setCompanies(companyPayload?.data || [])
        setForm((prev) => ({
          ...prev,
          service_line_id: prev.service_line_id || activeLines[0]?.id || null,
        }))
      })
      .catch(() => {
        if (!cancelled) setError('Failed to load workorder lookups.')
      })
      .finally(() => {
        if (!cancelled) setLoadingLookups(false)
      })
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    if (form.work_context !== 'internal' || !form.internal_company_id) {
      setFleetUnits([])
      return
    }

    let cancelled = false
    setFleetLoading(true)
    fleetService
      .listUnits({ company_id: form.internal_company_id, status: 'active', limit: 200 })
      .then((payload) => {
        if (!cancelled) setFleetUnits(payload?.data || [])
      })
      .catch(() => {
        if (!cancelled) setFleetUnits([])
      })
      .finally(() => {
        if (!cancelled) setFleetLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [form.work_context, form.internal_company_id])

  const serviceLineOptions = useMemo(
    () => serviceLines.map((line) => ({
      value: line.id,
      label: `${line.icon ? line.icon + ' ' : ''}${line.name}`,
    })),
    [serviceLines]
  )

  const activeLine = useMemo(
    () => serviceLines.find((line) => line.id === form.service_line_id) || null,
    [form.service_line_id, serviceLines]
  )

  const technicianOptions = useMemo(
    () => [
      { value: '', label: 'Unassigned' },
      ...technicians.map((tech) => ({ value: tech.id, label: tech.name })),
    ],
    [technicians]
  )

  const companyOptions = useMemo(
    () => [
      { value: '', label: 'No company selected' },
      ...companies.map((company) => ({ value: company.id, label: company.name || `Company #${company.id}` })),
    ],
    [companies]
  )

  const fleetUnitOptions = useMemo(
    () => [
      { value: '', label: fleetLoading ? 'Loading units...' : 'No fleet unit' },
      ...fleetUnits.map((unit) => ({ value: unit.id, label: fleetUnitLabel(unit) })),
    ],
    [fleetLoading, fleetUnits]
  )

  const searchCustomers = useCallback((query) => (
    customerService.listCustomers({ query, commercial: 1, limit: 10 })
  ), [])

  const updateForm = (patch) => {
    setForm((prev) => ({ ...prev, ...patch }))
  }

  const updateJob = (jobIndex, patch) => {
    setForm((prev) => ({
      ...prev,
      jobs: prev.jobs.map((job, index) => (index === jobIndex ? { ...job, ...patch } : job)),
    }))
  }

  const updateItem = (jobIndex, itemIndex, patch) => {
    setForm((prev) => ({
      ...prev,
      jobs: prev.jobs.map((job, index) => {
        if (index !== jobIndex) return job
        return {
          ...job,
          items: job.items.map((item, i) => (i === itemIndex ? { ...item, ...patch } : item)),
        }
      }),
    }))
  }

  const addJob = () => {
    setForm((prev) => ({ ...prev, jobs: [...prev.jobs, initialJob()] }))
  }

  const removeJob = (jobIndex) => {
    setForm((prev) => ({
      ...prev,
      jobs: prev.jobs.length > 1 ? prev.jobs.filter((_, index) => index !== jobIndex) : prev.jobs,
    }))
  }

  const addItem = (jobIndex) => {
    setForm((prev) => ({
      ...prev,
      jobs: prev.jobs.map((job, index) => (
        index === jobIndex
          ? {
            ...job,
            items: [...job.items, { type: 'LABOR', description: '', quantity: 1, unit_price: 0, taxable: true }],
          }
          : job
      )),
    }))
  }

  const removeItem = (jobIndex, itemIndex) => {
    setForm((prev) => ({
      ...prev,
      jobs: prev.jobs.map((job, index) => (
        index === jobIndex
          ? { ...job, items: job.items.filter((_, i) => i !== itemIndex) }
          : job
      )),
    }))
  }

  const buildPayload = () => {
    const isInternal = form.work_context === 'internal'
    const payload = {
      work_context: form.work_context,
      is_internal: isInternal,
      service_line_id: form.service_line_id,
      type: form.type,
      priority: form.priority,
      assigned_technician_id: form.assigned_technician_id || null,
      mileage_in: form.mileage_in === '' ? null : Number(form.mileage_in),
      internal_notes: form.internal_notes || null,
      customer_notes: isInternal ? null : (form.customer_notes || null),
      tax_rate: Number(form.tax_rate) || 0,
      jobs: form.jobs.map((job) => ({
        title: job.title.trim(),
        notes: job.notes || null,
        items: job.items
          .filter((item) => item.description.trim() !== '' || Number(item.unit_price) > 0)
          .map((item) => ({
            type: item.type || 'LABOR',
            description: item.description.trim(),
            quantity: Number(item.quantity) || 0,
            unit_price: Number(item.unit_price) || 0,
            taxable: !!item.taxable,
          })),
      })),
    }

    if (!isInternal) payload.customer_id = form.customer_id
    if (form.vehicle_id) payload.vehicle_id = form.vehicle_id
    if (form.site_asset_id) payload.site_asset_id = form.site_asset_id
    if (form.fleet_unit_id) payload.fleet_unit_id = Number(form.fleet_unit_id)

    return payload
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    setError('')

    if (!canCreateDirect) {
      setError('You do not have permission to create direct workorders.')
      return
    }
    if (!form.service_line_id) {
      setError('Service line is required.')
      return
    }
    if (form.work_context === 'contract' && !form.customer_id) {
      setError('Customer is required for contract work.')
      return
    }
    if (!form.jobs.some((job) => job.title.trim() !== '')) {
      setError('At least one job title is required.')
      return
    }

    setSubmitting(true)
    try {
      const response = await workorderService.createDirect(buildPayload())
      const data = response?.data?.data ?? response?.data ?? null
      toast.success?.('Workorder created.')
      if (data?.id) {
        navigate(`/cp/workorders/${data.id}`)
      } else {
        navigate('/cp/workorders')
      }
    } catch (submitError) {
      setError(
        submitError?.response?.data?.message
          || submitError?.response?.data?.error
          || submitError?.message
          || 'Failed to create workorder.'
      )
    } finally {
      setSubmitting(false)
    }
  }

  const showSubjectPicker =
    form.work_context === 'contract'
    || activeLine?.subject_column === 'site_asset_id'

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Create Workorder</h1>
          <p className="mt-1 text-sm text-gray-500">Direct creation without an estimate</p>
        </div>
        <div className="flex gap-2">
          <Link to="/cp/workorders">
            <Button variant="outline">Cancel</Button>
          </Link>
          <Button type="submit" loading={submitting} disabled={loadingLookups || !canCreateDirect}>
            Create workorder
          </Button>
        </div>
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}
      {!canCreateDirect ? (
        <Alert variant="warning">Direct workorder creation is not enabled for your role.</Alert>
      ) : null}

      <Card title="Work Context">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Select
            label="Work context"
            value={form.work_context}
            options={WORK_CONTEXT_OPTIONS}
            placeholder=""
            onChange={(event) => updateForm({
              work_context: event.target.value,
              customer_id: null,
              vehicle_id: null,
              site_asset_id: null,
              internal_company_id: '',
              fleet_unit_id: '',
              customer_notes: '',
            })}
          />
          <Select
            label="Service line"
            value={form.service_line_id ?? ''}
            options={serviceLineOptions}
            disabled={loadingLookups}
            placeholder={loadingLookups ? 'Loading...' : 'Select a service line'}
            required
            onChange={(event) => updateForm({
              service_line_id: event.target.value ? Number(event.target.value) : null,
              vehicle_id: null,
              site_asset_id: null,
            })}
          />
        </div>

        <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
          {form.work_context === 'contract' ? (
            <Autocomplete
              label="Customer"
              placeholder="Search business customers..."
              modelValue={form.customer_id}
              searchFn={searchCustomers}
              itemValue={(item) => item.id}
              itemLabel={customerLabel}
              itemSubtext={customerSubtext}
              required
              onSelect={(customer) => updateForm({
                customer_id: customer?.id ?? null,
                vehicle_id: null,
                site_asset_id: null,
              })}
              onUpdateModelValue={(value) => updateForm({
                customer_id: value,
                vehicle_id: null,
                site_asset_id: null,
              })}
            />
          ) : (
            <>
              <Select
                label="Internal company"
                value={form.internal_company_id}
                options={companyOptions}
                placeholder=""
                onChange={(event) => updateForm({
                  internal_company_id: event.target.value,
                  fleet_unit_id: '',
                })}
              />
              <Select
                label="Fleet unit"
                value={form.fleet_unit_id}
                options={fleetUnitOptions}
                placeholder=""
                disabled={!form.internal_company_id || fleetLoading}
                onChange={(event) => updateForm({ fleet_unit_id: event.target.value })}
              />
            </>
          )}

          <Select
            label="Type"
            value={form.type}
            options={TYPE_OPTIONS}
            placeholder=""
            onChange={(event) => updateForm({ type: event.target.value })}
          />
          <Select
            label="Priority"
            value={form.priority}
            options={PRIORITY_OPTIONS}
            placeholder=""
            onChange={(event) => updateForm({ priority: event.target.value })}
          />
          <Select
            label="Assigned technician"
            value={form.assigned_technician_id}
            options={technicianOptions}
            placeholder=""
            onChange={(event) => updateForm({ assigned_technician_id: event.target.value })}
          />
          <Input
            label="Mileage in"
            type="number"
            min="0"
            value={form.mileage_in}
            onUpdateModelValue={(value) => updateForm({ mileage_in: value })}
          />
        </div>

        {showSubjectPicker && form.service_line_id ? (
          <div className="mt-4">
            <SubjectPicker
              availableServiceLines={serviceLines}
              serviceLineId={form.service_line_id}
              vehicleId={form.vehicle_id}
              siteAssetId={form.site_asset_id}
              customerId={form.work_context === 'contract' ? form.customer_id : null}
              required={form.work_context === 'contract' ? undefined : false}
              showServiceLineSelector={false}
              onChange={(next) => updateForm({
                service_line_id: next.service_line_id ?? form.service_line_id,
                vehicle_id: next.vehicle_id ?? null,
                site_asset_id: next.site_asset_id ?? null,
              })}
            />
          </div>
        ) : null}

        <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
          {form.work_context === 'contract' ? (
            <Textarea
              label="Customer notes"
              rows={3}
              value={form.customer_notes}
              onUpdateModelValue={(value) => updateForm({ customer_notes: value })}
            />
          ) : null}
          <Textarea
            label="Internal notes"
            rows={3}
            value={form.internal_notes}
            onUpdateModelValue={(value) => updateForm({ internal_notes: value })}
          />
        </div>
      </Card>

      <Card
        title="Jobs"
        footer={(
          <div className="flex justify-between">
            <Button type="button" variant="outline" onClick={addJob}>
              Add job
            </Button>
            <Button type="submit" loading={submitting} disabled={loadingLookups || !canCreateDirect}>
              Create workorder
            </Button>
          </div>
        )}
      >
        <div className="space-y-6">
          {form.jobs.map((job, jobIndex) => (
            <div key={jobIndex} className="border border-gray-200 rounded-lg p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 flex-1">
                  <Input
                    label={`Job ${jobIndex + 1} title`}
                    value={job.title}
                    required
                    onUpdateModelValue={(value) => updateJob(jobIndex, { title: value })}
                  />
                  <Textarea
                    label="Job notes"
                    rows={2}
                    value={job.notes}
                    onUpdateModelValue={(value) => updateJob(jobIndex, { notes: value })}
                  />
                </div>
                {form.jobs.length > 1 ? (
                  <Button type="button" variant="ghost" size="sm" onClick={() => removeJob(jobIndex)}>
                    Remove
                  </Button>
                ) : null}
              </div>

              <div className="mt-4 space-y-3">
                <div className="grid grid-cols-12 gap-2 text-xs font-medium text-gray-500 uppercase">
                  <div className="col-span-2">Type</div>
                  <div className="col-span-5">Description</div>
                  <div className="col-span-2">Qty</div>
                  <div className="col-span-2">Unit price</div>
                  <div className="col-span-1" />
                </div>
                {job.items.map((item, itemIndex) => (
                  <div key={itemIndex} className="grid grid-cols-12 gap-2 items-start">
                    <div className="col-span-12 sm:col-span-2">
                      <Select
                        value={item.type}
                        options={ITEM_TYPE_OPTIONS}
                        placeholder=""
                        onChange={(event) => updateItem(jobIndex, itemIndex, { type: event.target.value })}
                      />
                    </div>
                    <div className="col-span-12 sm:col-span-5">
                      <Input
                        value={item.description}
                        placeholder="Description"
                        onUpdateModelValue={(value) => updateItem(jobIndex, itemIndex, { description: value })}
                      />
                    </div>
                    <div className="col-span-6 sm:col-span-2">
                      <Input
                        type="number"
                        min="0"
                        step="0.01"
                        value={item.quantity}
                        onUpdateModelValue={(value) => updateItem(jobIndex, itemIndex, { quantity: value })}
                      />
                    </div>
                    <div className="col-span-6 sm:col-span-2">
                      <Input
                        type="number"
                        min="0"
                        step="0.01"
                        value={item.unit_price}
                        onUpdateModelValue={(value) => updateItem(jobIndex, itemIndex, { unit_price: value })}
                      />
                    </div>
                    <div className="col-span-12 sm:col-span-1 flex sm:justify-end">
                      {job.items.length > 1 ? (
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          onClick={() => removeItem(jobIndex, itemIndex)}
                        >
                          Remove
                        </Button>
                      ) : null}
                    </div>
                  </div>
                ))}
                <Button type="button" variant="ghost" size="sm" onClick={() => addItem(jobIndex)}>
                  Add line item
                </Button>
              </div>
            </div>
          ))}
        </div>
      </Card>
    </form>
  )
}
