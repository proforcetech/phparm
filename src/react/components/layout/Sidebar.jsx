import { forwardRef, useEffect, useImperativeHandle, useMemo, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import {
  ArchiveBoxIcon,
  Bars3Icon,
  CalendarIcon,
  ChartBarIcon,
  ClipboardDocumentCheckIcon,
  ClipboardDocumentListIcon,
  ClockIcon,
  Cog6ToothIcon,
  CreditCardIcon,
  CubeIcon,
  DocumentDuplicateIcon,
  DocumentTextIcon,
  ExclamationTriangleIcon,
  FolderIcon,
  GlobeAltIcon,
  HomeIcon,
  RectangleGroupIcon,
  RectangleStackIcon,
  ShieldCheckIcon,
  Squares2X2Icon,
  TruckIcon,
  UserGroupIcon,
  UsersIcon,
} from '@heroicons/react/24/outline'

import { useAuthStore } from '../../stores/auth'

const adminMenuItems = [
  { path: '/cp/dashboard', label: 'Dashboard', icon: HomeIcon },
  { path: '/cp/invoices', label: 'Invoices', icon: DocumentTextIcon },
  { path: '/cp/estimates', label: 'Estimates', icon: DocumentTextIcon },
  { path: '/cp/appointments', label: 'Appointments', icon: CalendarIcon },
  { path: '/cp/dispatch', label: 'Dispatch', icon: TruckIcon },
  { path: '/cp/time-logs', label: 'Time Logs', icon: ClockIcon },
  { path: '/cp/customers', label: 'Customers', icon: UserGroupIcon },
  { path: '/cp/vehicles', label: 'Vehicles', icon: TruckIcon },
  { path: '/cp/bundles', label: 'Preset Bundles', icon: RectangleStackIcon },
  { path: '/cp/inventory/alerts', label: 'Inventory Alerts', icon: CubeIcon },
  { path: '/cp/inventory', label: 'Inventory', icon: CubeIcon },
  { path: '/cp/storage/impound-intake', label: 'Impound Storage', icon: ArchiveBoxIcon },
  { path: '/cp/financial/entries', label: 'Purchases & Expenses', icon: DocumentTextIcon },
  { path: '/cp/reports', label: 'Reports', icon: ChartBarIcon },
  { path: '/cp/inspections/templates', label: 'Inspection Templates', icon: ClipboardDocumentCheckIcon },
  { path: '/cp/inspections/work', label: 'Inspections', icon: ClipboardDocumentListIcon },
  { path: '/cp/cms', label: 'CMS Dashboard', icon: GlobeAltIcon, section: 'cms' },
  { path: '/cp/cms/pages', label: 'CMS Pages', icon: DocumentDuplicateIcon, section: 'cms' },
  { path: '/cp/cms/categories', label: 'CMS Categories', icon: FolderIcon, section: 'cms' },
  { path: '/cp/cms/menus', label: 'CMS Menus', icon: Bars3Icon, section: 'cms' },
  { path: '/cp/cms/components', label: 'CMS Components', icon: Squares2X2Icon, section: 'cms' },
  { path: '/cp/cms/templates', label: 'CMS Templates', icon: RectangleGroupIcon, section: 'cms' },
  { path: '/cp/cms/404-manager', label: '404 & Redirects', icon: ExclamationTriangleIcon, section: 'cms' },
  { path: '/cp/settings', label: 'Settings', icon: Cog6ToothIcon },
  { path: '/cp/users', label: 'Users', icon: UsersIcon },
]

const technicianMenuItems = [
  { path: '/cp/dashboard', label: 'Dashboard', icon: HomeIcon },
  { path: '/cp/my-time', label: 'My Time', icon: ClockIcon },
  { path: '/cp/time-logs', label: 'Time Logs', icon: ClockIcon },
  { path: '/cp/appointments', label: 'Appointments', icon: CalendarIcon },
  { path: '/cp/inspections/work', label: 'Inspections', icon: ClipboardDocumentListIcon },
]

const customerMenuItems = [
  { path: '/portal', label: 'Dashboard', icon: HomeIcon },
  { path: '/portal/invoices', label: 'My Invoices', icon: DocumentTextIcon },
  { path: '/portal/appointments', label: 'My Appointments', icon: CalendarIcon },
  { path: '/portal/vehicles', label: 'My Vehicles', icon: TruckIcon },
  { path: '/portal/inspections', label: 'My Inspections', icon: ClipboardDocumentCheckIcon },
  { path: '/portal/credit', label: 'Credit Account', icon: CreditCardIcon },
  { path: '/portal/warranty-claims', label: 'Warranty Claims', icon: ShieldCheckIcon },
  { path: '/portal/profile', label: 'Profile', icon: Cog6ToothIcon },
]

const isActiveRoute = (currentPath, targetPath) => {
  if (targetPath === '/cp/dashboard' || targetPath === '/portal') {
    return currentPath === targetPath
  }
  if (targetPath === '/cp/inventory') {
    return currentPath === '/cp/inventory'
  }
  if (targetPath === '/cp/cms') {
    return currentPath === '/cp/cms'
  }
  return currentPath.startsWith(targetPath)
}

const Sidebar = forwardRef(function Sidebar({ type = 'admin' }, ref) {
  const { user } = useAuthStore()
  const { pathname } = useLocation()
  const [isOpen, setIsOpen] = useState(true)

  useEffect(() => {
    if (typeof window !== 'undefined' && window.innerWidth < 1024) {
      setIsOpen(false)
    }

    const handleResize = () => {
      if (window.innerWidth >= 1024) {
        setIsOpen(true)
      }
    }

    window.addEventListener('resize', handleResize)
    return () => window.removeEventListener('resize', handleResize)
  }, [])

  const menuItems = useMemo(() => {
    if (type === 'customer') {
      return customerMenuItems
    }

    if (user?.role === 'technician') {
      return technicianMenuItems
    }

    return adminMenuItems
  }, [type, user?.role])

  const toggleSidebar = () => {
    setIsOpen((prev) => !prev)
  }

  useImperativeHandle(
    ref,
    () => ({
      toggleSidebar,
      isOpen,
    }),
    [isOpen]
  )

  return (
    <>
      <aside
        className={`fixed inset-y-0 left-0 bg-gray-900 w-64 transform transition-transform duration-300 ease-in-out z-30 ${
          isOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="flex flex-col h-full">
          <div className="flex items-center justify-between h-16 px-4 bg-gray-800">
            <span className="text-lg font-semibold text-white">Menu</span>
            <button
              type="button"
              onClick={toggleSidebar}
              className="lg:hidden text-gray-400 hover:text-white focus:outline-none"
            >
              <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>
          </div>

          <nav className="flex-1 px-2 py-4 space-y-1 overflow-y-auto">
            {menuItems.map((item) => {
              const Icon = item.icon
              const isActive = isActiveRoute(pathname, item.path)

              return (
                <Link
                  key={item.path}
                  to={item.path}
                  className={`flex items-center px-4 py-2 text-sm font-medium rounded-md transition-colors ${
                    isActive
                      ? 'bg-gray-800 text-white'
                      : 'text-gray-300 hover:bg-gray-700 hover:text-white'
                  }`}
                >
                  <Icon className="h-5 w-5 mr-3" />
                  {item.label}
                </Link>
              )}
            )}
          </nav>
        </div>
      </aside>

      {isOpen ? (
        <div
          onClick={toggleSidebar}
          className="lg:hidden fixed inset-0 bg-black bg-opacity-50 z-20"
        ></div>
      ) : null}
    </>
  )
})

export default Sidebar
