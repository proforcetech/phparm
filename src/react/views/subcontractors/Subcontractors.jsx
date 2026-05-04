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
import subcontractorsService from '../../../services/subcontractors.service'
import { useToast } from '../../stores/toast.jsx'

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'blocked', label: 'Blocked' },
]

function statusVariant(status) {
  const v = String(status || '').toLowerCase()
  if (v === 'active') return 'success'
  if (v === 'blocked') return 'danger'
  if (v === 'inactive') return 'default'
  return 'default'
}

function parseTrades(value) {
  if (!value) return []
  if (Array.isArray(value)) return value.filter(Boolean).map(String)
  const str = String(value).trim()
  if (!str) return []
  if (str.startsWith('[')) {
    try {
      const arr = JSON.parse(str)
      if (Array.isArray(arr)) return arr.filter(Boolean).map(String)
    } catch {
      // fall through to comma split
    }
  }
  return str.split(',').map((s) => s.trim()).filter(Boolean)
}

function formatDate(value) {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString()
}

const emptyForm = {
  name: '',
  contact_name: '',
  contact_email: '',
  contact_phone: '',
  trades: '',
  notes: '',
  status: 'active',
}

export default function Subcontractors() {
  const navigate = useNavigate()
  const toast = useToast()

  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [statusFilter, setStatusFilter] = useState('')
  const [tradeFilter, setTradeFilter] = useState('')
  const [search, setSearch] = useState('')

  const [createOpen, setCreateOpen] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    setError('')
    const params = {}
    if (statusFilter) params.status = statusFilter
    if (tradeFilter.trim()) params.trade = tradeFilter.trim()
    if (search.trim()) params.search = search.trim()
    subcontractorsService
      .list(params)
      .then((res) => setItems(res?.data ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load subcontractors'))
      .finally(() => setLoading(false))
  }, [statusFilter, tradeFilter, search])

  useEffect(() => { load() }, [load])

  const tradeOptions = useMemo(() => {
    const set = new Set()
    items.forEach((it) => parseTrades(it.trades).forEach((t) => set.add(t)))
    return [
      { value: '', label: 'All trades' },
      ...[...set].sort().map((t) => ({ value: t, label: t })),
    ]
  }, [items])

  const submitCreate = async () => {
    if (!form.name.trim()) {
      toast.error('Name is required')
      return
    }
    setBusy(true)
    const trades = parseTrades(form.trades)
    const payload = {
      name: form.name.trim(),
      contact_name: form.contact_name.trim() || undefined,
      contact_email: form.contact_email.trim() || undefined,
      contact_phone: form.contact_phone.trim() || undefined,
      trades: trades.length ? trades : undefined,
      notes: form.notes.trim() || undefined,
      status: form.status || 'active',
    }
    try {
      await subcontractorsService.create(payload)
      toast.success('Subcontractor created')
      setCreateOpen(false)
      setForm(emptyForm)
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Create failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-end justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-xl font-semibold">Subcontractors</h1>
          <p className="text-sm text-gray-500">External vendors available for assignment.</p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>New Subcontractor</Button>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card padding={false}>
        <div className="p-4 flex items-end gap-3 flex-wrap">
          <Select
            label="Status"
            value={statusFilter}
            placeholder=""
            options={STATUS_OPTIONS}
            onChange={(e) => setStatusFilter(e.target.value)}
          />
          <Select
            label="Trade"
            value={tradeFilter}
            placeholder=""
            options={tradeOptions}
            onChange={(e) => setTradeFilter(e.target.value)}
          />
          <Input
            label="Search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Name, contact, phone"
          />
          <Button variant="secondary" onClick={load}>Refresh</Button>
        </div>

        {loading ? (
          <div className="p-6 text-center"><Loading /></div>
        ) : items.length === 0 ? (
          <div className="p-6 text-center text-gray-500">No subcontractors found.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Name</th>
                  <th className="text-left p-2">Primary contact</th>
                  <th className="text-left p-2">Phone</th>
                  <th className="text-left p-2">Trades</th>
                  <th className="text-left p-2">Status</th>
                  <th className="text-left p-2">Last assignment</th>
                  <th className="text-left p-2">Rating</th>
                </tr>
              </thead>
              <tbody>
                {items.map((s) => {
                  const trades = parseTrades(s.trades)
                  return (
                    <tr
                      key={s.id}
                      className="border-t cursor-pointer hover:bg-gray-50"
                      onClick={() => navigate(`/cp/subcontractors/${s.id}`)}
                    >
                      <td className="p-2 font-medium">{s.name}</td>
                      <td className="p-2">
                        {s.primary_contact || s.contact_name || <span className="text-gray-400">—</span>}
                      </td>
                      <td className="p-2">{s.phone || s.contact_phone || <span className="text-gray-400">—</span>}</td>
                      <td className="p-2">
                        {trades.length === 0 ? (
                          <span className="text-gray-400">—</span>
                        ) : (
                          <div className="flex flex-wrap gap-1">
                            {trades.map((t) => (
                              <Badge key={t} variant="info" size="sm">{t}</Badge>
                            ))}
                          </div>
                        )}
                      </td>
                      <td className="p-2">
                        <Badge variant={statusVariant(s.status)}>{s.status || 'unknown'}</Badge>
                      </td>
                      <td className="p-2 text-gray-500">{formatDate(s.last_assignment_at)}</td>
                      <td className="p-2">
                        {s.rating !== null && s.rating !== undefined && s.rating !== ''
                          ? Number(s.rating).toFixed(1)
                          : <span className="text-gray-400">—</span>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="New Subcontractor" size="lg">
        <div className="space-y-3">
          <Input
            label="Name"
            required
            value={form.name}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
          />
          <div className="grid gap-3 sm:grid-cols-2">
            <Input
              label="Contact name"
              value={form.contact_name}
              onChange={(e) => setForm((f) => ({ ...f, contact_name: e.target.value }))}
            />
            <Input
              label="Contact email"
              type="email"
              value={form.contact_email}
              onChange={(e) => setForm((f) => ({ ...f, contact_email: e.target.value }))}
            />
            <Input
              label="Contact phone"
              value={form.contact_phone}
              onChange={(e) => setForm((f) => ({ ...f, contact_phone: e.target.value }))}
            />
            <Select
              label="Status"
              value={form.status}
              placeholder=""
              options={STATUS_OPTIONS.filter((o) => o.value !== '')}
              onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}
            />
          </div>
          <Textarea
            label="Trades"
            rows={2}
            value={form.trades}
            onChange={(e) => setForm((f) => ({ ...f, trades: e.target.value }))}
            helperText="Comma-separated, or JSON array"
          />
          <Textarea
            label="Notes"
            rows={3}
            value={form.notes}
            onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setCreateOpen(false)} disabled={busy}>Cancel</Button>
            <Button onClick={submitCreate} loading={busy}>Create</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
