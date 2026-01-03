import { useMemo, useRef } from 'react'

export default function Textarea({
  id,
  modelValue,
  value,
  label = '',
  placeholder = '',
  error = '',
  helperText = '',
  disabled = false,
  required = false,
  rows = 4,
  maxlength = null,
  fullWidth = true,
  onUpdateModelValue,
  onChange,
  onBlur,
  onFocus,
  className = '',
  ...rest
}) {
  const fallbackId = useRef(`textarea-${Math.random().toString(36).substr(2, 9)}`)
  const textareaId = id || fallbackId.current
  const resolvedValue = modelValue ?? value ?? ''

  const textareaClasses = useMemo(() => {
    const base =
      'block w-full rounded-md shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-0 disabled:opacity-50 disabled:cursor-not-allowed sm:text-sm px-3 py-2'

    if (error) {
      return `${base} border-red-300 text-red-900 placeholder-red-300 focus:ring-red-500 focus:border-red-500`
    }

    return `${base} border-gray-300 focus:ring-primary-500 focus:border-primary-500`
  }, [error])

  const handleChange = (event) => {
    onUpdateModelValue?.(event.target.value)
    onChange?.(event)
  }

  return (
    <div className={`${fullWidth ? 'w-full' : ''} ${className}`}>
      {label ? (
        <label htmlFor={textareaId} className="block text-sm font-medium text-gray-700 mb-1">
          {label}
          {required ? <span className="text-red-500">*</span> : null}
        </label>
      ) : null}

      <textarea
        id={textareaId}
        value={resolvedValue}
        placeholder={placeholder}
        disabled={disabled}
        required={required}
        rows={rows}
        maxLength={maxlength ?? undefined}
        className={textareaClasses}
        onChange={handleChange}
        onBlur={onBlur}
        onFocus={onFocus}
        {...rest}
      />

      {maxlength ? (
        <div className="mt-1 flex justify-between items-center">
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          {!error && helperText ? <p className="text-sm text-gray-500">{helperText}</p> : null}
          {!error && !helperText ? <span className="flex-1" /> : null}
          <span className="text-xs text-gray-500">
            {(resolvedValue?.length || 0)} / {maxlength}
          </span>
        </div>
      ) : (
        <div>
          {error ? <p className="mt-1 text-sm text-red-600">{error}</p> : null}
          {!error && helperText ? <p className="mt-1 text-sm text-gray-500">{helperText}</p> : null}
        </div>
      )}
    </div>
  )
}
