import { useEffect, useRef, useState } from 'react'
import { Outlet } from 'react-router-dom'

import Navbar from './Navbar'
import Sidebar from './Sidebar'
import MobileHeader from './MobileHeader'
import TwoFactorSetupWizard from '../auth/TwoFactorSetupWizard'
import ErrorBoundary from '../ErrorBoundary'
import { CmsPageProvider } from '../../stores/cmsPages'
import { CmsMenuProvider } from '../../stores/cmsMenus'
import { useAuthStore } from '../../stores/auth'

export default function AdminLayout({ children }) {
  const sidebarRef = useRef(null)
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false)
  const { user, checkAuth } = useAuthStore()

  // Check authentication on mount to load user data
  useEffect(() => {
    const loadUser = async () => {
      try {
        await checkAuth()
      } catch (error) {
        // If auth check fails, router guards will handle redirect
        console.error('Auth check failed:', error)
      }
    }

    // Only check auth if we don't have user data yet
    if (!user) {
      loadUser()
    }
  }, [checkAuth, user])

  useEffect(() => {
    if (typeof window === 'undefined') {
      return
    }

    const storedValue = window.localStorage.getItem('adminSidebarCollapsed')
    if (storedValue === 'true') {
      setIsSidebarCollapsed(true)
    }
  }, [])

  useEffect(() => {
    if (typeof window === 'undefined') {
      return
    }

    window.localStorage.setItem('adminSidebarCollapsed', String(isSidebarCollapsed))
  }, [isSidebarCollapsed])

  const toggleSidebar = () => {
    sidebarRef.current?.toggleSidebar()
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar
        showSidebarToggle
        isSidebarCollapsed={isSidebarCollapsed}
        onToggleSidebarCollapsed={() => setIsSidebarCollapsed((prev) => !prev)}
      />

      <div className="flex">
        <Sidebar ref={sidebarRef} type="admin" isCollapsed={isSidebarCollapsed} />

        <div className={`flex-1 ${isSidebarCollapsed ? 'lg:ml-20' : 'lg:ml-64'}`}>
          <MobileHeader
            onToggleSidebar={toggleSidebar}
            title="Auto Repair Shop"
          />

          <CmsPageProvider>
            <CmsMenuProvider>
              <main className="p-4 sm:p-6 lg:p-8">
                <ErrorBoundary>
                  {children ?? <Outlet />}
                </ErrorBoundary>
              </main>
            </CmsMenuProvider>
          </CmsPageProvider>
        </div>
      </div>
      {user?.two_factor_setup_pending && !user?.two_factor_enabled ? <TwoFactorSetupWizard /> : null}
    </div>
  )
}
