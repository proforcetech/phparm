import { useCallback, useEffect, useMemo, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import publicInvoiceService from '../../../services/public-invoice.service'

const formatCurrency = (value) =>
  `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const formatDate = (value) => {
  if (!value) return '—'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleDateString()
}

const statusVariant = (status) => {
  switch (status) {
    case 'paid':
      return 'success'
    case 'partial':
      return 'warning'
    case 'overdue':
      return 'danger'
    default:
      return 'info'
  }
}

export default function PublicPaymentPortal() {
  const { token } = useParams()
  const [searchParams] = useSearchParams()
  const [invoice, setInvoice] = useState(null)
  const [loading, setLoading] = useState(true)
  const [processing, setProcessing] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')

  const statusParam = searchParams.get('status')

  const fetchInvoice = useCallback(async () => {
    if (!token) return
    setLoading(true)
    setErrorMessage('')
    try {
      const data = await publicInvoiceService.getInvoice(token)
      setInvoice(data)
    } catch (error) {
      setErrorMessage(error.response?.data?.message || 'Unable to load invoice details.')
    } finally {
      setLoading(false)
    }
  }, [token])

  useEffect(() => {
    fetchInvoice()
  }, [fetchInvoice])

  const amountDue = useMemo(() => {
    if (!invoice) return 0
    return invoice.payment_amount ?? invoice.balance_due ?? invoice.total ?? 0
  }, [invoice])

  const providers = useMemo(() => invoice?.payment_providers ?? [], [invoice])

  const handleCheckout = async (provider) => {
    if (!invoice?.payment_token) {
      setErrorMessage('Payment token expired. Please refresh the page to request a new payment link.')
      return
    }

    setProcessing(true)
    setErrorMessage('')
    try {
      const payload = {
        provider,
        payment_token: invoice.payment_token,
      }
      const response = await publicInvoiceService.createCheckout(token, payload)
      if (response.checkout_url) {
        window.location.assign(response.checkout_url)
      } else {
        setErrorMessage('Unable to start checkout. Please contact the shop for assistance.')
      }
    } catch (error) {
      setErrorMessage(error.response?.data?.message || 'Unable to start checkout.')
    } finally {
      setProcessing(false)
    }
  }

  return (
    <div className="min-h-screen bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="mx-auto w-full max-w-3xl space-y-6">
        <div className="text-center">
          <p className="text-sm font-semibold uppercase tracking-wide text-primary-600">Public Payment Portal</p>
          <h1 className="mt-2 text-3xl font-bold text-gray-900">Pay your invoice</h1>
          <p className="mt-2 text-sm text-gray-500">Secure checkout powered by Stripe or Square.</p>
        </div>

        {statusParam === 'success' ? (
          <Alert variant="success" title="Payment received" message="Thank you! Your payment was successful." closable={false} />
        ) : null}
        {statusParam === 'cancel' ? (
          <Alert variant="warning" title="Payment canceled" message="Your payment was canceled. You can try again at any time." closable={false} />
        ) : null}
        {errorMessage ? <Alert variant="danger" title="Payment error" message={errorMessage} closable={false} /> : null}

        {loading ? (
          <Loading text="Loading invoice..." />
        ) : invoice ? (
          <Card>
            <div className="space-y-6">
              <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm text-gray-500">Invoice</p>
                  <p className="text-xl font-semibold text-gray-900">{invoice.number || `#${invoice.id}`}</p>
                </div>
                <Badge variant={statusVariant(invoice.status)} size="lg">
                  {invoice.status || 'pending'}
                </Badge>
              </div>

              <div className="grid gap-4 sm:grid-cols-3">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Issue Date</p>
                  <p className="mt-1 text-sm text-gray-900">{formatDate(invoice.issue_date)}</p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Due Date</p>
                  <p className="mt-1 text-sm text-gray-900">{formatDate(invoice.due_date)}</p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Amount Due</p>
                  <p className="mt-1 text-lg font-semibold text-gray-900">{formatCurrency(amountDue)}</p>
                </div>
              </div>

              {invoice.notes ? (
                <div className="rounded-md bg-gray-50 p-4 text-sm text-gray-700">
                  <p className="font-semibold text-gray-900">Invoice Notes</p>
                  <p className="mt-2 whitespace-pre-wrap">{invoice.notes}</p>
                </div>
              ) : null}

              <div className="space-y-3">
                <p className="text-sm font-medium text-gray-900">Select a payment method</p>
                <div className="flex flex-col gap-3 sm:flex-row">
                  <Button
                    variant="primary"
                    className="w-full sm:w-auto"
                    loading={processing}
                    disabled={!invoice.payment_token || !providers.includes('stripe')}
                    onClick={() => handleCheckout('stripe')}
                  >
                    Pay with Stripe
                  </Button>
                  <Button
                    variant="secondary"
                    className="w-full sm:w-auto"
                    loading={processing}
                    disabled={!invoice.payment_token || !providers.includes('square')}
                    onClick={() => handleCheckout('square')}
                  >
                    Pay with Square
                  </Button>
                  <Button
                    variant="outline"
                    className="w-full sm:w-auto"
                    onClick={() => window.open(`/public/invoices/${token}/pdf`, '_blank')}
                  >
                    Download PDF
                  </Button>
                </div>
                {!providers.length ? (
                  <p className="text-sm text-gray-500">No payment gateways are currently available. Please contact the shop.</p>
                ) : null}
                {!invoice.payment_token && invoice.status !== 'paid' ? (
                  <p className="text-sm text-gray-500">Payment link expired. Refresh the page to generate a new one-time token.</p>
                ) : null}
              </div>
            </div>
          </Card>
        ) : (
          <Alert variant="danger" title="Invoice not available" message="We could not find this invoice." closable={false} />
        )}
      </div>
    </div>
  )
}
