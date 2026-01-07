import { forwardRef, useImperativeHandle, useMemo } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { 
  HomeIcon, UsersIcon, WrenchScrewdriverIcon, ClipboardDocumentListIcon,
  CurrencyDollarIcon, ArchiveBoxIcon, Cog6ToothIcon, TruckIcon,
  DocumentTextIcon, MapIcon, ShieldCheckIcon, GlobeAltIcon, ClockIcon
} from '@heroicons/react/24/outline'
import { useAuth } from '../../stores/auth'

const Sidebar = forwardRef(({ onClose }, ref) => {
  const location = useLocation()
  const { hasModule } = useAuth()
  
  useImperativeHandle(ref, () => ({
    close: () => {}
  }))

  // Define navigation structure. 
  // 'module' matches the key required in user permissions.
  const navigation = [
    { name: 'Dashboard', href: '/dashboard', icon: HomeIcon, current: false, module: 'dashboard' },
    { name: 'Dispatch', href: '/dispatch', icon: MapIcon, current: false, module: 'dispatch' },
    { name: 'Tracking', href: '/tracking', icon: GlobeAltIcon, current: false, module: 'tracking' },
    { name: 'Work Orders', href: '/workorders', icon: WrenchScrewdriverIcon, current: false, module: 'workorders' },
    { name: 'Estimates', href: '/estimates', icon: DocumentTextIcon, current: false, module: 'estimates' },
    { name: 'Inspections', href: '/inspections', icon: ClipboardDocumentListIcon, current: false, module: 'inspections' },
    { name: 'Inventory', href: '/inventory', icon: ArchiveBoxIcon, current: false, module: 'inventory' },
    { name: 'Financial', href: '/financial', icon: CurrencyDollarIcon, current: false, module: 'financial' },
    { name: 'Invoices', href: '/invoices', icon: DocumentTextIcon, current: false, module: 'financial' }, 
    { name: 'Customers', href: '/customers', icon: UsersIcon, current: false, module: 'customers' },
    { name: 'Vehicles', href: '/vehicles', icon: TruckIcon, current: false, module: 'vehicles' },
    { name: 'Warranty', href: '/warranty', icon: ShieldCheckIcon, current: false, module: 'warranty' },
    { name: 'Time Logs', href: '/time-logs', icon: ClockIcon, current: false, module: 'time_tracking' },
    { name: 'Impound', href: '/impound', icon: ArchiveBoxIcon, current: false, module: 'storage' },
    { name: 'CMS', href: '/cms', icon: GlobeAltIcon, current: false, module: 'cms' },
    { name: 'Users', href: '/users', icon: UsersIcon, current: false, module: 'users' },
    { name: 'User Groups', href: '/user-groups', icon: UsersIcon, current: false, module: 'users' },
    { name: 'Settings', href: '/settings', icon: Cog6ToothIcon, current: false, module: 'settings' },
  ]

  const visibleNavigation = useMemo(() => {
    return navigation.filter(item => {
      // If no module specified, assume it's public/always visible
      if (!item.module) return true
      return hasModule(item.module)
    }).map(item => ({
      ...item,
      current: location.pathname.startsWith(item.href)
    }))
  }, [location.pathname, hasModule])

  return (
    <div className="flex grow flex-col gap-y-5 overflow-y-auto bg-white px-6 pb-4 border-r border-gray-200">
      <div className="flex h-16 shrink-0 items-center">
        <img
          className="h-8 w-auto"
          src="/logo.svg"
          alt="Company Logo"
        />
      </div>
      <nav className="flex flex-1 flex-col">
        <ul role="list" className="flex flex-1 flex-col gap-y-7">
          <li>
            <ul role="list" className="-mx-2 space-y-1">
              {visibleNavigation.map((item) => (
                <li key={item.name}>
                  <Link
                    to={item.href}
                    onClick={onClose}
                    className={`
                      group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6
                      ${item.current
                        ? 'bg-gray-50 text-blue-600'
                        : 'text-gray-700 hover:bg-gray-50 hover:text-blue-600'
                      }
                    `}
                  >
                    <item.icon
                      className={`h-6 w-6 shrink-0 ${
                        item.current ? 'text-blue-600' : 'text-gray-400 group-hover:text-blue-600'
                      }`}
                      aria-hidden="true"
                    />
                    {item.name}
                  </Link>
                </li>
              ))}
            </ul>
          </li>
          
          {/* FIX: Removed the duplicate hardcoded Settings link that was here */}
          
        </ul>
      </nav>
    </div>
  )
})

Sidebar.displayName = 'Sidebar'

export default Sidebar
