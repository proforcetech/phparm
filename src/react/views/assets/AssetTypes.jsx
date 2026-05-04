import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import assetsService from '../../../services/assets.service'
import { useToast } from '../../stores/toast.jsx'

const EMPTY_FORM = { name: '', division: '', description: '' }

function unwrapList(res) {
  if (Array.isArray(res)) return res
  if (Array.isArray(res?.data)) return res.data
  if (Array.isArray(res?.items)) return res.items
  return []
}

export default function AssetTypes() {
  const { success, error } = useToast()

  const [types, setTypes] = useState([])
  const [loading, setLoading] = useState(true)

  const [editorOpen, setEditorOpen] = useState(false)
  const [editorBusy, setEditorBusy] = useState(false)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleteBusy, setDeleteBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await assetsService.listTypes({ limit: 500 })
      setTypes(unwrapList(res))
    } catch {
      setTypes([])
      error('Failed to load asset types')
    } finally {
      setLoading(false)
    }
  }, [error])

  useEffect(() => {
    load()
  }, [load])

  const openCreate = () => {
    setEditing(null)
    setForm(EMPTY_FORM)
    setEditorOpen(true)
  }

  const openEdit = (t) => {
    setEditing(t)
    setForm({
      name: t.name || '',
      division: t.division || '',
      description: t.description || '',
    })
    setEditorOpen(true)
  }

  const submit = async () => {
    if (!form.name.trim()) {
      error('Name is required')
      return
    }
    setEditorBusy(true)
    try {
      const payload = {
        name: form.name.trim(),
        division: form.division.trim() || undefined,
        description: form.description.trim() || undefined,
      }
      if (editing) {
        await assetsService.updateType(editing.id, payload)
        success('Asset type updated')
      } else {
        await assetsService.createType(payload)
        success('Asset type created')
      }
      setEditorOpen(false)
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to save asset type')
    } finally {
      setEditorBusy(false)
    }
  }

  const confirmDelete = async () => {
    if (!deleteTarget) return
    setDeleteBusy(true)
    try {
      await assetsService.deleteType(deleteTarget.id)
      success('Asset type deleted')
      setDeleteTarget(null)
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to delete asset type')
    } finally {
      setDeleteBusy(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link to="/cp/assets" className="text-gray-500 hover:text-gray-700">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M15 19l-7-7 7-7"
              />
            </svg>
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Asset Types</h1>
            <p className="mt-1 text-sm text-gray-500">
              Catalog of installed equipment types (HVAC, alarm, generator, etc).
            </p>
          </div>
        </div>
        <Button onClick={openCreate}>New Type</Button>
      </div>

      <Card padding={false}>
        {loading ? (
          <div className="py-12 flex justify-center">
            <Loading text="Loading asset types..." />
          </div>
        ) : types.length === 0 ? (
          <div className="text-center py-12 px-6">
            <h3 className="text-sm font-medium text-gray-900">No asset types defined</h3>
            <p className="mt-1 text-sm text-gray-500">
              Create your first asset type to start tracking installed equipment.
            </p>
            <div className="mt-4">
              <Button onClick={openCreate}>New Type</Button>
            </div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Division</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Description
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Default PM Plan
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {types.map((t) => (
                  <tr key={t.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-medium text-gray-900">{t.name}</td>
                    <td className="px-4 py-3 text-sm text-gray-700">
                      {t.division || <span className="text-gray-400">—</span>}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-500 max-w-md truncate">
                      {t.description || <span className="text-gray-400">—</span>}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-500">
                      {t.default_pm_plan_id ? (
                        <span className="font-mono text-xs">#{t.default_pm_plan_id}</span>
                      ) : (
                        <span className="text-gray-400">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex justify-end gap-2">
                        <Button size="sm" variant="ghost" onClick={() => openEdit(t)}>
                          Edit
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-red-600 hover:text-red-700"
                          onClick={() => setDeleteTarget(t)}
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
        open={editorOpen}
        title={editing ? `Edit ${editing.name}` : 'New Asset Type'}
        onClose={() => setEditorOpen(false)}
      >
        <div className="space-y-3">
          <Input
            label="Name"
            required
            value={form.name}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, name: v }))}
            placeholder="e.g. Rooftop HVAC Unit"
          />
          <Input
            label="Division"
            value={form.division}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, division: v }))}
            placeholder="e.g. HVAC, Fire & Life Safety"
          />
          <Textarea
            label="Description"
            value={form.description}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, description: v }))}
            rows={3}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setEditorOpen(false)}>
              Cancel
            </Button>
            <Button loading={editorBusy} onClick={submit}>
              {editing ? 'Save' : 'Create'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={deleteTarget !== null}
        title="Delete Asset Type"
        onClose={() => setDeleteTarget(null)}
      >
        <p className="text-sm text-gray-600 mb-4">
          Delete <strong>{deleteTarget?.name}</strong>? This may fail if assets reference it.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteTarget(null)}>
            Cancel
          </Button>
          <Button variant="danger" loading={deleteBusy} onClick={confirmDelete}>
            Delete
          </Button>
        </div>
      </Modal>
    </div>
  )
}
