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
]

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

  useEffect(() => {
    if (tab === 'sites') loadSites()
    if (tab === 'entitlements') loadEntitlements()
    if (tab === 'amendments') loadAmendments()
  }, [tab, loadSites, loadEntitlements, loadAmendments])

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
