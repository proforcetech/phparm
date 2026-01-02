import { useState } from 'react'
import { Outlet } from 'react-router-dom'

import Navbar from './Navbar'
import Sidebar from './Sidebar'

export default function AdminLayout({ title }) {
  const [sidebarOpen, setSidebarOpen] = useState(true)

  return (
    <div className="min-h-screen bg-gray-100">
      <Navbar title={title || 'Admin'} onMenuToggle={() => setSidebarOpen((prev) => !prev)} />
      <div className="flex">
        {sidebarOpen ? <Sidebar /> : null}
        <main className="flex-1 p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
