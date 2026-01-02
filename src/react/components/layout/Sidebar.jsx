import { NavLink } from 'react-router-dom'

const defaultLinks = [
  { label: 'Dashboard', to: '/react/cp/dashboard' },
  { label: 'Invoices', to: '/react/cp/invoices' },
  { label: 'Settings', to: '/react/cp/settings' },
]

export default function Sidebar({ links = defaultLinks }) {
  return (
    <aside className="w-64 bg-gray-50 border-r px-4 py-6">
      <nav className="space-y-2">
        {links.map((link) => (
          <NavLink
            key={link.to}
            to={link.to}
            className={({ isActive }) =>
              `block rounded px-3 py-2 text-sm ${
                isActive ? 'bg-primary-600 text-white' : 'text-gray-700 hover:bg-gray-100'
              }`
            }
          >
            {link.label}
          </NavLink>
        ))}
      </nav>
    </aside>
  )
}
