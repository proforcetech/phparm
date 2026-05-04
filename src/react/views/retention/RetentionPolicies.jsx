import { useCallback, useEffect, useState } from 'react'

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
import retentionService from '../../../services/retention.service'

const ACTION_OPTIONS = [
  { value: 'delete', label: 'Delete (hard remove)' },
  { value: 'anonymize', label: 'Anonymize (scrub PII)' },
  { value: 'archive', label: 'Archive (move to cold storage)' },
]

const ACTION_VARIANT = {
  delete: 'danger',
  anonymize: 'warning',
  archive: 'info',
}

const CRITERIA_PLACEHOLDER = `{
  "status": "closed",
  "include_deleted": false
}`

function formatDate(value) {
  if (!value) return '—'
  try {
    const d = new Date(String(value).replace(' ', 'T'))
    if (Number.isNaN(d.getTime())) return String(value)
    return d.toLocaleString()
  } catch {
    return String(value)
  }
}

function emptyForm() {
  return {
    id: null,
    name: '',
    description: '',
    target_table: '',
    age_days: '',
    action: 'delete',
    criteria: '',
    is_active: true,
  }
}

export default function RetentionPolicies() {
  const toast = useToast()

  const [policies, setPolicies] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [formOpen, setFormOpen] = useState(false)
  const [form, setForm] = useState(emptyForm())
  const [formError, setFormError] = useState('')
  const [saving, setSaving] = useState(false)

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleting, setDeleting] = useState(false)

  const [runTarget, setRunTarget] = useState(null)
  const [running, setRunning] = useState(false)

  const [runAllOpen, setRunAllOpen] = useState(false)
  const [runningAll, setRunningAll] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    retentionService
      .listPolicies()
      .then((res) => setPolicies(res?.data ?? res ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load policies'))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { load() }, [load])

  const openCreate = () => {
    setForm(emptyForm())
    setFormError('')
    setFormOpen(true)
  }

  const openEdit = async (row) => {
    setFormError('')
    try {
      const res = await retentionService.getPolicy(row.id)
      const full = res?.data ?? res ?? row
      setForm({
        id: full.id,
        name: full.name || '',
        description: full.description || '',
        target_table: full.target_table || '',
        age_days: full.age_days != null ? String(full.age_days) : '',
        action: full.action || 'delete',
        criteria: full.criteria ? JSON.stringify(full.criteria, null, 2) : '',
        is_active: full.is_active !== false,
      })
      setFormOpen(true)
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Failed to load policy')
    }
  }

  const submitForm = async () => {
    setFormError('')
    if (!form.name.trim()) { setFormError('Name is required.'); return }
    if (!form.target_table.trim()) { setFormError('Target table is required.'); return }
    const ageDays = Number(form.age_days)
    if (!Number.isFinite(ageDays) || ageDays <= 0 || !Number.isInteger(ageDays)) {
      setFormError('Age (days) must be a positive integer.')
      return
    }
    let parsedCriteria = {}
    if (form.criteria.trim()) {
      try {
        parsedCriteria = JSON.parse(form.criteria)
      } catch (err) {
        setFormError(`Criteria is not valid JSON: ${err.message}`)
        return
      }
    }
    const payload = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      target_table: form.target_table.trim(),
      age_days: ageDays,
      action: form.action,
      criteria: parsedCriteria,
      is_active: form.is_active,
    }
    setSaving(true)
    try {
      if (form.id) {
        await retentionService.updatePolicy(form.id, payload)
        toast.success('Policy updated.')
      } else {
        await retentionService.createPolicy(payload)
        toast.success('Policy created.')
      }
      setFormOpen(false)
      load()
    } catch (e) {
      setFormError(e?.response?.data?.message || e?.message || 'Failed to save policy')
    } finally {
      setSaving(false)
    }
  }

  const submitDelete = async () => {
    if (!deleteTarget) return
    setDeleting(true)
    try {
      await retentionService.deletePolicy(deleteTarget.id)
      toast.success('Policy deleted.')
      setDeleteTarget(null)
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Failed to delete policy')
    } finally {
      setDeleting(false)
    }
  }

  const submitRun = async () => {
    if (!runTarget) return
    setRunning(true)
    try {
      const res = await retentionService.runPolicy(runTarget.id)
      const data = res?.data ?? res ?? {}
      const pruned = data.records_pruned ?? data.pruned ?? 0
      toast.success(`Policy ran. Records affected: ${pruned}`)
      setRunTarget(null)
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Run failed')
    } finally {
      setRunning(false)
    }
  }

  const submitRunAll = async () => {
    setRunningAll(true)
    try {
      const res = await retentionService.runAll()
      const data = res?.data ?? res ?? {}
      const total = data.total_pruned ?? data.records_pruned ?? data.policies_run ?? 'completed'
      toast.success(`Run-all completed: ${total}`)
      setRunAllOpen(false)
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Run-all failed')
    } finally {
      setRunningAll(false)
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Retention policies</h1>
          <p className="text-sm text-gray-500">
            Define rules that prune or anonymize old data on a schedule. Compliance-adjacent — every run is logged.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="danger" onClick={() => setRunAllOpen(true)}>Run all due policies now</Button>
          <Button onClick={openCreate}>New policy</Button>
        </div>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card padding={false}>
        {loading ? (
          <div className="p-6 text-center"><Loading /></div>
        ) : policies.length === 0 ? (
          <div className="p-6 text-center text-gray-500">No retention policies defined.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Name</th>
                  <th className="text-left p-2">Target table</th>
                  <th className="text-left p-2">Age (days)</th>
                  <th className="text-left p-2">Action</th>
                  <th className="text-left p-2">Active</th>
                  <th className="text-left p-2">Last run</th>
                  <th className="text-left p-2">Last pruned</th>
                  <th className="text-right p-2"> </th>
                </tr>
              </thead>
              <tbody>
                {policies.map((p) => (
                  <tr key={p.id} className="border-t">
                    <td className="p-2">{p.name}</td>
                    <td className="p-2 font-mono text-xs">{p.target_table || '—'}</td>
                    <td className="p-2">{p.age_days ?? '—'}</td>
                    <td className="p-2">
                      <Badge variant={ACTION_VARIANT[p.action] || 'default'}>{p.action || '—'}</Badge>
                    </td>
                    <td className="p-2">
                      {p.is_active === false
                        ? <Badge variant="default">No</Badge>
                        : <Badge variant="success">Yes</Badge>}
                    </td>
                    <td className="p-2">{formatDate(p.last_run_at)}</td>
                    <td className="p-2">{p.last_run_pruned_count ?? '—'}</td>
                    <td className="p-2 text-right">
                      <div className="flex gap-2 justify-end">
                        <Button size="xs" variant="secondary" onClick={() => openEdit(p)}>Edit</Button>
                        <Button size="xs" variant="danger" onClick={() => setDeleteTarget(p)}>Delete</Button>
                        <Button size="xs" onClick={() => setRunTarget(p)}>Run now</Button>
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
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title={form.id ? 'Edit retention policy' : 'New retention policy'}
        size="lg"
      >
        <div className="space-y-3">
          {formError && <Alert variant="danger" closable={false}>{formError}</Alert>}
          <Input
            label="Name"
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            placeholder="e.g. Old workorder cleanup"
            required
          />
          <Textarea
            label="Description"
            value={form.description}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
            rows={2}
            placeholder="What this policy does and why it exists."
          />
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <Input
              label="Target table"
              value={form.target_table}
              onChange={(e) => setForm({ ...form, target_table: e.target.value })}
              placeholder="e.g. workorders"
              required
            />
            <Input
              label="Age (days)"
              type="number"
              value={form.age_days}
              onChange={(e) => setForm({ ...form, age_days: e.target.value })}
              placeholder="e.g. 365"
              required
            />
            <Select
              label="Action"
              value={form.action}
              onChange={(e) => setForm({ ...form, action: e?.target?.value ?? form.action })}
              options={ACTION_OPTIONS}
              placeholder=""
              required
            />
          </div>
          <Textarea
            label="Criteria (JSON, optional)"
            value={form.criteria}
            onChange={(e) => setForm({ ...form, criteria: e.target.value })}
            rows={6}
            placeholder={CRITERIA_PLACEHOLDER}
            helperText="Additional WHERE filters applied alongside the age check."
          />
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
            />
            Active
          </label>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setFormOpen(false)}>Cancel</Button>
            <Button disabled={saving} onClick={submitForm}>
              {saving ? 'Saving…' : (form.id ? 'Save changes' : 'Create policy')}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={deleteTarget !== null}
        onClose={() => setDeleteTarget(null)}
        title="Delete retention policy"
      >
        <div className="space-y-3">
          <Alert variant="warning" closable={false}>
            Delete <strong>{deleteTarget?.name}</strong>? Past run history is preserved but this policy will
            no longer execute.
          </Alert>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteTarget(null)}>Cancel</Button>
            <Button variant="danger" disabled={deleting} onClick={submitDelete}>
              {deleting ? 'Deleting…' : 'Delete'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={runTarget !== null}
        onClose={() => setRunTarget(null)}
        title="Run retention policy"
      >
        <div className="space-y-3">
          <Alert variant="warning" closable={false}>
            This will permanently affect data in <strong>{runTarget?.target_table}</strong> matching
            <strong> {runTarget?.name}</strong>. Continue?
          </Alert>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setRunTarget(null)}>Cancel</Button>
            <Button variant="danger" disabled={running} onClick={submitRun}>
              {running ? 'Running…' : 'Run now'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={runAllOpen}
        onClose={() => setRunAllOpen(false)}
        title="Run all due retention policies"
      >
        <div className="space-y-3">
          <Alert variant="warning" closable={false}>
            This will execute every active policy whose schedule is due. Data may be permanently deleted
            or anonymized. Continue?
          </Alert>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setRunAllOpen(false)}>Cancel</Button>
            <Button variant="danger" disabled={runningAll} onClick={submitRunAll}>
              {runningAll ? 'Running…' : 'Run all'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
