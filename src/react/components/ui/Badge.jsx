export default function Badge({
  variant = 'default',
  size = 'md',
  rounded = false,
  dot = false,
  className = '',
  children,
  ...rest
}) {
  const variantClasses = {
    default: 'bg-gray-100 text-gray-800',
    secondary: 'bg-gray-200 text-gray-800',
    success: 'bg-green-100 text-green-800',
    warning: 'bg-yellow-100 text-yellow-800',
    danger: 'bg-red-100 text-red-800',
    info: 'bg-blue-100 text-blue-800',
    primary: 'bg-primary-100 text-primary-800',
  }

  const sizeClasses = {
    sm: 'px-2 py-0.5 text-xs',
    md: 'px-2.5 py-0.5 text-sm',
    lg: 'px-3 py-1 text-base',
  }

  const classes = [
    'inline-flex items-center font-medium',
    variantClasses[variant],
    sizeClasses[size],
    rounded ? 'rounded-full' : 'rounded',
    dot ? 'gap-1.5' : '',
    className,
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <span className={classes} {...rest}>
      {dot ? <span className="inline-block h-1.5 w-1.5 rounded-full bg-current" /> : null}
      {children}
    </span>
  )
}
