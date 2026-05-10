import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import { portalService } from '../../../services/portal/portal.service'

const formatMoney = (value) => {
  if (value == null) return '—'
  const amount = Number(value)
  if (Number.isNaN(amount)) return '—'
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount)
}

const formatDate = (value) => {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString()
}

const statusClasses = (status) => {
  switch (status) {
    case 'paid': return 'bg-green-100 text-green-800'
    case 'partial': return 'bg-amber-100 text-amber-800'
    case 'pending': return 'bg-yellow-100 text-yellow-800'
    case 'sent': return 'bg-blue-100 text-blue-800'
    default: return 'bg-gray-100 text-gray-800'
  }
}

export default function PortalInvoices() {
  const [invoices, setInvoices] = useState([])
  const [unpaidOnly, setUnpaidOnly] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const params = {}
      if (unpaidOnly) params.unpaid_only = '1'
      const list = await portalService.listInvoices(params)
      setInvoices(Array.isArray(list) ? list : [])
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load invoices.')
    } finally {
      setLoading(false)
    }
  }, [unpaidOnly])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-2xl font-semibold">Invoices</h1>
          <p className="text-sm text-gray-600 mt-1">
            View invoices and pay online.
          </p>
        </div>
        <label className="inline-flex items-center gap-2 text-sm text-gray-700">
          <input
            type="checkbox"
            className="rounded"
            checked={unpaidOnly}
            onChange={(e) => setUnpaidOnly(e.target.checked)}
          />
          Show unpaid only
        </label>
      </header>

      {error && <Alert variant="error" closable={false}>{error}</Alert>}

      <Card padding={false}>
        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading invoices…" />
          </div>
        ) : invoices.length === 0 ? (
          <div className="text-center py-12 px-6">
            <h3 className="text-sm font-medium text-gray-900">No invoices</h3>
            <p className="mt-1 text-sm text-gray-500">
              {unpaidOnly ? 'No unpaid invoices.' : 'You don’t have any invoices yet.'}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice #</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Issued</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                  <th className="px-4 py-3"></th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {invoices.map((invoice) => (
                  <tr key={invoice.id}>
                    <td className="px-4 py-3 text-sm text-gray-900 font-medium">{invoice.number}</td>
                    <td className="px-4 py-3 text-sm">
                      <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${statusClasses(invoice.status)}`}>
                        {invoice.status}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-500">{formatDate(invoice.issue_date)}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">{formatDate(invoice.due_date)}</td>
                    <td className="px-4 py-3 text-sm text-gray-900 text-right">{formatMoney(invoice.total)}</td>
                    <td className="px-4 py-3 text-sm text-gray-900 text-right">{formatMoney(invoice.balance_due)}</td>
                    <td className="px-4 py-3 text-right text-sm">
                      <Link to={`/p/invoices/${invoice.id}`}>
                        <Button variant="ghost" size="sm">View</Button>
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
