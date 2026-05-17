import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import api from '../../../services/api'
import Button from '../../components/ui/Button'
import Badge from '../../components/ui/Badge'
import Loading from '../../components/ui/Loading'
import { useToast } from '../../stores/toast'

const formatCurrency = (amount) => `$${Number(amount || 0).toFixed(2)}`

// Lightweight UA parsing for forensic fields. We persist the raw user_agent
// already; this just gives reports a friendly browser/OS pair without
// re-parsing the UA on every read. Order matters: Edg/OPR must beat Chrome,
// Chrome must beat Safari (Chrome's UA contains "Safari").
const parseUserAgent = (ua) => {
  const result = { browser_name: null, browser_version: null, os_name: null, os_version: null }
  if (!ua || typeof ua !== 'string') return result

  const browserPatterns = [
    { name: 'Edge', re: /Edg\/(\d+(?:\.\d+)*)/ },
    { name: 'Opera', re: /OPR\/(\d+(?:\.\d+)*)/ },
    { name: 'Firefox', re: /Firefox\/(\d+(?:\.\d+)*)/ },
    { name: 'Chrome', re: /Chrome\/(\d+(?:\.\d+)*)/ },
    { name: 'Safari', re: /Version\/(\d+(?:\.\d+)*).*Safari/ },
  ]
  for (const { name, re } of browserPatterns) {
    const m = ua.match(re)
    if (m) { result.browser_name = name; result.browser_version = m[1]; break }
  }

  if (/Windows NT ([\d.]+)/.test(ua)) {
    result.os_name = 'Windows'
    result.os_version = ua.match(/Windows NT ([\d.]+)/)[1]
  } else if (/Mac OS X ([\d_]+)/.test(ua)) {
    result.os_name = 'macOS'
    result.os_version = ua.match(/Mac OS X ([\d_]+)/)[1].replace(/_/g, '.')
  } else if (/Android ([\d.]+)/.test(ua)) {
    result.os_name = 'Android'
    result.os_version = ua.match(/Android ([\d.]+)/)[1]
  } else if (/iPhone OS ([\d_]+)|iPad.*OS ([\d_]+)/.test(ua)) {
    const m = ua.match(/(?:iPhone OS|iPad.*OS) ([\d_]+)/)
    result.os_name = 'iOS'
    result.os_version = m ? m[1].replace(/_/g, '.') : null
  } else if (/Linux/.test(ua)) {
    result.os_name = 'Linux'
  }

  return result
}

const requestGeolocation = () =>
  new Promise((resolve) => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      resolve(null)
      return
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => resolve({
        geo_lat: pos.coords.latitude,
        geo_lng: pos.coords.longitude,
        geo_accuracy_m: pos.coords.accuracy,
        geo_captured_at: new Date(pos.timestamp).toISOString(),
      }),
      () => resolve(null),
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
    )
  })

const statusLabels = {
  approved: 'Approved',
  rejected: 'Rejected',
  pending: 'Pending',
  sent: 'Sent',
  partial: 'Partially Approved',
  declined: 'Declined',
  expired: 'Expired',
}

const statusVariants = {
  approved: 'success',
  rejected: 'danger',
  pending: 'warning',
  sent: 'info',
  partial: 'warning',
  declined: 'danger',
  expired: 'danger',
}

