import { cloneElement, isValidElement, useEffect, useId, useRef, useState } from 'react'

import './Input.css'

export default function Input({
  id,
  modelValue,
  value,
  type = 'text',
  label = '',
  placeholder = '',
  error = '',
  helperText = '',
  disabled = false,
  required = false,
  autocomplete = 'off',
  icon = null,
  fullWidth = true,
  onChange,
  onUpdateModelValue,
  onBlur,
  onFocus,
  onValidationError,
  className = '',
  ...rest
}) {
  const fallbackId = useId()
  const inputId = id || `input-${fallbackId}`
  const inputRef = useRef(null)
  const [isShaking, setIsShaking] = useState(false)
  const previousError = useRef(error)
  const shakeTimeout = useRef(null)

  useEffect(() => {
    if (!error || error === previousError.current) {
      previousError.current = error
      return undefined
    }

    onValidationError?.(error)
    setIsShaking(true)

    if (inputRef.current?.scrollIntoView) {
      inputRef.current.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }

    if (shakeTimeout.current) {
      clearTimeout(shakeTimeout.current)
    }

    shakeTimeout.current = setTimeout(() => {
      setIsShaking(false)
    }, 450)

    previousError.current = error

    return () => {
      if (shakeTimeout.current) {
        clearTimeout(shakeTimeout.current)
      }
    }
  }, [error, onValidationError])

  const baseClasses =
    'block w-full rounded-md shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-0 disabled:opacity-50 disabled:cursor-not-allowed sm:text-sm'
  const shakeClass = isShaking ? 'input-shake' : ''

  const inputClasses = error
    ? `${baseClasses} ${shakeClass} border-red-300 text-red-900 placeholder-red-300 focus:ring-red-500 focus:border-red-500`
    : `${baseClasses} ${icon ? 'pl-10' : 'px-3'} ${shakeClass} py-2 border-gray-300 focus:ring-primary-500 focus:border-primary-500`

  const resolvedValue = modelValue ?? value ?? ''

  const handleChange = (event) => {
    onUpdateModelValue?.(event.target.value)
    onChange?.(event)
  }

  const iconElement = (() => {
    if (!icon) {
      return null
    }

    if (typeof icon === 'function') {
      const IconComponent = icon
      return <IconComponent className="h-5 w-5 text-gray-400" />
    }

    if (isValidElement(icon)) {
      return cloneElement(icon, {
        className: ['h-5 w-5 text-gray-400', icon.props?.className].filter(Boolean).join(' '),
      })
    }

    return null
  })()

  return (
    <div className={fullWidth ? `w-full ${className}` : className}>
      {label ? (
        <label htmlFor={inputId} className="block text-sm font-medium text-gray-700 mb-1">
          {label}
          {required ? <span className="text-red-500">*</span> : null}
        </label>
      ) : null}

      <div className="relative">
        <input
          id={inputId}
          type={type}
          value={resolvedValue}
          placeholder={placeholder}
          disabled={disabled}
          required={required}
          autoComplete={autocomplete}
          className={inputClasses}
          ref={inputRef}
          onChange={handleChange}
          onBlur={onBlur}
          onFocus={onFocus}
          {...rest}
        />

        {iconElement ? (
          <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            {iconElement}
          </div>
        ) : null}
      </div>

      {error ? <p className="mt-1 text-sm text-red-600">{error}</p> : null}
      {!error && helperText ? <p className="mt-1 text-sm text-gray-500">{helperText}</p> : null}
    </div>
  )
}
