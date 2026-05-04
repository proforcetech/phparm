import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import crm from '../../../services/crm.service'
import { useToast } from '../../stores/toast.jsx'

const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'sites', label: 'Sites' },
  { id: 'billing', label: 'Billing Contacts' },
  { id: 'contracts', label: 'Contracts' },
]

function statusVariant(status) {
  if (status === 'active') return 'success'
  if (status === 'prospect') return 'info'
  if (status === 'inactive') return 'default'
  return 'default'
}

const emptySite = {
  name: '',
  street: '',
  city: '',
  state: '',
  postal_code: '',
  status: 'active',
  notes: '',
}

const emptyBillingContact = {
  name: '',
  email: '',
  phone: '',
  title: '',
}

export default function CompanyDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [company, setCompany] = useState(null)
  const [loading, setLoading] = useState(true)
  const [activeTab, setActiveTab] = useState('overview')
  const [deleteModalOpen, setDeleteModalOpen] = useState(false)
  const [deleting, setDeleting] = useState(false)

  const [sites, setSites] = useState([])
  const [sitesLoading, setSitesLoading] = useState(false)
  const [siteModal, setSiteModal] = useState({ open: false, busy: false })
  const [siteForm, setSiteForm] = useState(emptySite)
  const [siteError, setSiteError] = useState('')

  const [billing, setBilling] = useState([])
  const [billingLoading, setBillingLoading] = useState(false)
  const [billingModal, setBillingModal] = useState({ open: false, busy: false, edit: null })
  const [billingForm, setBillingForm] = useState(emptyBillingContact)
  const [billingError, setBillingError] = useState('')
  const [billingDelete, setBillingDelete] = useState({ open: false, contact: null, busy: false })

  const loadCompany = useCallback(async () => {
    setLoading(true)
    try {
      const data = await crm.getCompany(id)
      setCompany(data)
    } catch {
      error('Failed to load company')
      navigate('/cp/crm/companies')
    } finally {
      setLoading(false)
    }
  }, [id, error, navigate])

  useEffect(() => {
    loadCompany()
  }, [loadCompany])

  const loadSites = useCallback(async () => {
    setSitesLoading(true)
    try {
      const res = await crm.listSitesForCompany(id)
      const list = Array.isArray(res) ? res : (res?.data ?? [])
      setSites(list)
    } catch {
      error('Failed to load sites')
      setSites([])
    } finally {
      setSitesLoading(false)
    }
  }, [id, error])

  const loadBilling = useCallback(async () => {
    setBillingLoading(true)
    try {
      const res = await crm.listBillingContacts(id)
      const list = Array.isArray(res) ? res : (res?.data ?? [])
      setBilling(list)
    } catch {
      error('Failed to load billing contacts')
      setBilling([])
    } finally {
      setBillingLoading(false)
    }
  }, [id, error])

  useEffect(() => {
    if (activeTab === 'sites') loadSites()
    if (activeTab === 'billing') loadBilling()
  }, [activeTab, loadSites, loadBilling])

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await crm.deleteCompany(id)
      success('Company deleted')
      navigate('/cp/crm/companies')
    } catch {
      error('Failed to delete company')
    } finally {
      setDeleting(false)
      setDeleteModalOpen(false)
    }
  }

  const openCreateSite = () => {
    setSiteForm(emptySite)
    setSiteError('')
    setSiteModal({ open: true, busy: false })
  }

  const submitCreateSite = async () => {
    if (!siteForm.name.trim()) {
      setSiteError('Site name is required')
      return
    }
    setSiteModal((s) => ({ ...s, busy: true }))
    try {
      await crm.createSite({ company_id: id, ...siteForm })
      success('Site created')
      setSiteModal({ open: false, busy: false })
      loadSites()
    } catch (err) {
      setSiteError(err?.response?.data?.message || 'Failed to create site')
      setSiteModal((s) => ({ ...s, busy: false }))
    }
  }

  const openCreateBilling = () => {
    setBillingForm(emptyBillingContact)
    setBillingError('')
    setBillingModal({ open: true, busy: false, edit: null })
  }

  const openEditBilling = (contact) => {
    setBillingForm({
      name: contact.name || '',
      email: contact.email || '',
      phone: contact.phone || '',
      title: contact.title || '',
    })
    setBillingError('')
    setBillingModal({ open: true, busy: false, edit: contact })
  }

  const submitBilling = async () => {
    if (!billingForm.name.trim()) {
      setBillingError('Name is required')
      return
    }
    setBillingModal((m) => ({ ...m, busy: true }))
    try {
      if (billingModal.edit) {
        await crm.updateBillingContact(billingModal.edit.id, billingForm)
        success('Billing contact updated')
      } else {
        await crm.createBillingContact({ company_id: id, ...billingForm })
        success('Billing contact added')
      }
      setBillingModal({ open: false, busy: false, edit: null })
      loadBilling()
    } catch (err) {
      setBillingError(err?.response?.data?.message || 'Failed to save billing contact')
      setBillingModal((m) => ({ ...m, busy: false }))
    }
  }

  const submitDeleteBilling = async () => {
    if (!billingDelete.contact) return
    setBillingDelete((d) => ({ ...d, busy: true }))
    try {
      await crm.deleteBillingContact(billingDelete.contact.id)
      success('Billing contact deleted')
      setBillingDelete({ open: false, contact: null, busy: false })
      loadBilling()
    } catch {
      error('Failed to delete billing contact')
      setBillingDelete((d) => ({ ...d, busy: false }))
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading company..." />
      </div>
    )
  }

  if (!company) {
    return (
      <div className="text-center py-12">
        <h3 className="text-lg font-medium text-gray-900">Company not found</h3>
        <div className="mt-4">
          <Link to="/cp/crm/companies"><Button>Back to Companies</Button></Link>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link to="/cp/crm/companies" className="text-gray-500 hover:text-gray-700">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{company.name}</h1>
            <div className="mt-1 flex items-center gap-2">
              <Badge size="sm" variant={statusVariant(company.status)}>
                {company.status || 'unknown'}
              </Badge>
              {company.division_name ? (
                <span className="text-sm text-gray-500">{company.division_name}</span>
              ) : null}
            </div>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => navigate(`/cp/crm/companies/${id}/edit`)}>
            Edit
          </Button>
          <Button variant="danger" onClick={() => setDeleteModalOpen(true)}>
            Delete
          </Button>
        </div>
      </div>

      <div className="border-b border-gray-200">
        <nav className="flex space-x-6 -mb-px" aria-label="Tabs">
          {TABS.map((tab) => (
            <button
              key={tab.id}
              type="button"
              onClick={() => setActiveTab(tab.id)}
              className={`whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm ${
                activeTab === tab.id
                  ? 'border-primary-500 text-primary-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </nav>
      </div>

      {activeTab === 'overview' ? (
        <Card>
          <h3 className="text-lg font-medium text-gray-900 mb-4">Overview</h3>
          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">Name</dt>
              <dd className="mt-1 text-sm text-gray-900">{company.name || '—'}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Status</dt>
              <dd className="mt-1">
                <Badge size="sm" variant={statusVariant(company.status)}>
                  {company.status || 'unknown'}
                </Badge>
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Division</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {company.division_name || company.division?.name || '—'}
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Tax ID</dt>
              <dd className="mt-1 text-sm text-gray-900">{company.tax_id || '—'}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Billing Email</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {company.billing_email ? (
                  <a className="text-primary-600 hover:text-primary-500" href={`mailto:${company.billing_email}`}>
                    {company.billing_email}
                  </a>
                ) : '—'}
              </dd>
            </div>
            {company.notes ? (
              <div className="sm:col-span-2">
                <dt className="text-sm font-medium text-gray-500">Notes</dt>
                <dd className="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{company.notes}</dd>
              </div>
            ) : null}
          </dl>
        </Card>
      ) : null}

      {activeTab === 'sites' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Sites</h3>
            <Button size="sm" onClick={openCreateSite}>Add Site</Button>
          </div>
          {sitesLoading ? (
            <div className="py-8 flex justify-center"><Loading /></div>
          ) : sites.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No sites for this company yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {sites.map((site) => (
                    <tr
                      key={site.id}
                      className="hover:bg-gray-50 cursor-pointer"
                      onClick={() => navigate(`/cp/crm/sites/${site.id}`)}
                    >
                      <td className="px-4 py-3 text-sm">
                        <Link
                          to={`/cp/crm/sites/${site.id}`}
                          className="text-primary-600 hover:text-primary-500 font-medium"
                          onClick={(e) => e.stopPropagation()}
                        >
                          {site.name || '—'}
                        </Link>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {[site.street, site.city, site.state, site.postal_code].filter(Boolean).join(', ') || '—'}
                      </td>
                      <td className="px-4 py-3">
                        <Badge size="sm" variant={statusVariant(site.status)}>
                          {site.status || 'unknown'}
                        </Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : null}

      {activeTab === 'billing' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Billing Contacts</h3>
            <Button size="sm" onClick={openCreateBilling}>Add Contact</Button>
          </div>
          {billingLoading ? (
            <div className="py-8 flex justify-center"><Loading /></div>
          ) : billing.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No billing contacts.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {billing.map((contact) => (
                    <tr key={contact.id}>
                      <td className="px-4 py-3 text-sm font-medium text-gray-900">{contact.name || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{contact.title || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{contact.email || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{contact.phone || '—'}</td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="ghost" onClick={() => openEditBilling(contact)}>Edit</Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            className="text-red-600 hover:text-red-700"
                            onClick={() => setBillingDelete({ open: true, contact, busy: false })}
                          >
                            Delete
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : null}

      {activeTab === 'contracts' ? (
        <Card>
          <h3 className="text-lg font-medium text-gray-900 mb-4">Contracts</h3>
          <p className="text-sm text-gray-500 mb-4">
            View and manage contracts for this company in the contracts module.
          </p>
          <Link to={`/cp/contracts?company_id=${id}`}>
            <Button variant="secondary">Open Contracts</Button>
          </Link>
        </Card>
      ) : null}

      <Modal
        open={deleteModalOpen}
        title="Delete Company"
        onClose={() => setDeleteModalOpen(false)}
      >
        <p className="text-sm text-gray-600 mb-4">
          Are you sure you want to delete <strong>{company.name}</strong>? This action cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModalOpen(false)}>Cancel</Button>
          <Button variant="danger" loading={deleting} onClick={handleDelete}>Delete</Button>
        </div>
      </Modal>

      <Modal
        open={siteModal.open}
        title="Add Site"
        onClose={() => setSiteModal({ open: false, busy: false })}
      >
        <div className="space-y-3">
          {siteError ? <Alert variant="danger" onClose={() => setSiteError('')}>{siteError}</Alert> : null}
          <Input
            label="Name"
            value={siteForm.name}
            onUpdateModelValue={(v) => setSiteForm((s) => ({ ...s, name: v }))}
            required
          />
          <Input
            label="Street"
            value={siteForm.street}
            onUpdateModelValue={(v) => setSiteForm((s) => ({ ...s, street: v }))}
          />
          <div className="grid grid-cols-3 gap-3">
            <Input
              label="City"
              value={siteForm.city}
              onUpdateModelValue={(v) => setSiteForm((s) => ({ ...s, city: v }))}
            />
            <Input
              label="State"
              value={siteForm.state}
              onUpdateModelValue={(v) => setSiteForm((s) => ({ ...s, state: v }))}
            />
            <Input
              label="ZIP"
              value={siteForm.postal_code}
              onUpdateModelValue={(v) => setSiteForm((s) => ({ ...s, postal_code: v }))}
            />
          </div>
          <Textarea
            label="Notes"
            value={siteForm.notes}
            onUpdateModelValue={(v) => setSiteForm((s) => ({ ...s, notes: v }))}
            rows={3}
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setSiteModal({ open: false, busy: false })}>Cancel</Button>
            <Button loading={siteModal.busy} onClick={submitCreateSite}>Create Site</Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={billingModal.open}
        title={billingModal.edit ? 'Edit Billing Contact' : 'Add Billing Contact'}
        onClose={() => setBillingModal({ open: false, busy: false, edit: null })}
      >
        <div className="space-y-3">
          {billingError ? <Alert variant="danger" onClose={() => setBillingError('')}>{billingError}</Alert> : null}
          <Input
            label="Name"
            value={billingForm.name}
            onUpdateModelValue={(v) => setBillingForm((s) => ({ ...s, name: v }))}
            required
          />
          <Input
            label="Title"
            value={billingForm.title}
            onUpdateModelValue={(v) => setBillingForm((s) => ({ ...s, title: v }))}
          />
          <Input
            label="Email"
            type="email"
            value={billingForm.email}
            onUpdateModelValue={(v) => setBillingForm((s) => ({ ...s, email: v }))}
          />
          <Input
            label="Phone"
            value={billingForm.phone}
            onUpdateModelValue={(v) => setBillingForm((s) => ({ ...s, phone: v }))}
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setBillingModal({ open: false, busy: false, edit: null })}>
              Cancel
            </Button>
            <Button loading={billingModal.busy} onClick={submitBilling}>
              {billingModal.edit ? 'Save Changes' : 'Add Contact'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={billingDelete.open}
        title="Delete Billing Contact"
        onClose={() => setBillingDelete({ open: false, contact: null, busy: false })}
      >
        <p className="text-sm text-gray-600 mb-4">
          Delete billing contact <strong>{billingDelete.contact?.name}</strong>?
        </p>
        <div className="flex justify-end gap-2">
          <Button
            variant="ghost"
            onClick={() => setBillingDelete({ open: false, contact: null, busy: false })}
          >
            Cancel
          </Button>
          <Button variant="danger" loading={billingDelete.busy} onClick={submitDeleteBilling}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
