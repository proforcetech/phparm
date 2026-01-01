function CheckCircleIcon({ className }) {
  return (
    <svg className={className} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.707a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
        clipRule="evenodd"
      />
    </svg>
  )
}

function ExclamationTriangleIcon({ className }) {
  return (
    <svg className={className} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M8.485 3.879a1.5 1.5 0 012.63 0l6.518 11.867A1.5 1.5 0 0116.318 18H3.682a1.5 1.5 0 01-1.315-2.254L8.485 3.88zM10 7a1 1 0 00-.993.883L9 8v4a1 1 0 001.993.117L11 12V8a1 1 0 00-1-1zm0 8a1.25 1.25 0 100-2.5 1.25 1.25 0 000 2.5z"
        clipRule="evenodd"
      />
    </svg>
  )
}

function XCircleIcon({ className }) {
  return (
    <svg className={className} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M10 18a8 8 0 100-16 8 8 0 000 16zm2.707-10.707a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293z"
        clipRule="evenodd"
      />
    </svg>
  )
}

function InformationCircleIcon({ className }) {
  return (
    <svg className={className} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-4a1 1 0 100 2 1 1 0 000-2zm1 3a1 1 0 10-2 0v5a1 1 0 002 0V9z"
        clipRule="evenodd"
      />
    </svg>
  )
}

const variantConfig = {
  success: {
    bg: 'bg-green-50',
    icon: CheckCircleIcon,
    iconColor: 'text-green-400',
    titleColor: 'text-green-800',
    textColor: 'text-green-700',
    closeColor: 'text-green-500 hover:bg-green-100',
  },
  warning: {
    bg: 'bg-yellow-50',
    icon: ExclamationTriangleIcon,
    iconColor: 'text-yellow-400',
    titleColor: 'text-yellow-800',
    textColor: 'text-yellow-700',
    closeColor: 'text-yellow-500 hover:bg-yellow-100',
  },
  danger: {
    bg: 'bg-red-50',
    icon: XCircleIcon,
    iconColor: 'text-red-400',
    titleColor: 'text-red-800',
    textColor: 'text-red-700',
    closeColor: 'text-red-500 hover:bg-red-100',
  },
  info: {
    bg: 'bg-blue-50',
    icon: InformationCircleIcon,
    iconColor: 'text-blue-400',
    titleColor: 'text-blue-800',
    textColor: 'text-blue-700',
    closeColor: 'text-blue-500 hover:bg-blue-100',
  },
}

export default function Alert({
  modelValue = true,
  variant = 'info',
  title = '',
  message = '',
  closable = true,
  onUpdateModelValue,
  onClose,
  children,
  className = '',
  ...rest
}) {
  if (!modelValue) {
    return null
  }

  const config = variantConfig[variant] || variantConfig.info
  const Icon = config.icon
  const alertClasses = `rounded-md p-4 ${config.bg} ${className}`
  const titleClasses = `text-sm font-medium ${config.titleColor}`
  const contentClasses = `text-sm ${config.textColor} ${title ? 'mt-2' : ''}`
  const closeButtonClasses =
    `inline-flex rounded-md p-1.5 focus:outline-none focus:ring-2 focus:ring-offset-2 ${config.closeColor}`

  const handleClose = () => {
    onUpdateModelValue?.(false)
    onClose?.()
  }

  return (
    <div className={alertClasses} role="alert" {...rest}>
      <div className="flex">
        <div className="flex-shrink-0">
          <Icon className={`h-5 w-5 ${config.iconColor}`} aria-hidden="true" />
        </div>

        <div className="ml-3 flex-1">
          {title ? <h3 className={titleClasses}>{title}</h3> : null}
          <div className={contentClasses}>{children ?? <p>{message}</p>}</div>
        </div>

        {closable ? (
          <div className="ml-auto pl-3">
            <div className="-mx-1.5 -my-1.5">
              <button type="button" className={closeButtonClasses} onClick={handleClose}>
                <span className="sr-only">Dismiss</span>
                <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                  <path
                    fillRule="evenodd"
                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                    clipRule="evenodd"
                  />
                </svg>
              </button>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  )
}
