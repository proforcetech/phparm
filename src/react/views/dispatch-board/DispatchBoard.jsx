import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import dispatchBoardService from '../../../services/dispatch-board.service'

/**
 * Phase 17 / M10 of docs/woms-expansion-plan.md.
 *
 * Multi-trade planning board for managers + dispatchers. Renders open
 * workorders grouped by status (kanban-style columns) and exposes a
 * "Suggest tech" panel per card that uses the server's match scoring (service
 * line membership + skill match + workload).
 *
 * Read perm:   dispatch_board.view  (server-enforced)
 * Assign perm: dispatch_board.assign
 */

const STATUS_COLUMNS = [
  { key: 'pending', label: 'Pending', tone: 'border-gray-300' },
  { key: 'in_progress', label: 'In Progress', tone: 'border-blue-300' },
  { key: 'on_hold', label: 'On Hold', tone: 'border-yellow-300' },
  { key: 'parts_pending', label: 'Parts Pending', tone: 'border-orange-300' },
  { key: 'awaiting_authorization', label: 'Awaiting Auth', tone: 'border-purple-300' },
  { key: 'qc_required', label: 'QC Required', tone: 'border-pink-300' },
]

const PRIORITY_VARIANT = {
  urgent: 'danger',
  high: 'warning',
  normal: 'info',
  low: 'secondary',
}

