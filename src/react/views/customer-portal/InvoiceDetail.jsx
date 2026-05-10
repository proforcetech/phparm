// @deprecated Phase 2a — frozen for legacy `customer` role. New portal lives at src/react/views/portal/*.
import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import invoiceService from '../../../services/invoice.service'
import { portalService } from '../../../services/portal.service'

const formatDate = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleDateString()
}

const statusClasses = (status) => {
  switch (status) {
    case 'paid':
      return 'bg-green-100 text-green-800'
    case 'pending':
      return 'bg-yellow-100 text-yellow-800'
    case 'sent':
      return 'bg-blue-100 text-blue-800'
    default:
      return 'bg-gray-100 text-gray-800'
  }
}

export default function InvoiceDetail() {
  const navigate = useNavigate()
  const { id } = useParams()
  const invoiceId = Number(id)

  const [invoice, setInvoice] = useState(null)
  const [loading, setLoading] = useState(false)
  const [paying, setPaying] = useState(false)

  const loadInvoice = useCallback(async () => {
    if (!invoiceId) return
    setLoading(true)
    try {
      const response = await portalService.getInvoiceById(invoiceId)
      setInvoice(response)
    } finally {
      setLoading(false)
    }
  }, [invoiceId])

  useEffect(() => {
    loadInvoice()
  }, [loadInvoice])

  const downloadPdf = () => {
    window.open(`/api/invoices/${invoiceId}/pdf`, '_blank')
  }

  const startCheckout = async () => {
    setPaying(true)
    try {
      const response = await invoiceService.createCheckout(invoiceId, 'stripe')
      if (response.checkout_url) {
        window.location.href = response.checkout_url
      }
    } finally {
      setPaying(false)
    }
  }

  return (
    <div>
      <div className="mb-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" onClick={() => navigate('/portal/invoices')}>
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Button>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Invoice Details</h1>
            <p className="mt-1 text-sm text-gray-500">View invoice and payment options</p>
          </div>
        </div>
      </div>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading invoice..." />
          </div>
        ) : !invoice ? (
          <div className="py-10 text-center text-sm text-red-600">Unable to load invoice.</div>
        ) : (
          <div className="space-y-6">
            <div className="flex flex-col md:flex-row md:items-center md:justify-between">
              <div>
                <p className="text-sm text-gray-500">Invoice #</p>
                <p className="text-xl font-semibold text-gray-900">{invoice.number}</p>
              </div>
              <div className="flex items-center gap-3">
                <span className={`px-3 py-1 rounded-full text-sm font-medium ${statusClasses(invoice.status)}`}>
                  {invoice.status}
                </span>
                <Button variant="ghost" onClick={downloadPdf}>Download PDF</Button>
                <Button variant="primary" disabled={paying} onClick={startCheckout}>
                  {paying ? 'Redirecting...' : 'Pay Now'}
                </Button>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <p className="text-sm text-gray-500">Issued</p>
                <p className="text-base text-gray-900">{formatDate(invoice.issue_date)}</p>
              </div>
              <div>
                <p className="text-sm text-gray-500">Due</p>
                <p className="text-base text-gray-900">{formatDate(invoice.due_date)}</p>
              </div>
              <div>
                <p className="text-sm text-gray-500">Balance Due</p>
                <p className="text-base text-gray-900">
                  ${Number(invoice.balance_due ?? invoice.total ?? 0).toFixed(2)}
                </p>
              </div>
            </div>
          </div>
        )}
      </Card>
    </div>
  )
}
