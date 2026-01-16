import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Bars3Icon, ChevronDownIcon } from '@heroicons/react/24/outline'

import { useAuthStore } from '../../stores/auth'
import OfflineStatusBadge from '../OfflineStatusBadge'

export default function Navbar({
  showSidebarToggle = true,
  isSidebarCollapsed = true,
  onToggleSidebarCollapsed = () => {},
  homePath = '/cp/dashboard',
  homeLabel = 'Auto Repair Shop',
}) {
  const { user, isCustomer, isAdmin, logout, impersonation, stopImpersonation } = useAuthStore()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)
  const buttonRef = useRef(null)

  const initials = useMemo(() => {
    if (!user) return '?'
    const firstName = user.first_name || user.name?.split(' ')[0] || ''
    const lastName = user.last_name || user.name?.split(' ')[1] || ''
    const value = `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase()
    return value || '?'
  }, [user])

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (buttonRef.current && !buttonRef.current.contains(event.target)) {
        setMenuOpen(false)
      }
    }

    document.addEventListener('click', handleClickOutside)
    return () => document.removeEventListener('click', handleClickOutside)
  }, [])

  return (
    <>
      {impersonation?.active ? (
        <div className="bg-amber-50 border-b border-amber-200">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="flex flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between">
              <div className="text-sm text-amber-900">
                <span className="font-semibold">Impersonating:</span>{' '}
                <span>{impersonation.impersonated?.name || 'Unknown user'}</span>
                {impersonation.impersonated?.email ? (
                  <span className="text-amber-700"> ({impersonation.impersonated.email})</span>
                ) : null}
                {impersonation.impersonated?.role ? (
                  <span className="ml-2 text-amber-700">
                    Role: {impersonation.impersonated.role}
                  </span>
                ) : null}
                {impersonation.impersonator?.name ? (
                  <span className="ml-2 text-amber-700">
                    Signed in as {impersonation.impersonator.name}
                  </span>
                ) : null}
              </div>
              <button
                type="button"
                onClick={async () => {
                  await stopImpersonation()
                  navigate('/cp/dashboard')
                }}
                className="inline-flex items-center justify-center rounded-md border border-amber-300 bg-white px-3 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-500"
              >
                Stop impersonation
              </button>
            </div>
          </div>
        </div>
      ) : null}
      <nav className="bg-white shadow-sm border-b border-gray-200">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between h-16">
            <div className="flex items-center space-x-3">
              {showSidebarToggle ? (
                <button
                  type="button"
                  onClick={onToggleSidebarCollapsed}
                  className="hidden lg:inline-flex items-center justify-center rounded-md p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
                  aria-pressed={isSidebarCollapsed}
                >
                  <span className="sr-only">
                    {isSidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                  </span>
                  <Bars3Icon className="h-5 w-5" aria-hidden="true" />
                </button>
              ) : null}
              <div className="flex-shrink-0 flex items-center">
                <Link to={homePath} className="text-xl font-bold text-primary-600">
                  {homeLabel}
                </Link>
              </div>
            </div>

            <div className="flex items-center space-x-4">
              <OfflineStatusBadge />
              <div className="ml-3 relative">
                <div>
                  <button
                    ref={buttonRef}
                    type="button"
                    className="flex items-center max-w-xs text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
                    id="user-menu-button"
                    aria-expanded={menuOpen}
                    aria-haspopup="true"
                    onClick={() => setMenuOpen((prev) => !prev)}
                  >
                    <span className="sr-only">Open user menu</span>
                    <div className="flex items-center space-x-3">
                      <div className="hidden md:block text-right">
                        <div className="text-sm font-medium text-gray-900">
                          {user?.name || `${user?.first_name || ''} ${user?.last_name || ''}`.trim()}
                        </div>
                        <div className="text-xs text-gray-500 capitalize">{user?.role}</div>
                      </div>
                      <div className="h-8 w-8 rounded-full bg-primary-600 flex items-center justify-center">
                        <span className="text-sm font-medium text-white">{initials}</span>
                      </div>
                      <ChevronDownIcon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                    </div>
                  </button>
                </div>

                {menuOpen ? (
                  <div
                    className="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg py-1 bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-50"
                    role="menu"
                    aria-orientation="vertical"
                    aria-labelledby="user-menu-button"
                    tabIndex="-1"
                  >
                    {isCustomer ? (
                      <Link
                        to="/portal/profile"
                        className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                        role="menuitem"
                        onClick={() => setMenuOpen(false)}
                      >
                        My Profile
                      </Link>
                    ) : null}
                    {isAdmin ? (
                      <Link
                        to="/cp/settings"
                        className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                        role="menuitem"
                        onClick={() => setMenuOpen(false)}
                      >
                        Settings
                      </Link>
                    ) : null}
                    <button
                      type="button"
                      onClick={async () => {
                        setMenuOpen(false)
                        await logout()
                        // Navigate to login page after logout
                        navigate('/login')
                      }}
                      className="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                      role="menuitem"
                    >
                      Sign out
                    </button>
                  </div>
                ) : null}
              </div>
            </div>
          </div>
        </div>
      </nav>
    </>
  )
}