function SignaturePad({ onChange }) {
  const canvasRef = useRef(null)
  const drawing = useRef(false)

  const resizeCanvas = useCallback(() => {
    const canvas = canvasRef.current
    if (!canvas) return
    const parent = canvas.parentElement
    if (!parent) return
    const ratio = window.devicePixelRatio || 1
    const width = parent.clientWidth
    const height = 180
    canvas.width = width * ratio
    canvas.height = height * ratio
    canvas.style.width = `${width}px`
    canvas.style.height = `${height}px`
    const ctx = canvas.getContext('2d')
    if (ctx) {
      ctx.scale(ratio, ratio)
      ctx.lineWidth = 2
      ctx.lineCap = 'round'
      ctx.strokeStyle = '#111827'
    }
  }, [])

  useEffect(() => {
    resizeCanvas()
    window.addEventListener('resize', resizeCanvas)
    return () => window.removeEventListener('resize', resizeCanvas)
  }, [resizeCanvas])

  const getPosition = (event) => {
    const canvas = canvasRef.current
    if (!canvas) return { x: 0, y: 0 }
    const rect = canvas.getBoundingClientRect()
    return {
      x: event.clientX - rect.left,
      y: event.clientY - rect.top,
    }
  }

  const handlePointerDown = (event) => {
    const canvas = canvasRef.current
    if (!canvas) return
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    drawing.current = true
    const { x, y } = getPosition(event)
    ctx.beginPath()
    ctx.moveTo(x, y)
  }

  const handlePointerMove = (event) => {
    if (!drawing.current) return
    const canvas = canvasRef.current
    if (!canvas) return
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    const { x, y } = getPosition(event)
    ctx.lineTo(x, y)
    ctx.stroke()
  }

  const handlePointerUp = () => {
    if (!drawing.current) return
    drawing.current = false
    const canvas = canvasRef.current
    if (!canvas) return
    onChange?.(canvas.toDataURL('image/png'))
  }

  const clear = () => {
    const canvas = canvasRef.current
    if (!canvas) return
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    onChange?.('')
  }

  return (
    <div className="space-y-3">
      <div className="rounded-lg border border-dashed border-gray-300 bg-white">
        <canvas
          ref={canvasRef}
          className="w-full touch-none"
          onPointerDown={handlePointerDown}
          onPointerMove={handlePointerMove}
          onPointerUp={handlePointerUp}
          onPointerLeave={handlePointerUp}
        />
      </div>
      <Button variant="ghost" size="sm" onClick={clear}>
        Clear Signature
      </Button>
    </div>
  )
}

