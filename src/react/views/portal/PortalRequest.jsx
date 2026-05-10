import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import { portalService } from '../../../services/portal/portal.service'

const STEPS = [
  { key: 'type', label: 'Request type' },
  { key: 'details', label: 'Details' },
  { key: 'location', label: 'Location' },
  { key: 'review', label: 'Review' },
]

const HIGH_SEVERITIES = new Set(['P1', 'P2'])

const IT_REQUEST_KINDS = [
  { value: 'incident', label: 'Incident — something broken' },
  { value: 'outage', label: 'Outage — service unavailable' },
  { value: 'request', label: 'Service request — new access / install' },
  { value: 'question', label: 'Question — how-to / advice' },
]

const SEVERITIES = [
  { value: 'P4', label: 'P4 — Low (cosmetic / nice-to-have)' },
  { value: 'P3', label: 'P3 — Normal (single user impacted)' },
  { value: 'P2', label: 'P2 — High (team or department impacted)' },
]

const PORTAL_PRIORITIES = [
  { value: 'p4_low', label: 'Low' },
  { value: 'p3_normal', label: 'Normal' },
]

const initialForm = {
  requestTypeId: '',
  subcategoryId: '',
  title: '',
  description: '',
  priority: '',
  it_request_kind: '',
  severity: '',
  affected_users_count: '',
  business_impact: '',
  site_id: '',
  asset_id: '',
}

