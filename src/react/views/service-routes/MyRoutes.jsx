import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import { useAuthStore } from '../../stores/auth'
import serviceRoutesService from '../../../services/service-routes.service'

/**
 * Phase 15 / M7 mobile/PWA flow for technicians executing recurring routes.
 *
 * Day-list → visit-detail with state-machine actions, QR scan-on-arrival,
 * and direct-from-camera photo capture. The state writes go through the
 * /transition endpoint (perm: service_routes.execute), and photos are
 * uploaded via the multipart /photos/upload endpoint.
 *
 * Designed for portrait phone: large touch targets, single-column layout,
 * bottom-anchored primary action.
 */

const VISIT_STATUSES = [
  'planned', 'en_route', 'arrived',
  'completed', 'skipped', 'missed',
]

const STATUS_VARIANT = {
  planned: 'secondary',
  en_route: 'info',
  arrived: 'info',
  completed: 'success',
  skipped: 'warning',
  missed: 'danger',
}

const STATUS_LABEL = {
  planned: 'Planned',
  en_route: 'En route',
  arrived: 'On site',
  completed: 'Done',
  skipped: 'Skipped',
  missed: 'Missed',
}

function titleize(s) {
  if (!s) return ''
  return String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function fmtDate(d) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function fmtTime(s) {
  if (!s) return '—'
  try {
    return new Date(String(s).replace(' ', 'T')).toLocaleTimeString([], {
      hour: 'numeric',
      minute: '2-digit',
    })
  } catch {
    return s
  }
}

export default function MyRoutes() {
  const { user } = useAuthStore()
  const [date, setDate] = useState(() => fmtDate(new Date()))
  const [visits, setVisits] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [info, setInfo] = useState('')
  const [selectedId, setSelectedId] = useState(null)
  const [showScanner, setShowScanner] = useState(false)

  const load = useCallback(async () => {
    if (!user?.id) return
    setLoading(true)
    setError('')
    try {
      const params = {
        assigned_user_id: user.id,
        scheduled_from: `${date} 00:00:00`,
        scheduled_to: `${date} 23:59:59`,
        per_page: 100,
      }
      const res = await serviceRoutesService.listVisits(params)
      const rows = res?.data?.route_visits || []
      // Stable sort by scheduled_for so techs see the day in order.
      rows.sort((a, b) => String(a.scheduled_for).localeCompare(String(b.scheduled_for)))
      setVisits(rows)
    } catch (err) {
      console.error('Failed to load my visits', err)
      setError('Unable to load your route visits.')
    } finally {
      setLoading(false)
    }
  }, [user?.id, date])

  useEffect(() => { load() }, [load])

  if (selectedId !== null) {
    return (
      <VisitDetail
        id={selectedId}
        onBack={() => { setSelectedId(null); load() }}
        onError={setError}
        onInfo={setInfo}
        error={error}
        info={info}
      />
    )
  }

  // Day-list summary counts.
  const counts = useMemo(() => {
    const out = { planned: 0, in_progress: 0, completed: 0, other: 0 }
    visits.forEach((v) => {
      if (v.status === 'planned') out.planned += 1
      else if (v.status === 'en_route' || v.status === 'arrived') out.in_progress += 1
      else if (v.status === 'completed') out.completed += 1
      else out.other += 1
    })
    return out
  }, [visits])

  return (
    <div className="space-y-4 max-w-2xl mx-auto pb-20">
      <div className="flex flex-col gap-2">
        <h1 className="text-2xl font-bold text-gray-900">My route</h1>
        <p className="text-sm text-gray-500">
          Your assigned recurring service visits. Tap a stop to start, arrive, or complete.
        </p>
      </div>

      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <Input label="Date" type="date" modelValue={date} onUpdateModelValue={setDate} />
          <div className="flex-1" />
          <Button variant="outline" onClick={load} disabled={loading}>
            {loading ? 'Refreshing...' : 'Refresh'}
          </Button>
          <Button variant="primary" onClick={() => setShowScanner(true)}>
            Scan QR
          </Button>
        </div>
      </Card>

      {error ? <Alert variant="danger">{error}</Alert> : null}
      {info ? <Alert variant="info">{info}</Alert> : null}

      <div className="grid grid-cols-4 gap-2">
        <CountCell label="Planned" value={counts.planned} variant="secondary" />
        <CountCell label="Active" value={counts.in_progress} variant="info" />
        <CountCell label="Done" value={counts.completed} variant="success" />
        <CountCell label="Other" value={counts.other} variant="warning" />
      </div>

      {loading ? (
        <Card><div className="py-10 flex justify-center"><Loading text="Loading..." /></div></Card>
      ) : visits.length === 0 ? (
        <Card>
          <div className="py-10 text-center text-gray-500">
            No visits assigned to you on {date}.
          </div>
        </Card>
      ) : (
        <div className="space-y-2">
          {visits.map((v) => (
            <VisitRow key={v.id} visit={v} onOpen={() => setSelectedId(v.id)} />
          ))}
        </div>
      )}

      {showScanner && (
        <QrScanModal
          onClose={() => setShowScanner(false)}
          onScanned={async (token) => {
            setShowScanner(false)
            try {
              const res = await serviceRoutesService.scan(token)
              const visitId = res?.data?.visit?.id
              if (visitId) {
                setSelectedId(visitId)
              } else {
                setError('No visit matched the scanned code.')
              }
            } catch (err) {
              setError(err?.response?.data?.message || 'Unable to look up scanned code.')
            }
          }}
          onError={setError}
        />
      )}
    </div>
  )
}

function CountCell({ label, value, variant }) {
  return (
    <div className="rounded-md border border-gray-200 px-2 py-2 text-center">
      <div className="text-[10px] uppercase tracking-wide text-gray-500">{label}</div>
      <div className="mt-1">
        <Badge variant={variant}>{value}</Badge>
      </div>
    </div>
  )
}

function VisitRow({ visit, onOpen }) {
  return (
    <button
      onClick={onOpen}
      className="w-full text-left rounded-md border border-gray-200 bg-white p-3 active:bg-gray-50 hover:bg-gray-50"
    >
      <div className="flex items-center justify-between gap-2">
        <div className="text-sm font-semibold text-gray-900">
          {fmtTime(visit.scheduled_for)} · Stop #{visit.route_stop_id}
        </div>
        <Badge variant={STATUS_VARIANT[visit.status] || 'secondary'}>
          {STATUS_LABEL[visit.status] || titleize(visit.status)}
        </Badge>
      </div>
      <div className="mt-1 text-xs text-gray-600">
        Visit #{visit.id} · Route #{visit.service_route_id} · Photos {visit.photos_uploaded}
      </div>
    </button>
  )
}

// ---------------------------------------------------------------------------
// Visit detail
// ---------------------------------------------------------------------------

function VisitDetail({ id, onBack, onError, onInfo, error, info }) {
  const { user } = useAuthStore()
  const [visit, setVisit] = useState(null)
  const [allowed, setAllowed] = useState([])
  const [photos, setPhotos] = useState([])
  const [loading, setLoading] = useState(true)
  const [transitioning, setTransitioning] = useState(false)
  const [skipModal, setSkipModal] = useState(false)
  const [uploading, setUploading] = useState(false)
  const fileInputRef = useRef(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await serviceRoutesService.getVisit(id)
      const data = res?.data || {}
      setVisit(data)
      setAllowed(data.allowed_transitions || [])
      setPhotos(data.photos || [])
    } catch (err) {
      console.error('Failed to load visit', err)
      onError('Unable to load the visit.')
    } finally {
      setLoading(false)
    }
  }, [id, onError])

  useEffect(() => { load() }, [load])

  const transitionTo = useCallback(async (target, extra = {}) => {
    setTransitioning(true)
    onError('')
    onInfo('')
    try {
      await serviceRoutesService.transitionVisit(id, { status: target, ...extra })
      onInfo(`Moved to ${STATUS_LABEL[target] || titleize(target)}.`)
      await load()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to apply transition.')
    } finally {
      setTransitioning(false)
    }
  }, [id, load, onError, onInfo])

  const start = () => transitionTo('en_route')

  // Try to attach a GPS fix to the arrival when permission is granted, but
  // don't block on it — older devices and denied permissions just skip.
  const arrive = () => {
    if (!navigator.geolocation) {
      transitionTo('arrived')
      return
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        transitionTo('arrived', {
          arrival_lat: pos.coords.latitude,
          arrival_lng: pos.coords.longitude,
        })
      },
      () => transitionTo('arrived'),
      { timeout: 8000, maximumAge: 60000, enableHighAccuracy: true }
    )
  }

  const complete = () => transitionTo('completed')

  const handleFile = async (event) => {
    const files = Array.from(event.target.files || [])
    if (!files.length) return
    setUploading(true)
    onError('')
    try {
      for (const f of files) {
        await serviceRoutesService.uploadPhoto(id, f)
      }
      onInfo(`Uploaded ${files.length} photo${files.length === 1 ? '' : 's'}.`)
      await load()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to upload photo.')
    } finally {
      setUploading(false)
      if (fileInputRef.current) fileInputRef.current.value = ''
    }
  }

  const removePhoto = async (photoId) => {
    if (!confirm('Delete this photo?')) return
    try {
      await serviceRoutesService.deletePhoto(photoId)
      await load()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to delete photo.')
    }
  }

  if (loading) {
    return (
      <div className="space-y-4 max-w-2xl mx-auto pb-20">
        <Button variant="ghost" onClick={onBack}>← Back</Button>
        <Card><div className="py-10 flex justify-center"><Loading text="Loading visit..." /></div></Card>
      </div>
    )
  }
  if (!visit) {
    return (
      <div className="space-y-4 max-w-2xl mx-auto pb-20">
        <Button variant="ghost" onClick={onBack}>← Back</Button>
        <Card><div className="py-10 text-center text-gray-500">Visit not found.</div></Card>
      </div>
    )
  }

  const canStart = allowed.includes('en_route')
  const canArrive = allowed.includes('arrived')
  const canComplete = allowed.includes('completed')
  const canSkip = allowed.includes('skipped')
  const isMine = visit.assigned_user_id == null || visit.assigned_user_id === user?.id

  return (
    <div className="space-y-4 max-w-2xl mx-auto pb-32">
      <Button variant="ghost" onClick={onBack}>← Back to my route</Button>

      {error ? <Alert variant="danger">{error}</Alert> : null}
      {info ? <Alert variant="info">{info}</Alert> : null}
      {!isMine && (
        <Alert variant="warning">
          This visit is assigned to user #{visit.assigned_user_id}. State changes are still
          allowed but will be audited under your account.
        </Alert>
      )}

      <Card>
        <div className="space-y-3">
          <div className="flex items-center justify-between gap-2">
            <h2 className="text-lg font-semibold text-gray-900">
              Visit #{visit.id}
            </h2>
            <Badge variant={STATUS_VARIANT[visit.status] || 'secondary'}>
              {STATUS_LABEL[visit.status] || titleize(visit.status)}
            </Badge>
          </div>
          <div className="grid grid-cols-2 gap-2 text-sm">
            <Field label="Scheduled" value={fmtTime(visit.scheduled_for)} />
            <Field label="Window (min)" value={visit.scheduled_window_minutes ?? '—'} />
            <Field label="Route" value={`#${visit.service_route_id}`} />
            <Field label="Stop" value={`#${visit.route_stop_id}`} />
            <Field label="En route at" value={fmtTime(visit.en_route_at)} />
            <Field label="Arrived at" value={fmtTime(visit.arrived_at)} />
            <Field label="Completed at" value={fmtTime(visit.completed_at)} />
            <Field label="Photos" value={visit.photos_uploaded} />
          </div>
          {visit.skip_reason ? (
            <Section title="Skip reason" body={visit.skip_reason} />
          ) : null}
          {visit.verification_notes ? (
            <Section title="Verification notes" body={visit.verification_notes} />
          ) : null}
        </div>
      </Card>

      <Card>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-base font-semibold text-gray-900">Photos</h3>
          <label className="cursor-pointer">
            <Button as="span" variant="outline" disabled={uploading || visit.is_terminal}>
              {uploading ? 'Uploading...' : 'Add photos'}
            </Button>
            <input
              ref={fileInputRef}
              type="file"
              multiple
              accept="image/*"
              capture="environment"
              onChange={handleFile}
              className="hidden"
              disabled={uploading || visit.is_terminal}
            />
          </label>
        </div>
        {photos.length === 0 ? (
          <p className="text-sm text-gray-500 text-center py-3">No photos yet.</p>
        ) : (
          <div className="grid grid-cols-3 gap-2">
            {photos.map((p) => (
              <div key={p.id} className="relative group rounded-md overflow-hidden border border-gray-200 bg-gray-50">
                {/* file_path is rooted at /uploads/... served by the static handler */}
                <img src={p.file_path} alt="" className="w-full h-24 object-cover" />
                {!visit.is_terminal && (
                  <button
                    onClick={() => removePhoto(p.id)}
                    className="absolute top-1 right-1 bg-black/60 text-white text-xs rounded px-2 py-0.5 opacity-90"
                  >
                    Delete
                  </button>
                )}
              </div>
            ))}
          </div>
        )}
      </Card>

      <div className="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 p-3 sm:static sm:bg-transparent sm:border-0 sm:p-0">
        <div className="max-w-2xl mx-auto flex flex-wrap gap-2 justify-end">
          {canStart && (
            <Button variant="primary" onClick={start} disabled={transitioning}>
              Start (en route)
            </Button>
          )}
          {canArrive && (
            <Button variant="primary" onClick={arrive} disabled={transitioning}>
              Arrive
            </Button>
          )}
          {canSkip && (
            <Button variant="danger" onClick={() => setSkipModal(true)} disabled={transitioning}>
              Skip
            </Button>
          )}
          {canComplete && (
            <Button variant="success" onClick={complete} disabled={transitioning}>
              Complete
            </Button>
          )}
          {!canStart && !canArrive && !canComplete && !canSkip && (
            <span className="text-sm text-gray-500 self-center">No actions available.</span>
          )}
        </div>
      </div>

      {skipModal && (
        <SkipModal
          onClose={() => setSkipModal(false)}
          onSubmit={async (reason) => {
            setSkipModal(false)
            await transitionTo('skipped', { skip_reason: reason })
          }}
        />
      )}
    </div>
  )
}