export default function PublicEstimateView() {
  const location = useLocation()
  const { success, error } = useToast()
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [estimate, setEstimate] = useState(null)
  const [customer, setCustomer] = useState(null)
  const [vehicle, setVehicle] = useState(null)
  const [jobs, setJobs] = useState([])
  const [terms, setTerms] = useState('')
  const [hasSignature, setHasSignature] = useState(false)
  const [token, setToken] = useState('')
  const [shortCode, setShortCode] = useState('')
  const [signerName, setSignerName] = useState('')
  const [signerEmail, setSignerEmail] = useState('')
  const [signatureData, setSignatureData] = useState('')
  const [comment, setComment] = useState('')
  const [legalConsent, setLegalConsent] = useState(false)
  const [consentText, setConsentText] = useState('')
  const [rejectionReasons, setRejectionReasons] = useState([])
  const [jobNotes, setJobNotes] = useState({})
  const [jobRejectionReasons, setJobRejectionReasons] = useState({})
  const [submitting, setSubmitting] = useState(false)
  const [geo, setGeo] = useState(null)
  const [geoStatus, setGeoStatus] = useState('idle') // idle | requesting | granted | denied
  const browserInfo = useMemo(() => parseUserAgent(typeof navigator !== 'undefined' ? navigator.userAgent : ''), [])

  const query = useMemo(() => new URLSearchParams(location.search), [location.search])

  const requireSignature = Boolean(estimate?.require_signature)
  const isReadOnlyShortLink = !token && Boolean(shortCode)

  const loadEstimate = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    try {
      const tokenParam = query.get('token') || ''
      const codeParam = query.get('code') || ''
      setToken(tokenParam)
      setShortCode(codeParam)

      if (!tokenParam && !codeParam) {
        setLoadError('Missing estimate link details. Please use the link provided in your notification.')
        return
      }

      const response = tokenParam
        ? await api.get('/public/estimate', { params: { token: tokenParam } })
        : await api.get(`/public/estimate/by-code/${codeParam}`)

      setEstimate(response.data.estimate)
      setCustomer(response.data.customer)
      setVehicle(response.data.vehicle)
      setJobs(response.data.jobs || [])
      setTerms(response.data.terms || '')
      setHasSignature(Boolean(response.data.has_signature))
      setConsentText(response.data.terms || '')
    } catch (loadFailure) {
      console.error('Failed to load estimate:', loadFailure)
      setLoadError(loadFailure.response?.data?.error || 'Failed to load estimate.')
    } finally {
      setLoading(false)
    }
  }, [query])

  const loadRejectionReasons = useCallback(async () => {
    try {
      const response = await api.get('/public/estimate/rejection-reasons')
      setRejectionReasons(response.data.reasons || [])
    } catch (err) {
      console.error('Failed to load rejection reasons:', err)
    }
  }, [])

  useEffect(() => {
    loadEstimate()
    loadRejectionReasons()
  }, [loadEstimate, loadRejectionReasons])

  // Best-effort: ask for the user's location once the estimate is loaded so
  // the coords are ready when they sign. Browsers will surface a permission
  // prompt; if they deny, we just submit without coords (legal validity of
  // the e-signature does not depend on geo).
  const captureGeo = useCallback(async () => {
    if (geoStatus !== 'idle') return geo
    setGeoStatus('requesting')
    const result = await requestGeolocation()
    if (result) {
      setGeo(result)
      setGeoStatus('granted')
    } else {
      setGeoStatus('denied')
    }
    return result
  }, [geo, geoStatus])

  useEffect(() => {
    if (estimate && !hasSignature && geoStatus === 'idle') {
      captureGeo()
    }
  }, [estimate, hasSignature, geoStatus, captureGeo])

  const jobStatusSummary = useMemo(() => {
    const approved = jobs.filter((job) => job.customer_status === 'approved').length
    const rejected = jobs.filter((job) => job.customer_status === 'rejected').length
    const pending = jobs.length - approved - rejected
    return { approved, rejected, pending }
  }, [jobs])

  const allJobsResponded = useMemo(
    () => jobs.length > 0 && jobs.every((job) => job.customer_status === 'approved' || job.customer_status === 'rejected'),
    [jobs]
  )

  const updateJobStatus = (jobId, status) => {
    setJobs((prev) =>
      prev.map((job) => (job.id === jobId ? { ...job, customer_status: status } : job))
    )
  }

  const submitJobAction = async (jobId, status) => {
    // R-03 / AUD-065 — state-changing endpoints accept only the long
    // token. Short-code-only sessions are read-only; the user must
    // open the original signing link from their notification to act.
    if (!token) {
      throw new Error('Open the signing link from your email to approve, reject, or sign this estimate.')
    }
    const payload = {
      token,
      job_id: jobId,
      signer_name: signerName || undefined,
      signer_email: signerEmail || undefined,
      comment: jobNotes[jobId] || undefined,
    }

    if (status === 'rejected') {
      payload.rejection_reason = jobRejectionReasons[jobId] || undefined
    }

    const endpoint = status === 'approved' ? '/public/estimate/approve-job' : '/public/estimate/reject-job'
    await api.post(endpoint, payload)
    updateJobStatus(jobId, status)
  }

  const handleApproveJob = async (jobId) => {
    try {
      await submitJobAction(jobId, 'approved')
      success('Job approved.')
    } catch (approveError) {
      console.error('Failed to approve job:', approveError)
      error(approveError.response?.data?.error || approveError.message || 'Failed to approve job.')
    }
  }

  const handleRejectJob = async (jobId) => {
    try {
      await submitJobAction(jobId, 'rejected')
      success('Job rejected.')
    } catch (rejectError) {
      console.error('Failed to reject job:', rejectError)
      error(rejectError.response?.data?.error || rejectError.message || 'Failed to reject job.')
    }
  }

  const handleSignEstimate = async () => {
    if (!signerName.trim()) {
      error('Please enter your name to sign.')
      return
    }
    if (!signatureData) {
      error('Please provide your signature.')
      return
    }
    if (!legalConsent) {
      error('Please accept the consent terms to continue.')
      return
    }

    setSubmitting(true)
    try {
      // Re-attempt geo capture in case the user dismissed it earlier — a
      // user-initiated click is the most likely time browsers will allow it.
      const liveGeo = geo ?? (await captureGeo())

      // R-03 / AUD-065 — signature capture requires the long token.
      if (!token) {
        throw new Error('Open the signing link from your email to sign this estimate.')
      }
      // Capture the signature FIRST when require_signature is on so the
      // backend approve-job guard sees the signature row and lets the
      // sign-and-approve-all loop proceed.
      const submitSignature = () => api.post('/public/estimate/signature', {
        token,
        name: signerName,
        email: signerEmail || undefined,
        signature_data: signatureData,
        comment: comment || undefined,
        legal_consent: legalConsent,
        consent_text: consentText || undefined,
        geo_lat: liveGeo?.geo_lat ?? null,
        geo_lng: liveGeo?.geo_lng ?? null,
        geo_accuracy_m: liveGeo?.geo_accuracy_m ?? null,
        geo_captured_at: liveGeo?.geo_captured_at ?? null,
        browser_name: browserInfo.browser_name,
        browser_version: browserInfo.browser_version,
        os_name: browserInfo.os_name,
        os_version: browserInfo.os_version,
      })

      if (requireSignature) {
        await submitSignature()
        const pendingJobs = jobs.filter((job) => job.customer_status !== 'approved')
        for (const job of pendingJobs) {
          await submitJobAction(job.id, 'approved')
        }
      } else {
        const pendingJobs = jobs.filter((job) => job.customer_status !== 'approved')
        for (const job of pendingJobs) {
          await submitJobAction(job.id, 'approved')
        }
        await submitSignature()
      }

      setHasSignature(true)
      success('Estimate approved and signed. Thank you!')
      loadEstimate()
    } catch (signatureError) {
      console.error('Failed to sign estimate:', signatureError)
      error(signatureError.response?.data?.error || signatureError.message || 'Failed to sign estimate.')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[60vh]">
        <Loading size="xl" text="Loading estimate..." />
      </div>
    )
  }

  if (loadError) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-16 text-center">
        <h2 className="text-2xl font-semibold text-gray-900">Unable to load estimate</h2>
        <p className="mt-3 text-gray-600">{loadError}</p>
      </div>
    )
  }

  if (!estimate) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-16 text-center">
        <h2 className="text-2xl font-semibold text-gray-900">Estimate not found</h2>
        <p className="mt-3 text-gray-600">The estimate link might be expired or invalid.</p>
      </div>
    )
  }

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="mx-auto max-w-5xl px-4 py-10">
        <div className="bg-white shadow-sm rounded-2xl p-6 space-y-6">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h1 className="text-3xl font-bold text-gray-900">Estimate #{estimate.number}</h1>
              <p className="mt-1 text-sm text-gray-500">Thank you for choosing our shop. Review and approve below.</p>
            </div>
            <Badge variant={statusVariants[estimate.status] || 'info'} size="lg">
              {statusLabels[estimate.status] || estimate.status}
            </Badge>
          </div>

          {isReadOnlyShortLink ? (
            <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              This short link is view-only. To approve, reject, or sign this estimate, open the secure approval link from your message or ask the shop to resend it.
            </div>
          ) : null}

          <div className="grid gap-6 md:grid-cols-2">
            <div className="rounded-xl border border-gray-200 p-4">
              <h2 className="text-sm font-semibold text-gray-700 uppercase">Customer</h2>
              <p className="mt-2 text-lg font-medium text-gray-900">{customer?.name || 'Customer'}</p>
              <p className="text-sm text-gray-500">{customer?.email || 'Email not available'}</p>
              <p className="text-sm text-gray-500">{customer?.phone || 'Phone not available'}</p>
            </div>
            <div className="rounded-xl border border-gray-200 p-4">
              <h2 className="text-sm font-semibold text-gray-700 uppercase">Vehicle</h2>
              <p className="mt-2 text-lg font-medium text-gray-900">
                {[vehicle?.year, vehicle?.make, vehicle?.model].filter(Boolean).join(' ') || 'Vehicle'}
              </p>
              <p className="text-sm text-gray-500">VIN: {vehicle?.vin || 'N/A'}</p>
              <p className="text-sm text-gray-500">Plate: {vehicle?.license_plate || 'N/A'}</p>
            </div>
          </div>

          <div className="rounded-xl border border-gray-200 p-4">
            <h2 className="text-lg font-semibold text-gray-900">Job Summary</h2>
            <p className="mt-1 text-sm text-gray-500">
              {jobStatusSummary.approved} approved · {jobStatusSummary.rejected} rejected · {jobStatusSummary.pending} pending
            </p>
          </div>

          <div className="space-y-6">
            {jobs.map((job) => (
              <div key={job.id} className="rounded-xl border border-gray-200 p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h3 className="text-lg font-semibold text-gray-900">{job.title || `Job #${job.id}`}</h3>
                    {job.description ? <p className="mt-1 text-sm text-gray-500">{job.description}</p> : null}
                  </div>
                  <Badge variant={statusVariants[job.customer_status] || 'warning'}>
                    {statusLabels[job.customer_status] || 'Pending'}
                  </Badge>
                </div>

                {job.items?.length ? (
                  <div className="mt-4 overflow-x-auto">
                    <table className="min-w-full text-sm">
                      <thead className="text-gray-500">
                        <tr>
                          <th className="py-2 text-left font-medium">Item</th>
                          <th className="py-2 text-right font-medium">Qty</th>
                          <th className="py-2 text-right font-medium">Price</th>
                          <th className="py-2 text-right font-medium">Total</th>
                        </tr>
                      </thead>
                      <tbody className="text-gray-700">
                        {job.items.map((item) => (
                          <tr key={item.id || `${job.id}-${item.name}`}>
                            <td className="py-2">{item.description || item.name || 'Line item'}</td>
                            <td className="py-2 text-right">{item.quantity || 1}</td>
                            <td className="py-2 text-right">{formatCurrency(item.unit_price)}</td>
                            <td className="py-2 text-right">
                              {formatCurrency((Number(item.quantity) || 1) * (Number(item.unit_price) || 0))}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : null}

                <div className="mt-4 grid gap-3 md:grid-cols-2">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Comment (optional)</label>
                    <textarea
                      value={jobNotes[job.id] || ''}
                      onChange={(event) =>
                        setJobNotes((prev) => ({ ...prev, [job.id]: event.target.value }))
                      }
                      rows={2}
                      className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                      placeholder="Add a note about this job"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Rejection reason</label>
                    <select
                      value={jobRejectionReasons[job.id] || ''}
                      onChange={(event) =>
                        setJobRejectionReasons((prev) => ({ ...prev, [job.id]: event.target.value }))
                      }
                      className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                    >
                      <option value="">Select a reason</option>
                      {rejectionReasons.map((reason) => (
                        <option key={reason} value={reason}>
                          {reason}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                <div className="mt-4 flex flex-wrap gap-3">
                  <Button
                    variant="primary"
                    size="sm"
                    onClick={() => handleApproveJob(job.id)}
                    disabled={isReadOnlyShortLink || job.customer_status === 'approved' || (requireSignature && !hasSignature)}
                  >
                    Approve Job
                  </Button>
                  <Button
                    variant="danger"
                    size="sm"
                    onClick={() => handleRejectJob(job.id)}
                    disabled={isReadOnlyShortLink || job.customer_status === 'rejected' || (requireSignature && !hasSignature)}
                  >
                    Reject Job
                  </Button>
                  {requireSignature && !hasSignature ? (
                    <p className="w-full text-xs text-amber-700">
                      A signature is required to approve or reject jobs on this estimate.
                      Please scroll down and sign first.
                    </p>
                  ) : null}
                </div>
              </div>
            ))}
          </div>

          <div className="rounded-xl border border-gray-200 p-5">
            <h2 className="text-lg font-semibold text-gray-900">Estimate Totals</h2>
            <div className="mt-4 grid gap-2 text-sm">
              <div className="flex justify-between">
                <span className="text-gray-600">Subtotal</span>
                <span className="font-medium text-gray-900">{formatCurrency(estimate.subtotal)}</span>
              </div>
              {Number(estimate.call_out_fee) > 0 ? (
                <div className="flex justify-between">
                  <span className="text-gray-600">Call out fee</span>
                  <span className="font-medium text-gray-900">{formatCurrency(estimate.call_out_fee)}</span>
                </div>
              ) : null}
              {Number(estimate.mileage_total) > 0 ? (
                <div className="flex justify-between">
                  <span className="text-gray-600">Mileage</span>
                  <span className="font-medium text-gray-900">{formatCurrency(estimate.mileage_total)}</span>
                </div>
              ) : null}
              {Number(estimate.discounts) > 0 ? (
                <div className="flex justify-between">
                  <span className="text-gray-600">Discounts</span>
                  <span className="font-medium text-gray-900">-{formatCurrency(estimate.discounts)}</span>
                </div>
              ) : null}
              <div className="flex justify-between">
                <span className="text-gray-600">Tax</span>
                <span className="font-medium text-gray-900">{formatCurrency(estimate.tax)}</span>
              </div>
              <div className="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold">
                <span>Total</span>
                <span>{formatCurrency(estimate.grand_total)}</span>
              </div>
            </div>
          </div>

          <div className="rounded-xl border border-gray-200 p-6 space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="text-lg font-semibold text-gray-900">Digital Signature</h2>
              {hasSignature ? <Badge variant="success">Signed</Badge> : null}
            </div>
            <p className="text-sm text-gray-500">
              Please sign to approve the estimate and authorize work. Your signature records your approval.
            </p>
            {requireSignature && !hasSignature ? (
              <div className="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-800">
                This estimate requires an electronic signature before any job can be approved or rejected.
              </div>
            ) : null}
            <div className="text-xs text-gray-500">
              For audit purposes we record your IP address, browser, operating system,
              and the date/time of your signature.
              {geoStatus === 'granted' ? ' Approximate location will also be attached.' : ''}
              {geoStatus === 'denied' ? ' Location was not shared and will not be recorded.' : ''}
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <div>
                <label className="block text-sm font-medium text-gray-700">Your name</label>
                <input
                  type="text"
                  value={signerName}
                  onChange={(event) => setSignerName(event.target.value)}
                  className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                  placeholder="Enter full name"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Email (optional)</label>
                <input
                  type="email"
                  value={signerEmail}
                  onChange={(event) => setSignerEmail(event.target.value)}
                  className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                  placeholder="name@email.com"
                />
              </div>
            </div>

            <SignaturePad onChange={setSignatureData} />

            <div>
              <label className="block text-sm font-medium text-gray-700">Comment (optional)</label>
              <textarea
                value={comment}
                onChange={(event) => setComment(event.target.value)}
                rows={3}
                className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                placeholder="Add any notes about this approval"
              />
            </div>

            {terms ? (
              <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
                <div className="mb-2 font-medium text-gray-700">Estimate Terms</div>
                <div
                  className="prose max-w-none text-sm text-gray-600"
                  dangerouslySetInnerHTML={{ __html: terms }}
                />
              </div>
            ) : null}

            <label className="flex items-start gap-2 text-sm text-gray-700">
              <input
                type="checkbox"
                className="mt-1 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                checked={legalConsent}
                onChange={(event) => setLegalConsent(event.target.checked)}
              />
              <span>I agree to the estimate terms and authorize the work described above.</span>
            </label>

            <div className="flex flex-wrap items-center gap-3">
              <Button
                variant="primary"
                onClick={handleSignEstimate}
                disabled={isReadOnlyShortLink || hasSignature || submitting || !jobs.length}
                loading={submitting}
              >
                Approve & Sign Estimate
              </Button>
              {!allJobsResponded ? (
                <p className="text-sm text-gray-500">
                  You can sign now to approve all remaining jobs, or approve/reject each item above.
                </p>
              ) : null}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
