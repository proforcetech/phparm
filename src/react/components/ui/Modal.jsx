import { useEffect, useMemo } from 'react'
import { createPortal } from 'react-dom'

export default function Modal({
  modelValue,
  open,
  title = '',
  size = 'md',
  closable = true,
  closeOnBackdrop = true,
  header,
  footer,
  content,
  onClose,
  onUpdateModelValue,
  className = '',
  children,
}) {
  const isOpen = typeof open === 'boolean' ? open : !!modelValue

  const modalSizeClasses = useMemo(() => {
    const sizes = {
      sm: 'sm:max-w-sm w-full',
      md: 'sm:max-w-lg w-full',
      lg: 'sm:max-w-2xl w-full',
      xl: 'sm:max-w-4xl w-full',
      full: 'sm:max-w-7xl w-full',
    }
    return sizes[size] || sizes.md
  }, [size])

  const handleClose = () => {
    onUpdateModelValue?.(false)
    onClose?.()
  }

  useEffect(() => {
    if (!isOpen) {
      document.body.style.overflow = ''
      return
    }

    document.body.style.overflow = 'hidden'

    return () => {
      document.body.style.overflow = ''
    }
  }, [isOpen])

  if (!isOpen) {
    return null
  }

  const headerContent = header ?? (title ? (
    <h3 id="modal-title" className="text-lg font-medium text-gray-900">
      {title}
    </h3>
  ) : null)

  const modalBody = (
    <div
      className="fixed inset-0 z-50 overflow-y-auto"
      aria-labelledby="modal-title"
      role="dialog"
      aria-modal="true"
    >
      <div className="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div
          className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
          aria-hidden="true"
          onClick={() => {
            if (closeOnBackdrop) {
              handleClose()
            }
          }}
        />

        <span className="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">
          &#8203;
        </span>

        <div
          className={`inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle ${modalSizeClasses} ${className}`}
        >
          {headerContent ? (
            <div className="px-6 py-4 border-b border-gray-200">
              <div className="flex items-center justify-between">
                {headerContent}
                {closable ? (
                  <button
                    type="button"
                    onClick={handleClose}
                    className="text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                  >
                    <span className="sr-only">Close</span>
                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                ) : null}
              </div>
            </div>
          ) : null}

          <div className="px-6 py-4">{content ?? children}</div>

          {footer ? (
            <div className="px-6 py-4 bg-gray-50 border-t border-gray-200">{footer}</div>
          ) : null}
        </div>
      </div>
    </div>
  )

  return createPortal(modalBody, document.body)
}
