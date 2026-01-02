export default function Textarea({ label, name, rows = 3, value, onChange, ...rest }) {
  return (
    <label className="block text-sm text-gray-700">
      {label ? <span className="mb-1 block font-medium">{label}</span> : null}
      <textarea
        name={name}
        rows={rows}
        value={value}
        onChange={onChange}
        className="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
        {...rest}
      />
    </label>
  )
}
