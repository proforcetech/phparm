import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

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
import crmService from '../../../services/crm.service'

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
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

function nextYear() {
  return new Date().getFullYear() + 1
}

function emptyForm() {
  const start = nextYear()
  return {
    name: '',
    company_id: '',
    start_year: String(start),
    end_year: String(start + 4),
    description: '',
  }
}

export default function CapitalPlans() {
  const toast = useToast()
  const navigate = useNavigate()
  const [plans, setPlans] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [companies, setCompanies] = useState([])
  const [filterCompanyId, setFilterCompanyId] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [search, setSearch] = useState('')

  const [createOpen, setCreateOpen] = useState(false)
  const [form, setForm] = useState(emptyForm())
  const [formError, setFormError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    crmService
      .listCompanies({ limit: 500 })
      .then((res) => setCompanies(res?.data ?? []))
      .catch(() => setCompanies([]))
  }, [])

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (filterCompanyId) params.company_id = filterCompanyId
    if (filterStatus) params.status = filterStatus
    if (search.trim()) params.search = search.trim()
    capitalPlanService
      .listPlans(params)
      .then((res) => setPlans(res?.data ?? []))
      .catch((e) => {
        const msg = e?.response?.data?.message || e?.message || 'Failed to load plans'
        setError(msg)
      })
      .finally(() => setLoading(false))
  }, [filterCompanyId, filterStatus, search])

  useEffect(() => {
    load()
  }, [load])

  const companyName = useMemo(() => {
    const map = new Map()
    for (const c of companies) map.set(String(c.id), c.name)
    return (id) => (id ? map.get(String(id)) || '—' : '—')
  }, [companies])

  const openCreate = () => {
    setForm(emptyForm())
    setFormError('')
    setCreateOpen(true)
  }

  const submit = async () => {
    setFormError('')
    if (!form.name.trim()) {
      setFormError('Name is required.')
      return
    }
    if (!form.company_id) {
      setFormError('Company is required.')
      return
    }
    const startYear = Number.parseInt(form.start_year, 10)
    const endYear = Number.parseInt(form.end_year, 10)
    if (!Number.isFinite(startYear) || !Number.isFinite(endYear)) {
      setFormError('Start and end year must be numbers.')
      return
    }
    if (endYear < startYear) {
      setFormError('End year must be on or after start year.')
      return
    }
    const payload = {
      name: form.name.trim(),
      company_id: Number(form.company_id),
      start_year: startYear,
      end_year: endYear,
      description: form.description.trim() || null,
    }
    setBusy(true)
    try {
      const res = await capitalPlanService.createPlan(payload)
      toast.success('Plan created.')
      const created = res?.data
      setCreateOpen(false)
      if (created?.id) {
        navigate(`/cp/capital-plan/plans/${created.id}`)
      } else {
        load()
      }
    } catch (e) {
      const msg = e?.response?.data?.message || e?.message || 'Create failed'
      setFormError(msg)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-center justify-between flex-wrap gap-2">
        <div>
          <h1 className="text-xl font-semibold">Capital Plans</h1>
          <p className="text-sm text-gray-500">
            Multi-year replacement plans with scenarios for asset capital spend.
          </p>
        </div>
        <Button onClick={openCreate}>New Plan</Button>
      </header>

      {error ? (
        <Alert variant="danger" onClose={() => setError('')}>
          {error}
        </Alert>
      ) : null}

      <Card padding={false}>
        <div className="p-4 flex items-end gap-3 flex-wrap">
          <Select
            label="Company"
            value={filterCompanyId}
            onChange={(e) => setFilterCompanyId(e?.target?.value ?? e)}
            options={[
              { value: '', label: 'All companies' },
              ...companies.map((c) => ({ value: String(c.id), label: c.name })),
            ]}
          />
          <Select
            label="Status"
            value={filterStatus}
            placeholder=""
            onChange={(e) => setFilterStatus(e?.target?.value ?? e)}
            options={STATUS_OPTIONS}
          />
          <Input
            label="Search"
            value={search}
            placeholder="Plan name…"
            onChange={(e) => setSearch(e?.target?.value ?? e)}
          />
          <Button variant="secondary" onClick={load} disabled={loading}>
            Refresh
          </Button>
        </div>

        {loading ? (
          <div className="p-6 text-center">
            <Loading />
          </div>
        ) : plans.length === 0 ? (
          <div className="p-6 text-center text-gray-500">No capital plans match the current filters.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Name</th>
                  <th className="text-left p-2">Company</th>
                  <th className="text-left p-2">Status</th>
                  <th className="text-right p-2">Start</th>
                  <th className="text-right p-2">End</th>
                  <th className="text-right p-2">Total Estimate</th>
                  <th className="text-left p-2">Created</th>
                </tr>
              </thead>
              <tbody>
                {plans.map((p) => (
                  <tr
                    key={p.id}
                    className="border-t cursor-pointer hover:bg-gray-50"
                    onClick={() => navigate(`/cp/capital-plan/plans/${p.id}`)}
                  >
                    <td className="p-2 font-medium">{p.name}</td>
                    <td className="p-2">{p.company_name || companyName(p.company_id)}</td>
                    <td className="p-2">
                      <Badge variant={STATUS_VARIANT[p.status] || 'default'}>
                        {p.status || 'draft'}
                      </Badge>
                    </td>
                    <td className="p-2 text-right">{p.start_year ?? '—'}</td>
                    <td className="p-2 text-right">{p.end_year ?? '—'}</td>
                    <td className="p-2 text-right">{formatMoney(p.total_estimate)}</td>
                    <td className="p-2">{p.created_at || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="New capital plan" size="lg">
        <div className="space-y-3">
          {formError ? <Alert variant="danger">{formError}</Alert> : null}
          <Input
            label="Name"
            required
            value={form.name}
            onChange={(e) => setForm((f) => ({ ...f, name: e?.target?.value ?? e }))}
          />
          <Select
            label="Company"
            required
            value={form.company_id}
            onChange={(e) => setForm((f) => ({ ...f, company_id: e?.target?.value ?? e }))}
            options={[
              { value: '', label: 'Select company…' },
              ...companies.map((c) => ({ value: String(c.id), label: c.name })),
            ]}
          />
          <div className="grid grid-cols-2 gap-3">
            <Input
              label="Start year"
              type="number"
              value={form.start_year}
              onChange={(e) => setForm((f) => ({ ...f, start_year: e?.target?.value ?? e }))}
            />
            <Input
              label="End year"
              type="number"
              value={form.end_year}
              onChange={(e) => setForm((f) => ({ ...f, end_year: e?.target?.value ?? e }))}
            />
          </div>
          <Textarea
            label="Description"
            rows={3}
            value={form.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e?.target?.value ?? e }))}
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setCreateOpen(false)}>
              Cancel
            </Button>
            <Button disabled={busy} onClick={submit}>
              {busy ? 'Creating…' : 'Create plan'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
