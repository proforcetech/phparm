import { useEffect, useRef } from 'react'
import { Outlet } from 'react-router-dom'

import Navbar from './Navbar'
import Sidebar from './Sidebar'
import TwoFactorSetupWizard from '../auth/TwoFactorSetupWizard'
import { useAuthStore } from '../../stores/auth'

export default function CustomerLayout({ children }) {
  const sidebarRef = useRef(null)
  const { user, checkAuth } = useAuthStore()

  useEffect(() => {
    const loadUser = async () => {
      try {
        await checkAuth()
      } catch (error) {
        console.error('Auth check failed:', error)
      }
    }

    if (!user) {
      loadUser()
    }
  }, [checkAuth, user])

  const toggleSidebar = () => {
    sidebarRef.current?.toggleSidebar()
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />

      <div className="flex">
        <Sidebar ref={sidebarRef} type="customer" />

        <div className="flex-1 lg:ml-64">
          <div className="lg:hidden flex items-center justify-between p-4 bg-white border-b border-gray-200">
            <button
              type="button"
              onClick={toggleSidebar}
              className="text-gray-500 hover:text-gray-700 focus:outline-none"
              aria-label="Open navigation menu"
            >
              <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M4 6h16M4 12h16M4 18h16"
                />
              </svg>
            </button>
            <span className="text-lg font-semibold text-gray-900">Customer Portal</span>
            <div className="w-6"></div>
          </div>

          <main className="p-4 sm:p-6 lg:p-8">{children ?? <Outlet />}</main>
        </div>
      </div>
      {user?.two_factor_setup_pending && !user?.two_factor_enabled ? <TwoFactorSetupWizard /> : null}
    </div>
  )
}
