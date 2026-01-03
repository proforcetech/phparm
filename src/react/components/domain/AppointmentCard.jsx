import { useMemo } from 'react'
import Badge from '../ui/Badge'

const STATUS_COLORS = {
  pending: '#F59E0B',
  confirmed: '#3B82F6',
  in_progress: '#8B5CF6',
  completed: '#10B981',
  cancelled: '#EF4444',
  no_show: '#6B7280',
}

const STATUS_VARIANTS = {
  pending: 'warning',
  confirmed: 'info',
  in_progress: 'default',
  completed: 'success',
  cancelled: 'danger',
  no_show: 'default',
}

function formatDate(dateTime) {
  if (!dateTime) return 'N/A'
  return new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(dateTime))
}

function formatTime(dateTime) {
  if (!dateTime) return ''
  return new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(dateTime))
}

function getDuration(appointment) {
  if (!appointment?.start_time || !appointment?.end_time) return 'N/A'

  const start = new Date(appointment.start_time)
  const end = new Date(appointment.end_time)
  const diffMs = end - start
  const diffMins = Math.floor(diffMs / 60000)

  if (diffMins < 60) {
    return `${diffMins} min`
  }
  const hours = Math.floor(diffMins / 60)
  const mins = diffMins % 60
  return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`
}

export default function AppointmentCard({
  appointment,
  showActions = true,
  showNotes = true,
  showDuration = true,
  onClick,
  onAction,
  actions,
  footer,
}) {
  const status = appointment?.status || ''
  const statusKey = status.toLowerCase()
  const statusColor = useMemo(
    () => STATUS_COLORS[statusKey] || '#6B7280',
    [statusKey]
  )
  const statusVariant = useMemo(
    () => STATUS_VARIANTS[statusKey] || 'default',
    [statusKey]
  )
  const formattedDate = useMemo(
    () => formatDate(appointment?.start_time),
    [appointment?.start_time]
  )
  const startTime = useMemo(
    () => formatTime(appointment?.start_time),
    [appointment?.start_time]
  )
  const endTime = useMemo(
    () => formatTime(appointment?.end_time),
    [appointment?.end_time]
  )
  const duration = useMemo(
    () => getDuration(appointment),
    [appointment?.start_time, appointment?.end_time]
  )

  const handleClick = () => {
    onClick?.(appointment)
  }

  const handleAction = (event) => {
    event.stopPropagation()
    onAction?.(appointment)
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
        <div className="flex items-start gap-3 mb-4">
          <div
            className="w-1 h-16 rounded-full flex-shrink-0"
            style={{ backgroundColor: statusColor }}
          />
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 mb-1">
              <Badge variant={statusVariant}>{status}</Badge>
              {appointment?.service_type ? (
                <span className="text-sm text-gray-600">{appointment.service_type}</span>
              ) : null}
            </div>
            {appointment?.customer_name ? (
              <p className="text-base font-semibold text-gray-900 truncate">
                {appointment.customer_name}
              </p>
            ) : null}
            {!appointment?.customer_name && appointment?.customer_id ? (
              <p className="text-base font-semibold text-gray-900">
                Customer #{appointment.customer_id}
              </p>
            ) : null}
            {!appointment?.customer_name && !appointment?.customer_id ? (
              <p className="text-base font-semibold text-gray-500">Walk-in</p>
            ) : null}
          </div>
          <div className="flex-shrink-0">
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
          <div className="flex items-center gap-2 text-sm">
            <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
              />
            </svg>
            <span className="font-medium text-gray-900">{formattedDate}</span>
          </div>
          <div className="flex items-center gap-2 text-sm">
            <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
            <span className="text-gray-600">
              {startTime} - {endTime}
            </span>
          </div>
        </div>

        <div className="space-y-2 border-t border-gray-200 pt-3">
          {appointment?.vehicle_id ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Vehicle</span>
              <span className="text-gray-900">Vehicle #{appointment.vehicle_id}</span>
            </div>
          ) : null}
          {appointment?.technician_id ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Technician</span>
              <span className="text-gray-900">Tech {appointment.technician_id}</span>
            </div>
          ) : null}
          {showDuration ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Duration</span>
              <span className="text-gray-900">{duration}</span>
            </div>
          ) : null}
        </div>

        {appointment?.notes && showNotes ? (
          <div className="mt-3 border-t border-gray-200 pt-3">
            <p className="text-xs text-gray-500 uppercase tracking-wide mb-1">Notes</p>
            <p
              className="text-sm text-gray-700"
              style={{
                display: '-webkit-box',
                WebkitLineClamp: 2,
                WebkitBoxOrient: 'vertical',
                overflow: 'hidden',
              }}
            >
              {appointment.notes}
            </p>
          </div>
        ) : null}

        {footer ? <div>{footer}</div> : null}
      </div>
    </div>
  )
}
