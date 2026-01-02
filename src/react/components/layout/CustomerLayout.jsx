import { Outlet } from 'react-router-dom'

import Navbar from './Navbar'

export default function CustomerLayout({ title }) {
  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar title={title || 'Customer Portal'} />
      <main className="p-6">
        <Outlet />
      </main>
    </div>
  )
}
