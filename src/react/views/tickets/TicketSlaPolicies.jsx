import { useCallback, useEffect, useMemo, useState } from 'react'

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

const PRIORITY_OPTIONS = [
  { value: '', label: 'Any priority' },
  { value: 'low', label: 'Low' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'High' },
  { value: 'urgent', label: 'Urgent' },
]

const emptyForm = {
  name: '',
  category_id: '',
  priority: '',
  response_minutes: '',
  resolution_minutes: '',
  business_hours_only: false,
  is_active: true,
}

export default function TicketSlaPolicies() {
  const { success, error } = useToast()
  const [items, setItems] = useState([])
  const [categories, setCategories] = useState([])
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
      const res = await ticketsService.listSlaPolicies()
      const list = Array.isArray(res) ? res : res?.data ?? []
      setItems(list)
    } catch (e) {
      setApiError(e?.response?.data?.message || e?.message || 'Failed to load SLA policies')
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    let cancelled = false
    ticketsService.listCategories({ limit: 200 })
      .then((res) => {
        if (cancelled) return
        setCategories(Array.isArray(res) ? res : res?.data ?? [])
      })
      .catch(() => {})
    return () => { cancelled = true }
  }, [])

  const categoryOptions = useMemo(
    () => [{ value: '', label: 'Any category' }, ...categories.map((c) => ({ value: String(c.id), label: c.name || `#${c.id}` }))],
    [categories]
  )

  const openCreate = () => { setEditing(null); setForm(emptyForm); setModalOpen(true) }
  const openEdit = (it) => {
    setEditing(it)
    setForm({
      name: it.name || '',
      category_id: it.category_id ? String(it.category_id) : '',
      priority: it.priority || '',
      response_minutes: it.response_minutes != null ? String(it.response_minutes) : '',
      resolution_minutes: it.resolution_minutes != null ? String(it.resolution_minutes) : '',
      business_hours_only: !!it.business_hours_only,
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
        category_id: form.category_id || null,
        priority: form.priority || null,
        response_minutes: form.response_minutes !== '' ? Number(form.response_minutes) : null,
        resolution_minutes: form.resolution_minutes !== '' ? Number(form.resolution_minutes) : null,
        business_hours_only: !!form.business_hours_only,
        is_active: !!form.is_active,
      }
      if (editing) {
        await ticketsService.updateSlaPolicy(editing.id, payload)
        success('Policy updated')
      } else {
        await ticketsService.createSlaPolicy(payload)
        success('Policy created')
      }
      setModalOpen(false)
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to save policy')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteModal.item) return
    setDeleting(true)
    try {
      await ticketsService.deleteSlaPolicy(deleteModal.item.id)
      success('Policy deleted')
      setDeleteModal({ open: false, item: null })
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to delete policy')
    } finally {
      setDeleting(false)
    }
  }

  const categoryName = (id) => {
    if (!id) return 'Any'
    const c = categories.find((x) => String(x.id) === String(id))
    return c?.name || `#${id}`
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">SLA Policies</h1>
          <p className="mt-1 text-sm text-gray-500">Define response and resolution targets per category and priority.</p>
        </div>
        <Button onClick={openCreate}>New policy</Button>
      </div>

      {apiError && <Alert variant="danger" onClose={() => setApiError('')}>{apiError}</Alert>}

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading /></div>
        ) : items.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No SLA policies yet</h3>
            <p className="mt-1 text-sm text-gray-500">Create one to get started.</p>
            <div className="mt-4"><Button onClick={openCreate}>New policy</Button></div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Priority</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Response (min)</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Resolution (min)</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Business hrs</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {items.map((it) => (
                  <tr key={it.id}>
                    <td className="px-3 py-2 text-sm font-medium text-gray-900">{it.name}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{categoryName(it.category_id)}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{it.priority || 'Any'}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{it.response_minutes ?? '—'}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{it.resolution_minutes ?? '—'}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{it.business_hours_only ? 'Yes' : 'No'}</td>
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

      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editing ? 'Edit SLA policy' : 'New SLA policy'}>
        <div className="space-y-3">
          <Input label="Name" required value={form.name} onChange={updateField('name')} />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Select label="Category" value={form.category_id} onChange={updateField('category_id')} options={categoryOptions} placeholder="" />
            <Select label="Priority" value={form.priority} onChange={updateField('priority')} options={PRIORITY_OPTIONS} placeholder="" />
            <Input label="Response minutes" type="number" value={form.response_minutes} onChange={updateField('response_minutes')} />
            <Input label="Resolution minutes" type="number" value={form.resolution_minutes} onChange={updateField('resolution_minutes')} />
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={!!form.business_hours_only} onChange={(e) => setForm((f) => ({ ...f, business_hours_only: e.target.checked }))} />
            Business hours only
          </label>
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

      <Modal open={deleteModal.open} onClose={() => setDeleteModal({ open: false, item: null })} title="Delete SLA policy">
        <p className="text-sm text-gray-600 mb-4">Delete <strong>{deleteModal.item?.name}</strong>?</p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModal({ open: false, item: null })}>Cancel</Button>
          <Button variant="danger" onClick={handleDelete} loading={deleting} disabled={deleting}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
