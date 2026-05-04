import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import { useToast } from '../../stores/toast.jsx'
import capitalPlanService from '../../../services/capital-plan.service'

const STATUS_OPTIONS = [
  { value: 'draft', label: 'Draft' },
  { value: 'active', label: 'Active' },
  { value: 'archived', label: 'Archived' },
]

const STATUS_VARIANT = {
  draft: 'default',
  active: 'success',
  archived: 'secondary',
}

function formatMoney(n) {
  if (n === null || n === undefined || n === '') return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return num.toLocaleString(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })
}

function formatNumber(n) {
  if (n === null || n === undefined || n === '') return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return num.toLocaleString()
}

function lineItemsForScenario(s) {
  if (!s) return []
  if (Array.isArray(s.line_items)) return s.line_items
  if (Array.isArray(s.items)) return s.items
  if (Array.isArray(s.lines)) return s.lines
  return []
}

export default function CapitalPlanDetail() {
  const toast = useToast()
  const navigate = useNavigate()
  const { id } = useParams()

  const [plan, setPlan] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [editOpen, setEditOpen] = useState(false)
  const [editForm, setEditForm] = useState({
    name: '',
    status: 'draft',
    start_year: '',
    end_year: '',
    description: '',
  })
  const [editError, setEditError] = useState('')
  const [editBusy, setEditBusy] = useState(false)

  const [confirmDelete, setConfirmDelete] = useState(false)
  const [deleteBusy, setDeleteBusy] = useState(false)

  const [scenarioOpen, setScenarioOpen] = useState(false)
  const [scenarioForm, setScenarioForm] = useState({ name: '', description: '', baseline: false })
  const [scenarioError, setScenarioError] = useState('')
  const [scenarioBusy, setScenarioBusy] = useState(false)

  const load = useCallback(() => {
    if (!id) return
    setLoading(true)
    capitalPlanService
      .getPlan(id)
      .then((res) => setPlan(res?.data ?? null))
      .catch((e) => {
        const msg = e?.response?.data?.message || e?.message || 'Failed to load plan'
        setError(msg)
      })
      .finally(() => setLoading(false))
  }, [id])

  useEffect(() => {
    load()
  }, [load])

  const openEdit = () => {
    if (!plan) return
    setEditForm({
      name: plan.name || '',
      status: plan.status || 'draft',
      start_year: plan.start_year != null ? String(plan.start_year) : '',
      end_year: plan.end_year != null ? String(plan.end_year) : '',
      description: plan.description || '',
    })
    setEditError('')
    setEditOpen(true)
  }

  const submitEdit = async () => {
    setEditError('')
    if (!editForm.name.trim()) {
      setEditError('Name is required.')
      return
    }
    const startYear = Number.parseInt(editForm.start_year, 10)
    const endYear = Number.parseInt(editForm.end_year, 10)
    if (!Number.isFinite(startYear) || !Number.isFinite(endYear)) {
      setEditError('Start and end year must be numbers.')
      return
    }
    if (endYear < startYear) {
      setEditError('End year must be on or after start year.')
      return
    }
    setEditBusy(true)
    try {
      await capitalPlanService.updatePlan(id, {
        name: editForm.name.trim(),
        status: editForm.status,
        start_year: startYear,
        end_year: endYear,
        description: editForm.description.trim() || null,
      })
      toast.success('Plan updated.')
      setEditOpen(false)
      load()
    } catch (e) {
      setEditError(e?.response?.data?.message || e?.message || 'Save failed')
    } finally {
      setEditBusy(false)
    }
  }

  const submitDelete = async () => {
    setDeleteBusy(true)
    try {
      await capitalPlanService.deletePlan(id)
      toast.success('Plan deleted.')
      navigate('/cp/capital-plan/plans')
    } catch (e) {
      const msg = e?.response?.data?.message || e?.message || 'Delete failed'
      toast.error(msg)
      setDeleteBusy(false)
    }
  }

  const openScenario = () => {
    setScenarioForm({ name: '', description: '', baseline: false })
    setScenarioError('')
    setScenarioOpen(true)
  }

  const submitScenario = async () => {
    setScenarioError('')
    if (!scenarioForm.name.trim()) {
      setScenarioError('Name is required.')
      return
    }
    setScenarioBusy(true)
    try {
      await capitalPlanService.createScenario(id, {
        name: scenarioForm.name.trim(),
        description: scenarioForm.description.trim() || null,
        baseline: !!scenarioForm.baseline,
      })
      toast.success('Scenario created.')
      setScenarioOpen(false)
      load()
    } catch (e) {
      setScenarioError(e?.response?.data?.message || e?.message || 'Create failed')
    } finally {
      setScenarioBusy(false)
    }
  }

  if (loading) {
    return (
      <div className="p-6 text-center">
        <Loading />
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-4 space-y-3">
        <Alert variant="danger">{error}</Alert>
        <Link to="/cp/capital-plan/plans" className="text-sm text-primary-600 hover:underline">
          Back to plans
        </Link>
      </div>
    )
  }

  if (!plan) {
    return (
      <div className="p-4 space-y-3">
        <Alert variant="warning">Plan not found.</Alert>
        <Link to="/cp/capital-plan/plans" className="text-sm text-primary-600 hover:underline">
          Back to plans
        </Link>
      </div>
    )
  }

  const scenarios = Array.isArray(plan.scenarios) ? plan.scenarios : []

  return (
    <div className="space-y-4 p-4">
      <div>
        <Link to="/cp/capital-plan/plans" className="text-sm text-primary-600 hover:underline">
          &larr; Back to plans
        </Link>
      </div>

      <header className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-xl font-semibold">{plan.name}</h1>
            <Badge variant={STATUS_VARIANT[plan.status] || 'default'}>{plan.status || 'draft'}</Badge>
          </div>
          <div className="text-sm text-gray-500 mt-1">
            {plan.company_name ? <span>{plan.company_name} · </span> : null}
            <span>
              {plan.start_year ?? '—'} → {plan.end_year ?? '—'}
            </span>
          </div>
          {plan.description ? (
            <p className="text-sm text-gray-700 mt-2 max-w-2xl">{plan.description}</p>
          ) : null}
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={openEdit}>
            Edit
          </Button>
          <Button variant="danger" onClick={() => setConfirmDelete(true)}>
            Delete
          </Button>
        </div>
      </header>

      <Card padding={false}>
        <div className="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-700">Scenarios</h2>
          <Button size="sm" onClick={openScenario}>
            New Scenario
          </Button>
        </div>
        {scenarios.length === 0 ? (
          <div className="p-6 text-center text-gray-500">No scenarios yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Name</th>
                  <th className="text-right p-2">Total Estimate</th>
                  <th className="text-right p-2">Line Items</th>
                  <th className="text-left p-2">Baseline</th>
                  <th className="text-left p-2">Updated</th>
                </tr>
              </thead>
              <tbody>
                {scenarios.map((s) => {
                  const items = lineItemsForScenario(s)
                  const itemsCount = s.line_items_count ?? items.length
                  return (
                    <tr key={s.id} className="border-t">
                      <td className="p-2">
                        <div className="font-medium">{s.name}</div>
                        {s.description ? (
                          <div className="text-xs text-gray-500">{s.description}</div>
                        ) : null}
                      </td>
                      <td className="p-2 text-right">{formatMoney(s.total_estimate)}</td>
                      <td className="p-2 text-right">{formatNumber(itemsCount)}</td>
                      <td className="p-2">
                        {s.is_baseline || s.baseline ? (
                          <Badge variant="info">Baseline</Badge>
                        ) : (
                          <span className="text-gray-400">—</span>
                        )}
                      </td>
                      <td className="p-2">{s.updated_at || s.created_at || '—'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {scenarios.some((s) => lineItemsForScenario(s).length > 0) ? (
        <div className="space-y-3">
          {scenarios.map((s) => {
            const items = lineItemsForScenario(s)
            if (items.length === 0) return null
            return (
              <Card key={`items-${s.id}`} padding={false}>
                <div className="px-4 py-3 border-b border-gray-200">
                  <h3 className="text-sm font-semibold text-gray-700">
                    Line items — {s.name}
                  </h3>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                      <tr>
                        <th className="text-left p-2">Asset</th>
                        <th className="text-right p-2">Year</th>
                        <th className="text-right p-2">Estimate</th>
                        <th className="text-left p-2">Notes</th>
                      </tr>
                    </thead>
                    <tbody>
                      {items.map((it) => (
                        <tr key={it.id ?? `${it.asset_id}-${it.year}`} className="border-t">
                          <td className="p-2">{it.asset_name || it.asset || '—'}</td>
                          <td className="p-2 text-right">{it.year ?? '—'}</td>
                          <td className="p-2 text-right">{formatMoney(it.estimate ?? it.amount)}</td>
                          <td className="p-2">{it.notes || ''}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Card>
            )
          })}
        </div>
      ) : scenarios.length > 0 ? (
        // TODO: needs a scenario line-item endpoint and dedicated edit page; service exposes
        // only createScenario today, so per-item editing is left for a future iteration.
        <Card>
          <div className="p-4 text-sm text-gray-500">
            Per-scenario line items are not yet available here. A future page will allow editing
            line items per scenario.
          </div>
        </Card>
      ) : null}

      <Modal open={editOpen} onClose={() => setEditOpen(false)} title="Edit plan" size="lg">
        <div className="space-y-3">
          {editError ? <Alert variant="danger">{editError}</Alert> : null}
          <Input
            label="Name"
            required
            value={editForm.name}
            onChange={(e) => setEditForm((f) => ({ ...f, name: e?.target?.value ?? e }))}
          />
          <Select
            label="Status"
            value={editForm.status}
            placeholder=""
            onChange={(e) => setEditForm((f) => ({ ...f, status: e?.target?.value ?? e }))}
            options={STATUS_OPTIONS}
          />
          <div className="grid grid-cols-2 gap-3">
            <Input
              label="Start year"
              type="number"
              value={editForm.start_year}
              onChange={(e) => setEditForm((f) => ({ ...f, start_year: e?.target?.value ?? e }))}
            />
            <Input
              label="End year"
              type="number"
              value={editForm.end_year}
              onChange={(e) => setEditForm((f) => ({ ...f, end_year: e?.target?.value ?? e }))}
            />
          </div>
          <Textarea
            label="Description"
            rows={3}
            value={editForm.description}
            onChange={(e) => setEditForm((f) => ({ ...f, description: e?.target?.value ?? e }))}
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setEditOpen(false)}>
              Cancel
            </Button>
            <Button disabled={editBusy} onClick={submitEdit}>
              {editBusy ? 'Saving…' : 'Save'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal open={confirmDelete} onClose={() => setConfirmDelete(false)} title="Delete plan">
        <div className="space-y-3">
          <p className="text-sm">
            Delete <strong>{plan.name}</strong>? This will remove all scenarios and line items.
          </p>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmDelete(false)}>
              Cancel
            </Button>
            <Button variant="danger" disabled={deleteBusy} onClick={submitDelete}>
              {deleteBusy ? 'Deleting…' : 'Delete'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal open={scenarioOpen} onClose={() => setScenarioOpen(false)} title="New scenario">
        <div className="space-y-3">
          {scenarioError ? <Alert variant="danger">{scenarioError}</Alert> : null}
          <Input
            label="Name"
            required
            value={scenarioForm.name}
            placeholder="e.g. Base case, Accelerated"
            onChange={(e) => setScenarioForm((f) => ({ ...f, name: e?.target?.value ?? e }))}
          />
          <Textarea
            label="Description"
            rows={3}
            value={scenarioForm.description}
            onChange={(e) => setScenarioForm((f) => ({ ...f, description: e?.target?.value ?? e }))}
          />
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={scenarioForm.baseline}
              onChange={(e) => setScenarioForm((f) => ({ ...f, baseline: e.target.checked }))}
            />
            Mark as baseline scenario
          </label>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setScenarioOpen(false)}>
              Cancel
            </Button>
            <Button disabled={scenarioBusy} onClick={submitScenario}>
              {scenarioBusy ? 'Creating…' : 'Create scenario'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
