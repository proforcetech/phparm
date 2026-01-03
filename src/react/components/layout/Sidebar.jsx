import { Link, useLocation } from 'react-router-dom'
import {
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
  { path: '/react/cp/dashboard', label: 'Dashboard', icon: HomeIcon },
  { path: '/react/cp/invoices', label: 'Invoices', icon: DocumentTextIcon },
  { path: '/react/cp/estimates', label: 'Estimates', icon: DocumentTextIcon },
  { path: '/react/cp/appointments', label: 'Appointments', icon: CalendarIcon },
  { path: '/react/cp/time-logs', label: 'Time Logs', icon: ClockIcon },
  { path: '/react/cp/customers', label: 'Customers', icon: UserGroupIcon },
  { path: '/react/cp/vehicles', label: 'Vehicles', icon: TruckIcon },
  { path: '/react/cp/bundles', label: 'Preset Bundles', icon: RectangleStackIcon },
  { path: '/react/cp/inventory/alerts', label: 'Inventory Alerts', icon: CubeIcon },
  { path: '/react/cp/inventory', label: 'Inventory', icon: CubeIcon },
  { path: '/react/cp/financial/entries', label: 'Purchases & Expenses', icon: DocumentTextIcon },
  { path: '/react/cp/reports', label: 'Reports', icon: ChartBarIcon },
  { path: '/react/cp/inspections/templates', label: 'Inspection Templates', icon: ClipboardDocumentCheckIcon },
  { path: '/react/cp/inspections/work', label: 'Inspections', icon: ClipboardDocumentListIcon },
  { path: '/react/cp/cms', label: 'CMS Dashboard', icon: GlobeAltIcon, section: 'cms' },
  { path: '/react/cp/cms/pages', label: 'CMS Pages', icon: DocumentDuplicateIcon, section: 'cms' },
  { path: '/react/cp/cms/categories', label: 'CMS Categories', icon: FolderIcon, section: 'cms' },
  { path: '/react/cp/cms/menus', label: 'CMS Menus', icon: Bars3Icon, section: 'cms' },
  { path: '/react/cp/cms/components', label: 'CMS Components', icon: Squares2X2Icon, section: 'cms' },
  { path: '/react/cp/cms/templates', label: 'CMS Templates', icon: RectangleGroupIcon, section: 'cms' },
  { path: '/react/cp/cms/404-manager', label: '404 & Redirects', icon: ExclamationTriangleIcon, section: 'cms' },
  { path: '/react/cp/settings', label: 'Settings', icon: Cog6ToothIcon },
  { path: '/react/cp/users', label: 'Users', icon: UsersIcon },
]

const technicianMenuItems = [
  { path: '/react/cp/dashboard', label: 'Dashboard', icon: HomeIcon },
  { path: '/react/cp/my-time', label: 'My Time', icon: ClockIcon },
  { path: '/react/cp/time-logs', label: 'Time Logs', icon: ClockIcon },
  { path: '/react/cp/appointments', label: 'Appointments', icon: CalendarIcon },
  { path: '/react/cp/inspections/work', label: 'Inspections', icon: ClipboardDocumentListIcon },
]

const customerMenuItems = [
  { path: '/react/portal', label: 'Dashboard', icon: HomeIcon },
  { path: '/react/portal/invoices', label: 'My Invoices', icon: DocumentTextIcon },
  { path: '/react/portal/appointments', label: 'My Appointments', icon: CalendarIcon },
  { path: '/react/portal/vehicles', label: 'My Vehicles', icon: TruckIcon },
  { path: '/react/portal/inspections', label: 'My Inspections', icon: ClipboardDocumentCheckIcon },
  { path: '/react/portal/credit', label: 'Credit Account', icon: CreditCardIcon },
  { path: '/react/portal/warranty-claims', label: 'Warranty Claims', icon: ShieldCheckIcon },
  { path: '/react/portal/profile', label: 'Profile', icon: Cog6ToothIcon },
]

const isActiveRoute = (currentPath, targetPath) => {
  if (targetPath === '/react/cp/dashboard' || targetPath === '/react/portal') {
    return currentPath === targetPath
  }
  if (targetPath === '/react/cp/inventory') {
    return currentPath === '/react/cp/inventory'
  }
  if (targetPath === '/react/cp/cms') {
    return currentPath === '/react/cp/cms'
  }
  return currentPath.startsWith(targetPath)
}

export default function Sidebar({ type = 'admin', isOpen, onToggle }) {
  const { user } = useAuthStore()
  const { pathname } = useLocation()

  const menuItems = type === 'customer'
    ? customerMenuItems
    : user?.role === 'technician'
      ? technicianMenuItems
      : adminMenuItems

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
              onClick={onToggle}
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
              )
            })}
          </nav>
        </div>
      </aside>

      {isOpen ? (
        <div
          onClick={onToggle}
          className="lg:hidden fixed inset-0 bg-black bg-opacity-50 z-20"
        ></div>
      ) : null}
    </>
  )
}
