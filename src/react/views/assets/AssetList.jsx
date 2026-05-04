import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import assetsService from '../../../services/assets.service'
import crmService from '../../../services/crm.service'
import { useToast } from '../../stores/toast.jsx'

const PAGE_SIZE = 25

const STATUS_OPTIONS = [
  { value: '', label: 'Any status' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'decommissioned', label: 'Decommissioned' },
  { value: 'pending', label: 'Pending' },
]

function statusVariant(status) {
  switch ((status || '').toLowerCase()) {
    case 'active':
      return 'success'
    case 'decommissioned':
      return 'danger'
    case 'inactive':
      return 'default'
    case 'pending':
      return 'warning'
    default:
      return 'secondary'
  }
}

function unwrapList(res) {
  if (Array.isArray(res)) return res
  if (Array.isArray(res?.data)) return res.data
  if (Array.isArray(res?.items)) return res.items
  return []
}

export default function AssetList() {
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [companies, setCompanies] = useState([])
  const [sites, setSites] = useState([])
  const [types, setTypes] = useState([])

  const [companyId, setCompanyId] = useState('')
  const [siteId, setSiteId] = useState('')
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [typeId, setTypeId] = useState('')
  const [status, setStatus] = useState('')

  const [assets, setAssets] = useState([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(false)

  const [createOpen, setCreateOpen] = useState(false)
  const [createBusy, setCreateBusy] = useState(false)
  const [form, setForm] = useState({
    site_id: '',
    asset_type_id: '',
    identifier: '',
    install_date: '',
    location_notes: '',
  })

  const lastReqId = useRef(0)

  useEffect(() => {
    crmService
      .listCompanies({ limit: 500 })
      .then((res) => setCompanies(unwrapList(res)))
      .catch(() => error('Failed to load companies'))
    assetsService
      .listTypes({ limit: 500 })
      .then((res) => setTypes(unwrapList(res)))
      .catch(() => error('Failed to load asset types'))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (!companyId) {
      setSites([])
      setSiteId('')
      return
    }
    crmService
      .listSitesForCompany(companyId, { limit: 500 })
      .then((res) => setSites(unwrapList(res)))
      .catch(() => {
        setSites([])
        error('Failed to load sites')
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId])

  const loadAssets = useCallback(async () => {
    const reqId = ++lastReqId.current
    setLoading(true)
    try {
      const params = {
        limit: PAGE_SIZE,
        offset: (page - 1) * PAGE_SIZE,
      }
      if (search.trim()) params.q = search.trim()
      if (typeId) params.asset_type_id = typeId
      if (status) params.status = status
      if (companyId) params.company_id = companyId

      let response
      if (siteId) {
        response = await assetsService.listForSite(siteId, params)
      } else {
        response = await assetsService.search(params)
      }
      if (reqId !== lastReqId.current) return
      const list = unwrapList(response)
      setAssets(list)
      setTotal(response?.total ?? response?.meta?.total ?? list.length)
    } catch {
      if (reqId === lastReqId.current) {
        setAssets([])
        error('Failed to load assets')
      }
    } finally {
      if (reqId === lastReqId.current) setLoading(false)
    }
  }, [page, search, typeId, status, companyId, siteId, error])

  useEffect(() => {
    loadAssets()
  }, [loadAssets])

  const onSubmitSearch = (e) => {
    e.preventDefault()
    setPage(1)
    setSearch(searchInput)
  }

  const resetForm = () => {
    setForm({
      site_id: siteId || '',
      asset_type_id: '',
      identifier: '',
      install_date: '',
      location_notes: '',
    })
  }

  const openCreate = () => {
    resetForm()
    setCreateOpen(true)
  }

  const submitCreate = async () => {
    if (!form.site_id || !form.asset_type_id) {
      error('Site and asset type are required')
      return
    }
    setCreateBusy(true)
    try {
      const created = await assetsService.create({
        site_id: form.site_id,
        asset_type_id: form.asset_type_id,
        identifier: form.identifier || undefined,
        install_date: form.install_date || undefined,
        location_notes: form.location_notes || undefined,
      })
      const newId = created?.id ?? created?.data?.id
      success('Asset created')
      setCreateOpen(false)
      if (newId) navigate(`/cp/assets/${newId}`)
      else loadAssets()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to create asset')
    } finally {
      setCreateBusy(false)
    }
  }

  const companyOptions = useMemo(
    () => [
      { value: '', label: 'All companies' },
      ...companies.map((c) => ({ value: String(c.id), label: c.name || `Company #${c.id}` })),
    ],
    [companies]
  )
  const siteOptions = useMemo(
    () => [
      { value: '', label: companyId ? 'All sites' : 'Select company first' },
      ...sites.map((s) => ({ value: String(s.id), label: s.name || `Site #${s.id}` })),
    ],
    [sites, companyId]
  )
  const typeOptions = useMemo(
    () => [
      { value: '', label: 'Any type' },
      ...types.map((t) => ({ value: String(t.id), label: t.name || `Type #${t.id}` })),
    ],
    [types]
  )

  const typeName = (id) => types.find((t) => String(t.id) === String(id))?.name || '—'
  const siteName = (id) => sites.find((s) => String(s.id) === String(id))?.name || ''
  const hasNext = assets.length === PAGE_SIZE

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Installed Assets</h1>
          <p className="mt-1 text-sm text-gray-500">
            Equipment installed at customer sites: HVAC units, alarms, generators, and more.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => navigate('/cp/assets/types')}>
            Asset Types
          </Button>
          <Button onClick={openCreate}>New Asset</Button>
        </div>
      </div>

      <Card>
        <form onSubmit={onSubmitSearch} className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
          <Select
            label="Company"
            value={companyId}
            placeholder=""
            options={companyOptions}
            onUpdateModelValue={(v) => {
              setCompanyId(v)
              setSiteId('')
              setPage(1)
            }}
          />
          <Select
            label="Site"
            value={siteId}
            placeholder=""
            disabled={!companyId}
            options={siteOptions}
            onUpdateModelValue={(v) => {
              setSiteId(v)
              setPage(1)
            }}
          />
          <Select
            label="Type"
            value={typeId}
            placeholder=""
            options={typeOptions}
            onUpdateModelValue={(v) => {
              setTypeId(v)
              setPage(1)
            }}
          />
          <Select
            label="Status"
            value={status}
            placeholder=""
            options={STATUS_OPTIONS}
            onUpdateModelValue={(v) => {
              setStatus(v)
              setPage(1)
            }}
          />
          <Input
            label="Search"
            value={searchInput}
            onUpdateModelValue={setSearchInput}
            placeholder="Name, serial, identifier..."
          />
          <Button type="submit" variant="secondary">
            Search
          </Button>
        </form>
      </Card>

      <Card padding={false}>
        {loading ? (
          <div className="py-12 flex justify-center">
            <Loading text="Loading assets..." />
          </div>
        ) : assets.length === 0 ? (
          <div className="text-center py-12 px-6">
            <h3 className="text-sm font-medium text-gray-900">No assets found</h3>
            <p className="mt-1 text-sm text-gray-500">
              Try adjusting your filters or create a new asset.
            </p>
            <div className="mt-4">
              <Button onClick={openCreate}>New Asset</Button>
            </div>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                      Name / Identifier
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Site</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Installed</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last PM</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {assets.map((a) => {
                    const display =
                      a.name || a.identifier || a.serial_number || a.label || `Asset #${a.id}`
                    const sName = a.site_name || siteName(a.site_id) || (a.site_id ? `Site #${a.site_id}` : '—')
                    const tName = a.asset_type_name || a.type_name || typeName(a.asset_type_id)
                    return (
                      <tr
                        key={a.id}
                        className="hover:bg-gray-50 cursor-pointer"
                        onClick={() => navigate(`/cp/assets/${a.id}`)}
                      >
                        <td className="px-4 py-3 text-sm">
                          <Link
                            to={`/cp/assets/${a.id}`}
                            className="text-primary-600 hover:text-primary-500 font-medium"
                            onClick={(e) => e.stopPropagation()}
                          >
                            {display}
                          </Link>
                          {a.identifier && a.name ? (
                            <div className="text-xs text-gray-500">{a.identifier}</div>
                          ) : null}
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-700">{tName}</td>
                        <td className="px-4 py-3 text-sm text-gray-700">{sName}</td>
                        <td className="px-4 py-3">
                          <Badge size="sm" variant={statusVariant(a.status)}>
                            {a.status || 'unknown'}
                          </Badge>
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-500">
                          {a.installed_at || a.install_date || '—'}
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-500">{a.last_pm_at || '—'}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            <div className="flex justify-between items-center px-4 py-3 border-t bg-gray-50">
              <span className="text-sm text-gray-500">
                Showing {(page - 1) * PAGE_SIZE + 1} -{' '}
                {(page - 1) * PAGE_SIZE + assets.length}
                {total ? ` of ${total}` : ''}
              </span>
              <div className="flex gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  disabled={page === 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  Previous
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  disabled={!hasNext}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Next
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>

      <Modal open={createOpen} title="New Installed Asset" onClose={() => setCreateOpen(false)}>
        <div className="space-y-3">
          <Select
            label="Site"
            required
            value={form.site_id}
            placeholder="Select a site"
            options={sites.map((s) => ({ value: String(s.id), label: s.name || `Site #${s.id}` }))}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, site_id: v }))}
            helperText={!companyId ? 'Pick a company in the toolbar first' : ''}
          />
          <Select
            label="Asset Type"
            required
            value={form.asset_type_id}
            placeholder="Select a type"
            options={types.map((t) => ({ value: String(t.id), label: t.name || `Type #${t.id}` }))}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, asset_type_id: v }))}
          />
          <Input
            label="Identifier / Serial"
            value={form.identifier}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, identifier: v }))}
            placeholder="e.g. SN-12345"
          />
          <Input
            label="Install date"
            type="date"
            value={form.install_date}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, install_date: v }))}
          />
          <Textarea
            label="Location notes"
            value={form.location_notes}
            onUpdateModelValue={(v) => setForm((f) => ({ ...f, location_notes: v }))}
            placeholder="Roof access, north wing, etc."
            rows={3}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setCreateOpen(false)}>
              Cancel
            </Button>
            <Button loading={createBusy} onClick={submitCreate}>
              Create
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
