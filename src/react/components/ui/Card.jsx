export default function Card({
  title = '',
  padding = true,
  shadow = 'md',
  hover = false,
  header = null,
  footer = null,
  className = '',
  children,
  ...rest
}) {
  const shadowClasses = {
    none: '',
    sm: 'shadow-sm',
    md: 'shadow-md',
    lg: 'shadow-lg',
    xl: 'shadow-xl',
  }

  const cardClasses = [
    'bg-white rounded-lg overflow-hidden',
    shadowClasses[shadow],
    hover ? 'transition-shadow hover:shadow-lg cursor-pointer' : '',
    className,
  ]
    .filter(Boolean)
    .join(' ')

  const bodyClasses = padding ? 'p-6' : ''

  const shouldRenderHeader = Boolean(title || header)

  return (
    <div className={cardClasses} {...rest}>
      {shouldRenderHeader ? (
        <div className="px-6 py-4 border-b border-gray-200">
          {header ?? <h3 className="text-lg font-medium text-gray-900">{title}</h3>}
        </div>
      ) : null}

      <div className={bodyClasses}>{children}</div>

      {footer ? (
        <div className="px-6 py-4 bg-gray-50 border-t border-gray-200">{footer}</div>
      ) : null}
    </div>
  )
}