export default function PortalRequest() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const initialKind = searchParams.get('kind') || ''

  const [stepIndex, setStepIndex] = useState(0)
  const [form, setForm] = useState({ ...initialForm, it_request_kind: initialKind })
  const [requestTypes, setRequestTypes] = useState([])
  const [subcategories, setSubcategories] = useState([])
  const [sites, setSites] = useState([])
  const [assets, setAssets] = useState([])
  const [loadingTypes, setLoadingTypes] = useState(false)
  const [loadingSubs, setLoadingSubs] = useState(false)
  const [loadingSites, setLoadingSites] = useState(false)
  const [loadingAssets, setLoadingAssets] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [stepError, setStepError] = useState('')
  const [submitSuccess, setSubmitSuccess] = useState(null)

  const updateField = (field) => (value) => {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  useEffect(() => {
    let cancelled = false
    setLoadingTypes(true)
    portalService.listRequestTypes()
      .then((data) => { if (!cancelled) setRequestTypes(data) })
      .catch(() => { if (!cancelled) setStepError('Could not load request types.') })
      .finally(() => { if (!cancelled) setLoadingTypes(false) })
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    if (!form.requestTypeId) {
      setSubcategories([])
      return undefined
    }
    let cancelled = false
    setLoadingSubs(true)
    portalService.listRequestSubcategories(form.requestTypeId)
      .then((data) => { if (!cancelled) setSubcategories(data) })
      .catch(() => { if (!cancelled) setSubcategories([]) })
      .finally(() => { if (!cancelled) setLoadingSubs(false) })
    return () => { cancelled = true }
  }, [form.requestTypeId])

  useEffect(() => {
    let cancelled = false
    setLoadingSites(true)
    portalService.listSites()
      .then((data) => { if (!cancelled) setSites(data) })
      .catch(() => {})
      .finally(() => { if (!cancelled) setLoadingSites(false) })
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    if (!form.site_id) {
      setAssets([])
      return undefined
    }
    let cancelled = false
    setLoadingAssets(true)
    portalService.listAssetsAtSite(form.site_id)
      .then((res) => {
        if (cancelled) return
        const list = Array.isArray(res) ? res : (res?.data ?? [])
        setAssets(list)
      })
      .catch(() => { if (!cancelled) setAssets([]) })
      .finally(() => { if (!cancelled) setLoadingAssets(false) })
    return () => { cancelled = true }
  }, [form.site_id])

  const selectedType = useMemo(
    () => requestTypes.find((rt) => String(rt.id) === String(form.requestTypeId)) || null,
    [requestTypes, form.requestTypeId],
  )
  const selectedSubcategory = useMemo(
    () => subcategories.find((s) => String(s.id) === String(form.subcategoryId)) || null,
    [subcategories, form.subcategoryId],
  )
  const selectedSite = useMemo(
    () => sites.find((s) => String(s.id) === String(form.site_id)) || null,
    [sites, form.site_id],
  )
  const selectedAsset = useMemo(
    () => assets.find((a) => String(a.id) === String(form.asset_id)) || null,
    [assets, form.asset_id],
  )

  const isItKindSelected = Boolean(form.it_request_kind)
  const requiresBusinessImpact =
    isItKindSelected && (HIGH_SEVERITIES.has(form.severity) || form.it_request_kind === 'outage')

  const validateCurrentStep = () => {
    setStepError('')
    const step = STEPS[stepIndex].key
    if (step === 'type') {
      if (!form.requestTypeId) {
        setStepError('Please choose a request type to continue.')
        return false
      }
    }
    if (step === 'details') {
      if (!form.title.trim()) {
        setStepError('Title is required.')
        return false
      }
      if (form.title.length > 255) {
        setStepError('Title must be 255 characters or fewer.')
        return false
      }
      if (form.description && form.description.length > 20000) {
        setStepError('Description is too long.')
        return false
      }
      if (
        isItKindSelected
        && form.affected_users_count !== ''
        && Number(form.affected_users_count) < 0
      ) {
        setStepError('Affected users must be zero or greater.')
        return false
      }
      if (requiresBusinessImpact && !form.business_impact.trim()) {
        setStepError('Business impact is required for outages and high-severity tickets.')
        return false
      }
    }
    return true
  }

  const goNext = () => {
    if (!validateCurrentStep()) return
    setStepIndex((idx) => Math.min(idx + 1, STEPS.length - 1))
  }
  const goBack = () => {
    setStepError('')
    setStepIndex((idx) => Math.max(idx - 1, 0))
  }

  const submit = async () => {
    if (!validateCurrentStep()) return
    setSubmitting(true)
    setSubmitSuccess(null)
    try {
      const payload = {
        title: form.title.trim(),
        description: form.description.trim() || null,
        category_id: Number(form.requestTypeId),
        subcategory_id: form.subcategoryId ? Number(form.subcategoryId) : null,
        priority: form.priority || undefined,
        site_id: form.site_id ? Number(form.site_id) : null,
        asset_id: form.asset_id ? Number(form.asset_id) : null,
      }
      if (isItKindSelected) {
        payload.it_request_kind = form.it_request_kind
        if (form.severity) payload.severity = form.severity
        if (form.affected_users_count !== '') {
          payload.affected_users_count = Number(form.affected_users_count)
        }
        if (form.business_impact.trim()) {
          payload.business_impact = form.business_impact.trim()
        }
      }
      const ticket = await portalService.submitRequest(payload)
      setSubmitSuccess(ticket?.ticket_number || (ticket?.id ? `#${ticket.id}` : 'submitted'))
      // Brief pause so the user sees the success state, then home.
      setTimeout(() => navigate('/p'), 900)
    } catch (err) {
      const message = err?.response?.data?.message || err?.message || 'Submission failed.'
      setStepError(message)
    } finally {
      setSubmitting(false)
    }
  }

  const renderStep = useCallback(() => {
    const step = STEPS[stepIndex].key
    if (step === 'type') {
      return (
        <div className="space-y-4">
          <p className="text-sm text-gray-600">
            What kind of help do you need? Pick the closest match — we&rsquo;ll narrow it down on the next step.
          </p>
          {loadingTypes ? (
            <Loading text="Loading request types…" />
          ) : requestTypes.length === 0 ? (
            <Alert variant="info" closable={false}>
              No portal-visible request types are configured yet.
            </Alert>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {requestTypes.map((rt) => {
                const selected = String(rt.id) === String(form.requestTypeId)
                return (
                  <button
                    key={rt.id}
                    type="button"
                    onClick={() => updateField('requestTypeId')(String(rt.id))}
                    className={`text-left rounded-lg border px-4 py-3 transition ${
                      selected
                        ? 'border-blue-600 ring-2 ring-blue-200 bg-blue-50'
                        : 'border-gray-200 hover:border-gray-400'
                    }`}
                  >
                    <p className="font-semibold text-gray-900">{rt.name}</p>
                    {rt.description && (
                      <p className="mt-1 text-sm text-gray-600">{rt.description}</p>
                    )}
                  </button>
                )
              })}
            </div>
          )}
        </div>
      )
    }

    if (step === 'details') {
      return (
        <div className="space-y-4">
          <Select
            label="Subcategory (optional)"
            modelValue={form.subcategoryId}
            options={[
              { value: '', label: loadingSubs ? 'Loading…' : 'No subcategory' },
              ...subcategories.map((s) => ({ value: String(s.id), label: s.name })),
            ]}
            onUpdateModelValue={updateField('subcategoryId')}
          />
          <Input
            label="Title"
            required
            placeholder="One-line summary of what you need"
            modelValue={form.title}
            onUpdateModelValue={updateField('title')}
          />
          <Textarea
            label="Description (optional)"
            placeholder="Tell us more — error messages, what you've tried, who's affected."
            modelValue={form.description}
            onUpdateModelValue={updateField('description')}
          />
          <Select
            label="Priority"
            modelValue={form.priority}
            options={[{ value: '', label: 'Use default' }, ...PORTAL_PRIORITIES]}
            onUpdateModelValue={updateField('priority')}
          />
          <hr className="my-2 border-gray-200" />
          <div>
            <p className="text-sm font-semibold text-gray-900">IT helpdesk details (optional)</p>
            <p className="text-xs text-gray-500">
              Pick a request kind to enable IT-specific routing and severity tracking.
            </p>
          </div>
          <Select
            label="IT request kind"
            modelValue={form.it_request_kind}
            options={[{ value: '', label: 'Not an IT request' }, ...IT_REQUEST_KINDS]}
            onUpdateModelValue={(value) => {
              setForm((prev) => ({
                ...prev,
                it_request_kind: value,
                severity: value ? prev.severity : '',
                affected_users_count: value ? prev.affected_users_count : '',
                business_impact: value ? prev.business_impact : '',
              }))
            }}
          />
          {isItKindSelected && (
            <>
              <Select
                label="Severity"
                modelValue={form.severity}
                options={[{ value: '', label: 'Auto-derive from kind / impact' }, ...SEVERITIES]}
                onUpdateModelValue={updateField('severity')}
                helperText="P1 is reserved for IT staff after triage."
              />
              <Input
                label="Affected users (estimate)"
                type="number"
                min="0"
                modelValue={form.affected_users_count}
                onUpdateModelValue={updateField('affected_users_count')}
                helperText="50+ auto-bumps to P2; 200+ to P1."
              />
              <Textarea
                label={requiresBusinessImpact ? 'Business impact' : 'Business impact (optional)'}
                required={requiresBusinessImpact}
                placeholder="e.g. Sales floor offline, ~25 users blocked from POS"
                modelValue={form.business_impact}
                onUpdateModelValue={updateField('business_impact')}
                helperText={requiresBusinessImpact ? 'Required for outages and P1/P2 tickets.' : 'Optional — gives the IT team context for triage.'}
              />
            </>
          )}
        </div>
      )
    }

    if (step === 'location') {
      return (
        <div className="space-y-4">
          <p className="text-sm text-gray-600">
            Where is this happening? Site and asset are optional — leave blank if it&rsquo;s not location-specific.
          </p>
          <Select
            label="Site (optional)"
            modelValue={form.site_id}
            options={[
              { value: '', label: loadingSites ? 'Loading sites…' : 'No specific site' },
              ...sites.map((s) => ({ value: String(s.id), label: s.name })),
            ]}
            onUpdateModelValue={(value) => {
              setForm((prev) => ({ ...prev, site_id: value, asset_id: '' }))
            }}
          />
          {form.site_id && (
            <Select
              label="Asset (optional)"
              modelValue={form.asset_id}
              options={[
                { value: '', label: loadingAssets ? 'Loading assets…' : 'No specific asset' },
                ...assets.map((a) => ({
                  value: String(a.id),
                  label: a.label || a.name || a.tag || `Asset #${a.id}`,
                })),
              ]}
              onUpdateModelValue={updateField('asset_id')}
            />
          )}
        </div>
      )
    }

    if (step === 'review') {
      return (
        <div className="space-y-3 text-sm">
          <ReviewRow label="Request type" value={selectedType?.name || '—'} />
          <ReviewRow label="Subcategory" value={selectedSubcategory?.name || 'None'} />
          <ReviewRow label="Title" value={form.title} />
          {form.description && <ReviewRow label="Description" value={form.description} />}
          {form.priority && (
            <ReviewRow
              label="Priority"
              value={PORTAL_PRIORITIES.find((p) => p.value === form.priority)?.label}
            />
          )}
          {isItKindSelected && (
            <>
              <ReviewRow
                label="IT request kind"
                value={IT_REQUEST_KINDS.find((k) => k.value === form.it_request_kind)?.label}
              />
              <ReviewRow
                label="Severity"
                value={
                  form.severity
                    ? SEVERITIES.find((s) => s.value === form.severity)?.label
                    : 'Auto-derive from kind / impact'
                }
              />
              {form.affected_users_count !== '' && (
                <ReviewRow label="Affected users" value={form.affected_users_count} />
              )}
              {form.business_impact && (
                <ReviewRow label="Business impact" value={form.business_impact} />
              )}
            </>
          )}
          {selectedSite && <ReviewRow label="Site" value={selectedSite.name} />}
          {selectedAsset && (
            <ReviewRow
              label="Asset"
              value={selectedAsset.label || selectedAsset.name || `#${selectedAsset.id}`}
            />
          )}
        </div>
      )
    }

    return null
  }, [
    stepIndex, form, requestTypes, subcategories, sites, assets,
    loadingTypes, loadingSubs, loadingSites, loadingAssets,
    isItKindSelected, requiresBusinessImpact,
    selectedType, selectedSubcategory, selectedSite, selectedAsset,
  ])

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">Submit a request</h1>
        <p className="mt-1 text-sm text-gray-600">
          Open a ticket with our service team. We&rsquo;ll route it to the right queue and follow up.
        </p>
      </header>

      <Card>
        <ol className="mb-6 flex flex-wrap items-center gap-2 text-xs font-medium">
          {STEPS.map((s, idx) => {
            const active = idx === stepIndex
            const done = idx < stepIndex
            return (
              <li
                key={s.key}
                className={`flex items-center gap-2 rounded-full px-3 py-1 ${
                  active
                    ? 'bg-blue-600 text-white'
                    : done
                    ? 'bg-green-100 text-green-800'
                    : 'bg-gray-100 text-gray-600'
                }`}
              >
                <span className="flex h-5 w-5 items-center justify-center rounded-full bg-white/40 text-[10px]">
                  {idx + 1}
                </span>
                {s.label}
              </li>
            )
          })}
        </ol>

        {stepError && (
          <div className="mb-4">
            <Alert variant="error" closable={false}>{stepError}</Alert>
          </div>
        )}
        {submitSuccess && (
          <div className="mb-4">
            <Alert variant="success" closable={false}>
              Request submitted — ticket {submitSuccess}. Returning home…
            </Alert>
          </div>
        )}

        {renderStep()}

        <div className="mt-6 flex items-center justify-between">
          <Button variant="ghost" onClick={() => navigate('/p')} disabled={submitting}>
            Cancel
          </Button>
          <div className="flex items-center gap-2">
            {stepIndex > 0 && (
              <Button variant="outline" onClick={goBack} disabled={submitting}>
                Back
              </Button>
            )}
            {stepIndex < STEPS.length - 1 ? (
              <Button onClick={goNext} disabled={submitting}>Next</Button>
            ) : (
              <Button onClick={submit} loading={submitting} disabled={submitting}>
                Submit request
              </Button>
            )}
          </div>
        </div>
      </Card>
    </div>
  )
}

function ReviewRow({ label, value }) {
  return (
    <div className="grid grid-cols-3 gap-3 border-b border-gray-100 py-2 last:border-b-0">
      <dt className="col-span-1 text-gray-500">{label}</dt>
      <dd className="col-span-2 whitespace-pre-wrap text-gray-900">{value || '—'}</dd>
    </div>
  )
}