function titleize(s) {
  if (!s) return ''
  return String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function formatMoney(n) {
  if (n === null || n === undefined) return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return num.toLocaleString(undefined, { style: 'currency', currency: 'USD' })
}

export default function DispatchBoard() {
  const [loading, setLoading] = useState(true)
  const [data, setData] = useState({ workorders: [], candidates: {}, technicians: [], skills: [] })
  const [error, setError] = useState('')
  const [filters, setFilters] = useState({
    service_line_id: '',
    priority: '',
    unassigned_only: false,
  })
  const [activeWo, setActiveWo] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (filters.service_line_id) params.service_line_id = filters.service_line_id
      if (filters.priority) params.priority = filters.priority
      if (filters.unassigned_only) params.unassigned_only = '1'
      const res = await dispatchBoardService.board(params)
      setData(res?.data ?? { workorders: [], candidates: {}, technicians: [], skills: [] })
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Failed to load dispatch board')
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    load()
  }, [load])

  const techsById = useMemo(() => {
    const out = {}
    for (const t of data.technicians || []) {
      out[t.id] = t
    }
    return out
  }, [data.technicians])

  const skillsById = useMemo(() => {
    const out = {}
    for (const s of data.skills || []) {
      out[s.id] = s
    }
    return out
  }, [data.skills])

  const grouped = useMemo(() => {
    const out = {}
    for (const col of STATUS_COLUMNS) out[col.key] = []
    for (const wo of data.workorders || []) {
      if (!out[wo.status]) continue
      out[wo.status].push(wo)
    }
    return out
  }, [data.workorders])

  const handleAssign = async (workorderId, technicianId) => {
    try {
      await dispatchBoardService.assign(workorderId, technicianId)
      setActiveWo(null)
      await load()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Failed to assign technician')
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-center justify-between flex-wrap gap-2">
        <div>
          <h1 className="text-xl font-semibold">Dispatch Board</h1>
          <p className="text-sm text-gray-500">
            Plan workorder assignments across service lines. Match scoring weighs primary service line, required skill, and current workload.
          </p>
        </div>
        <div className="flex items-end gap-2">
          <Input
            label="Service line"
            type="number"
            value={filters.service_line_id}
            onChange={(e) => setFilters((p) => ({ ...p, service_line_id: e.target.value ?? e }))}
            placeholder="Any"
          />
          <Select
            label="Priority"
            value={filters.priority}
            onChange={(e) => setFilters((p) => ({ ...p, priority: e?.target?.value ?? e }))}
            options={[
              { value: '', label: 'Any' },
              { value: 'urgent', label: 'Urgent' },
              { value: 'high', label: 'High' },
              { value: 'normal', label: 'Normal' },
              { value: 'low', label: 'Low' },
            ]}
          />
          <label className="flex items-center gap-2 mb-2">
            <input
              type="checkbox"
              checked={filters.unassigned_only}
              onChange={(e) => setFilters((p) => ({ ...p, unassigned_only: e.target.checked }))}
            />
            <span className="text-sm">Unassigned only</span>
          </label>
          <Button onClick={load}>Refresh</Button>
        </div>
      </header>

      {error && (
        <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>
      )}

      {loading ? (
        <Loading />
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
          {STATUS_COLUMNS.map((col) => (
            <Card key={col.key} className={`border-t-4 ${col.tone}`}>
              <div className="px-3 py-2 border-b flex items-center justify-between">
                <div className="font-medium">{col.label}</div>
                <Badge variant="secondary">{(grouped[col.key] || []).length}</Badge>
              </div>
              <div className="p-2 space-y-2 min-h-[200px]">
                {(grouped[col.key] || []).map((wo) => (
                  <WorkorderCard
                    key={wo.id}
                    workorder={wo}
                    technician={wo.assigned_technician_id ? techsById[wo.assigned_technician_id] : null}
                    skill={wo.required_skill_id ? skillsById[wo.required_skill_id] : null}
                    onClick={() => setActiveWo(wo)}
                  />
                ))}
                {(grouped[col.key] || []).length === 0 && (
                  <div className="text-xs text-gray-400 text-center py-6">Empty</div>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      {activeWo && (
        <AssignModal
          workorder={activeWo}
          candidates={data.candidates?.[activeWo.id] || []}
          skill={activeWo.required_skill_id ? skillsById[activeWo.required_skill_id] : null}
          currentTechnician={activeWo.assigned_technician_id ? techsById[activeWo.assigned_technician_id] : null}
          onClose={() => setActiveWo(null)}
          onAssign={(techId) => handleAssign(activeWo.id, techId)}
        />
      )}
    </div>
  )
}

function WorkorderCard({ workorder, technician, skill, onClick }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="block w-full text-left rounded border bg-white p-2 hover:shadow focus:outline-none focus:ring-2 focus:ring-primary-300"
    >
      <div className="flex items-center justify-between">
        <span className="font-mono text-xs text-gray-500">#{workorder.number}</span>
        <Badge variant={PRIORITY_VARIANT[workorder.priority] || 'secondary'}>
          {titleize(workorder.priority)}
        </Badge>
      </div>
      <div className="mt-1 text-sm text-gray-800">
        {titleize(workorder.type)} · {formatMoney(workorder.grand_total)}
      </div>
      {skill && (
        <div className="mt-1 text-xs text-gray-500">
          Needs: <span className="font-medium">{skill.name}</span>
          {workorder.min_proficiency_level ? ` (≥ ${titleize(workorder.min_proficiency_level)})` : ''}
        </div>
      )}
      <div className="mt-1 text-xs">
        {technician
          ? <span className="text-gray-700">→ {technician.name}</span>
          : <span className="text-orange-600 font-medium">Unassigned</span>}
      </div>
    </button>
  )
}

function AssignModal({ workorder, candidates, skill, currentTechnician, onClose, onAssign }) {
  const top = candidates.slice(0, 10)

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={`Assign #${workorder.number}`}
      footer={(
        <div className="flex justify-between w-full">
          <div>
            {currentTechnician && (
              <Button variant="danger" onClick={() => onAssign(null)}>
                Unassign
              </Button>
            )}
          </div>
          <Button variant="secondary" onClick={onClose}>Close</Button>
        </div>
      )}
    >
      <div className="space-y-3">
        <div className="text-sm text-gray-700">
          <div>Status: <strong>{titleize(workorder.status)}</strong></div>
          <div>Priority: <strong>{titleize(workorder.priority)}</strong></div>
          {skill && (
            <div>
              Required skill: <strong>{skill.name}</strong>
              {workorder.min_proficiency_level ? ` (≥ ${titleize(workorder.min_proficiency_level)})` : ''}
            </div>
          )}
          {currentTechnician && (
            <div>Currently assigned to: <strong>{currentTechnician.name}</strong></div>
          )}
        </div>

        <div>
          <div className="text-xs uppercase tracking-wide text-gray-500 mb-1">Suggested technicians</div>
          {top.length === 0 ? (
            <div className="text-sm text-gray-500 italic">
              No qualified technicians match this workorder's service line + skill requirements.
            </div>
          ) : (
            <ul className="divide-y border rounded">
              {top.map((c) => (
                <li key={c.user_id} className="flex items-center justify-between p-2">
                  <div>
                    <div className="font-medium text-sm">{c.name}</div>
                    <div className="text-xs text-gray-500">{c.email}</div>
                    <div className="text-xs text-gray-500">
                      {c.match_reasons?.length > 0 ? c.match_reasons.map(titleize).join(' · ') : 'eligible'}
                      {' '}· {c.open_workorder_count} open WOs · score {c.score}
                    </div>
                  </div>
                  <Button
                    variant={c.user_id === workorder.assigned_technician_id ? 'secondary' : 'primary'}
                    size="sm"
                    onClick={() => onAssign(c.user_id)}
                    disabled={c.user_id === workorder.assigned_technician_id}
                  >
                    {c.user_id === workorder.assigned_technician_id ? 'Assigned' : 'Assign'}
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </Modal>
  )
}
