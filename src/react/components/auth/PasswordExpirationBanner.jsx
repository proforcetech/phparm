import { Link } from 'react-router-dom'
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline'

import { useAuthStore } from '../../stores/auth'

export default function PasswordExpirationBanner() {
  const { user } = useAuthStore()

  if (!user || user.role === 'customer') {
    return null
  }
  if (user.must_change_password || !user.password_warn_active) {
    return null
  }

  const days = user.password_expires_in_days
  const label =
    typeof days === 'number'
      ? days <= 0
        ? 'today'
        : days === 1
          ? 'in 1 day'
          : `in ${days} days`
      : 'soon'

  return (
    <div className="border-b border-amber-200 bg-amber-50">
      <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-2 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
        <div className="flex items-start gap-2">
          <ExclamationTriangleIcon className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" aria-hidden="true" />
          <span>
            Your password expires <span className="font-semibold">{label}</span>. Update it now to
            avoid being locked out.
          </span>
        </div>
        <Link
          to="/cp/security"
          className="inline-flex items-center justify-center self-start rounded-md border border-amber-300 bg-white px-3 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-500 sm:self-auto"
        >
          Update password
        </Link>
      </div>
    </div>
  )
}
