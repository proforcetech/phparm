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
import { useToast } from '../../stores/toast.jsx'
import routingService from '../../../services/routing.service'

const KIND_OPTIONS = [
  { value: 'circle', label: 'Circle' },
  { value: 'polygon', label: 'Polygon' },
]

const ACTIVE_OPTIONS = [
  { value: '', label: 'All' },
  { value: '1', label: 'Active only' },
  { value: '0', label: 'Inactive only' },
]

const POLYGON_PLACEHOLDER = '[[37.7749,-122.4194],[37.7752,-122.4180],[37.7740,-122.4170]]'

function titleize(s) {
  if (!s) return ''
  return String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function formatDate(s) {
  if (!s) return '—'
  try {
    return new Date(String(s).replace(' ', 'T')).toLocaleString()
  } catch {
    return s
  }
}

function unwrap(res) {
  return res?.data ?? res
}

function unwrapList(res, keys) {
  const data = unwrap(res)
  if (Array.isArray(data)) return data
  for (const k of keys) {
    if (Array.isArray(data?.[k])) return data[k]
  }
  return []
}

function parseVertices(text) {
  if (!text || !text.trim()) return null
  const parsed = JSON.parse(text)
  if (!Array.isArray(parsed) || parsed.length < 3) {
    throw new Error('Polygon needs at least 3 [lat,lng] vertices.')
  }
  for (const v of parsed) {
    if (!Array.isArray(v) || v.length < 2 || Number.isNaN(Number(v[0])) || Number.isNaN(Number(v[1]))) {
      throw new Error('Each vertex must be [lat, lng] numeric pair.')
    }
  }
  return parsed.map(([lat, lng]) => [Number(lat), Number(lng)])
}

function fenceCenter(fence) {
  if (!fence) return '—'
  if (fence.kind === 'circle' || (fence.center_lat != null && fence.center_lng != null)) {
    if (fence.center_lat != null && fence.center_lng != null) {
      return `${Number(fence.center_lat).toFixed(5)}, ${Number(fence.center_lng).toFixed(5)}`
    }
  }
  const verts = fence.vertices
  if (Array.isArray(verts) && verts.length > 0) {
    const v = verts[0]
    if (Array.isArray(v) && v.length >= 2) {
      return `${Number(v[0]).toFixed(5)}, ${Number(v[1]).toFixed(5)}`
    }
  }
  return '—'
}

export default function GeoFences() {
  const toast = useToast()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [activeFilter, setActiveFilter] = useState('')

  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState(null)
  const [deleting, setDeleting] = useState(null)
  const [testing, setTesting] = useState(null)

  const [eventsOpen, setEventsOpen] = useState(false)
  const [events, setEvents] = useState([])
  const [eventsLoading, setEventsLoading] = useState(false)

  const params = useMemo(() => {
    const p = { per_page: 200 }
    if (search.trim()) p.search = search.trim()
    if (activeFilter !== '') p.is_active = activeFilter
    return p
  }, [search, activeFilter])

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const res = await routingService.listFences(params)
      setRows(unwrapList(res, ['geo_fences', 'fences', 'items']))
    } catch (err) {
      setError(err?.response?.data?.message || 'Unable to load geo-fences.')
    } finally {
      setLoading(false)
    }
  }, [params])

  useEffect(() => { load() }, [load])

  const loadEvents = useCallback(async () => {
    setEventsLoading(true)
    try {
      const res = await routingService.listFenceEvents({ limit: 50 })
      setEvents(unwrapList(res, ['geo_fence_events', 'events', 'items']))
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Unable to load fence events.')
    } finally {
      setEventsLoading(false)
    }
  }, [toast])

  useEffect(() => {
    if (eventsOpen) loadEvents()
  }, [eventsOpen, loadEvents])

  const onDelete = async () => {
    if (!deleting) return
    try {
      await routingService.deleteFence(deleting.id)
      toast.success('Geo-fence deleted.')
      setDeleting(null)
      await load()
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Unable to delete fence.')
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Geo-fences</h1>
          <p className="mt-1 text-sm text-gray-500">
            Define geographic boundaries to trigger enter and exit events.
          </p>
        </div>
        <Button variant="primary" onClick={() => { setEditing(null); setShowForm(true) }}>New geo-fence</Button>
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      <Card>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <Input label="Search" modelValue={search} onUpdateModelValue={setSearch} />
          <Select
            label="Active"
            placeholder=""
            options={ACTIVE_OPTIONS}
            modelValue={activeFilter}
            onUpdateModelValue={setActiveFilter}
          />
        </div>
      </Card>

      <Card padding={false}>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading geo-fences..." /></div>
        ) : rows.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No geo-fences found.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Kind</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Center / first vertex</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Radius (m)</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="px-4 py-2 text-sm text-gray-900 font-medium">
                      <div>{row.name}</div>
                      <div className="text-xs text-gray-500">#{row.id}</div>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{titleize(row.kind)}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{fenceCenter(row)}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {row.kind === 'circle' && row.radius_m != null ? row.radius_m : '—'}
                    </td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={row.is_active ? 'success' : 'secondary'}>
                        {row.is_active ? 'Yes' : 'No'}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{formatDate(row.created_at)}</td>
                    <td className="px-4 py-2 text-sm text-right">
                      <div className="flex flex-wrap gap-1 justify-end">
                        <Button size="xs" variant="ghost" onClick={() => { setEditing(row); setShowForm(true) }}>Edit</Button>
                        <Button size="xs" variant="ghost" onClick={() => setTesting(row)}>Test</Button>
                        <Button size="xs" variant="ghost" onClick={() => setDeleting(row)}>Delete</Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card>
        <button
          type="button"
          onClick={() => setEventsOpen((v) => !v)}
          className="w-full flex items-center justify-between text-left"
        >
          <span className="text-base font-semibold text-gray-900">Recent fence events</span>
          <span className="text-sm text-primary-600">{eventsOpen ? 'Hide' : 'Show'}</span>
        </button>
        {eventsOpen ? (
          <div className="mt-4">
            {eventsLoading ? (
              <div className="py-6 flex justify-center"><Loading text="Loading events..." /></div>
            ) : events.length === 0 ? (
              <p className="py-3 text-sm text-gray-500 text-center">No fence events recorded.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fence</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Asset / unit</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Occurred</th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-100">
                    {events.map((ev) => {
                      const eventType = ev.event_type || ev.type
                      const assetLabel = ev.unit_id ? `Unit #${ev.unit_id}`
                        : ev.asset_id ? `Asset #${ev.asset_id}`
                        : ev.user_id ? `User #${ev.user_id}` : '—'
                      return (
                        <tr key={ev.id}>
                          <td className="px-4 py-2 text-sm text-gray-700">
                            {ev.fence_name || (ev.geo_fence_id ? `Fence #${ev.geo_fence_id}` : '—')}
                          </td>
                          <td className="px-4 py-2 text-sm text-gray-700">{assetLabel}</td>
                          <td className="px-4 py-2 text-sm">
                            <Badge variant={eventType === 'enter' ? 'info' : eventType === 'exit' ? 'warning' : 'secondary'}>
                              {titleize(eventType)}
                            </Badge>
                          </td>
                          <td className="px-4 py-2 text-sm text-gray-700">
                            {formatDate(ev.occurred_at || ev.created_at)}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        ) : null}
      </Card>

      {showForm && (
        <FenceFormModal
          fence={editing}
          onClose={() => { setShowForm(false); setEditing(null) }}
          onSaved={async () => {
            setShowForm(false)
            setEditing(null)
            toast.success(editing?.id ? 'Geo-fence updated.' : 'Geo-fence created.')
            await load()
          }}
          onError={(msg) => toast.error(msg)}
        />
      )}

      {deleting && (
        <Modal open title={`Delete geo-fence "${deleting.name}"?`} onClose={() => setDeleting(null)} size="md">
          <p className="text-sm text-gray-700">
            This permanently removes the fence. Existing event records are preserved.
          </p>
          <div className="mt-5 flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setDeleting(null)}>Cancel</Button>
            <Button variant="danger" onClick={onDelete}>Delete</Button>
          </div>
        </Modal>
      )}

      {testing && (
        <FenceTestModal
          fence={testing}
          onClose={() => setTesting(null)}
          onError={(msg) => toast.error(msg)}
        />
      )}
    </div>
  )
}

function FenceFormModal({ fence, onClose, onSaved, onError }) {
  const isEdit = Boolean(fence?.id)
  const [form, setForm] = useState(() => ({
    name: fence?.name || '',
    kind: fence?.kind || 'circle',
    description: fence?.description || '',
    is_active: fence?.is_active ?? true,
    center_lat: fence?.center_lat ?? '',
    center_lng: fence?.center_lng ?? '',
    radius_m: fence?.radius_m ?? '',
    vertices: Array.isArray(fence?.vertices)
      ? JSON.stringify(fence.vertices)
      : '',
  }))
  const [submitting, setSubmitting] = useState(false)
  const [validation, setValidation] = useState('')

  const isCircle = form.kind === 'circle'
  const isPolygon = form.kind === 'polygon'

  const submit = async () => {
    if (!form.name.trim()) {
      setValidation('Name is required.')
      return
    }
    setValidation('')
    let payload
    try {
      payload = {
        name: form.name.trim(),
        kind: form.kind,
        description: form.description || null,
        is_active: form.is_active ? 1 : 0,
      }
      if (isCircle) {
        const lat = Number(form.center_lat)
        const lng = Number(form.center_lng)
        const radius = Number(form.radius_m)
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
          throw new Error('Center latitude and longitude are required.')
        }
        if (!Number.isFinite(radius) || radius <= 0) {
          throw new Error('Radius must be a positive number of meters.')
        }
        payload.center_lat = lat
        payload.center_lng = lng
        payload.radius_m = radius
      } else if (isPolygon) {
        const verts = parseVertices(form.vertices)
        if (!verts) throw new Error('Polygon vertices are required.')
        payload.vertices = verts
      }
    } catch (err) {
      setValidation(err?.message || 'Invalid fence input.')
      return
    }

    setSubmitting(true)
    try {
      if (isEdit) {
        await routingService.updateFence(fence.id, payload)
      } else {
        await routingService.createFence(payload)
      }
      await onSaved()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to save geo-fence.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open title={isEdit ? `Edit geo-fence #${fence.id}` : 'New geo-fence'} onClose={onClose} size="lg">
      {validation ? <Alert variant="danger" className="mb-3">{validation}</Alert> : null}

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="sm:col-span-2">
          <Input
            label="Name"
            required
            modelValue={form.name}
            onUpdateModelValue={(v) => setForm((p) => ({ ...p, name: v }))}
          />
        </div>
        <Select
          label="Kind"
          placeholder=""
          options={KIND_OPTIONS}
          modelValue={form.kind}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, kind: v }))}
        />
        <label className="flex items-end pb-2 text-sm font-medium text-gray-700">
          <input
            type="checkbox"
            checked={!!form.is_active}
            onChange={(e) => setForm((p) => ({ ...p, is_active: e.target.checked }))}
            className="mr-2"
          />
          Active
        </label>
        <div className="sm:col-span-2">
          <Textarea
            label="Description"
            rows={2}
            modelValue={form.description}
            onUpdateModelValue={(v) => setForm((p) => ({ ...p, description: v }))}
          />
        </div>

        {isCircle && (
          <>
            <Input
              label="Center latitude"
              type="number"
              step="any"
              modelValue={form.center_lat}
              onUpdateModelValue={(v) => setForm((p) => ({ ...p, center_lat: v }))}
            />
            <Input
              label="Center longitude"
              type="number"
              step="any"
              modelValue={form.center_lng}
              onUpdateModelValue={(v) => setForm((p) => ({ ...p, center_lng: v }))}
            />
            <Input
              label="Radius (meters)"
              type="number"
              step="any"
              modelValue={form.radius_m}
              onUpdateModelValue={(v) => setForm((p) => ({ ...p, radius_m: v }))}
            />
          </>
        )}

        {isPolygon && (
          <div className="sm:col-span-2">
            <Textarea
              label="Vertices (JSON array of [lat, lng])"
              rows={5}
              placeholder={POLYGON_PLACEHOLDER}
              modelValue={form.vertices}
              onUpdateModelValue={(v) => setForm((p) => ({ ...p, vertices: v }))}
              helperText="We don't yet support a map editor; paste a JSON polygon array."
            />
          </div>
        )}
      </div>

      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={submitting || !form.name.trim()}>
          {submitting ? 'Saving...' : isEdit ? 'Save fence' : 'Create fence'}
        </Button>
      </div>
    </Modal>
  )
}

function FenceTestModal({ fence, onClose, onError }) {
  const [lat, setLat] = useState('')
  const [lng, setLng] = useState('')
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState(null)

  const check = async () => {
    const latNum = Number(lat)
    const lngNum = Number(lng)
    if (!Number.isFinite(latNum) || !Number.isFinite(lngNum)) {
      onError('Enter numeric latitude and longitude.')
      return
    }
    setBusy(true)
    setResult(null)
    try {
      const res = await routingService.evaluateFenceEvent({
        fence_id: fence.id,
        lat: latNum,
        lng: lngNum,
      })
      const data = unwrap(res) || {}
      const inside = Boolean(data.inside ?? data.is_inside ?? data.contains)
      setResult({ inside, raw: data })
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to evaluate position.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open title={`Test geo-fence: ${fence.name}`} onClose={onClose} size="md">
      <div className="grid grid-cols-2 gap-3">
        <Input
          label="Latitude"
          type="number"
          step="any"
          modelValue={lat}
          onUpdateModelValue={setLat}
        />
        <Input
          label="Longitude"
          type="number"
          step="any"
          modelValue={lng}
          onUpdateModelValue={setLng}
        />
      </div>

      {result ? (
        <Alert variant={result.inside ? 'success' : 'info'} className="mt-4">
          Position is <strong>{result.inside ? 'inside' : 'outside'}</strong> the fence.
        </Alert>
      ) : null}

      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={busy}>Close</Button>
        <Button variant="primary" onClick={check} disabled={busy || !lat || !lng}>
          {busy ? 'Checking...' : 'Check'}
        </Button>
      </div>
    </Modal>
  )
}
