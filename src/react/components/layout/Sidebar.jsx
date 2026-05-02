import { forwardRef, useEffect, useImperativeHandle, useMemo, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { AdjustmentsHorizontalIcon, ArchiveBoxIcon, Bars3Icon, BellAlertIcon, BookOpenIcon, BuildingOffice2Icon, BuildingOfficeIcon, CalendarIcon, ChartBarIcon, ChartPieIcon, ClipboardDocumentCheckIcon, ClipboardDocumentListIcon, ClockIcon, Cog6ToothIcon, CpuChipIcon, CreditCardIcon, CubeIcon, CurrencyDollarIcon, DocumentDuplicateIcon, DocumentTextIcon, ExclamationTriangleIcon, FingerPrintIcon, FolderIcon, GlobeAltIcon, HomeIcon, KeyIcon, LifebuoyIcon, MapIcon, MapPinIcon, MicrophoneIcon, PhotoIcon, PuzzlePieceIcon, RectangleGroupIcon, RectangleStackIcon, ShieldCheckIcon, Squares2X2Icon, TagIcon, TicketIcon, TrashIcon, TruckIcon, UserGroupIcon, UsersIcon, WrenchScrewdriverIcon } from '@heroicons/react/24/outline'

import { useAuthStore } from '../../stores/auth'
import ServiceLineSwitcher from './ServiceLineSwitcher'

// Section dividers render as non-clickable headings between groups. Empty
// sections are pruned in renderMenu (so module-key filtering doesn't leave a
// header floating above nothing).
const sec = (label) => ({ divider: true, label })

const adminMenuItems = [
  sec('Service Delivery'),
  { path: '/cp/dashboard', label: 'Dashboard', icon: HomeIcon, moduleKey: 'core' },
  { path: '/cp/tickets', label: 'Tickets', icon: TicketIcon, moduleKey: 'tickets',
    children: [
      { path: '/cp/tickets/triage', label: 'Triage Suggestions', icon: CpuChipIcon },
      { path: '/cp/tickets/queues', label: 'Queues', icon: RectangleStackIcon },
    ],
  },
  { path: '/cp/appointments', label: 'Appointments', icon: CalendarIcon, moduleKey: 'appointments' },
  { path: '/cp/estimates', label: 'Estimates', icon: DocumentTextIcon, moduleKey: 'estimates' },
  { path: '/cp/workorders', label: 'Workorders', icon: ClipboardDocumentListIcon, moduleKey: 'workorders' },
  { path: '/cp/invoices', label: 'Invoices', icon: DocumentTextIcon, moduleKey: 'invoicing' },
  { path: '/cp/quick-sale', label: 'Quick Sale', icon: CreditCardIcon, moduleKey: 'invoicing' },
  { path: '/cp/time-logs', label: 'Time Logs', icon: ClockIcon, moduleKey: 'time_tracking' },
  { path: '/cp/leave-requests', label: 'Leave Requests', icon: ClipboardDocumentCheckIcon, moduleKey: 'time_tracking' },
  { path: '/cp/eta/promises', label: 'ETA Promises', icon: BellAlertIcon, moduleKey: 'tickets' },

  sec('Customers & Sites'),
  { path: '/cp/customers', label: 'Customers', icon: UserGroupIcon, moduleKey: 'core' },
  { path: '/cp/crm/companies', label: 'Companies', icon: BuildingOffice2Icon, moduleKey: 'crm' },
  { path: '/cp/crm/sites', label: 'Sites', icon: MapPinIcon, moduleKey: 'crm' },
  { path: '/cp/contracts', label: 'Contracts', icon: DocumentDuplicateIcon, moduleKey: 'contracts' },
  { path: '/cp/vehicles', label: 'Vehicles', icon: TruckIcon, moduleKey: 'core' },
  { path: '/cp/bundles', label: 'Preset Bundles', icon: RectangleStackIcon, moduleKey: 'bundles' },
  sec('Maintenance'),
  { path: '/cp/pm/plans', label: 'PM Plans', icon: BookOpenIcon, moduleKey: 'pm' },
  { path: '/cp/pm/schedules', label: 'PM Schedules', icon: CalendarIcon, moduleKey: 'pm' },
  { path: '/cp/pm/compliance', label: 'PM Compliance', icon: ChartPieIcon, moduleKey: 'pm' },
  {
    path: '/cp/inventory',
    label: 'Inventory',
    icon: CubeIcon,
    moduleKey: 'inventory',
    children: [
      {
        path: '/cp/inventory/alerts',
        label: 'Inventory Alerts',
        icon: CubeIcon,
      },
    ],
  },
  { path: '/cp/warranty', label: 'Warranty Claims', icon: ShieldCheckIcon, moduleKey: 'warranty' },

  sec('Assets & Fleet'),
  { path: '/cp/assets', label: 'Installed Assets', icon: WrenchScrewdriverIcon, moduleKey: 'assets' },
  { path: '/cp/assets/types', label: 'Asset Types', icon: TagIcon, moduleKey: 'assets' },
  { path: '/cp/fleet/units', label: 'Fleet Units', icon: TruckIcon, moduleKey: 'fleet' },
  { path: '/cp/fleet/external-repairs', label: 'External Repairs', icon: LifebuoyIcon, moduleKey: 'fleet' },
  { path: '/cp/fleet/reports', label: 'Fleet Reports', icon: ChartBarIcon, moduleKey: 'fleet' },
  { path: '/cp/routing/route-plans', label: 'Route Plans', icon: MapIcon, moduleKey: 'routing' },
  { path: '/cp/routing/geo-fences', label: 'Geo-Fences', icon: MapPinIcon, moduleKey: 'routing' },
  { path: '/cp/capital-plan/aging', label: 'Asset Aging', icon: ChartPieIcon, moduleKey: 'capital_plan' },
  { path: '/cp/capital-plan/plans', label: 'Capital Plans', icon: CurrencyDollarIcon, moduleKey: 'capital_plan' },
  {
    path: '/cp/dispatch',
    label: 'Dispatch',
    icon: TruckIcon,
    moduleKey: 'towing',
    children: [
      {
        path: '/cp/dispatch',
        label: 'Dispatch Board',
        icon: TruckIcon,
      },
      {
        path: '/cp/driver/truck-checklists',
        label: 'Truck Checklists',
        icon: ClipboardDocumentCheckIcon,
      },
      {
        path: '/cp/driver/truck-checklists/logs',
        label: 'Checklist Logs',
        icon: ClipboardDocumentListIcon,
      },
      {
        path: '/cp/driver/truck-checklists/templates',
        label: 'Checklist Templates',
        icon: ClipboardDocumentCheckIcon,
      },
    ],
  },
  {
    path: '/cp/storage/impound-intake',
    label: 'Storage',
    icon: ArchiveBoxIcon,
    moduleKey: 'impound',
    children: [
      {
        path: '/cp/storage/impound-intake',
        label: 'Impound Intake',
        icon: ArchiveBoxIcon,
      },
      {
        path: '/cp/storage/spot-checks',
        label: 'Inventory Spot-Checks',
        icon: ClipboardDocumentCheckIcon,
      },
    ],
  },
  { path: '/cp/document-vault', label: 'Document Vault', icon: DocumentDuplicateIcon, moduleKey: 'documents' },
  { path: '/cp/voice-notes', label: 'Voice Notes', icon: MicrophoneIcon, moduleKey: 'voice_notes' },
  { path: '/cp/subcontractors', label: 'Subcontractors', icon: BuildingOfficeIcon, moduleKey: 'subcontractors' },

  sec('Finance'),
  { path: '/cp/financial/entries', label: 'Purchases & Expenses', icon: DocumentTextIcon, moduleKey: 'financial' },
  { path: '/cp/financial/reconciliation', label: 'Reconciliation', icon: ClipboardDocumentCheckIcon, moduleKey: 'financial' },
  { path: '/cp/financial/categories', label: 'Account Categories', icon: FolderIcon, moduleKey: 'financial' },
  { path: '/cp/reports', label: 'Reports', icon: ChartBarIcon, moduleKey: 'reports' },
  { path: '/cp/branches/dashboards', label: 'Branch Dashboards', icon: BuildingOfficeIcon, moduleKey: 'reports' },
  {
    path: '/cp/inspections/work',
    label: 'Inspections',
    icon: ClipboardDocumentListIcon,
    moduleKey: 'inspections',
    children: [
      {
        path: '/cp/inspections/templates',
        label: 'Inspection Templates',
        icon: ClipboardDocumentCheckIcon,
      },
    ],
  },
  { 
    path: '/cp/cms', 
    label: 'CMS Dashboard',
    icon: GlobeAltIcon, 
    section: 'cms', 
    moduleKey: 'cms',
    children: [
      {
        path: '/cp/cms/pages',
        label: 'CMS Pages',
        icon: DocumentDuplicateIcon,
      },
      {
        path: '/cp/cms/categories',
        label: 'CMS Categories',
        icon: FolderIcon,
      },
      {
        path: '/cp/cms/menus',
        label: 'CMS Menus',
        icon: Bars3Icon,
      },
      {
        path: '/cp/cms/media',
        label: 'Media Library',
        icon: PhotoIcon,
      },
      {
        path: '/cp/cms/components',
        label: 'CMS Components',
        icon: Squares2X2Icon,
      },
      {
        path: '/cp/cms/templates',
        label: 'CMS Templates',
        icon: RectangleGroupIcon,
      },
      {
        path: '/cp/cms/404-manager',
        label: '404 Manager',
        icon: ExclamationTriangleIcon,
      },
    ],
  },
  sec('Admin & Integrations'),
  { path: '/cp/divisions', label: 'Divisions', icon: BuildingOffice2Icon, moduleKey: 'divisions' },
  { path: '/cp/integrations', label: 'Integrations', icon: PuzzlePieceIcon, moduleKey: 'integrations' },
  { path: '/cp/sso/providers', label: 'SSO Providers', icon: KeyIcon, moduleKey: 'sso' },
  { path: '/cp/security-events', label: 'Security Events', icon: FingerPrintIcon, moduleKey: 'security' },
  { path: '/cp/retention/policies', label: 'Retention Policies', icon: TrashIcon, moduleKey: 'retention' },
  { path: '/cp/custom-fields', label: 'Custom Fields', icon: AdjustmentsHorizontalIcon, moduleKey: 'custom_fields' },
  { path: '/cp/settings', label: 'Settings', icon: Cog6ToothIcon },
  {
    path: '/cp/users',
    label: 'Users',
    icon: UsersIcon,
    children: [
      {
        path: '/cp/users/roles',
        label: 'Roles',
        icon: ShieldCheckIcon,
      },
      {
        path: '/cp/users/groups',
        label: 'User Groups',
        icon: UserGroupIcon,
      },
    ],
  },
]

const technicianMenuItems = [
  { path: '/cp/dashboard', label: 'Dashboard', icon: HomeIcon },
  { path: '/cp/my-time', label: 'My Time', icon: ClockIcon },
  { path: '/cp/time-logs', label: 'Time Logs', icon: ClockIcon },
  { path: '/cp/appointments', label: 'Appointments', icon: CalendarIcon },
  { path: '/cp/inspections/work', label: 'Inspections', icon: ClipboardDocumentListIcon },
  { path: '/cp/driver/truck-checklists', label: 'Truck Checklists', icon: ClipboardDocumentCheckIcon },
]

const customerMenuItems = [
  { path: '/portal', label: 'Dashboard', icon: HomeIcon },
  { path: '/portal/workorders', label: 'Communication Hub', icon: DocumentTextIcon },
  { path: '/portal/invoices', label: 'My Invoices', icon: DocumentTextIcon },
  { path: '/portal/appointments', label: 'My Appointments', icon: CalendarIcon },
  { path: '/portal/vehicles', label: 'My Vehicles', icon: TruckIcon },
  { path: '/portal/inspections', label: 'My Inspections', icon: ClipboardDocumentCheckIcon },
  { path: '/portal/credit', label: 'Credit Account', icon: CreditCardIcon },
  { path: '/portal/warranty-claims', label: 'Warranty Claims', icon: ShieldCheckIcon },
  { path: '/portal/profile', label: 'Profile', icon: Cog6ToothIcon },
]

const essMenuItems = [
  { path: '/ess', label: 'Dashboard', icon: HomeIcon },
  { path: '/ess/time-clock', label: 'Time Clock', icon: ClockIcon },
  { path: '/ess/schedule', label: 'My Schedule', icon: CalendarIcon },
  { path: '/ess/pay-history', label: 'Pay History', icon: DocumentTextIcon },
  { path: '/ess/leave-requests', label: 'Leave Requests', icon: ClipboardDocumentCheckIcon },
  { path: '/ess/profile', label: 'Profile Updates', icon: Cog6ToothIcon },
]

// Phase 12 of docs/woms-expansion-plan.md — tenant portal slice. Backed by
// /api/tenant/* endpoints and gated server-side via Tenant.portal_user_id.
const tenantMenuItems = [
  { path: '/tenant', label: 'My Units', icon: HomeIcon },
  { path: '/tenant/requests', label: 'Maintenance Requests', icon: ClipboardDocumentListIcon },
  { path: '/tenant/requests/new', label: 'New Request', icon: ClipboardDocumentCheckIcon },
]

const isActiveRoute = (currentPath, targetPath) => {
  if (targetPath === '/cp/dashboard' || targetPath === '/portal') {
    return currentPath === targetPath
  }
  if (targetPath === '/ess') {
    return currentPath === '/ess'
  }
  if (targetPath === '/tenant') {
    return currentPath === '/tenant'
  }
  if (targetPath === '/cp/inventory') {
    return currentPath === '/cp/inventory'
  }
  if (targetPath === '/cp/cms') {
    return currentPath === '/cp/cms'
  }
  return currentPath.startsWith(targetPath)
}

/**
 * Tooltip component for collapsed sidebar items
 */
function SidebarTooltip({ label, children }) {
  return (
    <div className="group relative">
      {children}
      <div className="absolute left-full ml-2 px-2 py-1 bg-gray-900 text-white text-sm rounded-md whitespace-nowrap opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 pointer-events-none">
        {label}
        <div className="absolute top-1/2 -left-1 -translate-y-1/2 border-4 border-transparent border-r-gray-900" />
      </div>
    </div>
  )
}

const Sidebar = forwardRef(function Sidebar({ type = 'admin', isCollapsed = false }, ref) {
  const { user, hasModuleAccess } = useAuthStore()
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

    if (type === 'ess') {
      return essMenuItems
    }

    if (type === 'tenant') {
      return tenantMenuItems
    }

    if (user?.role?.toLowerCase() === 'technician') {
      return technicianMenuItems
    }

    // Admin users see everything as-is.
    const items = user?.role?.toLowerCase() === 'admin'
      ? adminMenuItems
      : adminMenuItems.filter((item) => {
          if (item.divider) {
            return true
          }
          if (!item.moduleKey) {
            return true
          }
          return hasModuleAccess(item.moduleKey)
        })

    // Prune dividers that would otherwise headline an empty section.
    return items.filter((item, idx) => {
      if (!item.divider) {
        return true
      }
      const next = items[idx + 1]
      return next && !next.divider
    })
  }, [type, user?.role, hasModuleAccess])

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

  const renderMenuItem = (item, idx) => {
    if (item.divider) {
      // Hide section headings entirely when collapsed — a thin separator
      // would just add visual noise next to icons.
      if (isCollapsed) {
        return (
          <div
            key={`divider-${idx}-${item.label}`}
            className="hidden lg:block my-2 border-t border-gray-700"
            aria-hidden="true"
          />
        )
      }
      return (
        <div
          key={`divider-${idx}-${item.label}`}
          className="px-4 pt-4 pb-1 text-[10px] font-semibold uppercase tracking-wider text-gray-500"
        >
          {item.label}
        </div>
      )
    }

    const Icon = item.icon
    const isActive = isActiveRoute(pathname, item.path)
    const isChildActive = item.children?.some((child) => isActiveRoute(pathname, child.path))
    const isCurrentActive = isActive || isChildActive

    // Collapsed mode - show icon only with tooltip
    if (isCollapsed) {
      const menuLink = (
        <Link
          to={item.path}
          className={`flex items-center justify-center p-3 rounded-md transition-colors ${
            isCurrentActive
              ? 'bg-gray-800 text-white'
              : 'text-gray-300 hover:bg-gray-700 hover:text-white'
          }`}
          aria-label={item.label}
        >
          {Icon ? <Icon className="h-5 w-5" aria-hidden="true" /> : null}
        </Link>
      )

      return (
        <div key={item.path} className="hidden lg:block">
          <SidebarTooltip label={item.label}>
            {menuLink}
          </SidebarTooltip>
        </div>
      )
    }

    // Expanded mode - show full menu item
    return (
      <div key={item.path} className="space-y-1">
        <Link
          to={item.path}
          className={`flex items-center px-4 py-2 text-sm font-medium rounded-md transition-colors ${
            isCurrentActive
              ? 'bg-gray-800 text-white'
              : 'text-gray-300 hover:bg-gray-700 hover:text-white'
          }`}
        >
          {Icon ? <Icon className="h-5 w-5 mr-3" aria-hidden="true" /> : null}
          <span>{item.label}</span>
        </Link>

        {item.children?.length ? (
          <div className="ml-6 space-y-1">
            {item.children.map((child) => {
              const ChildIcon = child.icon
              const isChildItemActive = isActiveRoute(pathname, child.path)

              return (
                <Link
                  key={child.path}
                  to={child.path}
                  className={`flex items-center px-3 py-2 text-sm rounded-md transition-colors ${
                    isChildItemActive
                      ? 'bg-gray-800 text-white'
                      : 'text-gray-300 hover:bg-gray-700 hover:text-white'
                  }`}
                >
                  {ChildIcon ? <ChildIcon className="h-4 w-4 mr-3" aria-hidden="true" /> : null}
                  <span>{child.label}</span>
                </Link>
              )
            })}
          </div>
        ) : null}
      </div>
    )
  }

  return (
    <>
      <aside
        className={`fixed inset-y-0 left-0 bg-gray-900 w-64 ${
          isCollapsed ? 'lg:w-20' : 'lg:w-64'
        } transform transition-all duration-300 ease-in-out z-30 ${
          isOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
        aria-label="Main sidebar navigation"
      >
        <div className="flex flex-col h-full">
          <div className={`flex items-center h-16 bg-gray-800 ${isCollapsed ? 'lg:justify-center lg:px-2' : 'justify-between px-4'}`}>
            <span
              className={`text-lg font-semibold text-white ${isCollapsed ? 'lg:hidden' : ''}`}
            >
              Menu
            </span>
            {isCollapsed && (
              <span className="hidden lg:block text-lg font-semibold text-white">
                M
              </span>
            )}
            <button
              type="button"
              onClick={toggleSidebar}
              className="lg:hidden text-gray-400 hover:text-white focus:outline-none focus:ring-2 focus:ring-primary-500 rounded-md"
              aria-label="Close navigation menu"
            >
              <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>
          </div>

          <ServiceLineSwitcher isCollapsed={isCollapsed} />

          <nav
            className={`flex-1 py-4 overflow-y-auto ${isCollapsed ? 'lg:px-2 px-2' : 'px-2'}`}
            aria-label="Main navigation"
          >
            <div className={isCollapsed ? 'lg:space-y-2 space-y-1' : 'space-y-1'}>
              {menuItems.map((item, idx) => renderMenuItem(item, idx))}
            </div>
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
