export default function Select({
  label,
  options = [],
  value,
  onChange,
  name,
  placeholder = 'Select...',
}) {
  return (
    <label className="block text-sm text-gray-700">
      {label ? <span className="mb-1 block font-medium">{label}</span> : null}
      <select
        name={name}
        value={value}
        onChange={onChange}
        className="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
      >
        <option value="">{placeholder}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  )
}
