import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import ticketsService from '../../../services/tickets.service'
import { useToast } from '../../stores/toast.jsx'

const emptyForm = {
  name: '',
  priority: '0',
  conditions: '',
  target_queue_id: '',
  is_active: true,
}

const summarizeConditions = (raw) => {
  if (!raw) return '—'
  if (typeof raw === 'string') {
    try {
      const parsed = JSON.parse(raw)
      return summarizeConditions(parsed)
    } catch {
      return raw.length > 60 ? `${raw.slice(0, 57)}...` : raw
    }
  }
  if (Array.isArray(raw)) return `${raw.length} condition(s)`
  if (typeof raw === 'object') {
    const keys = Object.keys(raw)
    return keys.slice(0, 3).map((k) => `${k}=${JSON.stringify(raw[k])}`).join(', ') + (keys.length > 3 ? '...' : '')
  }
  return String(raw)
}

export default function TicketRoutingRules() {
  const { success, error } = useToast()
  const [items, setItems] = useState([])
  const [queues, setQueues] = useState([])
  const [loading, setLoading] = useState(true)
  const [apiError, setApiError] = useState('')

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [saving, setSaving] = useState(false)
  const [conditionsError, setConditionsError] = useState('')

  const [deleteModal, setDeleteModal] = useState({ open: false, item: null })
  const [deleting, setDeleting] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setApiError('')
    try {
      const res = await ticketsService.listRoutingRules()
      const list = Array.isArray(res) ? res : res?.data ?? []
      setItems(list)
    } catch (e) {
      setApiError(e?.response?.data?.message || e?.message || 'Failed to load routing rules')
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    let cancelled = false
    ticketsService.listQueues({ limit: 200 })
      .then((res) => { if (!cancelled) setQueues(Array.isArray(res) ? res : res?.data ?? []) })
      .catch(() => {})
    return () => { cancelled = true }
  }, [])

  const queueOptions = useMemo(
    () => [{ value: '', label: 'No queue' }, ...queues.map((q) => ({ value: String(q.id), label: q.name || `#${q.id}` }))],
    [queues]
  )

  const queueName = (id) => {
    if (!id) return '—'
    const q = queues.find((x) => String(x.id) === String(id))
    return q?.name || `#${id}`
  }

  const openCreate = () => { setEditing(null); setForm(emptyForm); setConditionsError(''); setModalOpen(true) }
  const openEdit = (it) => {
    setEditing(it)
    setForm({
      name: it.name || '',
      priority: it.priority != null ? String(it.priority) : '0',
      conditions: typeof it.conditions === 'string' ? it.conditions : (it.conditions ? JSON.stringify(it.conditions, null, 2) : ''),
      target_queue_id: it.target_queue_id ? String(it.target_queue_id) : '',
      is_active: it.is_active !== false,
    })
    setConditionsError('')
    setModalOpen(true)
  }

  const updateField = (field) => (eOrValue) => {
    const value = eOrValue && eOrValue.target !== undefined ? eOrValue.target.value : eOrValue
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  const submit = async () => {
    if (!form.name.trim()) { error('Name is required'); return }
    let conditionsParsed = null
    if (form.conditions && form.conditions.trim()) {
      try {
        conditionsParsed = JSON.parse(form.conditions)
        setConditionsError('')
      } catch (e) {
        setConditionsError('Conditions must be valid JSON')
        return
      }
    }
    setSaving(true)
    try {
      const payload = {
        name: form.name.trim(),
        priority: form.priority !== '' ? Number(form.priority) : 0,
        conditions: conditionsParsed,
        target_queue_id: form.target_queue_id || null,
        is_active: !!form.is_active,
      }
      if (editing) {
        await ticketsService.updateRoutingRule(editing.id, payload)
        success('Rule updated')
      } else {
        await ticketsService.createRoutingRule(payload)
        success('Rule created')
      }
      setModalOpen(false)
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to save rule')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteModal.item) return
    setDeleting(true)
    try {
      await ticketsService.deleteRoutingRule(deleteModal.item.id)
      success('Rule deleted')
      setDeleteModal({ open: false, item: null })
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to delete rule')
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Routing Rules</h1>
          <p className="mt-1 text-sm text-gray-500">Auto-route tickets to queues when conditions match.</p>
        </div>
        <Button onClick={openCreate}>New rule</Button>
      </div>

      {apiError && <Alert variant="danger" onClose={() => setApiError('')}>{apiError}</Alert>}

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading /></div>
        ) : items.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No routing rules yet</h3>
            <p className="mt-1 text-sm text-gray-500">Create one to get started.</p>
            <div className="mt-4"><Button onClick={openCreate}>New rule</Button></div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Priority</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Conditions</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Target Queue</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {items.map((it) => (
                  <tr key={it.id}>
                    <td className="px-3 py-2 text-sm font-medium text-gray-900">{it.name}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{it.priority ?? 0}</td>
                    <td className="px-3 py-2 text-sm text-gray-500 max-w-md truncate">{summarizeConditions(it.conditions)}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{queueName(it.target_queue_id)}</td>
                    <td className="px-3 py-2">
                      {it.is_active !== false ? <Badge size="sm" variant="success">Active</Badge> : <Badge size="sm">Inactive</Badge>}
                    </td>
                    <td className="px-3 py-2 text-right">
                      <div className="flex justify-end gap-2">
                        <Button size="sm" variant="ghost" onClick={() => openEdit(it)}>Edit</Button>
                        <Button size="sm" variant="ghost" className="text-red-600 hover:text-red-700" onClick={() => setDeleteModal({ open: true, item: it })}>Delete</Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editing ? 'Edit routing rule' : 'New routing rule'} size="lg">
        <div className="space-y-3">
          <Input label="Name" required value={form.name} onChange={updateField('name')} />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input label="Priority (lower runs first)" type="number" value={form.priority} onChange={updateField('priority')} />
            <Select label="Target queue" value={form.target_queue_id} onChange={updateField('target_queue_id')} options={queueOptions} placeholder="" />
          </div>
          <Textarea
            label="Conditions (JSON)"
            rows={6}
            value={form.conditions}
            onChange={updateField('conditions')}
            placeholder={'{\n  "priority": "high",\n  "category_id": 4\n}'}
            error={conditionsError}
          />
          <p className="text-xs text-gray-500">
            Conditions are matched against the incoming ticket. Leave blank to apply to all tickets.
          </p>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={!!form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
            Active
          </label>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setModalOpen(false)}>Cancel</Button>
            <Button onClick={submit} loading={saving} disabled={saving}>Save</Button>
          </div>
        </div>
      </Modal>

      <Modal open={deleteModal.open} onClose={() => setDeleteModal({ open: false, item: null })} title="Delete routing rule">
        <p className="text-sm text-gray-600 mb-4">Delete <strong>{deleteModal.item?.name}</strong>?</p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModal({ open: false, item: null })}>Cancel</Button>
          <Button variant="danger" onClick={handleDelete} loading={deleting} disabled={deleting}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
