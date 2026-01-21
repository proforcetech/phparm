import { Bars3Icon } from '@heroicons/react/24/outline'

/**
 * MobileHeader component - Displays a mobile-only header with hamburger menu toggle
 * Used consistently across all layout components (Admin, Customer, ESS)
 *
 * @param {Object} props
 * @param {Function} props.onToggleSidebar - Function to toggle the sidebar visibility
 * @param {string} props.title - Title to display in the header
 * @param {string} props.className - Additional CSS classes
 */
export default function MobileHeader({
  onToggleSidebar,
  title = 'Menu',
  className = '',
}) {
  return (
    <div
      className={`lg:hidden flex items-center justify-between p-4 bg-white border-b border-gray-200 ${className}`}
    >
      <button
        type="button"
        onClick={onToggleSidebar}
        className="text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 rounded-md p-1"
        aria-label="Toggle navigation"
      >
        <Bars3Icon className="h-6 w-6" aria-hidden="true" />
      </button>
      <span className="text-lg font-semibold text-gray-900">{title}</span>
      {/* Spacer for visual balance */}
      <div className="w-8" aria-hidden="true" />
    </div>
  )
}
