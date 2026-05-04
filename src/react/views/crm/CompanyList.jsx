import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import crm from '../../../services/crm.service'
import divisionsService from '../../../services/divisions.service'
import { useToast } from '../../stores/toast.jsx'

const PER_PAGE = 25

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
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

export default function CompanyList() {
  const navigate = useNavigate()
  const { error } = useToast()

  const [companies, setCompanies] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [divisionId, setDivisionId] = useState('')
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)

  const [divisions, setDivisions] = useState([])

  useEffect(() => {
    let cancelled = false
    divisionsService
      .list({ limit: 200 })
      .then((res) => {
        if (cancelled) return
        const list = Array.isArray(res) ? res : (res?.data ?? [])
        setDivisions(list)
      })
      .catch(() => {
        if (!cancelled) setDivisions([])
      })
    return () => { cancelled = true }
  }, [])

  const loadCompanies = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        limit: PER_PAGE,
        offset: (page - 1) * PER_PAGE,
      }
      const trimmed = search.trim()
      if (trimmed) params.query = trimmed
      if (status) params.status = status
      if (divisionId) params.division_id = divisionId

      const response = await crm.listCompanies(params)
      const list = Array.isArray(response) ? response : (response?.data ?? [])
      setCompanies(list)
      setTotal(response?.total ?? list.length ?? 0)
    } catch {
      error('Failed to load companies')
      setCompanies([])
    } finally {
      setLoading(false)
    }
  }, [page, search, status, divisionId, error])

  useEffect(() => {
    loadCompanies()
  }, [loadCompanies])

  const handleSearch = (e) => {
    e.preventDefault()
    setPage(1)
    loadCompanies()
  }

  const hasNext = companies.length === PER_PAGE
  const startIndex = (page - 1) * PER_PAGE + 1
  const endIndex = (page - 1) * PER_PAGE + companies.length

  const divisionOptions = [
    { value: '', label: 'All divisions' },
    ...divisions.map((d) => ({ value: String(d.id), label: d.name })),
  ]

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Companies</h1>
          <p className="mt-1 text-sm text-gray-500">B2B parent accounts and their sites</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => navigate('/cp/crm/companies/create')}>
            New Company
          </Button>
        </div>
      </div>

      <Card>
        <form onSubmit={handleSearch} className="mb-4 grid grid-cols-1 md:grid-cols-4 gap-3">
          <Input
            value={search}
            onUpdateModelValue={setSearch}
            placeholder="Search companies..."
            className="md:col-span-2"
          />
          <Select
            value={status}
            placeholder=""
            onUpdateModelValue={setStatus}
            options={STATUS_OPTIONS}
          />
          <Select
            value={divisionId}
            placeholder=""
            onUpdateModelValue={setDivisionId}
            options={divisionOptions}
          />
          <div className="md:col-span-4 flex justify-end">
            <Button type="submit" variant="secondary">Apply</Button>
          </div>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading companies..." />
          </div>
        ) : companies.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="mt-2 text-sm font-medium text-gray-900">No companies found</h3>
            <p className="mt-1 text-sm text-gray-500">Get started by creating a new company.</p>
            <div className="mt-4">
              <Button onClick={() => navigate('/cp/crm/companies/create')}>New Company</Button>
            </div>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Division</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sites</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {companies.map((company) => (
                    <tr
                      key={company.id}
                      className="hover:bg-gray-50 cursor-pointer"
                      onClick={() => navigate(`/cp/crm/companies/${company.id}`)}
                    >
                      <td className="px-4 py-3">
                        <Link
                          to={`/cp/crm/companies/${company.id}`}
                          className="text-primary-600 hover:text-primary-500 font-medium"
                          onClick={(e) => e.stopPropagation()}
                        >
                          {company.name || '—'}
                        </Link>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {company.division_name || company.division?.name || '—'}
                      </td>
                      <td className="px-4 py-3">
                        <Badge size="sm" variant={statusVariant(company.status)}>
                          {company.status || 'unknown'}
                        </Badge>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {company.sites_count ?? '—'}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {company.created_at ? new Date(company.created_at).toLocaleDateString() : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="flex justify-between items-center mt-4 pt-4 border-t">
              <span className="text-sm text-gray-500">
                Showing {startIndex} - {endIndex} {total ? `of ${total}` : ''}
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
