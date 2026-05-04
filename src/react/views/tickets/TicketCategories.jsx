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
  { value: '', label: 'No default' },
  { value: 'low', label: 'Low' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'High' },
  { value: 'urgent', label: 'Urgent' },
]

const emptyForm = {
  name: '',
  parent_id: '',
  default_priority: '',
  is_active: true,
}

export default function TicketCategories() {
  const { success, error } = useToast()
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [apiError, setApiError] = useState('')
  const [search, setSearch] = useState('')

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
      const params = {}
      if (search.trim()) params.query = search.trim()
      const res = await ticketsService.listCategories(params)
      const list = Array.isArray(res) ? res : res?.data ?? []
      setItems(list)
    } catch (e) {
      setApiError(e?.response?.data?.message || e?.message || 'Failed to load categories')
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [search])

  useEffect(() => { load() }, [load])

  const parentOptions = useMemo(() => {
    const others = items
      .filter((c) => !editing || c.id !== editing.id)
      .map((c) => ({ value: String(c.id), label: c.name || `#${c.id}` }))
    return [{ value: '', label: 'No parent' }, ...others]
  }, [items, editing])

  const parentName = (id) => {
    if (!id) return '—'
    const p = items.find((x) => String(x.id) === String(id))
    return p?.name || `#${id}`
  }

  const openCreate = () => { setEditing(null); setForm(emptyForm); setModalOpen(true) }
  const openEdit = (it) => {
    setEditing(it)
    setForm({
      name: it.name || '',
      parent_id: it.parent_id ? String(it.parent_id) : '',
      default_priority: it.default_priority || '',
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
        parent_id: form.parent_id || null,
        default_priority: form.default_priority || null,
        is_active: !!form.is_active,
      }
      if (editing) {
        await ticketsService.updateCategory(editing.id, payload)
        success('Category updated')
      } else {
        await ticketsService.createCategory(payload)
        success('Category created')
      }
      setModalOpen(false)
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to save category')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteModal.item) return
    setDeleting(true)
    try {
      await ticketsService.deleteCategory(deleteModal.item.id)
      success('Category deleted')
      setDeleteModal({ open: false, item: null })
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to delete category')
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Ticket Categories</h1>
          <p className="mt-1 text-sm text-gray-500">Classify tickets to drive routing and SLAs.</p>
        </div>
        <Button onClick={openCreate}>New category</Button>
      </div>

      {apiError && <Alert variant="danger" onClose={() => setApiError('')}>{apiError}</Alert>}

      <Card>
        <form onSubmit={(e) => { e.preventDefault(); load() }} className="flex gap-2 mb-4">
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search categories..." className="flex-1" />
          <Button type="submit" variant="secondary">Search</Button>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center"><Loading /></div>
        ) : items.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No categories yet</h3>
            <p className="mt-1 text-sm text-gray-500">Create one to get started.</p>
            <div className="mt-4"><Button onClick={openCreate}>New category</Button></div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Parent</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Default Priority</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {items.map((it) => (
                  <tr key={it.id}>
                    <td className="px-3 py-2 text-sm font-medium text-gray-900">{it.name}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{parentName(it.parent_id)}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{it.default_priority || '—'}</td>
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

      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editing ? 'Edit category' : 'New category'}>
        <div className="space-y-3">
          <Input label="Name" required value={form.name} onChange={updateField('name')} />
          <Select label="Parent category" value={form.parent_id} onChange={updateField('parent_id')} options={parentOptions} placeholder="" />
          <Select label="Default priority" value={form.default_priority} onChange={updateField('default_priority')} options={PRIORITY_OPTIONS} placeholder="" />
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

      <Modal open={deleteModal.open} onClose={() => setDeleteModal({ open: false, item: null })} title="Delete category">
        <p className="text-sm text-gray-600 mb-4">Delete <strong>{deleteModal.item?.name}</strong>?</p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModal({ open: false, item: null })}>Cancel</Button>
          <Button variant="danger" onClick={handleDelete} loading={deleting} disabled={deleting}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
