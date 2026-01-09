import { useEffect, useRef, useState } from 'react'
import { Outlet } from 'react-router-dom'

import Navbar from './Navbar'
import Sidebar from './Sidebar'
import TwoFactorSetupWizard from '../auth/TwoFactorSetupWizard'
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
          <div className="lg:hidden flex items-center justify-between p-4 bg-white border-b border-gray-200">
            <button
              type="button"
              onClick={toggleSidebar}
              className="text-gray-500 hover:text-gray-700 focus:outline-none"
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
            <span className="text-lg font-semibold text-gray-900">Auto Repair Shop</span>
            <div className="w-6"></div>
          </div>

          <CmsPageProvider>
            <CmsMenuProvider>
              <main className="p-4 sm:p-6 lg:p-8">{children ?? <Outlet />}</main>
            </CmsMenuProvider>
          </CmsPageProvider>
        </div>
      </div>
      {user?.two_factor_setup_pending && !user?.two_factor_enabled ? <TwoFactorSetupWizard /> : null}
    </div>
  )
}
