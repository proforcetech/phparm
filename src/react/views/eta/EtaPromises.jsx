import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import etaService from '../../../services/eta.service'
import ticketsService from '../../../services/tickets.service'
import workorderService from '../../../services/workorder.service'
import { useToast } from '../../stores/toast.jsx'

/**
 * ETA Promises board (/cp/eta/promises).
 *
 * Backend exposes per-entity endpoints only — there is no cross-entity
 * "list all promises" route. We work around that with a two-pane UI:
 * pick an entity on the left, view/manage its promises on the right.
 * Deep-link via ?type=ticket|workorder&id=NN.
 */

const ENTITY_TYPES = {
  TICKET: 'ticket',
  WORKORDER: 'workorder',
}

const CHANNEL_OPTIONS = [
  { value: 'sms', label: 'SMS' },
  { value: 'email', label: 'Email' },
  { value: 'portal', label: 'Portal' },
  { value: 'none', label: 'None' },
]

const formatDateTime = (value) => {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleString()
}

const ticketLabel = (t) =>
  t?.subject || t?.title || t?.summary || `Ticket #${t?.id ?? ''}`.trim()

const workorderLabel = (w) =>
  w?.title || w?.summary || w?.description || `WO #${w?.id ?? w?.workorder_number ?? ''}`.trim()

const customerSiteLabel = (entity) => {
  const customer =
    entity?.customer_name ||
    entity?.customer?.name ||
    [entity?.customer?.first_name, entity?.customer?.last_name].filter(Boolean).join(' ') ||
    ''
  const site = entity?.site_name || entity?.location_name || entity?.site?.name || ''
  return [customer, site].filter(Boolean).join(' / ')
}

const lastUpdatedOf = (entity) =>
  entity?.updated_at || entity?.last_updated_at || entity?.created_at || ''

