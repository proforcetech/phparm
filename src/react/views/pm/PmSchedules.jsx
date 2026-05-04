import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import pmService from '../../../services/pm.service'
import crmService from '../../../services/crm.service'
import { useToast } from '../../stores/toast.jsx'

const blankForm = {
  id: null,
  plan_id: '',
  target_kind: 'site_asset',
  target_id: '',
  cadence_unit: 'days',
  cadence_value: '',
  starts_at: '',
  is_active: true,
}

const formatDateTime = (value) => {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString()
}

const supportsForceGenerate = typeof pmService.forceGenerate === 'function'

export default function PmSchedules() {
  const { success, error } = useToast()

  const [schedules, setSchedules] = useState([])
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')

  const [plans, setPlans] = useState([])
  const [companies, setCompanies] = useState([])

  const [planFilter, setPlanFilter] = useState('')
  const [companyFilter, setCompanyFilter] = useState('')
  const [activeFilter, setActiveFilter] = useState('all')

  const [modalOpen, setModalOpen] = useState(false)
  const [form, setForm] = useState(blankForm)
  const [formError, setFormError] = useState('')
  const [saving, setSaving] = useState(false)

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleting, setDeleting] = useState(false)

  const [generatingId, setGeneratingId] = useState(null)

  useEffect(() => {
    pmService
      .listPlans({ limit: 500 })
      .then((res) => setPlans(Array.isArray(res) ? res : res?.data ?? []))
      .catch(() => setPlans([]))
    crmService
      .listCompanies({ limit: 500 })
      .then((res) => setCompanies(Array.isArray(res) ? res : res?.data ?? []))
      .catch(() => setCompanies([]))
  }, [])

  const planById = useMemo(() => {
    const m = new Map()
    for (const p of plans) m.set(String(p.id), p)
    return m
  }, [plans])

  const loadSchedules = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (planFilter) params.plan_id = planFilter
      if (companyFilter) params.company_id = companyFilter
      if (activeFilter !== 'all') params.is_active = activeFilter === 'active' ? 1 : 0
      const res = await pmService.listSchedules(params)
      const list = Array.isArray(res) ? res : res?.data ?? []
      setSchedules(list)
      setPageError('')
    } catch (e) {
      setSchedules([])
      setPageError(e?.response?.data?.message || e?.message || 'Failed to load schedules')
    } finally {
      setLoading(false)
    }
  }, [planFilter, companyFilter, activeFilter])

  useEffect(() => { loadSchedules() }, [loadSchedules])

  const inferredTargetKind = (planId) => {
    const p = planById.get(String(planId))
    return p?.target_kind || 'site_asset'
  }

  const openCreate = () => {
    setForm({ ...blankForm })
    setFormError('')
    setModalOpen(true)
  }

  const openEdit = (s) => {
    const cadenceUnit = s.interval_hours != null ? 'hours' : 'days'
    const cadenceValue = cadenceUnit === 'hours'
      ? (s.interval_hours ?? '')
      : (s.interval_days ?? '')
    let startsAt = s.starts_at || ''
    if (startsAt && startsAt.includes(' ')) startsAt = startsAt.replace(' ', 'T').slice(0, 16)
    else if (startsAt) startsAt = startsAt.slice(0, 16)
    setForm({
      id: s.id,
      plan_id: String(s.plan_id ?? ''),
      target_kind: s.target_kind ?? inferredTargetKind(s.plan_id),
      target_id: s.target_id != null ? String(s.target_id) : '',
      cadence_unit: cadenceUnit,
      cadence_value: cadenceValue !== null ? String(cadenceValue) : '',
      starts_at: startsAt,
      is_active: s.is_active !== false,
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleSave = async () => {
    if (!form.plan_id) { setFormError('Plan is required'); return }
    if (!form.target_id.trim()) { setFormError('Target ID is required'); return }
    const cadenceNum = Number(form.cadence_value)
    if (!Number.isFinite(cadenceNum) || cadenceNum <= 0) {
      setFormError('Cadence must be a positive number')
      return
    }

    const targetKind = form.target_kind || inferredTargetKind(form.plan_id)
    const payload = {
      plan_id: Number(form.plan_id),
      target_kind: targetKind,
      target_id: Number(form.target_id) || form.target_id,
      is_active: !!form.is_active,
    }
    if (form.cadence_unit === 'hours') payload.interval_hours = cadenceNum
    else payload.interval_days = cadenceNum
    if (form.starts_at) payload.starts_at = form.starts_at

    setSaving(true)
    setFormError('')
    try {
      if (form.id) {
        await pmService.updateSchedule(form.id, payload)
        success('Schedule updated')
      } else {
        await pmService.createSchedule(payload)
        success('Schedule created')
      }
      setModalOpen(false)
      loadSchedules()
    } catch (e) {
      const msg = e?.response?.data?.message || e?.message || 'Failed to save schedule'
      setFormError(msg)
      error(msg)
    } finally {
      setSaving(false)
    }
  }

  const confirmDelete = async () => {
    if (!deleteTarget) return
    setDeleting(true)
    try {
      await pmService.deleteSchedule(deleteTarget.id)
      success('Schedule deleted')
      setDeleteTarget(null)
      loadSchedules()
    } catch (e) {
      error(e?.response?.data?.message || e?.message || 'Failed to delete schedule')
    } finally {
      setDeleting(false)
    }
  }

  const handleForceGenerate = async (s) => {
    if (!supportsForceGenerate) return
    setGeneratingId(s.id)
    try {
      await pmService.forceGenerate(s.id)
      success('Generation triggered')
      loadSchedules()
    } catch (e) {
      error(e?.response?.data?.message || e?.message || 'Failed to generate')
    } finally {
      setGeneratingId(null)
    }
  }

  const planLabel = (s) => {
    const p = planById.get(String(s.plan_id))
    return p?.name || s.plan_name || `Plan #${s.plan_id}`
  }

  const cadenceLabel = (s) => {
    if (s.interval_hours != null) return `${s.interval_hours} hours`
    if (s.interval_days != null) return `${s.interval_days} days`
    return '—'
  }

  const targetLabel = (s) => {
    const kind = s.target_kind || '—'
    const id = s.target_id ?? '—'
    return `${kind} #${id}`
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">PM Schedules</h1>
          <p className="mt-1 text-sm text-gray-500">
            Bind a plan to a specific asset or fleet unit on a recurring cadence.
          </p>
        </div>
        <Button onClick={openCreate}>New Schedule</Button>
      </div>

      {pageError ? <Alert variant="danger" onClose={() => setPageError('')}>{pageError}</Alert> : null}

      <Card>
        <div className="mb-4 grid grid-cols-1 sm:grid-cols-4 gap-3">
          <Select
            label="Plan"
            value={planFilter}
            placeholder="All plans"
            onChange={(e) => setPlanFilter(e.target.value)}
            options={plans.map((p) => ({ value: String(p.id), label: p.name || `Plan #${p.id}` }))}
          />
          <Select
            label="Company"
            value={companyFilter}
            placeholder="All companies"
            onChange={(e) => setCompanyFilter(e.target.value)}
            options={companies.map((c) => ({ value: String(c.id), label: c.name || `Company #${c.id}` }))}
          />
          <Select
            label="Status"
            value={activeFilter}
            placeholder=""
            onChange={(e) => setActiveFilter(e.target.value)}
            options={[
              { value: 'all', label: 'All' },
              { value: 'active', label: 'Active' },
              { value: 'inactive', label: 'Inactive' },
            ]}
          />
          <div className="flex items-end">
            <Button variant="secondary" fullWidth onClick={loadSchedules}>Refresh</Button>
          </div>
        </div>

        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading schedules..." /></div>
        ) : schedules.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No schedules found</h3>
            <p className="mt-1 text-sm text-gray-500">Bind a plan to a target to start.</p>
            <div className="mt-4"><Button onClick={openCreate}>New Schedule</Button></div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Target</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cadence</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Starts</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last completed</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Next due</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {schedules.map((s) => (
                  <tr key={s.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium text-gray-900">{planLabel(s)}</td>
                    <td className="px-4 py-3 text-sm text-gray-700">{targetLabel(s)}</td>
                    <td className="px-4 py-3 text-sm text-gray-700">{cadenceLabel(s)}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">{formatDateTime(s.starts_at)}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">{formatDateTime(s.last_completed_at)}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">{formatDateTime(s.next_due_at)}</td>
                    <td className="px-4 py-3">
                      <Badge size="sm" variant={s.is_active !== false ? 'success' : 'default'}>
                        {s.is_active !== false ? 'Active' : 'Inactive'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex justify-end gap-2">
                        {supportsForceGenerate ? (
                          <Button
                            size="sm"
                            variant="ghost"
                            loading={generatingId === s.id}
                            onClick={() => handleForceGenerate(s)}
                          >
                            Force-generate
                          </Button>
                        ) : null}
                        <Button size="sm" variant="ghost" onClick={() => openEdit(s)}>Edit</Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-red-600 hover:text-red-700"
                          onClick={() => setDeleteTarget(s)}
                        >
                          Delete
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={modalOpen}
        title={form.id ? 'Edit Schedule' : 'New Schedule'}
        onClose={() => setModalOpen(false)}
        size="lg"
      >
        <div className="space-y-3">
          {formError ? <Alert variant="danger" closable={false}>{formError}</Alert> : null}
          <Select
            label="Plan"
            required
            value={form.plan_id}
            placeholder="Select a plan"
            onChange={(e) => {
              const v = e.target.value
              setForm((f) => ({ ...f, plan_id: v, target_kind: inferredTargetKind(v) }))
            }}
            options={plans.map((p) => ({ value: String(p.id), label: p.name || `Plan #${p.id}` }))}
          />
          <div className="text-sm text-gray-600">
            Target kind: <span className="font-medium">{form.target_kind || '—'}</span> (from plan)
          </div>
          <Input
            label="Target ID"
            required
            type="number"
            value={form.target_id}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, target_id: v }))}
            placeholder={form.target_kind === 'fleet_unit' ? 'Fleet unit ID' : 'Site asset ID'}
          />
          <div className="grid grid-cols-2 gap-3">
            <Select
              label="Cadence unit"
              value={form.cadence_unit}
              placeholder=""
              onChange={(e) => setForm((f) => ({ ...f, cadence_unit: e.target.value }))}
              options={[
                { value: 'days', label: 'Days (interval_days)' },
                { value: 'hours', label: 'Hours (interval_hours)' },
              ]}
            />
            <Input
              label={form.cadence_unit === 'hours' ? 'Interval (hours)' : 'Interval (days)'}
              required
              type="number"
              value={form.cadence_value}
              onUpdateModelValue={(v) => setForm((f) => ({ ...f, cadence_value: v }))}
              placeholder={form.cadence_unit === 'hours' ? '720' : '30'}
            />
          </div>
          <Input
            label="Starts at"
            type="datetime-local"
            value={form.starts_at}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, starts_at: v }))}
          />
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={!!form.is_active}
              onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
            />
            Active
          </label>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setModalOpen(false)}>Cancel</Button>
            <Button loading={saving} onClick={handleSave}>{form.id ? 'Save' : 'Create'}</Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={!!deleteTarget}
        title="Delete Schedule"
        onClose={() => setDeleteTarget(null)}
      >
        <p className="text-sm text-gray-600 mb-4">
          Delete this schedule? Any pending generations may be removed.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteTarget(null)}>Cancel</Button>
          <Button variant="danger" loading={deleting} onClick={confirmDelete}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
