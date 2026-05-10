// @deprecated Phase 2a — frozen for legacy `customer` role. New portal lives at src/react/views/portal/*.
import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import Textarea from '../../components/ui/Textarea'
import { creditService } from '../../../services/credit.service'

const paymentMethods = [
  { label: 'Credit Card', value: 'credit_card' },
  { label: 'ACH/Bank Transfer', value: 'ach' },
  { label: 'Cash', value: 'cash' },
  { label: 'Check', value: 'check' },
  { label: 'Other', value: 'other' },
]

const transactionColumns = [
  { key: 'transaction_type', label: 'Type' },
  { key: 'description', label: 'Description' },
  { key: 'amount', label: 'Amount' },
  { key: 'balance_after', label: 'Balance After' },
  { key: 'occurred_at', label: 'Date' },
]

const reminderColumns = [
  { key: 'reminder_type', label: 'Type' },
  { key: 'sent_via', label: 'Channel' },
  { key: 'status', label: 'Status' },
  { key: 'sent_at', label: 'Sent At' },
  { key: 'message', label: 'Message' },
]

const formatCurrency = (value) => {
  const amount = Number(value) || 0
  return amount.toLocaleString('en-US', { style: 'currency', currency: 'USD' })
}

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

export default function Credit() {
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [history, setHistory] = useState(null)
  const [errorMessage, setErrorMessage] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [paymentForm, setPaymentForm] = useState({
    amount: null,
    payment_method: 'credit_card',
    reference_number: '',
    notes: '',
  })

  const loadHistory = useCallback(async () => {
    setLoading(true)
    setErrorMessage('')
    try {
      const response = await creditService.getCustomerHistory()
      setHistory(response)
    } catch (error) {
      setErrorMessage(error.response?.data?.message || 'Unable to load credit history.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadHistory()
  }, [loadHistory])

  const submitPayment = async (event) => {
    event.preventDefault()
    setSubmitting(true)
    setSuccessMessage('')
    setErrorMessage('')

    try {
      await creditService.submitPayment(paymentForm)
      setSuccessMessage('Payment submitted for review.')
      setPaymentForm((prev) => ({ ...prev, amount: null, reference_number: '', notes: '' }))
      await loadHistory()
    } catch (error) {
      setErrorMessage(error.response?.data?.message || 'Unable to submit payment.')
    } finally {
      setSubmitting(false)
    }
  }

  const transactionRenderers = useMemo(
    () => ({
      amount: ({ value, row }) => (
        <span className={row.transaction_type === 'payment' ? 'text-green-700' : 'text-red-700'}>
          {formatCurrency(value)}
        </span>
      ),
      balance_after: ({ value }) => formatCurrency(value),
      occurred_at: ({ value }) => formatDate(value),
    }),
    []
  )

  const reminderRenderers = useMemo(
    () => ({
      sent_at: ({ value }) => formatDate(value),
      message: ({ value }) => <span className="line-clamp-2">{value || 'N/A'}</span>,
    }),
    []
  )

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Credit Account</h1>
          <p className="mt-1 text-sm text-gray-500">View your credit balance, history, and submit payments for review.</p>
        </div>
      </div>

      {errorMessage ? (
        <Alert variant="danger" className="mb-4" onClose={() => setErrorMessage('')}>
          {errorMessage}
        </Alert>
      ) : null}

      {successMessage ? (
        <Alert variant="success" className="mb-4" onClose={() => setSuccessMessage('')}>
          {successMessage}
        </Alert>
      ) : null}

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <Card>
          <div className="p-4">
            <p className="text-sm text-gray-500">Current Balance</p>
            <p className="mt-2 text-2xl font-semibold text-gray-900">
              {formatCurrency(history?.balance || 0)}
            </p>
          </div>
        </Card>
        <Card>
          <div className="p-4">
            <p className="text-sm text-gray-500">Available Credit</p>
            <p className="mt-2 text-2xl font-semibold text-gray-900">
              {formatCurrency(history?.available_credit || 0)}
            </p>
          </div>
        </Card>
        <Card>
          <div className="p-4">
            <p className="text-sm text-gray-500">Credit Limit</p>
            <p className="mt-2 text-2xl font-semibold text-gray-900">
              {formatCurrency(history?.account?.credit_limit || 0)}
            </p>
          </div>
        </Card>
        <Card>
          <div className="p-4">
            <p className="text-sm text-gray-500">Status</p>
            <Badge variant={history?.account?.status === 'active' ? 'success' : 'secondary'}>
              {history?.account?.status || 'inactive'}
            </Badge>
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <Card className="lg:col-span-2">
          <div className="p-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold text-gray-900">Recent Transactions</h2>
              <span className="text-sm text-gray-500">Showing last {history?.transactions?.length || 0}</span>
            </div>
            <Table
              columns={transactionColumns}
              data={history?.transactions || []}
              loading={loading}
              pagination={false}
              selectable={false}
              hoverable={false}
              cellRenderers={transactionRenderers}
            />
          </div>
        </Card>

        <Card>
          <div className="p-4 space-y-4">
            <div>
              <h2 className="text-lg font-semibold text-gray-900">Pending & Completed Payments</h2>
              <p className="text-sm text-gray-500">Submitted payments remain pending until processed by staff.</p>
            </div>
            <div className="space-y-3 max-h-80 overflow-y-auto">
              {history?.payments?.map((payment) => (
                <div key={payment.id} className="border border-gray-200 rounded-lg p-3">
                  <div className="flex items-center justify-between">
                    <div className="text-sm text-gray-500">{formatDate(payment.payment_date)}</div>
                    <Badge variant={payment.status === 'completed' ? 'success' : 'warning'}>{payment.status}</Badge>
                  </div>
                  <div className="mt-2 text-lg font-semibold text-gray-900">{formatCurrency(payment.amount)}</div>
                  <p className="text-sm text-gray-600">{payment.payment_method}</p>
                  {payment.reference_number ? (
                    <p className="text-xs text-gray-500">Ref: {payment.reference_number}</p>
                  ) : null}
                </div>
              ))}
              {!history?.payments?.length && !loading ? (
                <p className="text-sm text-gray-500">No payments yet.</p>
              ) : null}
            </div>
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <Card className="lg:col-span-2">
          <div className="p-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold text-gray-900">Reminder History</h2>
              <span className="text-sm text-gray-500">Automated notices for upcoming/past-due payments.</span>
            </div>
            <Table
              columns={reminderColumns}
              data={history?.reminders || []}
              loading={loading}
              pagination={false}
              selectable={false}
              hoverable={false}
              cellRenderers={reminderRenderers}
            />
          </div>
        </Card>

        <Card>
          <div className="p-4 space-y-4">
            <div>
              <h2 className="text-lg font-semibold text-gray-900">Submit a Payment</h2>
              <p className="text-sm text-gray-500">
                Send a payment for staff review. Approved payments are applied to your balance.
              </p>
            </div>

            <form className="space-y-3" onSubmit={submitPayment}>
              <Input
                modelValue={paymentForm.amount ?? ''}
                type="number"
                step="0.01"
                label="Payment Amount"
                placeholder="Enter amount"
                required
                onUpdateModelValue={(value) =>
                  setPaymentForm((prev) => ({
                    ...prev,
                    amount: value === '' ? null : Number(value),
                  }))
                }
              />

              <Select
                modelValue={paymentForm.payment_method}
                options={paymentMethods}
                label="Payment Method"
                onUpdateModelValue={(value) =>
                  setPaymentForm((prev) => ({
                    ...prev,
                    payment_method: value,
                  }))
                }
              />

              <Input
                modelValue={paymentForm.reference_number}
                label="Reference Number"
                placeholder="Optional reference"
                onUpdateModelValue={(value) =>
                  setPaymentForm((prev) => ({
                    ...prev,
                    reference_number: value,
                  }))
                }
              />

              <Textarea
                modelValue={paymentForm.notes}
                label="Notes"
                placeholder="Any additional context"
                rows={3}
                onUpdateModelValue={(value) =>
                  setPaymentForm((prev) => ({
                    ...prev,
                    notes: value,
                  }))
                }
              />

              <Button disabled={submitting} type="submit" className="w-full">
                {submitting ? (
                  <>
                    <svg
                      className="animate-spin -ml-1 mr-3 h-5 w-5 text-white"
                      xmlns="http://www.w3.org/2000/svg"
                      fill="none"
                      viewBox="0 0 24 24"
                    >
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path
                        className="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8v4l3-3-3-3v4a12 12 0 00-12 12h4z"
                      />
                    </svg>
                    Submitting
                  </>
                ) : (
                  'Submit Payment'
                )}
              </Button>
            </form>
          </div>
        </Card>
      </div>
    </div>
  )
}
