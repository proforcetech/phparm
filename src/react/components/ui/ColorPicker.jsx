import { useEffect, useId, useRef, useState } from 'react'

const presetColors = [
  '#EF4444', '#F97316', '#F59E0B', '#EAB308', '#84CC16',
  '#22C55E', '#10B981', '#14B8A6', '#06B6D4', '#0EA5E9',
  '#3B82F6', '#6366F1', '#8B5CF6', '#A855F7', '#D946EF',
  '#EC4899', '#F43F5E', '#78716C', '#6B7280', '#1F2937',
]

export default function ColorPicker({
  id,
  value = '',
  label = '',
  error = '',
  required = false,
  disabled = false,
  onChange,
  className = '',
}) {
  const fallbackId = useId()
  const inputId = id || `color-picker-${fallbackId}`
  const [showPicker, setShowPicker] = useState(false)
  const [inputValue, setInputValue] = useState(value || '')
  const containerRef = useRef(null)

  useEffect(() => {
    setInputValue(value || '')
  }, [value])

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (containerRef.current && !containerRef.current.contains(event.target)) {
        setShowPicker(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const normalizeColor = (color) => {
    if (!color) return ''
    const trimmed = color.trim()
    if (trimmed.startsWith('#')) return trimmed.toUpperCase()
    if (/^[0-9A-Fa-f]{6}$/.test(trimmed)) return `#${trimmed.toUpperCase()}`
    return trimmed.toUpperCase()
  }

  const handleInputChange = (event) => {
    const newValue = event.target.value
    setInputValue(newValue)
  }

  const handleInputBlur = () => {
    const normalized = normalizeColor(inputValue)
    setInputValue(normalized)
    onChange?.(normalized)
  }

  const handlePresetClick = (color) => {
    setInputValue(color)
    onChange?.(color)
    setShowPicker(false)
  }

  const handleNativeChange = (event) => {
    const color = event.target.value.toUpperCase()
    setInputValue(color)
    onChange?.(color)
  }

  const displayColor = normalizeColor(inputValue) || '#CCCCCC'

  return (
    <div className={`w-full ${className}`} ref={containerRef}>
      {label ? (
        <label htmlFor={inputId} className="block text-sm font-medium text-gray-700 mb-1">
          {label}
          {required ? <span className="text-red-500">*</span> : null}
        </label>
      ) : null}

      <div className="relative">
        <div className="flex">
          <button
            type="button"
            disabled={disabled}
            onClick={() => setShowPicker(!showPicker)}
            className="flex items-center justify-center w-10 h-10 border border-r-0 border-gray-300 rounded-l-md bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
            style={{ backgroundColor: displayColor }}
          >
            <span className="sr-only">Choose color</span>
          </button>
          <input
            id={inputId}
            type="text"
            value={inputValue}
            placeholder="#1F2937"
            disabled={disabled}
            className={`flex-1 block w-full rounded-r-md shadow-sm sm:text-sm py-2 px-3 border-gray-300 focus:ring-primary-500 focus:border-primary-500 disabled:opacity-50 disabled:cursor-not-allowed ${
              error ? 'border-red-300 text-red-900' : ''
            }`}
            onChange={handleInputChange}
            onBlur={handleInputBlur}
          />
        </div>

        {showPicker && !disabled ? (
          <div className="absolute z-10 mt-1 p-3 bg-white border border-gray-200 rounded-lg shadow-lg w-64">
            <div className="grid grid-cols-5 gap-2 mb-3">
              {presetColors.map((color) => (
                <button
                  key={color}
                  type="button"
                  onClick={() => handlePresetClick(color)}
                  className={`w-8 h-8 rounded border-2 transition-transform hover:scale-110 ${
                    normalizeColor(inputValue) === color ? 'border-gray-900 ring-2 ring-offset-1 ring-gray-400' : 'border-transparent'
                  }`}
                  style={{ backgroundColor: color }}
                  title={color}
                >
                  <span className="sr-only">{color}</span>
                </button>
              ))}
            </div>
            <div className="flex items-center gap-2 pt-2 border-t border-gray-100">
              <span className="text-xs text-gray-500">Custom:</span>
              <input
                type="color"
                value={displayColor}
                onChange={handleNativeChange}
                className="w-8 h-8 p-0 border-0 rounded cursor-pointer"
              />
            </div>
          </div>
        ) : null}
      </div>

      {error ? <p className="mt-1 text-sm text-red-600">{error}</p> : null}
    </div>
  )
}