function Field({ label, value }) {
  return (
    <div>
      <div className="text-[10px] uppercase tracking-wide text-gray-500">{label}</div>
      <div className="text-sm text-gray-800">{value ?? '—'}</div>
    </div>
  )
}

function Section({ title, body }) {
  return (
    <div className="border-l-4 border-gray-200 pl-3">
      <div className="text-[10px] uppercase tracking-wide text-gray-500">{title}</div>
      <p className="text-sm text-gray-800 whitespace-pre-wrap">{body}</p>
    </div>
  )
}

function SkipModal({ onClose, onSubmit }) {
  const [reason, setReason] = useState('')
  return (
    <Modal open title="Skip this visit" onClose={onClose} size="md">
      <Textarea
        label="Reason (required)"
        rows={4}
        modelValue={reason}
        onUpdateModelValue={setReason}
      />
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button variant="danger" onClick={() => onSubmit(reason.trim())} disabled={!reason.trim()}>
          Confirm skip
        </Button>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// QR scanner — uses the BarcodeDetector API where available, falls back to
// manual token entry. Same approach as InventoryList's barcode scanner.
// ---------------------------------------------------------------------------

function QrScanModal({ onClose, onScanned, onError }) {
  const videoRef = useRef(null)
  const [supported, setSupported] = useState(true)
  const [cameraError, setCameraError] = useState('')
  const [manualToken, setManualToken] = useState('')

  useEffect(() => {
    const ok = Boolean(navigator.mediaDevices?.getUserMedia && window.BarcodeDetector)
    setSupported(ok)
  }, [])

  useEffect(() => {
    if (!supported) return undefined
    let isActive = true
    let stream = null
    let raf = null

    const start = async () => {
      try {
        const detector = new window.BarcodeDetector({ formats: ['qr_code'] })
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' } },
          audio: false,
        })
        if (!isActive) return
        if (videoRef.current) {
          videoRef.current.srcObject = stream
          await videoRef.current.play()
        }
        const tick = async () => {
          if (!isActive || !videoRef.current) return
          try {
            const codes = await detector.detect(videoRef.current)
            const raw = codes?.[0]?.rawValue
            if (raw) {
              const token = extractToken(raw)
              if (token) {
                onScanned(token)
                return
              }
            }
          } catch (err) {
            // detection failures are expected per frame; ignore.
          }
          raf = requestAnimationFrame(tick)
        }
        raf = requestAnimationFrame(tick)
      } catch (err) {
        console.error('QR camera failed', err)
        setCameraError(err?.message || 'Unable to access camera.')
      }
    }
    start()

    return () => {
      isActive = false
      if (raf) cancelAnimationFrame(raf)
      if (stream) stream.getTracks().forEach((t) => t.stop())
      if (videoRef.current) videoRef.current.srcObject = null
    }
  }, [supported, onScanned])

  return (
    <Modal open title="Scan stop QR code" onClose={onClose} size="md">
      {supported ? (
        <div className="space-y-3">
          <div className="rounded-md overflow-hidden bg-black">
            <video ref={videoRef} playsInline muted className="w-full h-64 object-contain" />
          </div>
          {cameraError && <Alert variant="warning">{cameraError}</Alert>}
          <p className="text-xs text-gray-500 text-center">
            Hold the camera steady on the QR code on the stop placard.
          </p>
        </div>
      ) : (
        <Alert variant="info">
          QR scanning isn't supported in this browser. Enter the token manually below.
        </Alert>
      )}

      <div className="mt-4 space-y-2">
        <Input
          label="Or paste the token"
          modelValue={manualToken}
          onUpdateModelValue={setManualToken}
        />
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button
            variant="primary"
            onClick={() => {
              const token = extractToken(manualToken.trim())
              if (!token) {
                onError('Enter a valid scan token.')
                return
              }
              onScanned(token)
            }}
            disabled={!manualToken.trim()}
          >
            Look up
          </Button>
        </div>
      </div>
    </Modal>
  )
}

// QR codes may carry a full URL like https://app/cp/route-visits/scan/<token>
// or just the bare token. Extract whichever is present.
function extractToken(raw) {
  if (!raw) return ''
  const match = String(raw).match(/scan\/([a-f0-9]{16,})$/i)
  if (match) return match[1]
  // bare 32-hex token from bin2hex(random_bytes(16))
  const bare = String(raw).match(/^[a-f0-9]{16,}$/i)
  return bare ? bare[0] : ''
}
