import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import { portalService } from '../../../services/portal/portal.service'
import { usePortalAuth } from '../../stores/portalAuth'
import { PORTAL_PERMISSION } from '../../../services/portal/permissions'

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

const PROVIDER_OPTIONS = [
  { value: 'stripe', label: 'Stripe' },
  { value: 'square', label: 'Square' },
  { value: 'paypal', label: 'PayPal' },
]

export default function PortalInvoiceDetail() {
  const navigate = useNavigate()
  const { id } = useParams()
  const invoiceId = Number(id)
  const { can } = usePortalAuth()
  const canPayInvoices = can(PORTAL_PERMISSION.PAY_INVOICES)

  const [invoice, setInvoice] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [provider, setProvider] = useState('stripe')
  const [paying, setPaying] = useState(false)
  const [payError, setPayError] = useState(null)

  const load = useCallback(async () => {
    if (!invoiceId) {
      setError('Invalid invoice id.')
      setLoading(false)
      return
    }
    setLoading(true)
    setError(null)
    try {
      const result = await portalService.getInvoice(invoiceId)
      setInvoice(result)
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load invoice.')
    } finally {
      setLoading(false)
    }
  }, [invoiceId])

  useEffect(() => { load() }, [load])

  const startCheckout = async () => {
    setPaying(true)
    setPayError(null)
    try {
      const origin = window.location.origin
      const result = await portalService.startCheckout(invoiceId, {
        provider,
        success_url: `${origin}/p/invoices/${invoiceId}?paid=1`,
        cancel_url: `${origin}/p/invoices/${invoiceId}?canceled=1`,
      })
      const url = result?.checkout_url || result?.data?.checkout_url
      if (url) {
        window.location.assign(url)
        return
      }
      setPayError('Checkout did not return a redirect URL. Please try again.')
    } catch (err) {
      setPayError(err.response?.data?.message || 'Unable to start checkout.')
    } finally {
      setPaying(false)
    }
  }

  if (loading) {
    return (
      <Card>
        <div className="py-10 flex justify-center">
          <Loading text="Loading invoice…" />
        </div>
      </Card>
    )
  }

  if (error || !invoice) {
    return (
      <div className="space-y-4">
        <Alert variant="error" closable={false}>{error || 'Invoice not found.'}</Alert>
        <Button variant="ghost" onClick={() => navigate('/p/invoices')}>← Back to invoices</Button>
      </div>
    )
  }

  const balance = Number(invoice.balance_due ?? invoice.total ?? 0)
  const hasOpenBalance = invoice.status !== 'paid' && balance > 0
  const showCheckout = hasOpenBalance && canPayInvoices

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <Link to="/p/invoices" className="text-sm text-gray-500 hover:underline">← Invoices</Link>
          <h1 className="text-2xl font-semibold mt-1">Invoice {invoice.number}</h1>
        </div>
        <span className={`px-3 py-1 rounded-full text-sm font-medium ${statusClasses(invoice.status)}`}>
          {invoice.status}
        </span>
      </div>

      <Card>
        <dl className="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <div>
            <dt className="text-gray-500">Issued</dt>
            <dd className="text-gray-900">{formatDate(invoice.issue_date)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Due</dt>
            <dd className="text-gray-900">{formatDate(invoice.due_date)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Total</dt>
            <dd className="text-gray-900 font-medium">{formatMoney(invoice.total)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Subtotal</dt>
            <dd className="text-gray-900">{formatMoney(invoice.subtotal)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Tax</dt>
            <dd className="text-gray-900">{formatMoney(invoice.tax)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Paid</dt>
            <dd className="text-gray-900">{formatMoney(invoice.amount_paid)}</dd>
          </div>
          <div className="md:col-span-3 border-t pt-4">
            <dt className="text-gray-500">Balance due</dt>
            <dd className="text-2xl font-semibold text-gray-900">{formatMoney(balance)}</dd>
          </div>
        </dl>
      </Card>

      {hasOpenBalance && !canPayInvoices && (
        <Card>
          <p className="text-sm text-gray-700">
            This invoice has an open balance of <span className="font-medium">{formatMoney(balance)}</span>,
            but your portal role can&rsquo;t pay invoices. Ask an account approver to complete payment.
          </p>
        </Card>
      )}

      {showCheckout && (
        <Card>
          <h2 className="text-base font-semibold">Pay this invoice</h2>
          <p className="text-sm text-gray-600 mt-1 mb-4">
            Choose a payment provider — you&rsquo;ll be redirected to complete checkout.
          </p>
          {payError && (
            <div className="mb-4">
              <Alert variant="error" closable={false}>{payError}</Alert>
            </div>
          )}
          <div className="flex flex-col sm:flex-row sm:items-end gap-3">
            <div className="flex-1">
              <Select
                label="Payment provider"
                modelValue={provider}
                onUpdateModelValue={setProvider}
                options={PROVIDER_OPTIONS}
              />
            </div>
            <Button onClick={startCheckout} loading={paying} disabled={paying}>
              Pay {formatMoney(balance)}
            </Button>
          </div>
        </Card>
      )}
    </div>
  )
}
