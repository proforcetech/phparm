import { useEffect, useMemo, useRef, useState } from 'react'

export default function Autocomplete({
  id,
  modelValue = null,
  label = '',
  placeholder = 'Search...',
  error = '',
  helperText = '',
  disabled = false,
  required = false,
  fullWidth = true,
  searchFn,
  itemValue = 'id',
  itemLabel = 'name',
  itemSubtext = null,
  minChars = 1,
  debounce = 300,
  onSearchChange,
  onUpdateModelValue,
  onSelect,
  renderItem,
  className = '',
  ...rest
}) {
  const fallbackId = useRef(`autocomplete-${Math.random().toString(36).substr(2, 9)}`)
  const inputId = id || fallbackId.current
  const inputRef = useRef(null)
  const debounceTimer = useRef(null)
  const blurTimer = useRef(null)

  const [searchQuery, setSearchQuery] = useState('')
  const [results, setResults] = useState([])
  const [loading, setLoading] = useState(false)
  const [showDropdown, setShowDropdown] = useState(false)
  const [highlightedIndex, setHighlightedIndex] = useState(0)
  const [selectedItem, setSelectedItem] = useState(null)

  const inputClasses = useMemo(() => {
    const base = 'block w-full rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm'
    const state = error
      ? 'border-red-300 text-red-900 placeholder-red-300'
      : 'border-gray-300'
    const padding = 'pr-10'

    return `${base} ${state} ${padding}`
  }, [error])

  const displayValue = selectedItem ? getItemLabel(selectedItem) : searchQuery

  function getItemValue(item) {
    if (typeof itemValue === 'function') {
      return itemValue(item)
    }
    return item?.[itemValue]
  }

  function getItemLabel(item) {
    if (typeof itemLabel === 'function') {
      return itemLabel(item)
    }
    return item?.[itemLabel]
  }

  function getItemSubtext(item) {
    if (!itemSubtext) return null
    if (typeof itemSubtext === 'function') {
      return itemSubtext(item)
    }
    return item?.[itemSubtext]
  }

  const performSearch = async (query) => {
    if (!searchFn) return
    if (query.length < minChars) {
      setResults([])
      return
    }

    setLoading(true)
    try {
      const data = await searchFn(query)
      setResults(data || [])
      setHighlightedIndex(0)
    } catch (searchError) {
      console.error('Search failed:', searchError)
      setResults([])
    } finally {
      setLoading(false)
    }
  }

  const onInput = (event) => {
    const value = event.target.value
    setSearchQuery(value)
    setSelectedItem(null)
    onUpdateModelValue?.(null)
    onSearchChange?.(value)
    setShowDropdown(true)

    if (debounceTimer.current) {
      clearTimeout(debounceTimer.current)
    }

    debounceTimer.current = setTimeout(() => {
      performSearch(value)
    }, debounce)
  }

  const onFocus = () => {
    setShowDropdown(true)
    if (searchQuery.length >= minChars && results.length === 0) {
      performSearch(searchQuery)
    }
  }

  const onBlur = () => {
    if (blurTimer.current) {
      clearTimeout(blurTimer.current)
    }

    blurTimer.current = setTimeout(() => {
      setShowDropdown(false)
    }, 200)
  }

  const selectItem = (item) => {
    setSelectedItem(item)
    const value = getItemValue(item)
    onUpdateModelValue?.(value)
    onSelect?.(item)
    setShowDropdown(false)
    setSearchQuery(getItemLabel(item))
  }

  const clearSelection = () => {
    setSelectedItem(null)
    setSearchQuery('')
    onUpdateModelValue?.(null)
    onSearchChange?.('')
    setResults([])
    setShowDropdown(false)
    inputRef.current?.focus()
  }

  const navigateDown = () => {
    setHighlightedIndex((index) => Math.min(index + 1, results.length - 1))
  }

  const navigateUp = () => {
    setHighlightedIndex((index) => Math.max(index - 1, 0))
  }

  const selectHighlighted = () => {
    if (results[highlightedIndex]) {
      selectItem(results[highlightedIndex])
    }
  }

  const closeDropdown = () => {
    setShowDropdown(false)
  }

  const handleKeyDown = (event) => {
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault()
        navigateDown()
        break
      case 'ArrowUp':
        event.preventDefault()
        navigateUp()
        break
      case 'Enter':
        event.preventDefault()
        selectHighlighted()
        break
      case 'Escape':
        closeDropdown()
        break
      default:
        break
    }
  }

  useEffect(() => {
    return () => {
      if (debounceTimer.current) {
        clearTimeout(debounceTimer.current)
      }
      if (blurTimer.current) {
        clearTimeout(blurTimer.current)
      }
    }
  }, [])

  useEffect(() => {
    let isCancelled = false

    const loadInitialValue = async () => {
      if (!searchFn) return
      if (modelValue && !selectedItem) {
        setLoading(true)
        try {
          const data = await searchFn(String(modelValue))
          if (isCancelled) return
          const item = data?.find((entry) => getItemValue(entry) === modelValue)
          if (item) {
            setSelectedItem(item)
            setSearchQuery(getItemLabel(item))
          }
        } catch (searchError) {
          if (!isCancelled) {
            console.error('Failed to load initial value:', searchError)
          }
        } finally {
          if (!isCancelled) {
            setLoading(false)
          }
        }
      } else if (!modelValue) {
        setSelectedItem(null)
        setSearchQuery('')
      }
    }

    loadInitialValue()

    return () => {
      isCancelled = true
    }
  }, [modelValue, searchFn, itemValue, itemLabel, selectedItem])

  return (
    <div className={`relative ${fullWidth ? 'w-full' : ''} ${className}`} {...rest}>
      {label ? (
        <label htmlFor={inputId} className="block text-sm font-medium text-gray-700 mb-1">
          {label}
          {required ? <span className="text-red-500">*</span> : null}
        </label>
      ) : null}

      <div className="relative">
        <input
          id={inputId}
          ref={inputRef}
          type="text"
          value={displayValue}
          placeholder={placeholder}
          disabled={disabled}
          required={required}
          className={inputClasses}
          autoComplete="off"
          onChange={onInput}
          onFocus={onFocus}
          onBlur={onBlur}
          onKeyDown={handleKeyDown}
        />

        {loading ? (
          <div className="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
            <svg className="animate-spin h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
            </svg>
          </div>
        ) : modelValue && !disabled ? (
          <div className="absolute inset-y-0 right-0 pr-3 flex items-center">
            <button
              type="button"
              onMouseDown={(event) => {
                event.preventDefault()
                event.stopPropagation()
                clearSelection()
              }}
              className="text-gray-400 hover:text-gray-600"
            >
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        ) : null}

        {showDropdown && (results.length > 0 || loading) ? (
          <div className="absolute z-50 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm">
            {loading ? (
              <div className="px-4 py-2 text-sm text-gray-500">Searching...</div>
            ) : (
              results.map((item, index) => (
                <div
                  key={getItemValue(item)}
                  className={`cursor-pointer select-none relative py-2 px-4 hover:bg-primary-50 ${index === highlightedIndex ? 'bg-primary-50' : ''}`}
                  onMouseDown={(event) => {
                    event.preventDefault()
                    selectItem(item)
                  }}
                  onMouseEnter={() => setHighlightedIndex(index)}
                >
                  {renderItem ? (
                    renderItem({ item })
                  ) : (
                    <div>
                      <div className="font-medium text-gray-900">{getItemLabel(item)}</div>
                      {getItemSubtext(item) ? (
                        <div className="text-sm text-gray-500">{getItemSubtext(item)}</div>
                      ) : null}
                    </div>
                  )}
                </div>
              ))
            )}
          </div>
        ) : null}

        {showDropdown && !loading && results.length === 0 && searchQuery ? (
          <div className="absolute z-50 mt-1 w-full bg-white shadow-lg rounded-md py-2 px-4 text-base ring-1 ring-black ring-opacity-5 sm:text-sm">
            <div className="text-sm text-gray-500">No results found</div>
          </div>
        ) : null}
      </div>

      {error ? <p className="mt-1 text-sm text-red-600">{error}</p> : null}
      {!error && helperText ? <p className="mt-1 text-sm text-gray-500">{helperText}</p> : null}
    </div>
  )
}
