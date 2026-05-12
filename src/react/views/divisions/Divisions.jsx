import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import divisionsService from '../../../services/divisions.service'
import { useToast } from '../../stores/toast.jsx'

const emptyForm = {
  name: '',
  code: '',
  description: '',
  is_active: true,
}

function formatDate(value) {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString()
}

export default function Divisions() {
  const toast = useToast()
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')

  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    divisionsService
      .list()
      .then((res) => setItems(res?.data ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load divisions'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { load() }, [load])

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return items
    return items.filter((d) => {
      const haystack = `${d.name || ''} ${d.code || ''} ${d.description || ''}`.toLowerCase()
      return haystack.includes(q)
    })
  }, [items, search])

  const openCreate = () => {
    setEditing(null)
    setForm(emptyForm)
    setFormOpen(true)
  }

  const openEdit = (row) => {
    setEditing(row)
    setForm({
      name: row.name || '',
      code: row.code || '',
      description: row.description || '',
      is_active: row.is_active === undefined ? true : !!row.is_active,
    })
    setFormOpen(true)
  }

  const handleDelete = async (row) => {
    if (!window.confirm(`Delete division "${row.name}"? This cannot be undone.`)) {
      return
    }
    try {
      await divisionsService.delete(row.id)
      toast.success('Division deleted')
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Delete failed')
    }
  }

  const submit = async () => {
    if (!form.name.trim()) {
      toast.error('Name is required')
      return
    }
    setBusy(true)
    const payload = {
      name: form.name.trim(),
      code: form.code.trim().toUpperCase(),
      description: form.description.trim(),
      is_active: !!form.is_active,
    }
    try {
      if (editing) {
        await divisionsService.update(editing.id, payload)
        toast.success('Division updated')
      } else {
        await divisionsService.create(payload)
        toast.success('Division created')
      }
      setFormOpen(false)
      setEditing(null)
      setForm(emptyForm)
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Save failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-end justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-xl font-semibold">Divisions</h1>
          <p className="text-sm text-gray-500">
            Top-level org partitioning above branches.
          </p>
        </div>
        <Button onClick={openCreate}>New Division</Button>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card padding={false}>
        <div className="p-4 flex items-end gap-3 flex-wrap">
          <Input
            label="Search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Filter by name, code, or description"
          />
          <Button variant="secondary" onClick={load}>Refresh</Button>
        </div>

        {loading ? (
          <div className="p-6 text-center"><Loading /></div>
        ) : filtered.length === 0 ? (
          <div className="p-6 text-center text-gray-500">
            {items.length === 0 ? 'No divisions yet.' : 'No matches.'}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Name</th>
                  <th className="text-left p-2">Code</th>
                  <th className="text-left p-2">Description</th>
                  <th className="text-left p-2">Status</th>
                  <th className="text-left p-2">Created</th>
                  <th className="text-right p-2"> </th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((d) => (
                  <tr key={d.id} className="border-t">
                    <td className="p-2 font-medium">{d.name}</td>
                    <td className="p-2 font-mono text-xs">{d.code || <span className="text-gray-400">—</span>}</td>
                    <td className="p-2 text-gray-600">
                      {d.description
                        ? <span className="line-clamp-2">{d.description}</span>
                        : <span className="text-gray-400">—</span>}
                    </td>
                    <td className="p-2">
                      {d.is_active
                        ? <Badge variant="success">Active</Badge>
                        : <Badge variant="default">Inactive</Badge>}
                    </td>
                    <td className="p-2 text-gray-500">{formatDate(d.created_at)}</td>
                    <td className="p-2 text-right">
                      <Button variant="outline" size="sm" onClick={() => openEdit(d)}>Edit</Button>
                      <Button
                        variant="danger"
                        size="sm"
                        className="ml-2"
                        onClick={() => handleDelete(d)}
                      >
                        Delete
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title={editing ? 'Edit Division' : 'New Division'}
      >
        <div className="space-y-3">
          <Input
            label="Name"
            required
            value={form.name}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            placeholder="e.g. North Region"
          />
          <Input
            label="Code"
            value={form.code}
            onChange={(e) => setForm((f) => ({ ...f, code: e.target.value.toUpperCase() }))}
            placeholder="e.g. NR"
            helperText="Short uppercase code"
          />
          <Textarea
            label="Description"
            rows={3}
            value={form.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
          />
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
            />
            Active
          </label>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setFormOpen(false)} disabled={busy}>Cancel</Button>
            <Button onClick={submit} loading={busy}>
              {editing ? 'Save changes' : 'Create division'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
