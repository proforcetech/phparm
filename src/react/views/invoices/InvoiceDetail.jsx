import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import invoiceService from '../../../services/invoice.service'
import { useToast } from '../../stores/toast.jsx'

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

export default function InvoiceDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [invoice, setInvoice] = useState(null)
  const [loading, setLoading] = useState(true)
  const [sendModal, setSendModal] = useState(false)
  const [sending, setSending] = useState(false)
  const [deleteModal, setDeleteModal] = useState(false)
  const [deleting, setDeleting] = useState(false)

  const loadInvoice = useCallback(async () => {
    setLoading(true)
    try {
      const data = await invoiceService.getById(id)
      setInvoice(data)
    } catch {
      error('Failed to load invoice')
      navigate('/cp/invoices')
    } finally {
      setLoading(false)
    }
  }, [id, error, navigate])

  useEffect(() => {
    loadInvoice()
  }, [loadInvoice])

  const handleSend = async () => {
    setSending(true)
    try {
      await invoiceService.send(id)
      success('Invoice sent successfully')
      setSendModal(false)
      loadInvoice()
    } catch {
      error('Failed to send invoice')
    } finally {
      setSending(false)
    }
  }

  const handleDownloadPdf = async () => {
    try {
      const blob = await invoiceService.generatePdf(id)
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `invoice-${invoice.number}.pdf`
      link.click()
      window.URL.revokeObjectURL(url)
    } catch {
      error('Failed to download PDF')
    }
  }

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await invoiceService.delete(id)
      success('Invoice deleted successfully')
      navigate('/cp/invoices')
    } catch {
      error('Failed to delete invoice')
    } finally {
      setDeleting(false)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading invoice..." />
      </div>
    )
  }

  if (!invoice) {
    return (
      <div className="text-center py-12">
        <h3 className="text-lg font-medium text-gray-900">Invoice not found</h3>
        <p className="mt-2 text-gray-500">The invoice you&apos;re looking for doesn&apos;t exist.</p>
        <div className="mt-4">
          <Link to="/cp/invoices">
            <Button>Back to Invoices</Button>
          </Link>
        </div>
      </div>
    )
  }

  const lineItems = invoice.items || invoice.line_items || []

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link to="/cp/invoices" className="text-gray-500 hover:text-gray-700">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Invoice {invoice.number}</h1>
            <p className="mt-1 text-sm text-gray-500">
              {invoice.customer?.name || 'Unknown Customer'}
            </p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={handleDownloadPdf}>
            <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Download PDF
          </Button>
          {invoice.status !== 'paid' && invoice.status !== 'sent' ? (
            <Button variant="secondary" onClick={() => setSendModal(true)}>
              <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
              Send Invoice
            </Button>
          ) : null}
          <Button onClick={() => navigate(`/cp/invoices/${id}/edit`)}>Edit</Button>
          <Button variant="danger" onClick={() => setDeleteModal(true)}>Delete</Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card className="lg:col-span-2">
          <div className="flex justify-between items-start mb-6">
            <div>
              <h3 className="text-lg font-medium text-gray-900">Invoice Details</h3>
            </div>
            <Badge variant={statusVariant(invoice.status)} size="lg">
              {invoice.status}
            </Badge>
          </div>

          <div className="grid grid-cols-2 gap-4 mb-6">
            <div>
              <dt className="text-sm font-medium text-gray-500">Invoice Number</dt>
              <dd className="mt-1 text-sm text-gray-900">{invoice.number}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Issue Date</dt>
              <dd className="mt-1 text-sm text-gray-900">{formatDate(invoice.issue_date)}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Due Date</dt>
              <dd className="mt-1 text-sm text-gray-900">{formatDate(invoice.due_date)}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Customer</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {invoice.customer ? (
                  <Link to={`/cp/customers/${invoice.customer.id}`} className="text-primary-600 hover:text-primary-500">
                    {invoice.customer.name}
                  </Link>
                ) : '—'}
              </dd>
            </div>
          </div>

          <h4 className="text-sm font-medium text-gray-900 mb-3">Line Items</h4>
          {lineItems.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No line items</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                    <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                    <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {lineItems.map((item, index) => (
                    <tr key={item.id || index}>
                      <td className="px-4 py-2 text-sm text-gray-900">{item.description || item.name}</td>
                      <td className="px-4 py-2 text-sm text-gray-500 text-right">{item.quantity}</td>
                      <td className="px-4 py-2 text-sm text-gray-500 text-right">{formatCurrency(item.unit_price || item.price)}</td>
                      <td className="px-4 py-2 text-sm text-gray-900 text-right font-medium">
                        {formatCurrency((item.quantity || 1) * (item.unit_price || item.price || 0))}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card>
          <h3 className="text-lg font-medium text-gray-900 mb-4">Summary</h3>
          <dl className="space-y-3">
            <div className="flex justify-between">
              <dt className="text-sm text-gray-500">Subtotal</dt>
              <dd className="text-sm text-gray-900">{formatCurrency(invoice.subtotal)}</dd>
            </div>
            {invoice.tax_amount ? (
              <div className="flex justify-between">
                <dt className="text-sm text-gray-500">Tax</dt>
                <dd className="text-sm text-gray-900">{formatCurrency(invoice.tax_amount)}</dd>
              </div>
            ) : null}
            {invoice.discount_amount ? (
              <div className="flex justify-between">
                <dt className="text-sm text-gray-500">Discount</dt>
                <dd className="text-sm text-green-600">-{formatCurrency(invoice.discount_amount)}</dd>
              </div>
            ) : null}
            <div className="flex justify-between pt-3 border-t">
              <dt className="text-base font-medium text-gray-900">Total</dt>
              <dd className="text-base font-medium text-gray-900">{formatCurrency(invoice.total)}</dd>
            </div>
            {invoice.amount_paid ? (
              <>
                <div className="flex justify-between">
                  <dt className="text-sm text-gray-500">Amount Paid</dt>
                  <dd className="text-sm text-green-600">{formatCurrency(invoice.amount_paid)}</dd>
                </div>
                <div className="flex justify-between pt-3 border-t">
                  <dt className="text-base font-medium text-gray-900">Balance Due</dt>
                  <dd className="text-base font-medium text-gray-900">
                    {formatCurrency((invoice.total || 0) - (invoice.amount_paid || 0))}
                  </dd>
                </div>
              </>
            ) : null}
          </dl>

          {invoice.notes ? (
            <div className="mt-6 pt-6 border-t">
              <h4 className="text-sm font-medium text-gray-900 mb-2">Notes</h4>
              <p className="text-sm text-gray-500 whitespace-pre-wrap">{invoice.notes}</p>
            </div>
          ) : null}
        </Card>
      </div>

      <Modal open={sendModal} title="Send Invoice" onClose={() => setSendModal(false)}>
        <p className="text-sm text-gray-600 mb-4">
          This will send the invoice to <strong>{invoice.customer?.email || 'the customer'}</strong>.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setSendModal(false)}>Cancel</Button>
          <Button loading={sending} onClick={handleSend}>Send Invoice</Button>
        </div>
      </Modal>

      <Modal open={deleteModal} title="Delete Invoice" onClose={() => setDeleteModal(false)}>
        <p className="text-sm text-gray-600 mb-4">
          Are you sure you want to delete invoice <strong>{invoice.number}</strong>? This action cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModal(false)}>Cancel</Button>
          <Button variant="danger" loading={deleting} onClick={handleDelete}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
