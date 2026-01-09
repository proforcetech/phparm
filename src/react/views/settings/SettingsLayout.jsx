import { NavLink, Outlet } from 'react-router-dom'

export default function SettingsLayout() {
  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">Settings</h1>
        <p className="text-sm text-gray-500">
          Configure shop-wide preferences and integrations.
        </p>
      </header>
      <div className="flex flex-wrap gap-2">
        {[
          { label: 'Overview', to: '/cp/settings' },
          { label: 'Shop profile', to: '/cp/settings/profile' },
          { label: 'Terms', to: '/cp/settings/terms' },
          { label: 'Templates', to: '/cp/settings/templates' },
          { label: 'Rejection reasons', to: '/cp/settings/rejection-reasons' },
          { label: 'Pricing', to: '/cp/settings/pricing' },
          { label: 'Security', to: '/cp/settings/security' },
          { label: 'Notifications', to: '/cp/settings/notifications' },
          { label: 'Payments', to: '/cp/settings/payments' },
          { label: 'Integrations', to: '/cp/settings/integrations' },
          { label: 'Services', to: '/cp/settings/services' },
          { label: 'Modules', to: '/cp/settings/modules' },
        ].map((link) => (
          <NavLink
            key={link.to}
            to={link.to}
            className={({ isActive }) =>
              `rounded-full border px-3 py-1 text-xs ${
                isActive ? 'border-primary-600 text-primary-600' : 'text-gray-500'
              }`
            }
          >
            {link.label}
          </NavLink>
        ))}
      </div>
      <Outlet />
    </div>
  )
}
