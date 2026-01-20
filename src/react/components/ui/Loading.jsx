export default function Loading({
  size = 'md',
  variant = 'spinner',
  color = 'primary',
  text = '',
  fullscreen = false,
  overlay = false,
  className = '',
  ...rest
}) {
  const sizeMap = {
    sm: 'h-4 w-4',
    md: 'h-8 w-8',
    lg: 'h-12 w-12',
    xl: 'h-16 w-16',
  }

  const colorClasses = {
    primary: 'text-primary-600',
    white: 'text-white',
    gray: 'text-gray-600',
  }

  const sizeClasses = sizeMap[size]
  const colorClass = colorClasses[color] || colorClasses.primary

  const spinner = (
    <svg
      className={`animate-spin ${colorClass}`}
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
      role="status"
      aria-label="Loading"
    >
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path
        className="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
      />
    </svg>
  )

  const dots = (
    <div className="flex space-x-2">
      <div className={`${sizeClasses} ${colorClass} rounded-full bg-current animate-bounce`} />
      <div
        className={`${sizeClasses} ${colorClass} rounded-full bg-current animate-bounce`}
        style={{ animationDelay: '0.1s' }}
      />
      <div
        className={`${sizeClasses} ${colorClass} rounded-full bg-current animate-bounce`}
        style={{ animationDelay: '0.2s' }}
      />
    </div>
  )

  const pulse = <div className={`${sizeClasses} ${colorClass} rounded-full bg-current animate-pulse`} />

  const spinnerElement = variant === 'dots' ? dots : variant === 'pulse' ? pulse : spinner

  if (fullscreen) {
    return (
      <div
        className={`fixed inset-0 bg-white bg-opacity-75 flex items-center justify-center z-50 ${className}`}
        {...rest}
      >
        <div className="text-center">
          {spinnerElement}
          {text ? <p className="mt-4 text-gray-600">{text}</p> : null}
        </div>
      </div>
    )
  }

  if (overlay) {
    return (
      <div
        className={`absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center rounded-lg ${className}`}
        {...rest}
      >
        <div className="text-center">
          {spinnerElement}
          {text ? <p className="mt-4 text-gray-600">{text}</p> : null}
        </div>
      </div>
    )
  }

  return (
    <div
      className={`flex items-center justify-center ${text ? 'flex-col' : ''} ${className}`}
      {...rest}
    >
      {spinnerElement}
      {text ? (
        <p className={text ? 'mt-2 text-gray-600' : 'ml-2 text-gray-600'}>{text}</p>
      ) : null}
    </div>
  )
}
