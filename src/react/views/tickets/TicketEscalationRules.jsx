import { useCallback, useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import ticketsService from '../../../services/tickets.service'
import { useToast } from '../../stores/toast.jsx'

const THRESHOLD_OPTIONS = [
  { value: 'before_response', label: 'Before response SLA' },
  { value: 'after_response', label: 'After response SLA breach' },
  { value: 'before_resolution', label: 'Before resolution SLA' },
  { value: 'after_resolution', label: 'After resolution SLA breach' },
]

const emptyForm = {
  name: '',
  sla_breach_threshold: 'after_response',
  notify_role: '',
  notify_user: '',
  is_active: true,
}

export default function TicketEscalationRules() {
  const { success, error } = useToast()
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [apiError, setApiError] = useState('')

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [saving, setSaving] = useState(false)

  const [deleteModal, setDeleteModal] = useState({ open: false, item: null })
  const [deleting, setDeleting] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setApiError('')
    try {
      const res = await ticketsService.listEscalationRules()
      const list = Array.isArray(res) ? res : res?.data ?? []
      setItems(list)
    } catch (e) {
      setApiError(e?.response?.data?.message || e?.message || 'Failed to load escalation rules')
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const openCreate = () => { setEditing(null); setForm(emptyForm); setModalOpen(true) }
  const openEdit = (it) => {
    setEditing(it)
    setForm({
      name: it.name || '',
      sla_breach_threshold: it.sla_breach_threshold || 'after_response',
      notify_role: it.notify_role || '',
      notify_user: it.notify_user || it.notify_user_id || '',
      is_active: it.is_active !== false,
    })
    setModalOpen(true)
  }

  const updateField = (field) => (eOrValue) => {
    const value = eOrValue && eOrValue.target !== undefined ? eOrValue.target.value : eOrValue
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  const submit = async () => {
    if (!form.name.trim()) { error('Name is required'); return }
    setSaving(true)
    try {
      const payload = {
        name: form.name.trim(),
        sla_breach_threshold: form.sla_breach_threshold,
        notify_role: form.notify_role || null,
        notify_user: form.notify_user || null,
        is_active: !!form.is_active,
      }
      if (editing) {
        await ticketsService.updateEscalationRule(editing.id, payload)
        success('Rule updated')
      } else {
        await ticketsService.createEscalationRule(payload)
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
      await ticketsService.deleteEscalationRule(deleteModal.item.id)
      success('Rule deleted')
      setDeleteModal({ open: false, item: null })
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to delete rule')
    } finally {
      setDeleting(false)
    }
  }

  const labelForThreshold = (v) => THRESHOLD_OPTIONS.find((o) => o.value === v)?.label || v || '—'

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Escalation Rules</h1>
          <p className="mt-1 text-sm text-gray-500">Notify roles or users when SLAs are at risk or breached.</p>
        </div>
        <Button onClick={openCreate}>New rule</Button>
      </div>

      {apiError && <Alert variant="danger" onClose={() => setApiError('')}>{apiError}</Alert>}

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading /></div>
        ) : items.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No escalation rules yet</h3>
            <p className="mt-1 text-sm text-gray-500">Create one to get started.</p>
            <div className="mt-4"><Button onClick={openCreate}>New rule</Button></div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Threshold</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Notify Role</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Notify User</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {items.map((it) => (
                  <tr key={it.id}>
                    <td className="px-3 py-2 text-sm font-medium text-gray-900">{it.name}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{labelForThreshold(it.sla_breach_threshold)}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{it.notify_role || '—'}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{it.notify_user || it.notify_user_id || '—'}</td>
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

      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editing ? 'Edit escalation rule' : 'New escalation rule'}>
        <div className="space-y-3">
          <Input label="Name" required value={form.name} onChange={updateField('name')} />
          <Select
            label="SLA breach threshold"
            value={form.sla_breach_threshold}
            onChange={updateField('sla_breach_threshold')}
            options={THRESHOLD_OPTIONS}
            placeholder=""
          />
          <Input label="Notify role" value={form.notify_role} onChange={updateField('notify_role')} placeholder="e.g. manager, dispatcher" />
          <Input label="Notify user (ID or username)" value={form.notify_user} onChange={updateField('notify_user')} />
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

      <Modal open={deleteModal.open} onClose={() => setDeleteModal({ open: false, item: null })} title="Delete escalation rule">
        <p className="text-sm text-gray-600 mb-4">Delete <strong>{deleteModal.item?.name}</strong>?</p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModal({ open: false, item: null })}>Cancel</Button>
          <Button variant="danger" onClick={handleDelete} loading={deleting} disabled={deleting}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
