import { useCallback, useEffect, useMemo, useState } from 'react'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import { useToast } from '../../stores/toast.jsx'
import { portalService } from '../../../services/portal.service'

const perPage = 10

const formatDate = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleDateString()
}

const statusVariant = (status) => {
  switch (status) {
    case 'paid':
      return 'success'
    case 'pending':
      return 'warning'
    case 'sent':
      return 'info'
    default:
      return 'default'
  }
}

export default function CustomerPortalInvoices() {
  const [invoices, setInvoices] = useState([])
  const [loading, setLoading] = useState(false)
  const [page, setPage] = useState(1)
  const { error } = useToast()

  const hasNext = useMemo(() => invoices.length === perPage, [invoices.length])

  const loadInvoices = useCallback(
    async (pageNumber) => {
      setLoading(true)
      try {
        const response = await portalService.getInvoices({
          limit: perPage,
          offset: (pageNumber - 1) * perPage,
        })
        setInvoices(Array.isArray(response) ? response : [])
      } catch (err) {
        setInvoices([])
        error('Unable to load invoices right now.')
      } finally {
        setLoading(false)
      }
    },
    [error]
  )

  const changePage = (nextPage) => {
    setPage(Math.max(1, nextPage))
  }

  const handleViewInvoice = (id) => {
    window.location.assign(`/portal/invoices/${id}`)
  }

  useEffect(() => {
    loadInvoices(page)
  }, [loadInvoices, page])

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">My Invoices</h1>
        <p className="mt-1 text-sm text-gray-500">View and pay your invoices</p>
      </div>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading invoices..." />
          </div>
        ) : null}

        {!loading && invoices.length === 0 ? (
          <div className="text-center py-12">
            <svg
              className="mx-auto h-12 w-12 text-gray-400"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
            <h3 className="mt-2 text-sm font-medium text-gray-900">No Invoices</h3>
            <p className="mt-1 text-sm text-gray-500">You don&apos;t have any invoices yet.</p>
          </div>
        ) : null}

        {!loading && invoices.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Invoice #
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Status
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Issued
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Total
                  </th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {invoices.map((invoice) => (
                  <tr key={invoice.id}>
                    <td className="px-4 py-3 text-sm text-gray-900">{invoice.number}</td>
                    <td className="px-4 py-3 text-sm">
                      <Badge size="sm" rounded variant={statusVariant(invoice.status)}>
                        {invoice.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-500">
                      {formatDate(invoice.issue_date)}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-900">
                      ${Number(invoice.total || 0).toFixed(2)}
                    </td>
                    <td className="px-4 py-3 text-right text-sm">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleViewInvoice(invoice.id)}
                      >
                        View
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <div className="flex justify-end items-center gap-3 px-4 py-3 border-t">
              <Button
                variant="ghost"
                size="sm"
                disabled={page === 1}
                onClick={() => changePage(page - 1)}
              >
                Previous
              </Button>
              <span className="text-sm text-gray-600">Page {page}</span>
              <Button
                variant="ghost"
                size="sm"
                disabled={!hasNext}
                onClick={() => changePage(page + 1)}
              >
                Next
              </Button>
            </div>
          </div>
        ) : null}
      </Card>
    </div>
  )
}
