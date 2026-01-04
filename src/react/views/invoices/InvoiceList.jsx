import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import invoiceService from '../../../services/invoice.service'
import { useToast } from '../../stores/toast.jsx'

const perPage = 20

const formatCurrency = (value) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(value || 0)
}

const formatDate = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleDateString()
}

const statusVariant = (status) => {
  switch (status) {
    case 'paid':
      return 'success'
    case 'sent':
      return 'info'
    case 'pending':
    case 'draft':
      return 'warning'
    case 'overdue':
    case 'cancelled':
      return 'danger'
    default:
      return 'default'
  }
}

const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'pending', label: 'Pending' },
  { value: 'sent', label: 'Sent' },
  { value: 'paid', label: 'Paid' },
  { value: 'overdue', label: 'Overdue' },
  { value: 'cancelled', label: 'Cancelled' },
]

const getInvoiceCustomerName = (invoice) => {
  if (!invoice) return '—'
  const customer = invoice.customer
  const directName = customer?.name || invoice.customer_name
  const firstName = customer?.first_name || invoice.customer_first_name || invoice.first_name
  const lastName = customer?.last_name || invoice.customer_last_name || invoice.last_name
  const fullName = [firstName, lastName].filter(Boolean).join(' ').trim()
  return directName || fullName || '—'
}

export default function InvoiceList() {
  const navigate = useNavigate()
  const { error } = useToast()
  const [invoices, setInvoices] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const loadInvoices = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        limit: perPage,
        offset: (page - 1) * perPage,
      }
      if (search.trim()) {
        params.query = search.trim()
      }
      if (status) {
        params.status = status
      }
      const response = await invoiceService.getAll(params)
      setInvoices(Array.isArray(response) ? response : response?.data || [])
    } catch {
      error('Failed to load invoices')
      setInvoices([])
    } finally {
      setLoading(false)
    }
  }, [page, search, status, error])

  useEffect(() => {
    loadInvoices()
  }, [loadInvoices])

  const handleSearch = (e) => {
    e.preventDefault()
    setPage(1)
    loadInvoices()
  }

  const hasNext = invoices.length === perPage

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Invoices</h1>
          <p className="mt-1 text-sm text-gray-500">Manage customer invoices and payments</p>
        </div>
        <Button onClick={() => navigate('/cp/invoices/create')}>
          <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
          </svg>
          Create Invoice
        </Button>
      </div>

      <Card>
        <form onSubmit={handleSearch} className="mb-4">
          <div className="flex flex-col sm:flex-row gap-2">
            <Input
              value={search}
              onUpdateModelValue={setSearch}
              placeholder="Search by invoice number or customer..."
              className="flex-1"
            />
            <Select
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              options={statusOptions}
            />
            <Button type="submit" variant="secondary">Search</Button>
          </div>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading invoices..." />
          </div>
        ) : invoices.length === 0 ? (
          <div className="text-center py-12">
            <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <h3 className="mt-2 text-sm font-medium text-gray-900">No invoices found</h3>
            <p className="mt-1 text-sm text-gray-500">Get started by creating a new invoice.</p>
            <div className="mt-4">
              <Button onClick={() => navigate('/cp/invoices/create')}>Create Invoice</Button>
            </div>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {invoices.map((invoice) => (
                    <tr key={invoice.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <Link to={`/cp/invoices/${invoice.id}`} className="text-primary-600 hover:text-primary-500 font-medium">
                          {invoice.number}
                        </Link>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-900">
                        {getInvoiceCustomerName(invoice)}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {formatDate(invoice.issue_date || invoice.created_at)}
                      </td>
                      <td className="px-4 py-3">
                        <Badge size="sm" variant={statusVariant(invoice.status)}>
                          {invoice.status}
                        </Badge>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-900 text-right font-medium">
                        {formatCurrency(invoice.total)}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="ghost" onClick={() => navigate(`/cp/invoices/${invoice.id}`)}>
                            View
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => navigate(`/cp/invoices/${invoice.id}/edit`)}>
                            Edit
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="flex justify-between items-center mt-4 pt-4 border-t">
              <span className="text-sm text-gray-500">
                Page {page}
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
