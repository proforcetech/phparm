import { useMemo } from 'react'
import Badge from '../ui/Badge'

const STATUS_VARIANTS = {
  active: 'success',
  inactive: 'default',
  suspended: 'danger',
  pending: 'warning',
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount || 0)
}

export default function CustomerCard({
  customer,
  showActions = true,
  showStats = true,
  onClick,
  onAction,
  actions,
  footer,
}) {
  const statusKey = customer?.status?.toLowerCase()
  const statusVariant = useMemo(
    () => STATUS_VARIANTS[statusKey] || 'default',
    [statusKey]
  )
  const creditBalance = useMemo(
    () => formatCurrency(customer?.credit_balance),
    [customer?.credit_balance]
  )

  const handleClick = () => {
    onClick?.(customer)
  }

  const handleAction = (event) => {
    event.stopPropagation()
    onAction?.(customer)
  }

  return (
    <div
      className="bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 cursor-pointer"
      onClick={handleClick}
      role={onClick ? 'button' : undefined}
      tabIndex={onClick ? 0 : undefined}
    >
      <div className="p-4 sm:p-6">
        <div className="flex items-start justify-between mb-4">
          <div className="flex items-start gap-3 flex-1 min-w-0">
            <div className="flex-shrink-0">
              <div className="h-12 w-12 rounded-full bg-primary-100 flex items-center justify-center">
                <svg className="h-6 w-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth="2"
                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                  />
                </svg>
              </div>
            </div>

            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 mb-1">
                <h3 className="text-lg font-semibold text-gray-900 truncate">
                  {customer?.name || `Customer #${customer?.id}`}
                </h3>
                {customer?.status ? (
                  <Badge variant={statusVariant}>{customer.status}</Badge>
                ) : null}
              </div>
              {customer?.email ? (
                <p className="text-sm text-gray-600 truncate">{customer.email}</p>
              ) : null}
            </div>
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
          {customer?.phone ? (
            <div className="flex items-center gap-2 text-sm">
              <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"
                />
              </svg>
              <span className="text-gray-900">{customer.phone}</span>
            </div>
          ) : null}
          {customer?.address ? (
            <div className="flex items-start gap-2 text-sm">
              <svg className="h-4 w-4 text-gray-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                />
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                />
              </svg>
              <span className="text-gray-900 flex-1">{customer.address}</span>
            </div>
          ) : null}
        </div>

        {showStats ? (
          <div className="border-t border-gray-200 pt-3 grid grid-cols-3 gap-4">
            <div className="text-center">
              <p className="text-2xl font-bold text-gray-900">{customer?.total_invoices || 0}</p>
              <p className="text-xs text-gray-500">Invoices</p>
            </div>
            <div className="text-center">
              <p className="text-2xl font-bold text-gray-900">{customer?.total_vehicles || 0}</p>
              <p className="text-xs text-gray-500">Vehicles</p>
            </div>
            <div className="text-center">
              <p className="text-2xl font-bold text-gray-900">
                {customer?.total_appointments || 0}
              </p>
              <p className="text-xs text-gray-500">Visits</p>
            </div>
          </div>
        ) : null}

        {customer?.credit_account_id ? (
          <div className="border-t border-gray-200 pt-3 mt-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <svg className="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth="2"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                  />
                </svg>
                <span className="text-sm font-medium text-gray-700">Credit Account</span>
              </div>
              {customer?.credit_balance !== undefined ? (
                <span className="text-sm font-semibold text-gray-900">{creditBalance}</span>
              ) : null}
            </div>
          </div>
        ) : null}

        {customer?.tags?.length ? (
          <div className="flex flex-wrap gap-1 mt-3">
            {customer.tags.map((tag) => (
              <span
                key={tag}
                className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800"
              >
                {tag}
              </span>
            ))}
          </div>
        ) : null}

        {footer ? <div>{footer}</div> : null}
      </div>
    </div>
  )
}
