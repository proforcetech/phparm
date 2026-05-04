import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import crm from '../../../services/crm.service'
import { useToast } from '../../stores/toast.jsx'

const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'contacts', label: 'Site Contacts' },
  { id: 'blackouts', label: 'Blackout Windows' },
  { id: 'codes', label: 'Codes' },
  { id: 'assets', label: 'Assets' },
  { id: 'contracts', label: 'Contracts' },
]

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'prospect', label: 'Prospect' },
  { value: 'inactive', label: 'Inactive' },
]

function statusVariant(status) {
  if (status === 'active') return 'success'
  if (status === 'prospect') return 'info'
  if (status === 'inactive') return 'default'
  return 'default'
}

const emptyContact = { name: '', title: '', email: '', phone: '' }
const emptyBlackout = { start_date: '', end_date: '', reason: '' }

export default function SiteDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [site, setSite] = useState(null)
  const [loading, setLoading] = useState(true)
  const [activeTab, setActiveTab] = useState('overview')

  const [editForm, setEditForm] = useState(null)
  const [savingEdit, setSavingEdit] = useState(false)
  const [editError, setEditError] = useState('')

  const [deleteModalOpen, setDeleteModalOpen] = useState(false)
  const [deleting, setDeleting] = useState(false)

  const [contacts, setContacts] = useState([])
  const [contactsLoading, setContactsLoading] = useState(false)
  const [contactModal, setContactModal] = useState({ open: false, busy: false, edit: null })
  const [contactForm, setContactForm] = useState(emptyContact)
  const [contactError, setContactError] = useState('')
  const [contactDelete, setContactDelete] = useState({ open: false, contact: null, busy: false })

  const [blackouts, setBlackouts] = useState([])
  const [blackoutsLoading, setBlackoutsLoading] = useState(false)
  const [blackoutModal, setBlackoutModal] = useState({ open: false, busy: false, edit: null })
  const [blackoutForm, setBlackoutForm] = useState(emptyBlackout)
  const [blackoutError, setBlackoutError] = useState('')
  const [blackoutDelete, setBlackoutDelete] = useState({ open: false, item: null, busy: false })

  const [codes, setCodes] = useState([])
  const [codesLoading, setCodesLoading] = useState(false)

  const loadSite = useCallback(async () => {
    setLoading(true)
    try {
      const data = await crm.getSite(id)
      setSite(data)
      setEditForm({
        name: data.name || '',
        status: data.status || 'active',
        street: data.street || '',
        city: data.city || '',
        state: data.state || '',
        postal_code: data.postal_code || '',
        contact_email: data.contact_email || '',
        contact_phone: data.contact_phone || '',
        notes: data.notes || '',
      })
    } catch {
      error('Failed to load site')
      navigate('/cp/crm/sites')
    } finally {
      setLoading(false)
    }
  }, [id, error, navigate])

  useEffect(() => {
    loadSite()
  }, [loadSite])

  const loadContacts = useCallback(async () => {
    setContactsLoading(true)
    try {
      const res = await crm.listSiteContacts(id)
      setContacts(Array.isArray(res) ? res : (res?.data ?? []))
    } catch {
      error('Failed to load contacts')
      setContacts([])
    } finally {
      setContactsLoading(false)
    }
  }, [id, error])

  const loadBlackouts = useCallback(async () => {
    setBlackoutsLoading(true)
    try {
      const res = await crm.listBlackoutWindows(id)
      setBlackouts(Array.isArray(res) ? res : (res?.data ?? []))
    } catch {
      error('Failed to load blackout windows')
      setBlackouts([])
    } finally {
      setBlackoutsLoading(false)
    }
  }, [id, error])

  const loadCodes = useCallback(async () => {
    setCodesLoading(true)
    try {
      const res = await crm.listSiteCodes(id)
      setCodes(Array.isArray(res) ? res : (res?.data ?? []))
    } catch {
      error('Failed to load site codes')
      setCodes([])
    } finally {
      setCodesLoading(false)
    }
  }, [id, error])

  useEffect(() => {
    if (activeTab === 'contacts') loadContacts()
    if (activeTab === 'blackouts') loadBlackouts()
    if (activeTab === 'codes') loadCodes()
  }, [activeTab, loadContacts, loadBlackouts, loadCodes])

  const submitEdit = async () => {
    if (!editForm?.name?.trim()) {
      setEditError('Site name is required')
      return
    }
    setSavingEdit(true)
    setEditError('')
    try {
      await crm.updateSite(id, editForm)
      success('Site updated')
      loadSite()
    } catch (err) {
      setEditError(err?.response?.data?.message || 'Failed to update site')
    } finally {
      setSavingEdit(false)
    }
  }

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await crm.deleteSite(id)
      success('Site deleted')
      navigate('/cp/crm/sites')
    } catch {
      error('Failed to delete site')
    } finally {
      setDeleting(false)
      setDeleteModalOpen(false)
    }
  }

  const openCreateContact = () => {
    setContactForm(emptyContact)
    setContactError('')
    setContactModal({ open: true, busy: false, edit: null })
  }

  const openEditContact = (contact) => {
    setContactForm({
      name: contact.name || '',
      title: contact.title || '',
      email: contact.email || '',
      phone: contact.phone || '',
    })
    setContactError('')
    setContactModal({ open: true, busy: false, edit: contact })
  }

  const submitContact = async () => {
    if (!contactForm.name.trim()) {
      setContactError('Name is required')
      return
    }
    setContactModal((m) => ({ ...m, busy: true }))
    try {
      if (contactModal.edit) {
        await crm.updateSiteContact(contactModal.edit.id, contactForm)
        success('Contact updated')
      } else {
        await crm.createSiteContact({ site_id: id, ...contactForm })
        success('Contact added')
      }
      setContactModal({ open: false, busy: false, edit: null })
      loadContacts()
    } catch (err) {
      setContactError(err?.response?.data?.message || 'Failed to save contact')
      setContactModal((m) => ({ ...m, busy: false }))
    }
  }

  const submitDeleteContact = async () => {
    if (!contactDelete.contact) return
    setContactDelete((d) => ({ ...d, busy: true }))
    try {
      await crm.deleteSiteContact(contactDelete.contact.id)
      success('Contact deleted')
      setContactDelete({ open: false, contact: null, busy: false })
      loadContacts()
    } catch {
      error('Failed to delete contact')
      setContactDelete((d) => ({ ...d, busy: false }))
    }
  }

  const openCreateBlackout = () => {
    setBlackoutForm(emptyBlackout)
    setBlackoutError('')
    setBlackoutModal({ open: true, busy: false, edit: null })
  }

  const openEditBlackout = (item) => {
    setBlackoutForm({
      start_date: item.start_date || '',
      end_date: item.end_date || '',
      reason: item.reason || '',
    })
    setBlackoutError('')
    setBlackoutModal({ open: true, busy: false, edit: item })
  }

  const submitBlackout = async () => {
    if (!blackoutForm.start_date || !blackoutForm.end_date) {
      setBlackoutError('Start and end dates are required')
      return
    }
    setBlackoutModal((m) => ({ ...m, busy: true }))
    try {
      if (blackoutModal.edit) {
        await crm.updateBlackoutWindow(blackoutModal.edit.id, blackoutForm)
        success('Blackout window updated')
      } else {
        await crm.createBlackoutWindow({ site_id: id, ...blackoutForm })
        success('Blackout window added')
      }
      setBlackoutModal({ open: false, busy: false, edit: null })
      loadBlackouts()
    } catch (err) {
      setBlackoutError(err?.response?.data?.message || 'Failed to save blackout window')
      setBlackoutModal((m) => ({ ...m, busy: false }))
    }
  }

  const submitDeleteBlackout = async () => {
    if (!blackoutDelete.item) return
    setBlackoutDelete((d) => ({ ...d, busy: true }))
    try {
      await crm.deleteBlackoutWindow(blackoutDelete.item.id)
      success('Blackout window deleted')
      setBlackoutDelete({ open: false, item: null, busy: false })
      loadBlackouts()
    } catch {
      error('Failed to delete blackout window')
      setBlackoutDelete((d) => ({ ...d, busy: false }))
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading site..." />
      </div>
    )
  }

  if (!site) {
    return (
      <div className="text-center py-12">
        <h3 className="text-lg font-medium text-gray-900">Site not found</h3>
        <div className="mt-4">
          <Link to="/cp/crm/sites"><Button>Back to Sites</Button></Link>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link to="/cp/crm/sites" className="text-gray-500 hover:text-gray-700">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{site.name}</h1>
            <div className="mt-1 flex items-center gap-2">
              <Badge size="sm" variant={statusVariant(site.status)}>
                {site.status || 'unknown'}
              </Badge>
              {site.company_id ? (
                <Link
                  to={`/cp/crm/companies/${site.company_id}`}
                  className="text-sm text-primary-600 hover:text-primary-500"
                >
                  {site.company_name || 'View company'}
                </Link>
              ) : null}
            </div>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="danger" onClick={() => setDeleteModalOpen(true)}>Delete</Button>
        </div>
      </div>

      <div className="border-b border-gray-200">
        <nav className="flex space-x-6 -mb-px overflow-x-auto" aria-label="Tabs">
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

      {activeTab === 'overview' && editForm ? (
        <Card>
          <h3 className="text-lg font-medium text-gray-900 mb-4">Site Details</h3>
          {editError ? (
            <Alert variant="danger" className="mb-4" onClose={() => setEditError('')}>
              {editError}
            </Alert>
          ) : null}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              label="Name"
              value={editForm.name}
              onUpdateModelValue={(v) => setEditForm((s) => ({ ...s, name: v }))}
              required
            />
            <Select
              label="Status"
              value={editForm.status}
              placeholder=""
              onUpdateModelValue={(v) => setEditForm((s) => ({ ...s, status: v }))}
              options={STATUS_OPTIONS}
            />
            <Input
              label="Street"
              value={editForm.street}
              onUpdateModelValue={(v) => setEditForm((s) => ({ ...s, street: v }))}
            />
            <div className="grid grid-cols-3 gap-3">
              <Input
                label="City"
                value={editForm.city}
                onUpdateModelValue={(v) => setEditForm((s) => ({ ...s, city: v }))}
              />
              <Input
                label="State"
                value={editForm.state}
                onUpdateModelValue={(v) => setEditForm((s) => ({ ...s, state: v }))}
              />
              <Input
                label="ZIP"
                value={editForm.postal_code}
                onUpdateModelValue={(v) => setEditForm((s) => ({ ...s, postal_code: v }))}
              />
            </div>
            <Input
              label="Contact Email"
              type="email"
              value={editForm.contact_email}
              onUpdateModelValue={(v) => setEditForm((s) => ({ ...s, contact_email: v }))}
            />
            <Input
              label="Contact Phone"
              value={editForm.contact_phone}
              onUpdateModelValue={(v) => setEditForm((s) => ({ ...s, contact_phone: v }))}
            />
          </div>
          <div className="mt-4">
            <Textarea
              label="Notes"
              value={editForm.notes}
              onUpdateModelValue={(v) => setEditForm((s) => ({ ...s, notes: v }))}
              rows={4}
            />
          </div>
          <div className="mt-4 flex justify-end">
            <Button loading={savingEdit} onClick={submitEdit}>Save Changes</Button>
          </div>
        </Card>
      ) : null}

      {activeTab === 'contacts' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Site Contacts</h3>
            <Button size="sm" onClick={openCreateContact}>Add Contact</Button>
          </div>
          {contactsLoading ? (
            <div className="py-8 flex justify-center"><Loading /></div>
          ) : contacts.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No contacts for this site.</p>
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
                  {contacts.map((contact) => (
                    <tr key={contact.id}>
                      <td className="px-4 py-3 text-sm font-medium text-gray-900">{contact.name || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{contact.title || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{contact.email || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{contact.phone || '—'}</td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="ghost" onClick={() => openEditContact(contact)}>Edit</Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            className="text-red-600 hover:text-red-700"
                            onClick={() => setContactDelete({ open: true, contact, busy: false })}
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

      {activeTab === 'blackouts' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Blackout Windows</h3>
            <Button size="sm" onClick={openCreateBlackout}>Add Window</Button>
          </div>
          {blackoutsLoading ? (
            <div className="py-8 flex justify-center"><Loading /></div>
          ) : blackouts.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No blackout windows configured.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Start</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">End</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {blackouts.map((item) => (
                    <tr key={item.id}>
                      <td className="px-4 py-3 text-sm text-gray-900">{item.start_date || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-900">{item.end_date || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{item.reason || '—'}</td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="ghost" onClick={() => openEditBlackout(item)}>Edit</Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            className="text-red-600 hover:text-red-700"
                            onClick={() => setBlackoutDelete({ open: true, item, busy: false })}
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

      {activeTab === 'codes' ? (
        <Card>
          <h3 className="text-lg font-medium text-gray-900 mb-4">Codes</h3>
          {codesLoading ? (
            <div className="py-8 flex justify-center"><Loading /></div>
          ) : codes.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No codes for this site.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {codes.map((code) => (
                    <tr key={code.id}>
                      <td className="px-4 py-3 text-sm text-gray-500">{code.code_type || code.type || '—'}</td>
                      <td className="px-4 py-3 text-sm font-mono text-gray-900">{code.code || code.value || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{code.description || code.label || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : null}

      {activeTab === 'assets' ? (
        <Card>
          <h3 className="text-lg font-medium text-gray-900 mb-4">Assets</h3>
          <p className="text-sm text-gray-500 mb-4">
            View assets associated with this site in the assets module.
          </p>
          <Link to={`/cp/assets?site_id=${id}`}>
            <Button variant="secondary">Open Assets</Button>
          </Link>
        </Card>
      ) : null}

      {activeTab === 'contracts' ? (
        <Card>
          <h3 className="text-lg font-medium text-gray-900 mb-4">Contracts</h3>
          <p className="text-sm text-gray-500 mb-4">
            View and manage contracts that cover this site.
          </p>
          <Link to={`/cp/contracts?site_id=${id}`}>
            <Button variant="secondary">Open Contracts</Button>
          </Link>
        </Card>
      ) : null}

      <Modal
        open={deleteModalOpen}
        title="Delete Site"
        onClose={() => setDeleteModalOpen(false)}
      >
        <p className="text-sm text-gray-600 mb-4">
          Are you sure you want to delete <strong>{site.name}</strong>? This action cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModalOpen(false)}>Cancel</Button>
          <Button variant="danger" loading={deleting} onClick={handleDelete}>Delete</Button>
        </div>
      </Modal>

      <Modal
        open={contactModal.open}
        title={contactModal.edit ? 'Edit Contact' : 'Add Contact'}
        onClose={() => setContactModal({ open: false, busy: false, edit: null })}
      >
        <div className="space-y-3">
          {contactError ? <Alert variant="danger" onClose={() => setContactError('')}>{contactError}</Alert> : null}
          <Input
            label="Name"
            value={contactForm.name}
            onUpdateModelValue={(v) => setContactForm((s) => ({ ...s, name: v }))}
            required
          />
          <Input
            label="Title"
            value={contactForm.title}
            onUpdateModelValue={(v) => setContactForm((s) => ({ ...s, title: v }))}
          />
          <Input
            label="Email"
            type="email"
            value={contactForm.email}
            onUpdateModelValue={(v) => setContactForm((s) => ({ ...s, email: v }))}
          />
          <Input
            label="Phone"
            value={contactForm.phone}
            onUpdateModelValue={(v) => setContactForm((s) => ({ ...s, phone: v }))}
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setContactModal({ open: false, busy: false, edit: null })}>
              Cancel
            </Button>
            <Button loading={contactModal.busy} onClick={submitContact}>
              {contactModal.edit ? 'Save Changes' : 'Add Contact'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={contactDelete.open}
        title="Delete Contact"
        onClose={() => setContactDelete({ open: false, contact: null, busy: false })}
      >
        <p className="text-sm text-gray-600 mb-4">
          Delete contact <strong>{contactDelete.contact?.name}</strong>?
        </p>
        <div className="flex justify-end gap-2">
          <Button
            variant="ghost"
            onClick={() => setContactDelete({ open: false, contact: null, busy: false })}
          >
            Cancel
          </Button>
          <Button variant="danger" loading={contactDelete.busy} onClick={submitDeleteContact}>Delete</Button>
        </div>
      </Modal>

      <Modal
        open={blackoutModal.open}
        title={blackoutModal.edit ? 'Edit Blackout Window' : 'Add Blackout Window'}
        onClose={() => setBlackoutModal({ open: false, busy: false, edit: null })}
      >
        <div className="space-y-3">
          {blackoutError ? <Alert variant="danger" onClose={() => setBlackoutError('')}>{blackoutError}</Alert> : null}
          <Input
            label="Start Date"
            type="date"
            value={blackoutForm.start_date}
            onUpdateModelValue={(v) => setBlackoutForm((s) => ({ ...s, start_date: v }))}
            required
          />
          <Input
            label="End Date"
            type="date"
            value={blackoutForm.end_date}
            onUpdateModelValue={(v) => setBlackoutForm((s) => ({ ...s, end_date: v }))}
            required
          />
          <Textarea
            label="Reason"
            value={blackoutForm.reason}
            onUpdateModelValue={(v) => setBlackoutForm((s) => ({ ...s, reason: v }))}
            rows={3}
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setBlackoutModal({ open: false, busy: false, edit: null })}>
              Cancel
            </Button>
            <Button loading={blackoutModal.busy} onClick={submitBlackout}>
              {blackoutModal.edit ? 'Save Changes' : 'Add Window'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={blackoutDelete.open}
        title="Delete Blackout Window"
        onClose={() => setBlackoutDelete({ open: false, item: null, busy: false })}
      >
        <p className="text-sm text-gray-600 mb-4">
          Delete this blackout window?
        </p>
        <div className="flex justify-end gap-2">
          <Button
            variant="ghost"
            onClick={() => setBlackoutDelete({ open: false, item: null, busy: false })}
          >
            Cancel
          </Button>
          <Button variant="danger" loading={blackoutDelete.busy} onClick={submitDeleteBlackout}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
