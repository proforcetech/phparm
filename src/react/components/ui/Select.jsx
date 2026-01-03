import { useRef } from 'react'

export default function Select({
  id,
  modelValue,
  value,
  label = '',
  placeholder = 'Select an option',
  options = [],
  valueKey = 'value',
  labelKey = 'label',
  error = '',
  helperText = '',
  disabled = false,
  required = false,
  fullWidth = true,
  onUpdateModelValue,
  onChange,
  className = '',
  ...rest
}) {
  const fallbackId = useRef(`select-${Math.random().toString(36).substr(2, 9)}`)
  const selectId = id || fallbackId.current
  const resolvedValue = modelValue ?? value ?? ''

  const selectClasses = (() => {
    const base =
      'block w-full rounded-md shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-0 disabled:opacity-50 disabled:cursor-not-allowed sm:text-sm px-3 py-2'

    if (error) {
      return `${base} border-red-300 text-red-900 focus:ring-red-500 focus:border-red-500`
    }

    return `${base} border-gray-300 focus:ring-primary-500 focus:border-primary-500`
  })()

  const getOptionValue = (option) => {
    return typeof option === 'object' && option !== null ? option[valueKey] : option
  }

  const getOptionLabel = (option) => {
    return typeof option === 'object' && option !== null ? option[labelKey] : option
  }

  const handleChange = (event) => {
    const { selectedIndex } = event.target
    const offset = placeholder ? 1 : 0
    const option = options[selectedIndex - offset]
    const parsedValue = option !== undefined ? getOptionValue(option) : event.target.value

    onUpdateModelValue?.(parsedValue)
    onChange?.(event)
  }

  return (
    <div className={`${fullWidth ? 'w-full' : ''} ${className}`}>
      {label ? (
        <label htmlFor={selectId} className="block text-sm font-medium text-gray-700 mb-1">
          {label}
          {required ? <span className="text-red-500">*</span> : null}
        </label>
      ) : null}

      <select
        id={selectId}
        value={resolvedValue}
        disabled={disabled}
        required={required}
        className={selectClasses}
        onChange={handleChange}
        {...rest}
      >
        {placeholder ? (
          <option value="" disabled>
            {placeholder}
          </option>
        ) : null}
        {options.map((option) => (
          <option key={getOptionValue(option)} value={getOptionValue(option)}>
            {getOptionLabel(option)}
          </option>
        ))}
      </select>

      {error ? <p className="mt-1 text-sm text-red-600">{error}</p> : null}
      {!error && helperText ? <p className="mt-1 text-sm text-gray-500">{helperText}</p> : null}
    </div>
  )
}
