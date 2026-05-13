import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import contractsService from '../../../services/contracts.service'
import crmService from '../../../services/crm.service'
import { useToast } from '../../stores/toast.jsx'

const STATUS_BADGE = {
  draft: 'default',
  pending_signature: 'warning',
  active: 'success',
  expired: 'danger',
  terminated: 'danger',
}

const ENTITLEMENT_TYPES = [
  { value: 'labor_hours', label: 'Labor hours' },
  { value: 'parts_dollars', label: 'Parts dollars' },
  { value: 'response_minutes', label: 'Response minutes' },
  { value: 'resolution_minutes', label: 'Resolution minutes' },
  { value: 'pm_visits', label: 'PM visits' },
  { value: 'other', label: 'Other' },
]

const ENTITLEMENT_PERIODS = [
  { value: 'per_month', label: 'Per month' },
  { value: 'per_quarter', label: 'Per quarter' },
  { value: 'per_year', label: 'Per year' },
  { value: 'lifetime', label: 'Lifetime' },
]

const TABS = [
  { key: 'overview', label: 'Overview' },
  { key: 'sites', label: 'Sites Covered' },
  { key: 'entitlements', label: 'Entitlements' },
  { key: 'amendments', label: 'Amendments' },
  { key: 'signing', label: 'Signing' },
]

function formatDateTime(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString()
}

function statusLabel(s) {
  if (!s) return '—'
  return String(s).replace(/_/g, ' ')
}

function formatDate(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString()
}

function unwrap(res) {
  if (Array.isArray(res)) return res
  if (Array.isArray(res?.data)) return res.data
  if (Array.isArray(res?.items)) return res.items
  return []
}

function entitlementTypeLabel(v) {
  return ENTITLEMENT_TYPES.find((t) => t.value === v)?.label || v || '—'
}

function entitlementPeriodLabel(v) {
  return ENTITLEMENT_PERIODS.find((t) => t.value === v)?.label || v || '—'
}

