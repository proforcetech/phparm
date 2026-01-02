import { NavLink, Outlet } from 'react-router-dom'

export default function SettingsLayout() {
  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">Settings</h1>
        <p className="text-sm text-gray-500">
          React mirror of Vue /cp/settings.
        </p>
      </header>
      <div className="flex flex-wrap gap-2">
        {[
          { label: 'Shop profile', to: '/react/cp/settings' },
          { label: 'Terms', to: '/react/cp/settings/terms' },
          { label: 'Templates', to: '/react/cp/settings/templates' },
          { label: 'Rejection reasons', to: '/react/cp/settings/rejection-reasons' },
          { label: 'Pricing', to: '/react/cp/settings/pricing' },
          { label: 'Notifications', to: '/react/cp/settings/notifications' },
          { label: 'Payments', to: '/react/cp/settings/payments' },
          { label: 'Integrations', to: '/react/cp/settings/integrations' },
          { label: 'Services', to: '/react/cp/settings/services' },
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
