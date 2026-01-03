import { useMemo } from 'react'
import Badge from '../ui/Badge'

const STATUS_VARIANTS = {
  paid: 'success',
  pending: 'warning',
  overdue: 'danger',
  draft: 'default',
  cancelled: 'default',
  partial: 'info',
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount || 0)
}

function formatDate(date) {
  if (!date) return 'N/A'
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(date))
}

function isOverdue(invoice) {
  if (!invoice?.due_date || invoice?.status === 'paid' || invoice?.status === 'cancelled') {
    return false
  }
  return new Date(invoice.due_date) < new Date()
}

export default function InvoiceCard({
  invoice,
  showActions = true,
  showPaymentInfo = true,
  onClick,
  onAction,
  actions,
  footer,
}) {
  const statusKey = invoice?.status?.toLowerCase()
  const statusVariant = useMemo(
    () => STATUS_VARIANTS[statusKey] || 'default',
    [statusKey]
  )
  const formattedIssueDate = useMemo(
    () => formatDate(invoice?.issue_date),
    [invoice?.issue_date]
  )
  const formattedDueDate = useMemo(() => formatDate(invoice?.due_date), [invoice?.due_date])
  const overdue = useMemo(() => isOverdue(invoice), [invoice])
  const totalAmount = useMemo(
    () => formatCurrency(invoice?.total || invoice?.total_amount || 0),
    [invoice?.total, invoice?.total_amount]
  )
  const amountPaid = useMemo(
    () => formatCurrency(invoice?.amount_paid),
    [invoice?.amount_paid]
  )
  const balance = useMemo(() => formatCurrency(invoice?.balance), [invoice?.balance])

  const handleClick = () => {
    onClick?.(invoice)
  }

  const handleAction = (event) => {
    event.stopPropagation()
    onAction?.(invoice)
  }

  return (
    <div
      className={[
        'bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 cursor-pointer',
        statusKey === 'cancelled' ? 'opacity-75' : '',
      ]
        .filter(Boolean)
        .join(' ')}
      onClick={handleClick}
      role={onClick ? 'button' : undefined}
      tabIndex={onClick ? 0 : undefined}
    >
      <div className="p-4 sm:p-6">
        <div className="flex items-start justify-between mb-4">
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 mb-1">
              <h3 className="text-lg font-semibold text-gray-900 truncate">
                #{invoice?.invoice_number}
              </h3>
              <Badge variant={statusVariant}>{invoice?.status}</Badge>
            </div>
            {invoice?.customer_name ? (
              <p className="text-sm text-gray-600 truncate">{invoice.customer_name}</p>
            ) : null}
            {!invoice?.customer_name && invoice?.customer_id ? (
              <p className="text-sm text-gray-600">Customer #{invoice.customer_id}</p>
            ) : null}
          </div>
          <div className="flex-shrink-0 ml-4">
            {actions ??
              (showActions ? (
                <button
                  type="button"
                  onClick={handleAction}
                  className="text-gray-400 hover:text-gray-600"
                >
                  <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth="2"
                      d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"
                    />
                  </svg>
                </button>
              ) : null)}
          </div>
        </div>

        <div className="space-y-2 mb-4">
          <div className="flex items-center justify-between text-sm">
            <span className="text-gray-500">Issue Date</span>
            <span className="text-gray-900">{formattedIssueDate}</span>
          </div>
          {invoice?.due_date ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Due Date</span>
              <span className={overdue ? 'text-red-600 font-medium' : 'text-gray-900'}>
                {formattedDueDate}
                {overdue ? <span className="text-xs ml-1">(Overdue)</span> : null}
              </span>
            </div>
          ) : null}
          {invoice?.vehicle_id ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Vehicle</span>
              <span className="text-gray-900">Vehicle #{invoice.vehicle_id}</span>
            </div>
          ) : null}
        </div>

        <div className="border-t border-gray-200 pt-4">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-gray-500">Total Amount</span>
            <span className="text-xl font-bold text-gray-900">{totalAmount}</span>
          </div>
          {showPaymentInfo && invoice?.amount_paid > 0 ? (
            <div className="flex items-center justify-between mt-2">
              <span className="text-sm text-gray-500">Paid</span>
              <span className="text-sm font-medium text-green-600">{amountPaid}</span>
            </div>
          ) : null}
          {showPaymentInfo && invoice?.balance > 0 ? (
            <div className="flex items-center justify-between mt-1">
              <span className="text-sm text-gray-500">Balance</span>
              <span className="text-sm font-medium text-red-600">{balance}</span>
            </div>
          ) : null}
        </div>

        {footer ? <div>{footer}</div> : null}
      </div>
    </div>
  )
}