export default function ContractDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [contract, setContract] = useState(null)
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState('overview')
  const [deleteModal, setDeleteModal] = useState(false)
  const [deleting, setDeleting] = useState(false)

  // Sites
  const [sites, setSites] = useState([])
  const [sitesLoading, setSitesLoading] = useState(false)
  const [companySites, setCompanySites] = useState([])
  const [addSiteOpen, setAddSiteOpen] = useState(false)
  const [addSiteId, setAddSiteId] = useState('')
  const [addSiteBusy, setAddSiteBusy] = useState(false)
  const [removeSiteModal, setRemoveSiteModal] = useState({ open: false, site: null })
  const [removeSiteBusy, setRemoveSiteBusy] = useState(false)

  // Entitlements
  const [entitlements, setEntitlements] = useState([])
  const [entitlementsLoading, setEntitlementsLoading] = useState(false)
  const [addEntOpen, setAddEntOpen] = useState(false)
  const [addEntBusy, setAddEntBusy] = useState(false)
  const [entForm, setEntForm] = useState({
    entitlement_type: 'labor_hours',
    included_quantity: '',
    period: 'per_month',
    notes: '',
  })

  // Amendments
  const [amendments, setAmendments] = useState([])
  const [amendmentsLoading, setAmendmentsLoading] = useState(false)
  const [addAmendOpen, setAddAmendOpen] = useState(false)
  const [addAmendBusy, setAddAmendBusy] = useState(false)
  const [amendForm, setAmendForm] = useState({
    effective_date: '',
    summary: '',
    changes: '',
  })

  // Signing — signers (R-02c roster), legacy links, signature audit
  const [signers, setSigners] = useState([])
  const [signersLoading, setSignersLoading] = useState(false)
  const [inviteOpen, setInviteOpen] = useState(false)
  const [inviteBusy, setInviteBusy] = useState(false)
  const [inviteForm, setInviteForm] = useState({
    email: '',
    name: '',
    title: '',
    expires_at: '',
    send_email: true,
    notes: '',
  })
  const [invitedResult, setInvitedResult] = useState(null)
  const [links, setLinks] = useState([])
  const [linksLoading, setLinksLoading] = useState(false)
  const [issueLinkOpen, setIssueLinkOpen] = useState(false)
  const [issueLinkBusy, setIssueLinkBusy] = useState(false)
  const [issueLinkExpires, setIssueLinkExpires] = useState('')
  const [issuedLink, setIssuedLink] = useState(null)
  const [signatures, setSignatures] = useState([])
  const [signaturesLoading, setSignaturesLoading] = useState(false)

  const loadContract = useCallback(async () => {
    setLoading(true)
    try {
      const data = await contractsService.get(id)
      setContract(data)
    } catch {
      error('Failed to load contract')
      navigate('/cp/contracts')
    } finally {
      setLoading(false)
    }
  }, [id, error, navigate])

  useEffect(() => {
    loadContract()
  }, [loadContract])

  const loadSites = useCallback(async () => {
    setSitesLoading(true)
    try {
      const res = await contractsService.listSites(id)
      setSites(unwrap(res))
    } catch {
      error('Failed to load sites')
      setSites([])
    } finally {
      setSitesLoading(false)
    }
  }, [id, error])

  const loadEntitlements = useCallback(async () => {
    setEntitlementsLoading(true)
    try {
      const res = await contractsService.listEntitlements(id)
      setEntitlements(unwrap(res))
    } catch {
      error('Failed to load entitlements')
      setEntitlements([])
    } finally {
      setEntitlementsLoading(false)
    }
  }, [id, error])

  const loadAmendments = useCallback(async () => {
    setAmendmentsLoading(true)
    try {
      const res = await contractsService.listAmendments(id)
      setAmendments(unwrap(res))
    } catch {
      error('Failed to load amendments')
      setAmendments([])
    } finally {
      setAmendmentsLoading(false)
    }
  }, [id, error])

  const loadLinks = useCallback(async () => {
    setLinksLoading(true)
    try {
      const res = await contractsService.listLinks(id)
      setLinks(unwrap(res))
    } catch {
      error('Failed to load signing links')
      setLinks([])
    } finally {
      setLinksLoading(false)
    }
  }, [id, error])

  const loadSigners = useCallback(async () => {
    setSignersLoading(true)
    try {
      const res = await contractsService.listSigners(id, true)
      setSigners(unwrap(res))
    } catch {
      error('Failed to load signers')
      setSigners([])
    } finally {
      setSignersLoading(false)
    }
  }, [id, error])

  const loadSignatures = useCallback(async () => {
    setSignaturesLoading(true)
    try {
      const res = await contractsService.listSignatures(id)
      setSignatures(unwrap(res))
    } catch {
      error('Failed to load signatures')
      setSignatures([])
    } finally {
      setSignaturesLoading(false)
    }
  }, [id, error])

  useEffect(() => {
    if (tab === 'sites') loadSites()
    if (tab === 'entitlements') loadEntitlements()
    if (tab === 'amendments') loadAmendments()
    if (tab === 'signing') {
      loadSigners()
      loadLinks()
      loadSignatures()
    }
  }, [tab, loadSites, loadEntitlements, loadAmendments, loadSigners, loadLinks, loadSignatures])

  // Load company sites for "Add Site" picker once contract + tab known
  useEffect(() => {
    if (tab !== 'sites' || !contract?.company_id) return
    crmService
      .listSitesForCompany(contract.company_id, { limit: 500 })
      .then((res) => setCompanySites(unwrap(res)))
      .catch(() => setCompanySites([]))
  }, [tab, contract?.company_id])

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await contractsService.delete(id)
      success('Contract deleted')
      navigate('/cp/contracts')
    } catch {
      error('Failed to delete contract')
    } finally {
      setDeleting(false)
    }
  }

  const handleAddSite = async () => {
    if (!addSiteId) return
    setAddSiteBusy(true)
    try {
      await contractsService.addSite(id, { site_id: Number(addSiteId) })
      success('Site added')
      setAddSiteOpen(false)
      setAddSiteId('')
      loadSites()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to add site')
    } finally {
      setAddSiteBusy(false)
    }
  }

  const handleRemoveSite = async () => {
    const site = removeSiteModal.site
    if (!site) return
    setRemoveSiteBusy(true)
    try {
      await contractsService.removeSite(id, site.site_id ?? site.id)
      success('Site removed')
      setRemoveSiteModal({ open: false, site: null })
      loadSites()
    } catch {
      error('Failed to remove site')
    } finally {
      setRemoveSiteBusy(false)
    }
  }

  const handleAddEntitlement = async () => {
    if (!entForm.entitlement_type || entForm.included_quantity === '') {
      error('Type and quantity are required')
      return
    }
    setAddEntBusy(true)
    try {
      await contractsService.addEntitlement(id, {
        entitlement_type: entForm.entitlement_type,
        included_quantity: Number(entForm.included_quantity),
        period: entForm.period,
        notes: entForm.notes,
      })
      success('Entitlement added')
      setAddEntOpen(false)
      setEntForm({ entitlement_type: 'labor_hours', included_quantity: '', period: 'per_month', notes: '' })
      loadEntitlements()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to add entitlement')
    } finally {
      setAddEntBusy(false)
    }
  }

  const handleIssueLink = async () => {
    setIssueLinkBusy(true)
    try {
      const payload = issueLinkExpires ? { expires_at: issueLinkExpires } : {}
      const res = await contractsService.issueLink(id, payload)
      const data = res?.data ?? res
      setIssuedLink(data)
      setIssueLinkOpen(false)
      setIssueLinkExpires('')
      loadLinks()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to issue signing link')
    } finally {
      setIssueLinkBusy(false)
    }
  }

  const handleRevokeLink = async (link) => {
    if (!link?.id) return
    if (!window.confirm('Revoke this signing link? Existing recipients will no longer be able to sign with it.')) {
      return
    }
    try {
      await contractsService.revokeLink(id, link.id)
      success('Signing link revoked')
      loadLinks()
    } catch {
      error('Failed to revoke link')
    }
  }

  const handleInviteSigner = async () => {
    if (!inviteForm.email.trim() || !inviteForm.name.trim()) {
      error('Signer name and email are required')
      return
    }
    setInviteBusy(true)
    try {
      const payload = {
        email: inviteForm.email.trim(),
        name: inviteForm.name.trim(),
        send_email: inviteForm.send_email,
      }
      if (inviteForm.title.trim()) payload.title = inviteForm.title.trim()
      if (inviteForm.notes.trim()) payload.notes = inviteForm.notes.trim()
      if (inviteForm.expires_at) payload.expires_at = inviteForm.expires_at
      const res = await contractsService.inviteSigner(id, payload)
      const data = res?.data ?? res
      setInvitedResult(data)
      setInviteOpen(false)
      setInviteForm({ email: '', name: '', title: '', expires_at: '', send_email: true, notes: '' })
      if (data?.email_sent) {
        success(`Invitation sent to ${payload.email}`)
      } else if (data?.email_error) {
        error(`Invite created, but email failed: ${data.email_error}`)
      } else {
        success('Signer invited — copy the sign URL to share manually')
      }
      loadSigners()
      loadLinks()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to invite signer')
    } finally {
      setInviteBusy(false)
    }
  }

  const handleRevokeSigner = async (signer) => {
    if (!signer?.id) return
    if (!window.confirm(`Revoke invitation for ${signer.email}? Any bound signing link will also be revoked.`)) {
      return
    }
    try {
      await contractsService.revokeSigner(id, signer.id)
      success('Signer revoked')
      loadSigners()
      loadLinks()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to revoke signer')
    }
  }

  const handleCopy = async (text) => {
    if (!text) return
    try {
      await navigator.clipboard.writeText(text)
      success('Copied to clipboard')
    } catch {
      error('Could not access clipboard')
    }
  }

  const handleAddAmendment = async () => {
    if (!amendForm.effective_date || !amendForm.summary.trim()) {
      error('Effective date and summary are required')
      return
    }
    setAddAmendBusy(true)
    try {
      await contractsService.createAmendment(id, {
        effective_date: amendForm.effective_date,
        summary: amendForm.summary.trim(),
        changes: amendForm.changes,
      })
      success('Amendment created')
      setAddAmendOpen(false)
      setAmendForm({ effective_date: '', summary: '', changes: '' })
      loadAmendments()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to create amendment')
    } finally {
      setAddAmendBusy(false)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading contract..." />
      </div>
    )
  }

  if (!contract) {
    return (
      <div className="text-center py-12">
        <h3 className="text-lg font-medium text-gray-900">Contract not found</h3>
        <div className="mt-4">
          <Link to="/cp/contracts">
            <Button>Back to Contracts</Button>
          </Link>
        </div>
      </div>
    )
  }

  const availableSites = companySites.filter(
    (s) => !sites.some((bound) => String(bound.site_id ?? bound.id) === String(s.id)),
  )

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link to="/cp/contracts" className="text-gray-500 hover:text-gray-700">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{contract.name || `Contract #${contract.id}`}</h1>
            <p className="mt-1 text-sm text-gray-500">
              <Badge size="sm" variant={STATUS_BADGE[contract.status] || 'default'}>
                {statusLabel(contract.status)}
              </Badge>
            </p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => navigate(`/cp/contracts/${id}/edit`)}>
            Edit
          </Button>
          <Button variant="danger" onClick={() => setDeleteModal(true)}>
            Delete
          </Button>
        </div>
      </div>

      <div className="border-b border-gray-200">
        <nav className="-mb-px flex gap-6">
          {TABS.map((t) => (
            <button
              key={t.key}
              type="button"
              onClick={() => setTab(t.key)}
              className={`py-3 border-b-2 text-sm font-medium ${
                tab === t.key
                  ? 'border-primary-500 text-primary-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {tab === 'overview' ? (
        <Card>
          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">Name</dt>
              <dd className="mt-1 text-sm text-gray-900">{contract.name || '—'}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Company</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {contract.company_id ? (
                  <Link
                    to={`/cp/crm/companies/${contract.company_id}`}
                    className="text-primary-600 hover:text-primary-500"
                  >
                    {contract.company_name || `Company #${contract.company_id}`}
                  </Link>
                ) : '—'}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Status</dt>
              <dd className="mt-1">
                <Badge size="sm" variant={STATUS_BADGE[contract.status] || 'default'}>
                  {statusLabel(contract.status)}
                </Badge>
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Term</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {contract.term_months ? `${contract.term_months} months` : '—'}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Start Date</dt>
              <dd className="mt-1 text-sm text-gray-900">{formatDate(contract.start_date)}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">End Date</dt>
              <dd className="mt-1 text-sm text-gray-900">{formatDate(contract.end_date)}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Auto-renew</dt>
              <dd className="mt-1 text-sm text-gray-900">{contract.auto_renew ? 'Yes' : 'No'}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Created</dt>
              <dd className="mt-1 text-sm text-gray-900">{formatDate(contract.created_at)}</dd>
            </div>
            {contract.billing_terms ? (
              <div className="sm:col-span-2">
                <dt className="text-sm font-medium text-gray-500">Billing Terms</dt>
                <dd className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{contract.billing_terms}</dd>
              </div>
            ) : null}
            {contract.notes ? (
              <div className="sm:col-span-2">
                <dt className="text-sm font-medium text-gray-500">Notes</dt>
                <dd className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{contract.notes}</dd>
              </div>
            ) : null}
          </dl>
        </Card>
      ) : null}

      {tab === 'sites' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Sites Covered</h3>
            <Button size="sm" onClick={() => setAddSiteOpen(true)} disabled={!contract.company_id}>
              Add Site
            </Button>
          </div>
          {sitesLoading ? (
            <div className="py-6 flex justify-center"><Loading /></div>
          ) : sites.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No sites bound to this contract.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Site</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                    <th className="px-4 py-3"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {sites.map((s) => (
                    <tr key={s.id ?? s.site_id}>
                      <td className="px-4 py-3 text-sm text-gray-900">{s.site_name || s.name || `Site #${s.site_id ?? s.id}`}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{s.address || s.city || '—'}</td>
                      <td className="px-4 py-3 text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-red-600 hover:text-red-700"
                          onClick={() => setRemoveSiteModal({ open: true, site: s })}
                        >
                          Remove
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : null}

      {tab === 'entitlements' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Entitlements</h3>
            <Button size="sm" onClick={() => setAddEntOpen(true)}>Add Entitlement</Button>
          </div>
          {entitlementsLoading ? (
            <div className="py-6 flex justify-center"><Loading /></div>
          ) : entitlements.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No entitlements defined.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Included</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Used</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {entitlements.map((e) => (
                    <tr key={e.id}>
                      <td className="px-4 py-3 text-sm text-gray-900">{entitlementTypeLabel(e.entitlement_type)}</td>
                      <td className="px-4 py-3 text-sm text-gray-700">{e.included_quantity ?? '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-700">{e.used_quantity ?? 0}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{entitlementPeriodLabel(e.period)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : null}

      {tab === 'amendments' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Amendments</h3>
            <Button size="sm" onClick={() => setAddAmendOpen(true)}>New Amendment</Button>
          </div>
          {amendmentsLoading ? (
            <div className="py-6 flex justify-center"><Loading /></div>
          ) : amendments.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No amendments recorded.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Effective Date</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Summary</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Signed By</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {amendments.map((a) => (
                    <tr key={a.id}>
                      <td className="px-4 py-3 text-sm text-gray-900">{formatDate(a.effective_date)}</td>
                      <td className="px-4 py-3 text-sm text-gray-700">{a.summary || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{a.signed_by || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : null}

      {tab === 'signing' ? (
        <div className="space-y-6">
          <Card>
            <div className="flex justify-between items-center mb-4">
              <div>
                <h3 className="text-lg font-medium text-gray-900">Signers</h3>
                <p className="text-xs text-gray-500 mt-0.5">
                  Invite one row per signer. Each invitation creates a private link
                  bound to that signer&apos;s email — no one else can use it.
                </p>
              </div>
              <Button size="sm" onClick={() => setInviteOpen(true)}>
                Invite Signer
              </Button>
            </div>
            {signersLoading ? (
              <div className="py-6 flex justify-center"><Loading /></div>
            ) : signers.length === 0 ? (
              <p className="text-gray-500 text-sm py-4">No signers invited yet.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invited</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Signed</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                      <th className="px-4 py-3"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 bg-white">
                    {signers.map((signer) => {
                      const status = signer.status
                        || (signer.revoked_at ? 'revoked' : signer.signed_at ? 'signed' : 'invited')
                      return (
                        <tr key={signer.id}>
                          <td className="px-4 py-3 text-sm text-gray-500">{signer.display_order ?? '—'}</td>
                          <td className="px-4 py-3 text-sm text-gray-900">
                            <div className="font-medium">{signer.name}</div>
                            {signer.title && (
                              <div className="text-xs text-gray-500">{signer.title}</div>
                            )}
                          </td>
                          <td className="px-4 py-3 text-sm text-gray-500">{signer.email}</td>
                          <td className="px-4 py-3 text-sm text-gray-500">{formatDateTime(signer.invited_at)}</td>
                          <td className="px-4 py-3 text-sm text-gray-500">{formatDateTime(signer.signed_at)}</td>
                          <td className="px-4 py-3 text-sm">
                            {status === 'signed' ? (
                              <Badge size="sm" variant="success">signed</Badge>
                            ) : status === 'revoked' ? (
                              <Badge size="sm" variant="danger">revoked</Badge>
                            ) : (
                              <Badge size="sm" variant="warning">invited</Badge>
                            )}
                          </td>
                          <td className="px-4 py-3 text-right whitespace-nowrap">
                            {status === 'invited' && (
                              <Button
                                size="sm"
                                variant="ghost"
                                className="text-red-600 hover:text-red-700"
                                onClick={() => handleRevokeSigner(signer)}
                              >
                                Revoke
                              </Button>
                            )}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          <Card>
            <div className="flex justify-between items-center mb-4">
              <div>
                <h3 className="text-lg font-medium text-gray-900">Legacy Signing Links</h3>
                <p className="text-xs text-gray-500 mt-0.5">
                  Open (unbound) links — prefer the Signers panel above for new
                  invitations. Use this only when you need an &ldquo;any signer&rdquo; link.
                </p>
              </div>
              <Button size="sm" variant="ghost" onClick={() => setIssueLinkOpen(true)}>
                Issue Open Link
              </Button>
            </div>
            {linksLoading ? (
              <div className="py-6 flex justify-center"><Loading /></div>
            ) : links.length === 0 ? (
              <p className="text-gray-500 text-sm py-4">No signing links issued.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Short Code</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bound To</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Accessed</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                      <th className="px-4 py-3"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 bg-white">
                    {links.map((link) => {
                      const revoked = !!link.revoked_at
                      const expired = link.expires_at && new Date(link.expires_at) < new Date()
                      const consumed = !!link.consumed_at
                      const shortUrl = `${window.location.origin}/c/${link.short_code}`
                      return (
                        <tr key={link.id}>
                          <td className="px-4 py-3 text-sm font-mono text-gray-900">{link.short_code}</td>
                          <td className="px-4 py-3 text-sm text-gray-500">
                            {link.signer_email ? (
                              <span title="Bound to this signer only">{link.signer_email}</span>
                            ) : (
                              <span className="italic text-gray-400">open</span>
                            )}
                          </td>
                          <td className="px-4 py-3 text-sm text-gray-500">{formatDateTime(link.created_at)}</td>
                          <td className="px-4 py-3 text-sm text-gray-500">{formatDateTime(link.expires_at)}</td>
                          <td className="px-4 py-3 text-sm text-gray-500">{formatDateTime(link.last_accessed_at)}</td>
                          <td className="px-4 py-3 text-sm">
                            {revoked ? (
                              <Badge size="sm" variant="danger">revoked</Badge>
                            ) : consumed ? (
                              <Badge size="sm" variant="default">used</Badge>
                            ) : expired ? (
                              <Badge size="sm" variant="warning">expired</Badge>
                            ) : (
                              <Badge size="sm" variant="success">active</Badge>
                            )}
                          </td>
                          <td className="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                            <Button size="sm" variant="ghost" onClick={() => handleCopy(shortUrl)}>
                              Copy URL
                            </Button>
                            {!revoked && (
                              <Button
                                size="sm"
                                variant="ghost"
                                className="text-red-600 hover:text-red-700"
                                onClick={() => handleRevokeLink(link)}
                              >
                                Revoke
                              </Button>
                            )}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          <Card>
            <div className="mb-4">
              <h3 className="text-lg font-medium text-gray-900">Signature Audit</h3>
              <p className="text-xs text-gray-500 mt-0.5">
                Append-only record of every captured signature, with IP, user agent,
                and forensic hashes for the document and signature.
              </p>
            </div>
            {signaturesLoading ? (
              <div className="py-6 flex justify-center"><Loading /></div>
            ) : signatures.length === 0 ? (
              <p className="text-gray-500 text-sm py-4">No signatures captured yet.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Signer</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Signed At</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Consent</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Document Hash</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 bg-white">
                    {signatures.map((sig) => (
                      <tr key={sig.id}>
                        <td className="px-4 py-3 text-sm text-gray-900">
                          <div className="font-medium">{sig.signer_name || '—'}</div>
                          {sig.signer_title && (
                            <div className="text-xs text-gray-500">{sig.signer_title}</div>
                          )}
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-500">{sig.signer_email || '—'}</td>
                        <td className="px-4 py-3 text-sm text-gray-500">{formatDateTime(sig.signed_at)}</td>
                        <td className="px-4 py-3 text-sm text-gray-500 font-mono">{sig.ip_address || '—'}</td>
                        <td className="px-4 py-3 text-sm">
                          {sig.legal_consent ? (
                            <Badge size="sm" variant="success">yes</Badge>
                          ) : (
                            <Badge size="sm" variant="warning">no</Badge>
                          )}
                        </td>
                        <td
                          className="px-4 py-3 text-xs text-gray-500 font-mono truncate max-w-xs"
                          title={sig.document_hash || ''}
                        >
                          {sig.document_hash ? `${sig.document_hash.slice(0, 16)}…` : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </div>
      ) : null}

      <Modal open={inviteOpen} title="Invite Signer" onClose={() => setInviteOpen(false)}>
        <div className="space-y-3">
          <p className="text-sm text-gray-600">
            Send a signing invitation. The link is bound to this signer&apos;s email —
            no one else can use it, even with the URL.
          </p>
          <Input
            label="Signer Name"
            value={inviteForm.name}
            onUpdateModelValue={(v) => setInviteForm((f) => ({ ...f, name: v }))}
            required
          />
          <Input
            label="Email"
            type="email"
            value={inviteForm.email}
            onUpdateModelValue={(v) => setInviteForm((f) => ({ ...f, email: v }))}
            required
          />
          <Input
            label="Title (optional)"
            value={inviteForm.title}
            onUpdateModelValue={(v) => setInviteForm((f) => ({ ...f, title: v }))}
          />
          <Input
            label="Expires At (optional)"
            type="datetime-local"
            value={inviteForm.expires_at}
            onUpdateModelValue={(v) => setInviteForm((f) => ({ ...f, expires_at: v }))}
          />
          <Textarea
            label="Notes (optional, internal)"
            value={inviteForm.notes}
            onUpdateModelValue={(v) => setInviteForm((f) => ({ ...f, notes: v }))}
            rows={2}
          />
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={inviteForm.send_email}
              onChange={(e) => setInviteForm((f) => ({ ...f, send_email: e.target.checked }))}
              className="rounded border-gray-300"
            />
            Send invitation email automatically
          </label>
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setInviteOpen(false)}>Cancel</Button>
            <Button loading={inviteBusy} onClick={handleInviteSigner}>Send Invitation</Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={!!invitedResult}
        title="Signer Invited"
        onClose={() => setInvitedResult(null)}
      >
        <div className="space-y-4">
          <div className="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            This is the only time the plaintext sign URL will be shown. Copy it now
            if you need to share manually.
            {invitedResult?.email_sent ? (
              <div className="mt-1 font-medium">Invitation email was sent successfully.</div>
            ) : invitedResult?.email_error ? (
              <div className="mt-1 font-medium text-red-700">
                Email delivery failed: {invitedResult.email_error}. Share the URL manually.
              </div>
            ) : (
              <div className="mt-1 font-medium">Automatic email was disabled — share the URL manually.</div>
            )}
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 uppercase mb-1">Signer</label>
            <div className="text-sm text-gray-900">
              {invitedResult?.signer?.name} &lt;{invitedResult?.signer?.email}&gt;
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 uppercase mb-1">Sign URL (short)</label>
            <div className="flex gap-2">
              <input
                readOnly
                value={invitedResult?.short_url || ''}
                className="flex-1 rounded border border-gray-300 px-3 py-2 text-sm font-mono bg-gray-50"
              />
              <Button size="sm" variant="secondary" onClick={() => handleCopy(invitedResult?.short_url)}>
                Copy
              </Button>
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 uppercase mb-1">Sign URL (secure / long token)</label>
            <div className="flex gap-2">
              <input
                readOnly
                value={invitedResult?.secure_url || ''}
                className="flex-1 rounded border border-gray-300 px-3 py-2 text-sm font-mono bg-gray-50"
              />
              <Button size="sm" variant="secondary" onClick={() => handleCopy(invitedResult?.secure_url)}>
                Copy
              </Button>
            </div>
          </div>
          <div className="flex justify-end">
            <Button onClick={() => setInvitedResult(null)}>Done</Button>
          </div>
        </div>
      </Modal>

      <Modal open={issueLinkOpen} title="Issue Signing Link" onClose={() => setIssueLinkOpen(false)}>
        <div className="space-y-3">
          <p className="text-sm text-gray-600">
            Issue a fresh link for this contract. The plaintext token is shown only
            once after creation — store it safely or send it directly to the signer.
          </p>
          <Input
            label="Expires At (optional)"
            type="datetime-local"
            value={issueLinkExpires}
            onUpdateModelValue={setIssueLinkExpires}
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setIssueLinkOpen(false)}>Cancel</Button>
            <Button loading={issueLinkBusy} onClick={handleIssueLink}>Issue Link</Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={!!issuedLink}
        title="Signing Link Created"
        onClose={() => setIssuedLink(null)}
      >
        <div className="space-y-4">
          <div className="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            This is the only time the plaintext token will be shown. Copy it now.
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 uppercase mb-1">Short URL</label>
            <div className="flex gap-2">
              <input
                readOnly
                value={issuedLink?.short_url || ''}
                className="flex-1 rounded border border-gray-300 px-3 py-2 text-sm font-mono bg-gray-50"
              />
              <Button size="sm" variant="secondary" onClick={() => handleCopy(issuedLink?.short_url)}>
                Copy
              </Button>
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 uppercase mb-1">Secure URL (long token)</label>
            <div className="flex gap-2">
              <input
                readOnly
                value={issuedLink?.secure_url || ''}
                className="flex-1 rounded border border-gray-300 px-3 py-2 text-sm font-mono bg-gray-50"
              />
              <Button size="sm" variant="secondary" onClick={() => handleCopy(issuedLink?.secure_url)}>
                Copy
              </Button>
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 uppercase mb-1">Token</label>
            <div className="flex gap-2">
              <input
                readOnly
                value={issuedLink?.token || ''}
                className="flex-1 rounded border border-gray-300 px-3 py-2 text-sm font-mono bg-gray-50"
              />
              <Button size="sm" variant="secondary" onClick={() => handleCopy(issuedLink?.token)}>
                Copy
              </Button>
            </div>
          </div>
          <div className="flex justify-end">
            <Button onClick={() => setIssuedLink(null)}>Done</Button>
          </div>
        </div>
      </Modal>

      <Modal open={deleteModal} title="Delete Contract" onClose={() => setDeleteModal(false)}>
        <p className="text-sm text-gray-600 mb-4">
          Are you sure you want to delete <strong>{contract.name || `Contract #${contract.id}`}</strong>? This action cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModal(false)}>Cancel</Button>
          <Button variant="danger" loading={deleting} onClick={handleDelete}>Delete</Button>
        </div>
      </Modal>

      <Modal open={addSiteOpen} title="Add Site to Contract" onClose={() => setAddSiteOpen(false)}>
        <div className="space-y-3">
          <Select
            label="Site"
            value={addSiteId}
            onUpdateModelValue={setAddSiteId}
            placeholder={availableSites.length === 0 ? 'No available sites' : 'Select a site'}
            options={availableSites.map((s) => ({ value: String(s.id), label: s.name || `Site #${s.id}` }))}
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setAddSiteOpen(false)}>Cancel</Button>
            <Button loading={addSiteBusy} disabled={!addSiteId} onClick={handleAddSite}>Add</Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={removeSiteModal.open}
        title="Remove Site"
        onClose={() => setRemoveSiteModal({ open: false, site: null })}
      >
        <p className="text-sm text-gray-600 mb-4">
          Remove <strong>{removeSiteModal.site?.site_name || removeSiteModal.site?.name || 'this site'}</strong> from this contract?
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setRemoveSiteModal({ open: false, site: null })}>Cancel</Button>
          <Button variant="danger" loading={removeSiteBusy} onClick={handleRemoveSite}>Remove</Button>
        </div>
      </Modal>

      <Modal open={addEntOpen} title="Add Entitlement" onClose={() => setAddEntOpen(false)}>
        <div className="space-y-3">
          <Select
            label="Type"
            value={entForm.entitlement_type}
            onUpdateModelValue={(v) => setEntForm((p) => ({ ...p, entitlement_type: v }))}
            placeholder=""
            options={ENTITLEMENT_TYPES}
          />
          <Input
            label="Included Quantity"
            type="number"
            value={entForm.included_quantity}
            onUpdateModelValue={(v) => setEntForm((p) => ({ ...p, included_quantity: v }))}
            placeholder="e.g. 40"
          />
          <Select
            label="Period"
            value={entForm.period}
            onUpdateModelValue={(v) => setEntForm((p) => ({ ...p, period: v }))}
            placeholder=""
            options={ENTITLEMENT_PERIODS}
          />
          <Textarea
            label="Notes"
            value={entForm.notes}
            onUpdateModelValue={(v) => setEntForm((p) => ({ ...p, notes: v }))}
            rows={3}
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setAddEntOpen(false)}>Cancel</Button>
            <Button loading={addEntBusy} onClick={handleAddEntitlement}>Add</Button>
          </div>
        </div>
      </Modal>

      <Modal open={addAmendOpen} title="New Amendment" onClose={() => setAddAmendOpen(false)}>
        <div className="space-y-3">
          <Input
            label="Effective Date"
            type="date"
            value={amendForm.effective_date}
            onUpdateModelValue={(v) => setAmendForm((p) => ({ ...p, effective_date: v }))}
          />
          <Textarea
            label="Summary"
            value={amendForm.summary}
            onUpdateModelValue={(v) => setAmendForm((p) => ({ ...p, summary: v }))}
            rows={3}
            required
          />
          <Textarea
            label="Changes (JSON or freeform)"
            value={amendForm.changes}
            onUpdateModelValue={(v) => setAmendForm((p) => ({ ...p, changes: v }))}
            rows={5}
            placeholder='e.g. {"end_date":"2027-01-01"} or freeform notes'
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setAddAmendOpen(false)}>Cancel</Button>
            <Button loading={addAmendBusy} onClick={handleAddAmendment}>Create</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
