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
import skillsService, { PROFICIENCY_LEVELS } from '../../../services/skills.service'

/**
 * Phase 17 / S11 of docs/woms-expansion-plan.md.
 *
 * Two tabs:
 *   - Matrix    grid of technicians × skills with cell click → grant/revoke
 *   - Catalog   CRUD on the skills catalog
 *
 * The matrix grid uses the combined /api/skills/matrix payload so we render
 * the whole grid in one fetch — clicking a cell opens a small modal that
 * grants or updates the cell. Revoke is in the same modal.
 *
 * Read perm:  skills.view  (server-enforced)
 * Write perm: skills.manage
 */

const PROFICIENCY_VARIANT = {
  learner: 'secondary',
  competent: 'info',
  expert: 'success',
}

function titleize(s) {
  if (!s) return ''
  return String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function formatDate(s) {
  if (!s) return '—'
  try {
    return new Date(String(s).replace(' ', 'T')).toLocaleDateString()
  } catch {
    return s
  }
}

export default function SkillMatrix() {
  const [tab, setTab] = useState('matrix')
  const [error, setError] = useState('')

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Technician Skill Matrix</h1>
          <p className="text-sm text-gray-500">
            Track which technicians can do which kinds of work, at what proficiency, and when their certifications expire.
          </p>
        </div>
        <nav className="flex gap-2">
          <Button
            variant={tab === 'matrix' ? 'primary' : 'secondary'}
            onClick={() => setTab('matrix')}
          >
            Matrix
          </Button>
          <Button
            variant={tab === 'catalog' ? 'primary' : 'secondary'}
            onClick={() => setTab('catalog')}
          >
            Skills Catalog
          </Button>
        </nav>
      </header>

      {error && (
        <Alert variant="danger" onClose={() => setError('')}>
          {error}
        </Alert>
      )}

      {tab === 'matrix' && <MatrixGrid onError={setError} />}
      {tab === 'catalog' && <SkillsCatalog onError={setError} />}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Matrix grid tab
// ---------------------------------------------------------------------------
function MatrixGrid({ onError }) {
  const [loading, setLoading] = useState(true)
  const [data, setData] = useState({ users: [], skills: [], assignments: {} })
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (search.trim() !== '') params.search = search.trim()
      const res = await skillsService.matrix(params)
      setData(res?.data ?? { users: [], skills: [], assignments: {} })
    } catch (e) {
      onError?.(e?.response?.data?.message || e?.message || 'Failed to load matrix')
    } finally {
      setLoading(false)
    }
  }, [onError, search])

  useEffect(() => {
    load()
  }, [load])

  const skillsByCategory = useMemo(() => {
    const groups = {}
    for (const s of data.skills || []) {
      const key = s.category || 'general'
      if (!groups[key]) groups[key] = []
      groups[key].push(s)
    }
    return groups
  }, [data.skills])

  const handleCellClick = (user, skill) => {
    const existing = data.assignments?.[user.id]?.[skill.id] || null
    setEditing({ user, skill, existing })
  }

  const handleSaved = async () => {
    setEditing(null)
    await load()
  }

  if (loading) return <Loading />

  return (
    <Card>
      <div className="flex items-end gap-2 p-3 border-b">
        <Input
          label="Search technicians"
          value={search}
          onChange={(e) => setSearch(e.target.value ?? e)}
          placeholder="Name or email"
        />
        <Button onClick={load}>Refresh</Button>
      </div>

      {(!data.users || data.users.length === 0) ? (
        <div className="p-4 text-sm text-gray-500">No technicians found.</div>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50 sticky top-0">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-gray-600 sticky left-0 bg-gray-50 z-10">
                  Technician
                </th>
                {data.skills?.map((s) => (
                  <th
                    key={s.id}
                    className="px-2 py-2 text-left font-medium text-gray-600 whitespace-nowrap"
                    title={s.description || ''}
                  >
                    <div className="text-xs uppercase tracking-wide text-gray-400">
                      {titleize(s.category || 'general')}
                    </div>
                    {s.name}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.users.map((u) => (
                <tr key={u.id} className="border-t hover:bg-gray-50">
                  <td className="px-3 py-2 sticky left-0 bg-white z-10">
                    <div className="font-medium text-gray-800">{u.name}</div>
                    <div className="text-xs text-gray-500">{u.email}</div>
                    <div className="text-xs text-gray-400">{titleize(u.role)}</div>
                  </td>
                  {data.skills?.map((s) => {
                    const cell = data.assignments?.[u.id]?.[s.id] || null
                    return (
                      <td key={s.id} className="px-2 py-2">
                        <button
                          type="button"
                          className="rounded px-2 py-1 hover:bg-primary-50 focus:outline-none focus:ring-2 focus:ring-primary-300"
                          onClick={() => handleCellClick(u, s)}
                          aria-label={`Edit ${s.name} for ${u.name}`}
                        >
                          {cell ? (
                            <CellBadge cell={cell} />
                          ) : (
                            <span className="text-gray-300">—</span>
                          )}
                        </button>
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editing && (
        <CellEditModal
          user={editing.user}
          skill={editing.skill}
          existing={editing.existing}
          onClose={() => setEditing(null)}
          onSaved={handleSaved}
          onError={onError}
        />
      )}
    </Card>
  )
}

function CellBadge({ cell }) {
  const variant = cell.is_expired ? 'danger' : (PROFICIENCY_VARIANT[cell.proficiency_level] || 'secondary')
  return (
    <div className="flex flex-col items-start gap-1">
      <Badge variant={variant}>
        {titleize(cell.proficiency_level)}
        {cell.is_expired ? ' · expired' : ''}
      </Badge>
      {cell.expires_at && (
        <span className="text-[10px] text-gray-500">exp {formatDate(cell.expires_at)}</span>
      )}
    </div>
  )
}

function CellEditModal({ user, skill, existing, onClose, onSaved, onError }) {
  const [proficiency, setProficiency] = useState(existing?.proficiency_level || 'competent')
  const [certifiedAt, setCertifiedAt] = useState(existing?.certified_at || '')
  const [expiresAt, setExpiresAt] = useState(existing?.expires_at || '')
  const [notes, setNotes] = useState(existing?.notes || '')
  const [submitting, setSubmitting] = useState(false)

  const handleSave = async () => {
    setSubmitting(true)
    try {
      await skillsService.grant(user.id, skill.id, {
        proficiency_level: proficiency,
        certified_at: certifiedAt || null,
        expires_at: expiresAt || null,
        notes: notes || null,
      })
      await onSaved()
    } catch (e) {
      onError?.(e?.response?.data?.message || e?.message || 'Failed to save')
    } finally {
      setSubmitting(false)
    }
  }

  const handleRevoke = async () => {
    if (!existing) return
    if (!window.confirm(`Revoke ${skill.name} from ${user.name}?`)) return
    setSubmitting(true)
    try {
      await skillsService.revoke(user.id, skill.id)
      await onSaved()
    } catch (e) {
      onError?.(e?.response?.data?.message || e?.message || 'Failed to revoke')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`${skill.name} — ${user.name}`}
      footer={(
        <div className="flex justify-between w-full">
          <div>
            {existing && (
              <Button variant="danger" onClick={handleRevoke} disabled={submitting}>
                Revoke
              </Button>
            )}
          </div>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
            <Button variant="primary" onClick={handleSave} disabled={submitting}>
              {existing ? 'Update' : 'Grant'}
            </Button>
          </div>
        </div>
      )}
    >
      <div className="space-y-3">
        <Select
          label="Proficiency"
          value={proficiency}
          onChange={(e) => setProficiency(e?.target?.value ?? e)}
          options={PROFICIENCY_LEVELS}
        />
        <Input
          type="date"
          label="Certified at"
          value={certifiedAt || ''}
          onChange={(e) => setCertifiedAt(e?.target?.value ?? e)}
        />
        <Input
          type="date"
          label="Expires at"
          value={expiresAt || ''}
          onChange={(e) => setExpiresAt(e?.target?.value ?? e)}
          helperText="Leave blank for non-expiring competencies."
        />
        <Textarea
          label="Notes"
          rows={3}
          value={notes || ''}
          onChange={(e) => setNotes(e?.target?.value ?? e)}
        />
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// Skills catalog tab
// ---------------------------------------------------------------------------
function SkillsCatalog({ onError }) {
  const [loading, setLoading] = useState(true)
  const [skills, setSkills] = useState([])
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (search.trim() !== '') params.search = search.trim()
      const res = await skillsService.list(params)
      setSkills(res?.data?.skills ?? [])
    } catch (e) {
      onError?.(e?.response?.data?.message || e?.message || 'Failed to load catalog')
    } finally {
      setLoading(false)
    }
  }, [onError, search])

  useEffect(() => {
    load()
  }, [load])

  const handleDelete = async (skill) => {
    if (!window.confirm(`Delete skill "${skill.name}"? This will revoke it from every technician.`)) return
    try {
      await skillsService.delete(skill.id)
      await load()
    } catch (e) {
      onError?.(e?.response?.data?.message || e?.message || 'Failed to delete')
    }
  }

  if (loading) return <Loading />

  return (
    <Card>
      <div className="flex items-end justify-between gap-2 p-3 border-b">
        <Input
          label="Search skills"
          value={search}
          onChange={(e) => setSearch(e.target.value ?? e)}
          placeholder="Name, slug, or description"
        />
        <div className="flex gap-2">
          <Button onClick={load}>Refresh</Button>
          <Button variant="primary" onClick={() => setCreating(true)}>New skill</Button>
        </div>
      </div>

      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-3 py-2 text-left">Name</th>
              <th className="px-3 py-2 text-left">Slug</th>
              <th className="px-3 py-2 text-left">Service line</th>
              <th className="px-3 py-2 text-left">Category</th>
              <th className="px-3 py-2 text-left">Active</th>
              <th className="px-3 py-2 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {skills.map((s) => (
              <tr key={s.id} className="border-t">
                <td className="px-3 py-2">
                  <div className="font-medium text-gray-800">{s.name}</div>
                  {s.description && (
                    <div className="text-xs text-gray-500">{s.description}</div>
                  )}
                </td>
                <td className="px-3 py-2 font-mono text-xs text-gray-600">{s.slug}</td>
                <td className="px-3 py-2">{s.service_line_id ?? '—'}</td>
                <td className="px-3 py-2">{titleize(s.category || '—')}</td>
                <td className="px-3 py-2">
                  <Badge variant={s.is_active ? 'success' : 'secondary'}>
                    {s.is_active ? 'Active' : 'Inactive'}
                  </Badge>
                </td>
                <td className="px-3 py-2 text-right">
                  <Button variant="secondary" size="sm" onClick={() => setEditing(s)}>Edit</Button>
                  <Button variant="danger" size="sm" onClick={() => handleDelete(s)}>Delete</Button>
                </td>
              </tr>
            ))}
            {skills.length === 0 && (
              <tr>
                <td colSpan={6} className="px-3 py-8 text-center text-gray-500">No skills found.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {(creating || editing) && (
        <SkillFormModal
          skill={editing}
          onClose={() => { setCreating(false); setEditing(null) }}
          onSaved={async () => { setCreating(false); setEditing(null); await load() }}
          onError={onError}
        />
      )}
    </Card>
  )
}

function SkillFormModal({ skill, onClose, onSaved, onError }) {
  const isEdit = !!skill
  const [form, setForm] = useState({
    slug: skill?.slug || '',
    name: skill?.name || '',
    description: skill?.description || '',
    service_line_id: skill?.service_line_id ?? '',
    category: skill?.category || '',
    sort_order: skill?.sort_order ?? 0,
    is_active: skill?.is_active ?? true,
  })
  const [submitting, setSubmitting] = useState(false)

  const update = (key) => (e) => {
    const value = e?.target?.value ?? e
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  const handleSave = async () => {
    setSubmitting(true)
    try {
      const payload = {
        ...form,
        service_line_id: form.service_line_id === '' ? null : Number(form.service_line_id),
        sort_order: Number(form.sort_order) || 0,
      }
      if (isEdit) {
        await skillsService.update(skill.id, payload)
      } else {
        await skillsService.create(payload)
      }
      await onSaved()
    } catch (e) {
      onError?.(e?.response?.data?.message || e?.message || 'Failed to save skill')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={isEdit ? `Edit "${skill.name}"` : 'New skill'}
      footer={(
        <div className="flex justify-end gap-2 w-full">
          <Button variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
          <Button variant="primary" onClick={handleSave} disabled={submitting}>
            {isEdit ? 'Save' : 'Create'}
          </Button>
        </div>
      )}
    >
      <div className="space-y-3">
        <Input
          label="Slug"
          required
          value={form.slug}
          onChange={update('slug')}
          disabled={isEdit}
          helperText="Lowercase letters, digits, underscores. Cannot be renamed once created."
        />
        <Input label="Name" required value={form.name} onChange={update('name')} />
        <Textarea label="Description" rows={3} value={form.description || ''} onChange={update('description')} />
        <Input
          label="Service line ID"
          type="number"
          value={form.service_line_id || ''}
          onChange={update('service_line_id')}
          helperText="Leave blank for cross-trade competencies (e.g. First Aid)."
        />
        <Input label="Category" value={form.category || ''} onChange={update('category')} />
        <Input label="Sort order" type="number" value={form.sort_order} onChange={update('sort_order')} />
        <label className="flex items-center gap-2">
          <input
            type="checkbox"
            checked={!!form.is_active}
            onChange={(e) => setForm((prev) => ({ ...prev, is_active: e.target.checked }))}
          />
          <span className="text-sm">Active</span>
        </label>
      </div>
    </Modal>
  )
}
