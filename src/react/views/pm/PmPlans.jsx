import { useCallback, useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import pmService from '../../../services/pm.service'
import crmService from '../../../services/crm.service'
import { useToast } from '../../stores/toast.jsx'

const targetKindOptions = [
  { value: 'site_asset', label: 'Site asset' },
  { value: 'fleet_unit', label: 'Fleet unit' },
]

const blankForm = {
  id: null,
  name: '',
  target_kind: 'site_asset',
  description: '',
  task_definitions: '',
  is_active: true,
  company_id: '',
}

const taskJsonPlaceholder = `[
  { "name": "Replace filter", "estimate_minutes": 15 },
  { "name": "Inspect belts", "estimate_minutes": 10 }
]`

export default function PmPlans() {
  const { success, error } = useToast()
  const [plans, setPlans] = useState([])
  const [loading, setLoading] = useState(true)
  const [companies, setCompanies] = useState([])
  const [companyId, setCompanyId] = useState('')
  const [isActiveFilter, setIsActiveFilter] = useState('all')
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [pageError, setPageError] = useState('')

  const [modalOpen, setModalOpen] = useState(false)
  const [form, setForm] = useState(blankForm)
  const [formError, setFormError] = useState('')
  const [saving, setSaving] = useState(false)

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleting, setDeleting] = useState(false)

  useEffect(() => {
    crmService
      .listCompanies({ limit: 500 })
      .then((res) => setCompanies(Array.isArray(res) ? res : res?.data ?? []))
      .catch(() => setCompanies([]))
  }, [])

  const loadPlans = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (companyId) params.company_id = companyId
      if (isActiveFilter !== 'all') params.is_active = isActiveFilter === 'active' ? 1 : 0
      if (search.trim()) params.query = search.trim()
      const res = await pmService.listPlans(params)
      const list = Array.isArray(res) ? res : res?.data ?? []
      setPlans(list)
      setPageError('')
    } catch (e) {
      setPlans([])
      setPageError(e?.response?.data?.message || e?.message || 'Failed to load plans')
    } finally {
      setLoading(false)
    }
  }, [companyId, isActiveFilter, search])

  useEffect(() => { loadPlans() }, [loadPlans])

  const openCreate = () => {
    setForm({ ...blankForm, company_id: companyId || '' })
    setFormError('')
    setModalOpen(true)
  }

  const openEdit = (plan) => {
    let tasksText = ''
    const tasks = plan.task_definitions ?? plan.tasks ?? null
    if (tasks != null) {
      tasksText = typeof tasks === 'string' ? tasks : JSON.stringify(tasks, null, 2)
    }
    setForm({
      id: plan.id,
      name: plan.name ?? '',
      target_kind: plan.target_kind ?? 'site_asset',
      description: plan.description ?? '',
      task_definitions: tasksText,
      is_active: plan.is_active !== false,
      company_id: plan.company_id ?? '',
    })
    setFormError('')
    setModalOpen(true)
  }

  const handleSave = async () => {
    if (!form.name.trim()) {
      setFormError('Name is required')
      return
    }
    let tasksParsed = null
    if (form.task_definitions.trim()) {
      try {
        tasksParsed = JSON.parse(form.task_definitions)
        if (!Array.isArray(tasksParsed)) {
          setFormError('Task definitions must be a JSON array')
          return
        }
      } catch {
        setFormError('Task definitions must be valid JSON')
        return
      }
    }

    setSaving(true)
    setFormError('')
    const payload = {
      name: form.name.trim(),
      target_kind: form.target_kind,
      description: form.description?.trim() || null,
      task_definitions: tasksParsed,
      is_active: !!form.is_active,
    }
    if (form.company_id) payload.company_id = form.company_id

    try {
      if (form.id) {
        await pmService.updatePlan(form.id, payload)
        success('Plan updated')
      } else {
        await pmService.createPlan(payload)
        success('Plan created')
      }
      setModalOpen(false)
      loadPlans()
    } catch (e) {
      const msg = e?.response?.data?.message || e?.message || 'Failed to save plan'
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
      await pmService.deletePlan(deleteTarget.id)
      success('Plan deleted')
      setDeleteTarget(null)
      loadPlans()
    } catch (e) {
      error(e?.response?.data?.message || e?.message || 'Failed to delete plan')
    } finally {
      setDeleting(false)
    }
  }

  const summarizeTasks = (plan) => {
    const tasks = plan.task_definitions ?? plan.tasks
    if (Array.isArray(tasks)) return `${tasks.length} task${tasks.length === 1 ? '' : 's'}`
    if (typeof plan.task_count === 'number') return `${plan.task_count} task${plan.task_count === 1 ? '' : 's'}`
    return '—'
  }

  const handleSearch = (e) => {
    e.preventDefault()
    setSearch(searchInput)
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">PM Plans</h1>
          <p className="mt-1 text-sm text-gray-500">
            Templates that define recurring preventative maintenance work.
          </p>
        </div>
        <Button onClick={openCreate}>New Plan</Button>
      </div>

      {pageError ? <Alert variant="danger" onClose={() => setPageError('')}>{pageError}</Alert> : null}

      <Card>
        <form onSubmit={handleSearch} className="mb-4 grid grid-cols-1 sm:grid-cols-4 gap-3">
          <Select
            label="Company"
            value={companyId}
            placeholder="All companies"
            onChange={(e) => setCompanyId(e.target.value)}
            options={companies.map((c) => ({ value: String(c.id), label: c.name || `Company #${c.id}` }))}
          />
          <Select
            label="Status"
            value={isActiveFilter}
            placeholder=""
            onChange={(e) => setIsActiveFilter(e.target.value)}
            options={[
              { value: 'all', label: 'All' },
              { value: 'active', label: 'Active' },
              { value: 'inactive', label: 'Inactive' },
            ]}
          />
          <Input
            label="Search"
            value={searchInput}
            onUpdateModelValue={setSearchInput}
            placeholder="Plan name…"
          />
          <div className="flex items-end">
            <Button type="submit" variant="secondary" fullWidth>Search</Button>
          </div>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading plans..." /></div>
        ) : plans.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No plans found</h3>
            <p className="mt-1 text-sm text-gray-500">Create a PM plan to begin scheduling.</p>
            <div className="mt-4"><Button onClick={openCreate}>New Plan</Button></div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Target</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tasks</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {plans.map((plan) => (
                  <tr key={plan.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <div className="font-medium text-gray-900">{plan.name || '—'}</div>
                      {plan.description ? (
                        <div className="text-xs text-gray-500 line-clamp-1">{plan.description}</div>
                      ) : null}
                    </td>
                    <td className="px-4 py-3">
                      <Badge size="sm" variant={plan.target_kind === 'fleet_unit' ? 'info' : 'primary'}>
                        {plan.target_kind || '—'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-700">{summarizeTasks(plan)}</td>
                    <td className="px-4 py-3">
                      <Badge size="sm" variant={plan.is_active !== false ? 'success' : 'default'}>
                        {plan.is_active !== false ? 'Active' : 'Inactive'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex justify-end gap-2">
                        <Button size="sm" variant="ghost" onClick={() => openEdit(plan)}>Edit</Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-red-600 hover:text-red-700"
                          onClick={() => setDeleteTarget(plan)}
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
        title={form.id ? 'Edit Plan' : 'New Plan'}
        onClose={() => setModalOpen(false)}
        size="lg"
      >
        <div className="space-y-3">
          {formError ? <Alert variant="danger" closable={false}>{formError}</Alert> : null}
          <Input
            label="Name"
            required
            value={form.name}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, name: v }))}
            placeholder="Quarterly HVAC PM"
          />
          <Select
            label="Target kind"
            value={form.target_kind}
            placeholder=""
            onChange={(e) => setForm((f) => ({ ...f, target_kind: e.target.value }))}
            options={targetKindOptions}
          />
          {companies.length > 0 ? (
            <Select
              label="Company (optional)"
              value={form.company_id ? String(form.company_id) : ''}
              placeholder="Unassigned"
              onChange={(e) => setForm((f) => ({ ...f, company_id: e.target.value }))}
              options={companies.map((c) => ({ value: String(c.id), label: c.name || `Company #${c.id}` }))}
            />
          ) : null}
          <Textarea
            label="Description"
            value={form.description}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, description: v }))}
            rows={3}
          />
          <Textarea
            label="Task definitions (JSON array)"
            value={form.task_definitions}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, task_definitions: v }))}
            rows={8}
            placeholder={taskJsonPlaceholder}
            helperText="Provide an array of task objects. Leave blank for none."
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
        title="Delete Plan"
        onClose={() => setDeleteTarget(null)}
      >
        <p className="text-sm text-gray-600 mb-4">
          Delete <strong>{deleteTarget?.name || 'this plan'}</strong>? Schedules referencing this plan
          may be affected.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteTarget(null)}>Cancel</Button>
          <Button variant="danger" loading={deleting} onClick={confirmDelete}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
