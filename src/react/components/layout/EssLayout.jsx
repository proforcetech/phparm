import { useEffect, useRef } from 'react'
import { Outlet } from 'react-router-dom'

import Navbar from './Navbar'
import Sidebar from './Sidebar'
import MobileHeader from './MobileHeader'
import TwoFactorSetupWizard from '../auth/TwoFactorSetupWizard'
import ErrorBoundary from '../ErrorBoundary'
import { useAuthStore } from '../../stores/auth'

export default function EssLayout({ children }) {
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
      <Navbar homePath="/ess" homeLabel="ESS Portal" />

      <div className="flex">
        <Sidebar ref={sidebarRef} type="ess" />

        <div className="flex-1 lg:ml-64">
          <MobileHeader
            onToggleSidebar={toggleSidebar}
            title="Employee Self-Service"
          />

          <main className="p-4 sm:p-6 lg:p-8">
            <ErrorBoundary>
              {children ?? <Outlet />}
            </ErrorBoundary>
          </main>
        </div>
      </div>
      {user?.two_factor_setup_pending && !user?.two_factor_enabled ? <TwoFactorSetupWizard /> : null}
    </div>
  )
}
