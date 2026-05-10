import { Outlet, Link, useLocation, useNavigate } from 'react-router-dom'

import { usePortalAuth } from '../../stores/portalAuth'
import { usePortalTheme } from '../../stores/portalTheme'
import { PORTAL_TIER_LABELS } from '../../../services/portal/permissions'

/**
 * Phase 2a — chrome for the new portal tree.
 *
 * Renders header (logo + display_name + sign-out), a thin nav strip with
 * the surfaces 2b/2c will fill, and an outlet for the active route.
 * Inline styles use the CSS variables written by PortalThemeProvider so
 * tenant branding (primary color, background, text) is applied without
 * the views having to read the theme themselves.
 */
const NAV_ITEMS = [
  { to: '/p', label: 'Home', exact: true },
  { to: '/p/approvals', label: 'Approvals' },
  { to: '/p/requests', label: 'New request' },
  { to: '/p/work-orders', label: 'Work Orders' },
  { to: '/p/invoices', label: 'Invoices' },
  { to: '/p/sites', label: 'Sites' },
  { to: '/p/payment-methods', label: 'Payment Methods' },
  { to: '/p/contracts', label: 'Contracts' },
  { to: '/p/messages', label: 'Messages' },
  { to: '/p/satisfaction', label: 'Surveys' },
  { to: '/p/notifications', label: 'Notifications' },
  { to: '/p/activity', label: 'Activity' },
  { to: '/p/api-tokens', label: 'API tokens' },
]

export default function PortalLayout() {
  const { theme } = usePortalTheme()
  const { user, account, logout, tier } = usePortalAuth()
  const tierLabel = tier ? (PORTAL_TIER_LABELS[tier] || tier) : null
  const location = useLocation()
  const navigate = useNavigate()

  const isActive = (item) => {
    if (item.exact) return location.pathname === item.to
    return location.pathname === item.to || location.pathname.startsWith(`${item.to}/`)
  }

  const displayName = theme?.display_name || 'Customer Portal'
  const logoUrl = theme?.logo_url

  return (
    <div
      className="min-h-screen flex flex-col"
      style={{
        backgroundColor: 'var(--portal-bg, #f8fafc)',
        color: 'var(--portal-text, #0f172a)',
      }}
    >
      <header
        className="border-b border-gray-200 bg-white shadow-sm"
        style={{ borderColor: 'var(--portal-secondary, #e2e8f0)' }}
      >
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <button
            type="button"
            onClick={() => navigate('/p')}
            className="flex items-center gap-3 focus:outline-none"
          >
            {logoUrl ? (
              <img src={logoUrl} alt={displayName} className="h-8 w-auto" />
            ) : (
              <div
                className="h-8 w-8 rounded-md flex items-center justify-center text-white font-semibold"
                style={{ backgroundColor: 'var(--portal-primary, #2563eb)' }}
              >
                {displayName.charAt(0).toUpperCase()}
              </div>
            )}
            <span className="text-lg font-semibold">{displayName}</span>
          </button>
          <div className="flex items-center gap-4 text-sm">
            {user && (
              <span className="text-gray-600 hidden sm:inline">
                {user.name || user.email}
              </span>
            )}
            {tierLabel && (
              <span
                className="hidden sm:inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700"
                title={`Portal role: ${tierLabel}`}
              >
                {tierLabel}
              </span>
            )}
            <button
              type="button"
              onClick={() => logout()}
              className="text-sm font-medium hover:underline"
              style={{ color: 'var(--portal-primary, #2563eb)' }}
            >
              Sign out
            </button>
          </div>
        </div>
        <nav className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex gap-6 text-sm overflow-x-auto">
          {NAV_ITEMS.map((item) => (
            <Link
              key={item.to}
              to={item.to}
              className={`py-3 border-b-2 -mb-px whitespace-nowrap ${
                isActive(item)
                  ? 'border-current font-semibold'
                  : 'border-transparent text-gray-600 hover:text-gray-900'
              }`}
              style={isActive(item) ? { color: 'var(--portal-primary, #2563eb)' } : undefined}
            >
              {item.label}
            </Link>
          ))}
        </nav>
      </header>
      <main className="flex-1">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
          <Outlet />
        </div>
      </main>
      {(theme?.footer_text || account) && (
        <footer className="border-t border-gray-200 bg-white py-4 text-center text-xs text-gray-500">
          {theme?.footer_text || `Portal account · company #${account?.company_id ?? '—'}`}
        </footer>
      )}
    </div>
  )
}
