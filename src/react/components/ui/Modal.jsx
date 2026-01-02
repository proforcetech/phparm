export default function Modal({
  title,
  children,
  open,
  modelValue,
  onClose,
  onUpdateModelValue,
}) {
  const isOpen = typeof open === 'boolean' ? open : !!modelValue

  const handleClose = () => {
    if (onUpdateModelValue) {
      onUpdateModelValue(false)
    }
    if (onClose) {
      onClose()
    }
  }

  if (!isOpen) {
    return null
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="bg-white rounded-lg shadow-lg w-full max-w-lg">
        <div className="flex items-center justify-between border-b px-4 py-3">
          <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
          <button type="button" className="text-gray-500 hover:text-gray-900" onClick={handleClose}>
            ×
          </button>
        </div>
        <div className="p-4">{children}</div>
      </div>
    </div>
  )
}
