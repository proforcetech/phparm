import { useCallback, useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import ticketsService from '../../../services/tickets.service'
import { useToast } from '../../stores/toast.jsx'

const emptyForm = { code: '', label: '', requires_resolution: false, is_active: true }

export default function TicketCloseReasons() {
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
      const res = await ticketsService.listCloseReasons(params)
      const list = Array.isArray(res) ? res : res?.data ?? []
      setItems(list)
    } catch (e) {
      setApiError(e?.response?.data?.message || e?.message || 'Failed to load close reasons')
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [search])

  useEffect(() => { load() }, [load])

  const openCreate = () => { setEditing(null); setForm(emptyForm); setModalOpen(true) }
  const openEdit = (it) => {
    setEditing(it)
    setForm({
      code: it.code || '',
      label: it.label || it.name || '',
      requires_resolution: !!it.requires_resolution,
      is_active: it.is_active !== false,
    })
    setModalOpen(true)
  }

  const updateField = (field) => (eOrValue) => {
    const value = eOrValue && eOrValue.target !== undefined ? eOrValue.target.value : eOrValue
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  const submit = async () => {
    if (!form.code.trim()) { error('Code is required'); return }
    if (!form.label.trim()) { error('Label is required'); return }
    setSaving(true)
    try {
      const payload = {
        code: form.code.trim(),
        label: form.label.trim(),
        requires_resolution: !!form.requires_resolution,
        is_active: !!form.is_active,
      }
      if (editing) {
        await ticketsService.updateCloseReason(editing.id, payload)
        success('Close reason updated')
      } else {
        await ticketsService.createCloseReason(payload)
        success('Close reason created')
      }
      setModalOpen(false)
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to save close reason')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteModal.item) return
    setDeleting(true)
    try {
      await ticketsService.deleteCloseReason(deleteModal.item.id)
      success('Close reason deleted')
      setDeleteModal({ open: false, item: null })
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to delete close reason')
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Close Reasons</h1>
          <p className="mt-1 text-sm text-gray-500">Catalog of reasons used when closing a ticket.</p>
        </div>
        <Button onClick={openCreate}>New reason</Button>
      </div>

      {apiError && <Alert variant="danger" onClose={() => setApiError('')}>{apiError}</Alert>}

      <Card>
        <form onSubmit={(e) => { e.preventDefault(); load() }} className="flex gap-2 mb-4">
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search reasons..." className="flex-1" />
          <Button type="submit" variant="secondary">Search</Button>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center"><Loading /></div>
        ) : items.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No close reasons yet</h3>
            <p className="mt-1 text-sm text-gray-500">Create one to get started.</p>
            <div className="mt-4"><Button onClick={openCreate}>New reason</Button></div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Label</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Requires Resolution</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {items.map((it) => (
                  <tr key={it.id}>
                    <td className="px-3 py-2 text-sm font-mono text-gray-900">{it.code}</td>
                    <td className="px-3 py-2 text-sm text-gray-700">{it.label || it.name || '—'}</td>
                    <td className="px-3 py-2 text-sm text-gray-500">{it.requires_resolution ? 'Yes' : 'No'}</td>
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

      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editing ? 'Edit close reason' : 'New close reason'}>
        <div className="space-y-3">
          <Input label="Code" required value={form.code} onChange={updateField('code')} placeholder="e.g. duplicate" />
          <Input label="Label" required value={form.label} onChange={updateField('label')} placeholder="Human-readable label" />
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={!!form.requires_resolution} onChange={(e) => setForm((f) => ({ ...f, requires_resolution: e.target.checked }))} />
            Requires resolution code
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

      <Modal open={deleteModal.open} onClose={() => setDeleteModal({ open: false, item: null })} title="Delete close reason">
        <p className="text-sm text-gray-600 mb-4">Delete <strong>{deleteModal.item?.code}</strong>?</p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModal({ open: false, item: null })}>Cancel</Button>
          <Button variant="danger" onClick={handleDelete} loading={deleting} disabled={deleting}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
