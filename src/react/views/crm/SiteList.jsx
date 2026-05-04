import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

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

// NOTE: there is no global "list all sites" endpoint on the CRM service today,
// so this view requires a parent company to be selected before fetching sites.
// Future enhancement: expose GET /sites with cross-company pagination.

function statusVariant(status) {
  if (status === 'active') return 'success'
  if (status === 'prospect') return 'info'
  if (status === 'inactive') return 'default'
  return 'default'
}

const emptyNewSite = {
  company_id: '',
  name: '',
  street: '',
  city: '',
  state: '',
  postal_code: '',
  status: 'active',
  notes: '',
}

export default function SiteList() {
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [companies, setCompanies] = useState([])
  const [companiesLoading, setCompaniesLoading] = useState(true)
  const [selectedCompanyId, setSelectedCompanyId] = useState('')

  const [sites, setSites] = useState([])
  const [sitesLoading, setSitesLoading] = useState(false)
  const [search, setSearch] = useState('')

  const [createModal, setCreateModal] = useState({ open: false, busy: false })
  const [newSite, setNewSite] = useState(emptyNewSite)
  const [createError, setCreateError] = useState('')

  useEffect(() => {
    let cancelled = false
    setCompaniesLoading(true)
    crm
      .listCompanies({ limit: 500 })
      .then((res) => {
        if (cancelled) return
        const list = Array.isArray(res) ? res : (res?.data ?? [])
        setCompanies(list)
      })
      .catch(() => {
        if (!cancelled) {
          error('Failed to load companies')
          setCompanies([])
        }
      })
      .finally(() => {
        if (!cancelled) setCompaniesLoading(false)
      })
    return () => { cancelled = true }
  }, [error])

  const loadSites = useCallback(async () => {
    if (!selectedCompanyId) {
      setSites([])
      return
    }
    setSitesLoading(true)
    try {
      const res = await crm.listSitesForCompany(selectedCompanyId)
      const list = Array.isArray(res) ? res : (res?.data ?? [])
      setSites(list)
    } catch {
      error('Failed to load sites')
      setSites([])
    } finally {
      setSitesLoading(false)
    }
  }, [selectedCompanyId, error])

  useEffect(() => {
    loadSites()
  }, [loadSites])

  const filteredSites = useMemo(() => {
    const trimmed = search.trim().toLowerCase()
    if (!trimmed) return sites
    return sites.filter((site) => {
      const haystack = [
        site.name,
        site.street,
        site.city,
        site.state,
        site.postal_code,
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return haystack.includes(trimmed)
    })
  }, [sites, search])

  const companyOptions = [
    { value: '', label: companiesLoading ? 'Loading...' : 'Select a company' },
    ...companies.map((c) => ({ value: String(c.id), label: c.name })),
  ]

  const openCreate = () => {
    setNewSite({ ...emptyNewSite, company_id: selectedCompanyId || '' })
    setCreateError('')
    setCreateModal({ open: true, busy: false })
  }

  const submitCreate = async () => {
    if (!newSite.company_id) {
      setCreateError('Please select a company')
      return
    }
    if (!newSite.name.trim()) {
      setCreateError('Site name is required')
      return
    }
    setCreateModal((m) => ({ ...m, busy: true }))
    try {
      const created = await crm.createSite(newSite)
      success('Site created')
      setCreateModal({ open: false, busy: false })
      const newId = created?.id ?? created?.data?.id
      if (newId) {
        navigate(`/cp/crm/sites/${newId}`)
      } else {
        if (newSite.company_id === selectedCompanyId) loadSites()
      }
    } catch (err) {
      setCreateError(err?.response?.data?.message || 'Failed to create site')
      setCreateModal((m) => ({ ...m, busy: false }))
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Sites</h1>
          <p className="mt-1 text-sm text-gray-500">Service locations belonging to a company</p>
        </div>
        <div className="flex gap-2">
          <Button onClick={openCreate}>New Site</Button>
        </div>
      </div>

      <Card>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
          <Select
            label="Company"
            value={selectedCompanyId}
            placeholder=""
            onUpdateModelValue={setSelectedCompanyId}
            options={companyOptions}
          />
          <Input
            label="Search"
            value={search}
            onUpdateModelValue={setSearch}
            placeholder="Filter by name or address..."
            className="md:col-span-2"
          />
        </div>

        {!selectedCompanyId ? (
          <div className="text-center py-12">
            <h3 className="mt-2 text-sm font-medium text-gray-900">Select a company</h3>
            <p className="mt-1 text-sm text-gray-500">
              Choose a company above to load its sites.
            </p>
          </div>
        ) : sitesLoading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading sites..." />
          </div>
        ) : filteredSites.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="mt-2 text-sm font-medium text-gray-900">No sites found</h3>
            <p className="mt-1 text-sm text-gray-500">
              {sites.length === 0
                ? 'This company has no sites yet.'
                : 'No sites match your search.'}
            </p>
          </div>
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
                {filteredSites.map((site) => (
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

      <Modal
        open={createModal.open}
        title="New Site"
        onClose={() => setCreateModal({ open: false, busy: false })}
      >
        <div className="space-y-3">
          {createError ? <Alert variant="danger" onClose={() => setCreateError('')}>{createError}</Alert> : null}
          <Select
            label="Company"
            value={newSite.company_id}
            placeholder=""
            onUpdateModelValue={(v) => setNewSite((s) => ({ ...s, company_id: v }))}
            options={companyOptions}
            required
          />
          <Input
            label="Name"
            value={newSite.name}
            onUpdateModelValue={(v) => setNewSite((s) => ({ ...s, name: v }))}
            required
          />
          <Input
            label="Street"
            value={newSite.street}
            onUpdateModelValue={(v) => setNewSite((s) => ({ ...s, street: v }))}
          />
          <div className="grid grid-cols-3 gap-3">
            <Input
              label="City"
              value={newSite.city}
              onUpdateModelValue={(v) => setNewSite((s) => ({ ...s, city: v }))}
            />
            <Input
              label="State"
              value={newSite.state}
              onUpdateModelValue={(v) => setNewSite((s) => ({ ...s, state: v }))}
            />
            <Input
              label="ZIP"
              value={newSite.postal_code}
              onUpdateModelValue={(v) => setNewSite((s) => ({ ...s, postal_code: v }))}
            />
          </div>
          <Textarea
            label="Notes"
            value={newSite.notes}
            onUpdateModelValue={(v) => setNewSite((s) => ({ ...s, notes: v }))}
            rows={3}
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setCreateModal({ open: false, busy: false })}>
              Cancel
            </Button>
            <Button loading={createModal.busy} onClick={submitCreate}>Create Site</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
