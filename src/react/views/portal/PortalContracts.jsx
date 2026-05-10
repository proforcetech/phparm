import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import { portalService } from '../../../services/portal/portal.service'

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'pending_signature', label: 'Pending signature' },
  { value: 'active', label: 'Active' },
  { value: 'expired', label: 'Expired' },
  { value: 'cancelled', label: 'Cancelled' },
]

const statusBadge = (status) => {
  switch (status) {
    case 'active': return 'bg-green-100 text-green-800'
    case 'pending_signature': return 'bg-amber-100 text-amber-800'
    case 'draft': return 'bg-gray-100 text-gray-700'
    case 'expired': return 'bg-yellow-100 text-yellow-800'
    case 'cancelled': return 'bg-red-100 text-red-800'
    default: return 'bg-gray-100 text-gray-700'
  }
}

const formatMoney = (cents) => {
  if (cents == null || isNaN(Number(cents))) return '—'
  return `$${(Number(cents) / 100).toFixed(2)}`
}

const formatDate = (s) => (s ? new Date(s).toLocaleDateString() : '—')

export default function PortalContracts() {
  const [list, setList] = useState({ data: [], total: 0 })
  const [status, setStatus] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)
    const params = status ? { status } : {}
    portalService.listContracts(params)
      .then((result) => { if (!cancelled) setList(result || { data: [], total: 0 }) })
      .catch((err) => { if (!cancelled) setError(err.response?.data?.message || 'Unable to load contracts.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [status])

  return (
    <div className="space-y-6">
      <header className="flex items-end justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-2xl font-semibold">Contracts</h1>
          <p className="text-sm text-gray-600 mt-1">
            Service agreements covering your company. Renewals and pending signatures appear in Approvals.
          </p>
        </div>
        <div className="w-56">
          <Select
            label="Status filter"
            options={STATUS_OPTIONS}
            modelValue={status}
            onUpdateModelValue={setStatus}
          />
        </div>
      </header>

      {error && <Alert variant="error" closable={false}>{error}</Alert>}

      {loading ? (
        <Card>
          <div className="py-10 flex justify-center">
            <Loading text="Loading contracts…" />
          </div>
        </Card>
      ) : list.data.length === 0 ? (
        <Card>
          <p className="text-sm text-gray-600">
            No contracts {status ? `with status "${status}"` : ''} on file for your company.
          </p>
        </Card>
      ) : (
        <div className="space-y-3">
          {list.data.map((c) => (
            <Link key={c.id} to={`/p/contracts/${c.id}`} className="block">
              <Card className="hover:shadow-md transition-shadow">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h3 className="text-base font-semibold">{c.title || c.contract_number}</h3>
                      <span className="text-xs text-gray-500">{c.contract_number}</span>
                      <span className={`text-xs px-2 py-0.5 rounded-full ${statusBadge(c.status)}`}>
                        {c.status}
                      </span>
                      {c.kind === 'renewal' && (
                        <span className="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">
                          Renewal
                        </span>
                      )}
                    </div>
                    {c.description && (
                      <p className="text-sm text-gray-600 mt-1 line-clamp-2">{c.description}</p>
                    )}
                    <p className="text-xs text-gray-500 mt-2">
                      {formatDate(c.start_date)} – {formatDate(c.end_date)} · {c.billing_frequency}
                    </p>
                  </div>
                  <div className="text-right">
                    <div className="text-sm font-semibold text-gray-900">
                      {formatMoney(c.billing_amount_cents)}
                    </div>
                    <div className="text-xs text-gray-500">per {c.billing_frequency.replace(/ly$/, '')}</div>
                  </div>
                </div>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}
