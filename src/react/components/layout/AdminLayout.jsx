import { useRef } from 'react'
import { Outlet } from 'react-router-dom'

import ChatWidget from '../chat/ChatWidget'
import Navbar from './Navbar'
import Sidebar from './Sidebar'
import { CmsPageProvider } from '../../stores/cmsPages'
import { CmsMenuProvider } from '../../stores/cmsMenus'

export default function AdminLayout({ children }) {
  const sidebarRef = useRef(null)

  const toggleSidebar = () => {
    sidebarRef.current?.toggleSidebar()
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />

      <div className="flex">
        <Sidebar ref={sidebarRef} type="admin" />

        <div className="flex-1 lg:ml-64">
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
              <ChatWidget />
            </CmsMenuProvider>
          </CmsPageProvider>
        </div>
      </div>
    </div>
  )
}