export default function EtaPromises() {
  const { success, error: errorToast } = useToast()
  const [searchParams, setSearchParams] = useSearchParams()

  const initialType =
    searchParams.get('type') === ENTITY_TYPES.WORKORDER
      ? ENTITY_TYPES.WORKORDER
      : ENTITY_TYPES.TICKET
  const initialId = searchParams.get('id') ? Number(searchParams.get('id')) : null

  const [entityType, setEntityType] = useState(initialType)
  const [search, setSearch] = useState('')
  const [entities, setEntities] = useState([])
  const [entitiesLoading, setEntitiesLoading] = useState(false)
  const [selectedId, setSelectedId] = useState(initialId)

  const [promises, setPromises] = useState([])
  const [promisesLoading, setPromisesLoading] = useState(false)
  const [pageError, setPageError] = useState('')

  const [createOpen, setCreateOpen] = useState(false)
  const [createBusy, setCreateBusy] = useState(false)
  const [windowStart, setWindowStart] = useState('')
  const [windowEnd, setWindowEnd] = useState('')
  const [channel, setChannel] = useState('sms')
  const [notes, setNotes] = useState('')
  const [notifyCustomer, setNotifyCustomer] = useState(true)

  const [confirmClearOpen, setConfirmClearOpen] = useState(false)
  const [clearBusy, setClearBusy] = useState(false)

  const loadEntities = useCallback(() => {
    setEntitiesLoading(true)
    setPageError('')

    const handleSuccess = (raw) => {
      const list = Array.isArray(raw) ? raw : raw?.data ?? raw?.items ?? []
      setEntities(list)
    }
    const handleFail = (e) => {
      setEntities([])
      setPageError(e?.response?.data?.message || e?.message || 'Failed to load list')
    }

    if (entityType === ENTITY_TYPES.TICKET) {
      ticketsService
        .list({ status: 'open', limit: 200 })
        .then(handleSuccess)
        .catch(handleFail)
        .finally(() => setEntitiesLoading(false))
    } else {
      workorderService
        .getWorkorders({ status: 'in_progress', limit: 200 })
        .then((res) => handleSuccess(res?.data))
        .catch(handleFail)
        .finally(() => setEntitiesLoading(false))
    }
  }, [entityType])

  useEffect(() => {
    loadEntities()
  }, [loadEntities])

  const loadPromises = useCallback(() => {
    if (!selectedId) {
      setPromises([])
      return
    }
    setPromisesLoading(true)
    setPageError('')

    const promise =
      entityType === ENTITY_TYPES.TICKET
        ? etaService.listTicketPromises(selectedId)
        : etaService.listWorkorderPromises(selectedId)

    promise
      .then((res) => {
        const list = Array.isArray(res) ? res : res?.data ?? []
        setPromises(list)
      })
      .catch((e) => {
        setPromises([])
        setPageError(e?.response?.data?.message || e?.message || 'Failed to load promises')
      })
      .finally(() => setPromisesLoading(false))
  }, [entityType, selectedId])

  useEffect(() => {
    loadPromises()
  }, [loadPromises])

  // Keep deep-link query params in sync with selection.
  useEffect(() => {
    const next = new URLSearchParams(searchParams)
    next.set('type', entityType)
    if (selectedId) {
      next.set('id', String(selectedId))
    } else {
      next.delete('id')
    }
    if (next.toString() !== searchParams.toString()) {
      setSearchParams(next, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [entityType, selectedId])

  const filteredEntities = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return entities
    return entities.filter((e) => {
      const label =
        entityType === ENTITY_TYPES.TICKET ? ticketLabel(e) : workorderLabel(e)
      const idStr = String(e?.id ?? '')
      return (
        label.toLowerCase().includes(q) ||
        idStr.includes(q) ||
        customerSiteLabel(e).toLowerCase().includes(q)
      )
    })
  }, [entities, entityType, search])

  const selectedEntity = useMemo(
    () => entities.find((e) => Number(e?.id) === Number(selectedId)) || null,
    [entities, selectedId]
  )

  const sortedPromises = useMemo(() => {
    const copy = [...promises]
    copy.sort((a, b) => {
      const ad = new Date(a?.created_at || a?.window_start || 0).getTime()
      const bd = new Date(b?.created_at || b?.window_start || 0).getTime()
      return bd - ad
    })
    return copy
  }, [promises])

  const currentPromise = useMemo(() => {
    return (
      sortedPromises.find((p) => !p?.cleared_at && !p?.superseded_at) || null
    )
  }, [sortedPromises])

  const historyPromises = useMemo(
    () => sortedPromises.filter((p) => p !== currentPromise),
    [sortedPromises, currentPromise]
  )

  const resetCreateForm = () => {
    setWindowStart('')
    setWindowEnd('')
    setChannel('sms')
    setNotes('')
    setNotifyCustomer(true)
  }

  const openCreate = () => {
    resetCreateForm()
    setCreateOpen(true)
  }

  const submitCreate = async () => {
    if (!selectedId) return
    if (!windowStart || !windowEnd) {
      setPageError('Window start and end are required.')
      return
    }
    if (new Date(windowStart) >= new Date(windowEnd)) {
      setPageError('Window end must be after window start.')
      return
    }

    setCreateBusy(true)
    setPageError('')
    const payload = {
      window_start: new Date(windowStart).toISOString(),
      window_end: new Date(windowEnd).toISOString(),
      channel,
      notes: notes.trim() || null,
      notify_customer: notifyCustomer,
    }

    try {
      if (entityType === ENTITY_TYPES.TICKET) {
        await etaService.createTicketPromise(selectedId, payload)
      } else {
        await etaService.createWorkorderPromise(selectedId, payload)
      }
      success('Promise created')
      setCreateOpen(false)
      resetCreateForm()
      loadPromises()
    } catch (e) {
      const msg = e?.response?.data?.message || e?.message || 'Failed to create promise'
      setPageError(msg)
      errorToast(msg)
    } finally {
      setCreateBusy(false)
    }
  }

  const submitClear = async () => {
    if (!selectedId) return
    setClearBusy(true)
    setPageError('')
    try {
      if (entityType === ENTITY_TYPES.TICKET) {
        await etaService.clearCurrentTicketPromise(selectedId)
      } else {
        await etaService.clearCurrentWorkorderPromise(selectedId)
      }
      success('Current promise cleared')
      setConfirmClearOpen(false)
      loadPromises()
    } catch (e) {
      const msg = e?.response?.data?.message || e?.message || 'Failed to clear promise'
      setPageError(msg)
      errorToast(msg)
    } finally {
      setClearBusy(false)
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header>
        <h1 className="text-xl font-semibold">ETA Promises</h1>
        <p className="text-sm text-gray-500">
          Manage customer-facing arrival commitments on tickets and active workorders.
          Each entity has at most one current promise; creating a new one supersedes it.
        </p>
      </header>

      {pageError && (
        <Alert variant="danger" onClose={() => setPageError('')}>
          {pageError}
        </Alert>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card padding={false} className="lg:col-span-1">
          <div className="p-4 space-y-3 border-b border-gray-200">
            <div className="inline-flex rounded-lg border border-gray-300 overflow-hidden text-sm">
              <button
                type="button"
                className={`px-3 py-1.5 ${
                  entityType === ENTITY_TYPES.TICKET
                    ? 'bg-primary-600 text-white'
                    : 'bg-white text-gray-700 hover:bg-gray-50'
                }`}
                onClick={() => {
                  if (entityType !== ENTITY_TYPES.TICKET) {
                    setEntityType(ENTITY_TYPES.TICKET)
                    setSelectedId(null)
                    setPromises([])
                  }
                }}
              >
                Open Tickets
              </button>
              <button
                type="button"
                className={`px-3 py-1.5 border-l border-gray-300 ${
                  entityType === ENTITY_TYPES.WORKORDER
                    ? 'bg-primary-600 text-white'
                    : 'bg-white text-gray-700 hover:bg-gray-50'
                }`}
                onClick={() => {
                  if (entityType !== ENTITY_TYPES.WORKORDER) {
                    setEntityType(ENTITY_TYPES.WORKORDER)
                    setSelectedId(null)
                    setPromises([])
                  }
                }}
              >
                Active Workorders
              </button>
            </div>

            <Input
              placeholder="Search by id, title or customer"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />

            <div className="flex justify-between items-center">
              <span className="text-xs text-gray-500">
                {entitiesLoading ? 'Loading…' : `${filteredEntities.length} item(s)`}
              </span>
              <Button variant="secondary" size="sm" onClick={loadEntities}>
                Refresh
              </Button>
            </div>
          </div>

          <div className="max-h-[60vh] overflow-y-auto">
            {entitiesLoading ? (
              <div className="p-6 text-center">
                <Loading />
              </div>
            ) : filteredEntities.length === 0 ? (
              <div className="p-6 text-center text-gray-500 text-sm">
                {entityType === ENTITY_TYPES.TICKET
                  ? 'No open tickets found.'
                  : 'No active workorders found.'}
              </div>
            ) : (
              <ul className="divide-y divide-gray-200">
                {filteredEntities.map((e) => {
                  const isSelected = Number(e?.id) === Number(selectedId)
                  const label =
                    entityType === ENTITY_TYPES.TICKET
                      ? ticketLabel(e)
                      : workorderLabel(e)
                  return (
                    <li key={e?.id}>
                      <button
                        type="button"
                        onClick={() => setSelectedId(Number(e?.id))}
                        className={`w-full text-left p-3 hover:bg-gray-50 ${
                          isSelected ? 'bg-primary-50' : ''
                        }`}
                      >
                        <div className="flex items-center justify-between gap-2">
                          <div className="font-medium text-sm truncate">{label}</div>
                          <span className="text-xs text-gray-400 shrink-0">#{e?.id}</span>
                        </div>
                        <div className="text-xs text-gray-500 truncate">
                          {customerSiteLabel(e) || '—'}
                        </div>
                        <div className="text-xs text-gray-400 mt-0.5">
                          Updated {formatDateTime(lastUpdatedOf(e)) || '—'}
                        </div>
                      </button>
                    </li>
                  )
                })}
              </ul>
            )}
          </div>
        </Card>

        <div className="lg:col-span-2 space-y-4">
          {!selectedId ? (
            <Card>
              <div className="text-center text-gray-500 text-sm py-8">
                Select a {entityType === ENTITY_TYPES.TICKET ? 'ticket' : 'workorder'} on the
                left to view and manage its ETA promises.
              </div>
            </Card>
          ) : (
            <>
              <Card>
                <div className="flex items-start justify-between gap-3 flex-wrap">
                  <div>
                    <div className="text-xs uppercase text-gray-500 tracking-wide">
                      {entityType === ENTITY_TYPES.TICKET ? 'Ticket' : 'Workorder'}
                    </div>
                    <h2 className="text-lg font-semibold">
                      {selectedEntity
                        ? entityType === ENTITY_TYPES.TICKET
                          ? ticketLabel(selectedEntity)
                          : workorderLabel(selectedEntity)
                        : `#${selectedId}`}
                    </h2>
                    <div className="text-sm text-gray-500">
                      {selectedEntity ? customerSiteLabel(selectedEntity) : ''}
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <Button onClick={openCreate}>New promise</Button>
                    <Button
                      variant="danger"
                      disabled={!currentPromise}
                      onClick={() => setConfirmClearOpen(true)}
                    >
                      Clear current promise
                    </Button>
                  </div>
                </div>
              </Card>

              <Card title="Current promise">
                {promisesLoading ? (
                  <div className="py-6 text-center">
                    <Loading />
                  </div>
                ) : !currentPromise ? (
                  <div className="text-sm text-gray-500">
                    No active promise. Click "New promise" to create one.
                  </div>
                ) : (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                      <div className="text-xs uppercase text-gray-500 tracking-wide">
                        Window
                      </div>
                      <div className="font-medium">
                        {formatDateTime(currentPromise.window_start)}
                      </div>
                      <div className="text-gray-500">
                        to {formatDateTime(currentPromise.window_end)}
                      </div>
                    </div>
                    <div>
                      <div className="text-xs uppercase text-gray-500 tracking-wide">
                        Channel
                      </div>
                      <Badge variant="info">{currentPromise.channel || '—'}</Badge>
                    </div>
                    <div>
                      <div className="text-xs uppercase text-gray-500 tracking-wide">
                        Promised by
                      </div>
                      <div>
                        {currentPromise.promised_by_name ||
                          currentPromise.promised_by ||
                          '—'}
                      </div>
                    </div>
                    <div>
                      <div className="text-xs uppercase text-gray-500 tracking-wide">
                        Created
                      </div>
                      <div>{formatDateTime(currentPromise.created_at) || '—'}</div>
                    </div>
                    {currentPromise.notes ? (
                      <div className="sm:col-span-2">
                        <div className="text-xs uppercase text-gray-500 tracking-wide">
                          Notes
                        </div>
                        <div className="whitespace-pre-wrap">{currentPromise.notes}</div>
                      </div>
                    ) : null}
                  </div>
                )}
              </Card>

              <Card padding={false} title="History">
                {promisesLoading ? (
                  <div className="p-6 text-center">
                    <Loading />
                  </div>
                ) : historyPromises.length === 0 ? (
                  <div className="p-6 text-center text-gray-500 text-sm">
                    No prior promises.
                  </div>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                          <th className="text-left p-2">Window</th>
                          <th className="text-left p-2">Channel</th>
                          <th className="text-left p-2">Promised by</th>
                          <th className="text-left p-2">Created</th>
                          <th className="text-left p-2">Status</th>
                          <th className="text-left p-2">Notes</th>
                        </tr>
                      </thead>
                      <tbody>
                        {historyPromises.map((p) => (
                          <tr key={p.id} className="border-t align-top">
                            <td className="p-2 whitespace-nowrap">
                              <div>{formatDateTime(p.window_start)}</div>
                              <div className="text-gray-500">
                                to {formatDateTime(p.window_end)}
                              </div>
                            </td>
                            <td className="p-2">{p.channel || '—'}</td>
                            <td className="p-2">
                              {p.promised_by_name || p.promised_by || '—'}
                            </td>
                            <td className="p-2 whitespace-nowrap">
                              {formatDateTime(p.created_at) || '—'}
                            </td>
                            <td className="p-2">
                              {p.cleared_at ? (
                                <Badge variant="warning">Cleared</Badge>
                              ) : p.superseded_at ? (
                                <Badge variant="default">Superseded</Badge>
                              ) : (
                                <Badge variant="success">Past</Badge>
                              )}
                            </td>
                            <td className="p-2 max-w-md">
                              <div className="truncate" title={p.notes || ''}>
                                {p.notes || '—'}
                              </div>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </Card>
            </>
          )}
        </div>
      </div>

      <Modal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title="New ETA promise"
      >
        <div className="space-y-3">
          <Input
            label="Window start"
            type="datetime-local"
            value={windowStart}
            onChange={(e) => setWindowStart(e.target.value)}
            required
          />
          <Input
            label="Window end"
            type="datetime-local"
            value={windowEnd}
            onChange={(e) => setWindowEnd(e.target.value)}
            required
          />
          <Select
            label="Channel"
            value={channel}
            options={CHANNEL_OPTIONS}
            placeholder=""
            onChange={(e) => setChannel(e?.target?.value ?? channel)}
          />
          <Textarea
            label="Notes"
            rows={3}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="Optional context for the customer or team"
          />
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={notifyCustomer}
              onChange={(e) => setNotifyCustomer(e.target.checked)}
            />
            Notify customer
          </label>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setCreateOpen(false)}>
              Cancel
            </Button>
            <Button disabled={createBusy} onClick={submitCreate}>
              {createBusy ? 'Saving…' : 'Create promise'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={confirmClearOpen}
        onClose={() => setConfirmClearOpen(false)}
        title="Clear current promise"
        size="sm"
      >
        <div className="space-y-3">
          <Alert variant="warning" closable={false}>
            This will clear the current ETA promise. The customer-facing
            commitment will be removed and history retained.
          </Alert>
          <p className="text-sm text-gray-700">Are you sure you want to continue?</p>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="secondary" onClick={() => setConfirmClearOpen(false)}>
              Cancel
            </Button>
            <Button variant="danger" disabled={clearBusy} onClick={submitClear}>
              {clearBusy ? 'Clearing…' : 'Clear promise'}
            </Button>
          </div>
        </div>
      </Modal>

    </div>
  )
}
