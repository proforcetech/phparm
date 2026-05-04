import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import contractsService from '../../../services/contracts.service'
import crmService from '../../../services/crm.service'
import { useToast } from '../../stores/toast.jsx'

const PER_PAGE = 25

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'pending_signature', label: 'Pending signature' },
  { value: 'active', label: 'Active' },
  { value: 'expired', label: 'Expired' },
  { value: 'terminated', label: 'Terminated' },
]

const STATUS_BADGE = {
  draft: 'default',
  pending_signature: 'warning',
  active: 'success',
  expired: 'danger',
  terminated: 'danger',
}

function statusLabel(status) {
  if (!status) return '—'
  return String(status).replace(/_/g, ' ')
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

export default function ContractList() {
  const navigate = useNavigate()
  const { error } = useToast()

  const [contracts, setContracts] = useState([])
  const [loading, setLoading] = useState(true)
  const [companies, setCompanies] = useState([])
  const [companyId, setCompanyId] = useState('')
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)

  useEffect(() => {
    crmService
      .listCompanies({ limit: 500 })
      .then((res) => setCompanies(unwrap(res)))
      .catch(() => setCompanies([]))
  }, [])

  const loadContracts = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        limit: PER_PAGE,
        offset: (page - 1) * PER_PAGE,
      }
      if (companyId) params.company_id = companyId
      if (status) params.status = status
      if (search.trim()) params.query = search.trim()

      const res = await contractsService.list(params)
      const list = unwrap(res)
      setContracts(list)
      setTotal(res?.total ?? list.length)
    } catch {
      error('Failed to load contracts')
      setContracts([])
    } finally {
      setLoading(false)
    }
  }, [companyId, status, search, page, error])

  useEffect(() => {
    loadContracts()
  }, [loadContracts])

  const handleSearch = (e) => {
    e.preventDefault()
    setPage(1)
    setSearch(searchInput)
  }

  const handleFilterChange = (setter) => (value) => {
    setPage(1)
    setter(value)
  }

  const companyName = (id) => {
    const c = companies.find((x) => String(x.id) === String(id))
    return c?.name || '—'
  }

  const hasNext = contracts.length === PER_PAGE

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Contracts</h1>
          <p className="mt-1 text-sm text-gray-500">Master service agreements and entitlements</p>
        </div>
        <Button onClick={() => navigate('/cp/contracts/create')}>New Contract</Button>
      </div>

      <Card>
        <form onSubmit={handleSearch} className="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
          <Select
            label="Company"
            value={companyId}
            onUpdateModelValue={handleFilterChange(setCompanyId)}
            placeholder="All companies"
            options={[
              { value: '', label: 'All companies' },
              ...companies.map((c) => ({ value: String(c.id), label: c.name })),
            ]}
          />
          <Select
            label="Status"
            value={status}
            onUpdateModelValue={handleFilterChange(setStatus)}
            placeholder=""
            options={STATUS_OPTIONS}
          />
          <Input
            label="Search"
            value={searchInput}
            onUpdateModelValue={setSearchInput}
            placeholder="Name, number..."
          />
          <div className="flex items-end">
            <Button type="submit" variant="secondary" className="w-full">Apply</Button>
          </div>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading contracts..." />
          </div>
        ) : contracts.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No contracts found</h3>
            <p className="mt-1 text-sm text-gray-500">Adjust filters or create a new contract.</p>
            <div className="mt-4">
              <Button onClick={() => navigate('/cp/contracts/create')}>New Contract</Button>
            </div>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Start</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">End</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sites</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {contracts.map((c) => (
                    <tr
                      key={c.id}
                      className="hover:bg-gray-50 cursor-pointer"
                      onClick={() => navigate(`/cp/contracts/${c.id}`)}
                    >
                      <td className="px-4 py-3">
                        <Link
                          to={`/cp/contracts/${c.id}`}
                          className="text-primary-600 hover:text-primary-500 font-medium"
                          onClick={(e) => e.stopPropagation()}
                        >
                          {c.name || `Contract #${c.id}`}
                        </Link>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-700">
                        {c.company_name || companyName(c.company_id)}
                      </td>
                      <td className="px-4 py-3">
                        <Badge size="sm" variant={STATUS_BADGE[c.status] || 'default'}>
                          {statusLabel(c.status)}
                        </Badge>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">{formatDate(c.start_date)}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{formatDate(c.end_date)}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{c.sites_count ?? '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{formatDate(c.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="flex justify-between items-center mt-4 pt-4 border-t">
              <span className="text-sm text-gray-500">
                Showing {(page - 1) * PER_PAGE + 1} - {(page - 1) * PER_PAGE + contracts.length} {total ? `of ${total}` : ''}
              </span>
              <div className="flex gap-2">
                <Button variant="ghost" size="sm" disabled={page === 1} onClick={() => setPage(page - 1)}>
                  Previous
                </Button>
                <Button variant="ghost" size="sm" disabled={!hasNext} onClick={() => setPage(page + 1)}>
                  Next
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>
    </div>
  )
}
