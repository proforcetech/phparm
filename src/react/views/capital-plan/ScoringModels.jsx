import { useCallback, useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import { useToast } from '../../stores/toast.jsx'
import capitalPlanService from '../../../services/capital-plan.service'

const WEIGHTS_PLACEHOLDER = `{
  "age_years": 0.4,
  "failure_rate": 0.3,
  "maintenance_cost": 0.2,
  "downtime_hours": 0.1
}`

function summarizeWeights(weights) {
  if (!weights) return '—'
  let obj = weights
  if (typeof weights === 'string') {
    try {
      obj = JSON.parse(weights)
    } catch {
      return weights.length > 40 ? `${weights.slice(0, 40)}…` : weights
    }
  }
  if (typeof obj !== 'object' || obj === null) return '—'
  const entries = Object.entries(obj)
  if (entries.length === 0) return '—'
  const top = entries.slice(0, 3).map(([k, v]) => `${k}=${v}`).join(', ')
  return entries.length > 3 ? `${top}, +${entries.length - 3}` : top
}

function emptyForm() {
  return { id: null, name: '', description: '', weights: '' }
}

export default function ScoringModels() {
  const toast = useToast()
  const [models, setModels] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [formOpen, setFormOpen] = useState(false)
  const [form, setForm] = useState(emptyForm())
  const [formError, setFormError] = useState('')
  const [busy, setBusy] = useState(false)

  const [confirmDelete, setConfirmDelete] = useState(null)
  const [deleteBusy, setDeleteBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    capitalPlanService
      .listScoringModels()
      .then((res) => setModels(res?.data ?? []))
      .catch((e) => {
        const msg = e?.response?.data?.message || e?.message || 'Failed to load scoring models'
        setError(msg)
      })
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const openCreate = () => {
    setForm(emptyForm())
    setFormError('')
    setFormOpen(true)
  }

  const openEdit = (model) => {
    let weightsString = ''
    if (model.weights) {
      if (typeof model.weights === 'string') {
        weightsString = model.weights
      } else {
        try {
          weightsString = JSON.stringify(model.weights, null, 2)
        } catch {
          weightsString = ''
        }
      }
    }
    setForm({
      id: model.id,
      name: model.name || '',
      description: model.description || '',
      weights: weightsString,
    })
    setFormError('')
    setFormOpen(true)
  }

  const submit = async () => {
    setFormError('')
    if (!form.name.trim()) {
      setFormError('Name is required.')
      return
    }
    let parsedWeights = null
    if (form.weights.trim()) {
      try {
        parsedWeights = JSON.parse(form.weights)
        if (typeof parsedWeights !== 'object' || parsedWeights === null || Array.isArray(parsedWeights)) {
          setFormError('Weights must be a JSON object.')
          return
        }
      } catch {
        setFormError('Weights must be valid JSON.')
        return
      }
    }
    const payload = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      weights: parsedWeights,
    }
    setBusy(true)
    try {
      if (form.id) {
        await capitalPlanService.updateScoringModel(form.id, payload)
        toast.success('Scoring model updated.')
      } else {
        await capitalPlanService.createScoringModel(payload)
        toast.success('Scoring model created.')
      }
      setFormOpen(false)
      load()
    } catch (e) {
      const msg = e?.response?.data?.message || e?.message || 'Save failed'
      setFormError(msg)
    } finally {
      setBusy(false)
    }
  }

  const setDefault = async (model) => {
    try {
      await capitalPlanService.setDefaultScoringModel(model.id)
      toast.success(`"${model.name}" is now the default.`)
      load()
    } catch (e) {
      const msg = e?.response?.data?.message || e?.message || 'Failed to set default'
      toast.error(msg)
    }
  }

  const confirmRemove = async () => {
    if (!confirmDelete) return
    setDeleteBusy(true)
    try {
      await capitalPlanService.deleteScoringModel(confirmDelete.id)
      toast.success('Scoring model deleted.')
      setConfirmDelete(null)
      load()
    } catch (e) {
      const msg = e?.response?.data?.message || e?.message || 'Delete failed'
      toast.error(msg)
    } finally {
      setDeleteBusy(false)
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-center justify-between flex-wrap gap-2">
        <div>
          <h1 className="text-xl font-semibold">Scoring Models</h1>
          <p className="text-sm text-gray-500">
            Tunable weight sets used by the aging engine to classify assets into replacement buckets.
          </p>
        </div>
        <Button onClick={openCreate}>New Model</Button>
      </header>

      {error ? (
        <Alert variant="danger" onClose={() => setError('')}>
          {error}
        </Alert>
      ) : null}

      <Card padding={false}>
        {loading ? (
          <div className="p-6 text-center">
            <Loading />
          </div>
        ) : models.length === 0 ? (
          <div className="p-6 text-center text-gray-500">No scoring models defined.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Name</th>
                  <th className="text-left p-2">Default</th>
                  <th className="text-left p-2">Weights</th>
                  <th className="text-left p-2">Updated</th>
                  <th className="text-right p-2"> </th>
                </tr>
              </thead>
              <tbody>
                {models.map((m) => (
                  <tr key={m.id} className="border-t">
                    <td className="p-2">
                      <div className="font-medium">{m.name}</div>
                      {m.description ? (
                        <div className="text-xs text-gray-500">{m.description}</div>
                      ) : null}
                    </td>
                    <td className="p-2">
                      {m.is_default ? <Badge variant="success">Default</Badge> : <span className="text-gray-400">—</span>}
                    </td>
                    <td className="p-2 font-mono text-xs">{summarizeWeights(m.weights)}</td>
                    <td className="p-2">{m.updated_at || m.created_at || '—'}</td>
                    <td className="p-2 text-right space-x-2">
                      <Button size="sm" variant="outline" onClick={() => openEdit(m)}>
                        Edit
                      </Button>
                      {!m.is_default ? (
                        <Button size="sm" variant="secondary" onClick={() => setDefault(m)}>
                          Set as default
                        </Button>
                      ) : null}
                      <Button size="sm" variant="danger" onClick={() => setConfirmDelete(m)}>
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
        title={form.id ? 'Edit scoring model' : 'New scoring model'}
        size="lg"
      >
        <div className="space-y-3">
          {formError ? <Alert variant="danger">{formError}</Alert> : null}
          <Input
            label="Name"
            value={form.name}
            required
            onChange={(e) => setForm((f) => ({ ...f, name: e?.target?.value ?? e }))}
          />
          <Textarea
            label="Description"
            rows={2}
            value={form.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e?.target?.value ?? e }))}
          />
          <Textarea
            label="Weights (JSON object: attribute → weight)"
            rows={8}
            value={form.weights}
            placeholder={WEIGHTS_PLACEHOLDER}
            onChange={(e) => setForm((f) => ({ ...f, weights: e?.target?.value ?? e }))}
            helperText="Each key is a scoring attribute; each value is its relative weight. Leave blank to use defaults."
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setFormOpen(false)}>
              Cancel
            </Button>
            <Button disabled={busy} onClick={submit}>
              {busy ? 'Saving…' : form.id ? 'Save' : 'Create'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={confirmDelete !== null}
        onClose={() => setConfirmDelete(null)}
        title="Delete scoring model"
      >
        {confirmDelete ? (
          <div className="space-y-3">
            <p className="text-sm">
              Delete scoring model <strong>{confirmDelete.name}</strong>? This cannot be undone.
            </p>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setConfirmDelete(null)}>
                Cancel
              </Button>
              <Button variant="danger" disabled={deleteBusy} onClick={confirmRemove}>
                {deleteBusy ? 'Deleting…' : 'Delete'}
              </Button>
            </div>
          </div>
        ) : null}
      </Modal>
    </div>
  )
}
