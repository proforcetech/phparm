import { useMemo, useState } from 'react'

import Input from './Input'

export default function Autocomplete({
  label,
  options = [],
  value,
  onChange,
  placeholder = 'Search...',
}) {
  const [query, setQuery] = useState(value || '')

  const filteredOptions = useMemo(() => {
    if (!query) return options
    const lower = query.toLowerCase()
    return options.filter((option) => option.label.toLowerCase().includes(lower))
  }, [options, query])

  const handleChange = (event) => {
    const nextValue = event.target.value
    setQuery(nextValue)
    if (onChange) {
      onChange(nextValue)
    }
  }

  return (
    <div className="space-y-2">
      <Input label={label} value={query} onChange={handleChange} placeholder={placeholder} />
      {filteredOptions.length ? (
        <ul className="border rounded-md divide-y text-sm text-gray-700 bg-white">
          {filteredOptions.map((option) => (
            <li
              key={option.value}
              className="px-3 py-2 hover:bg-gray-50 cursor-pointer"
              onClick={() => {
                setQuery(option.label)
                if (onChange) {
                  onChange(option.value)
                }
              }}
            >
              {option.label}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}
