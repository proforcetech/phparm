import { forwardRef, useEffect, useImperativeHandle, useMemo, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { AdjustmentsHorizontalIcon, ArchiveBoxIcon, ArrowUpTrayIcon, Bars3Icon, BellAlertIcon, BookOpenIcon, BuildingOffice2Icon, BuildingOfficeIcon, BuildingStorefrontIcon, CalendarIcon, ChartBarIcon, ChartPieIcon, ChevronDownIcon, ChevronRightIcon, ClipboardDocumentCheckIcon, ClipboardDocumentListIcon, ClockIcon, Cog6ToothIcon, CpuChipIcon, CreditCardIcon, CubeIcon, CurrencyDollarIcon, DocumentDuplicateIcon, DocumentTextIcon, ExclamationTriangleIcon, FingerPrintIcon, FolderIcon, GlobeAltIcon, HomeIcon, KeyIcon, LifebuoyIcon, MapIcon, MapPinIcon, MicrophoneIcon, PhotoIcon, PuzzlePieceIcon, RectangleGroupIcon, RectangleStackIcon, ShieldCheckIcon, ShoppingCartIcon, Squares2X2Icon, TagIcon, TicketIcon, TrashIcon, TruckIcon, UserGroupIcon, UsersIcon, WrenchScrewdriverIcon } from '@heroicons/react/24/outline'

import { useAuthStore } from '../../stores/auth'
import ServiceLineSwitcher from './ServiceLineSwitcher'

// Section dividers render as non-clickable headings between groups. Empty
// sections are pruned in renderMenu (so module-key filtering doesn't leave a
// header floating above nothing).
const sec = (label) => ({ divider: true, label })

const adminMenuItems = [
  { path: '/cp/dashboard', label: 'Dashboard', icon: HomeIcon, moduleKey: 'core' },

  sec('Service Delivery'),
  {
    path: '/cp/appointments',
    label: 'Appointments',
    icon: CalendarIcon,
    moduleKey: 'appointments',
    children: [
      { path: '/cp/appointments/calendar', label: 'Calendar', icon: CalendarIcon },
      { path: '/cp/appointments/create', label: 'Book Appointment', icon: CalendarIcon },
      { path: '/cp/appointments/availability-settings', label: 'Availability Settings', icon: Cog6ToothIcon },
    ],
  },
  { path: '/cp/estimates', label: 'Estimates', icon: DocumentTextIcon, moduleKey: 'estimates' },
  { path: '/cp/workorders', label: 'Workorders', icon: ClipboardDocumentListIcon, moduleKey: 'workorders' },
  { path: '/cp/invoices', label: 'Invoices', icon: DocumentTextIcon, moduleKey: 'invoicing' },
  { path: '/cp/quick-sale', label: 'Quicksale', icon: CreditCardIcon, moduleKey: 'invoicing' },
  { path: '/cp/eta/promises', label: 'ETA Promises', icon: BellAlertIcon, moduleKey: 'tickets' },
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
    path: '/cp/pm/plans',
    label: 'PM',
    icon: BookOpenIcon,
    moduleKey: 'pm',
    children: [
      { path: '/cp/pm/plans', label: 'PM Plans', icon: BookOpenIcon },
      { path: '/cp/pm/schedules', label: 'PM Schedules', icon: CalendarIcon },
      { path: '/cp/pm/compliance', label: 'PM Compliance', icon: ChartPieIcon },
    ],
  },

  sec('Time Tracking'),
  { path: '/cp/time-logs', label: 'Time Logs', icon: ClockIcon, moduleKey: 'time_tracking' },
  { path: '/cp/leave-requests', label: 'Leave Requests', icon: ClipboardDocumentCheckIcon, moduleKey: 'time_tracking' },

  sec('Personnel'),
  { path: '/cp/skills/matrix', label: 'Skill Matrix', icon: UsersIcon, moduleKey: 'workforce' },

  sec('Customer & Sites'),
  { path: '/cp/customers', label: 'Customers', icon: UserGroupIcon, moduleKey: 'core' },
  { path: '/cp/crm/companies', label: 'Companies', icon: BuildingOffice2Icon, moduleKey: 'crm' },
  { path: '/cp/crm/sites', label: 'Sites', icon: MapPinIcon, moduleKey: 'crm' },
  { path: '/cp/contracts', label: 'Contracts', icon: DocumentDuplicateIcon, moduleKey: 'contracts' },
  {
    path: '/cp/vehicles',
    label: 'Vehicles',
    icon: TruckIcon,
    moduleKey: 'core',
    children: [
      { path: '/cp/vehicle-master', label: 'Vehicle Master', icon: TruckIcon },
    ],
  },
  {
    path: '/cp/tickets',
    label: 'Tickets',
    icon: TicketIcon,
    moduleKey: 'tickets',
    children: [
      { path: '/cp/tickets/triage', label: 'Triage Suggestions', icon: CpuChipIcon },
      { path: '/cp/tickets/queues', label: 'Queues', icon: RectangleStackIcon },
      { path: '/cp/tickets/sla-policies', label: 'SLA Policies', icon: ClockIcon },
      { path: '/cp/tickets/routing-rules', label: 'Routing Rules', icon: MapIcon },
      { path: '/cp/tickets/escalation-rules', label: 'Escalation Rules', icon: BellAlertIcon },
      { path: '/cp/tickets/categories', label: 'Categories', icon: FolderIcon },
      { path: '/cp/tickets/close-reasons', label: 'Close Reasons', icon: ClipboardDocumentCheckIcon },
      { path: '/cp/tickets/resolution-codes', label: 'Resolution Codes', icon: ClipboardDocumentCheckIcon },
      { path: '/cp/tickets/failure-codes', label: 'Failure Codes', icon: ExclamationTriangleIcon },
    ],
  },
  { path: '/cp/warranty', label: 'Warranty Claims', icon: ShieldCheckIcon, moduleKey: 'warranty' },

  sec('Inventory & Purchasing'),
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
      { path: '/cp/inventory/stock-orders', label: 'Stock Orders', icon: ClipboardDocumentListIcon },
      { path: '/cp/inventory/pull-requests', label: 'Pull Requests', icon: ClipboardDocumentCheckIcon },
      { path: '/cp/inventory/categories', label: 'Categories', icon: FolderIcon },
      { path: '/cp/inventory/vendors', label: 'Vendor Lookups', icon: BuildingStorefrontIcon },
      { path: '/cp/inventory/locations', label: 'Locations', icon: MapPinIcon },
    ],
  },
  { path: '/cp/procurement/purchase-orders', label: 'Purchase Orders', icon: ShoppingCartIcon, moduleKey: 'inventory' },
  { path: '/cp/procurement/vendors', label: 'Vendors', icon: BuildingStorefrontIcon, moduleKey: 'inventory' },
  { path: '/cp/vendor-portal-tokens', label: 'Vendor Portal Tokens', icon: BuildingStorefrontIcon, moduleKey: 'inventory' },

  sec('Assets & Fleet'),
  { path: '/cp/assets', label: 'Installed Assets', icon: WrenchScrewdriverIcon, moduleKey: 'assets' },
  { path: '/cp/assets/types', label: 'Asset Types', icon: TagIcon, moduleKey: 'assets' },
  { path: '/cp/assets/leases', label: 'Asset Leases', icon: DocumentDuplicateIcon, moduleKey: 'assets' },
  { path: '/cp/assets/acquisitions', label: 'Asset Acquisitions', icon: ClipboardDocumentListIcon, moduleKey: 'assets' },
  { path: '/cp/assets/decommissions', label: 'Asset Decommissions', icon: TrashIcon, moduleKey: 'assets' },
  { path: '/cp/assets/import', label: 'Bulk Asset Import', icon: ArrowUpTrayIcon, moduleKey: 'assets' },
  { path: '/cp/fleet/units', label: 'Fleet Units', icon: TruckIcon, moduleKey: 'fleet' },
  { path: '/cp/fleet/external-repairs', label: 'External Repairs', icon: LifebuoyIcon, moduleKey: 'fleet' },
  { path: '/cp/fleet/reports', label: 'Fleet Reports', icon: ChartBarIcon, moduleKey: 'fleet' },
  { path: '/cp/capital-plan/aging', label: 'Asset Aging', icon: ChartPieIcon, moduleKey: 'capital_plan' },
  { path: '/cp/capital-plan/plans', label: 'Capital Plans', icon: CurrencyDollarIcon, moduleKey: 'capital_plan' },

  sec('Dispatching'),
  { path: '/cp/routing/geo-fences', label: 'Geo-Fences', icon: MapPinIcon, moduleKey: 'routing' },
  {
    path: '/cp/routing/service-routes',
    label: 'Service Routes',
    icon: MapIcon,
    moduleKey: 'routing',
    children: [
      { path: '/cp/routing/route-plans', label: 'Route Plans', icon: MapIcon },
      { path: '/cp/my-routes', label: 'My Routes', icon: MapPinIcon },
    ],
  },
  {
    path: '/cp/dispatch',
    label: 'Dispatch',
    icon: TruckIcon,
    moduleKey: 'towing',
    children: [
      {
        path: '/cp/dispatch-board',
        label: 'Dispatch Board',
        icon: RectangleGroupIcon,
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
      {
        path: '/cp/driver/job-intake',
        label: 'Driver Job Intake',
        icon: TruckIcon,
      },
    ],
  },

  sec('Towing & Roadside Assistance'),
  { path: '/cp/towing/pricing', label: 'Towing Pricing Matrix', icon: CurrencyDollarIcon, moduleKey: 'towing' },
  {
    path: '/cp/storage/impound-intake',
    label: 'Vehicle Storage',
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
      { path: '/cp/storage/ledger', label: 'Storage Fee Ledger', icon: DocumentTextIcon },
      { path: '/cp/storage/notices', label: 'Notice Generation', icon: DocumentDuplicateIcon },
      { path: '/cp/storage/release-checklist', label: 'Release Checklist', icon: ClipboardDocumentCheckIcon },
      { path: '/cp/storage/auction-management', label: 'Auction Management', icon: CurrencyDollarIcon },
    ],
  },

  sec('Communications'),
  { path: '/cp/document-vault', label: 'Document Vault', icon: DocumentDuplicateIcon, moduleKey: 'documents' },
  {
    path: '/cp/voice-notes',
    label: 'Voice Notes',
    icon: MicrophoneIcon,
    moduleKey: 'voice_notes',
    children: [
      { path: '/cp/voice-notes/pending', label: 'Pending Notes', icon: ClockIcon },
    ],
  },

  sec('Third-Party'),
  { path: '/cp/subcontractors', label: 'Subcontractors', icon: BuildingOfficeIcon, moduleKey: 'subcontractors' },
  { path: '/cp/sub-portal-tokens', label: 'Sub Portal Tokens', icon: BuildingOfficeIcon, moduleKey: 'subcontractors' },

  sec('Finance & Reports'),
  { path: '/cp/financial/categories', label: 'Account Categories', icon: FolderIcon, moduleKey: 'financial' },
  { path: '/cp/financial/entries', label: 'Purchases & Expenses', icon: DocumentTextIcon, moduleKey: 'financial' },
  { path: '/cp/financial/reconciliation', label: 'Reconciliation', icon: ClipboardDocumentCheckIcon, moduleKey: 'financial' },
  {
    path: '/cp/reports',
    label: 'Reports',
    icon: ChartBarIcon,
    moduleKey: 'reports',
    children: [
      { path: '/cp/reports/overview', label: 'Overview', icon: ChartBarIcon },
      { path: '/cp/reports/customer-retention', label: 'Customer Retention', icon: UserGroupIcon, moduleKey: 'customer_retention' },
    ],
  },
  { path: '/cp/branches/dashboards', label: 'Branch Dashboards', icon: BuildingOfficeIcon, moduleKey: 'reports' },
  { path: '/cp/billing/consolidated', label: 'Consolidated Statements', icon: DocumentDuplicateIcon, moduleKey: 'invoicing' },
  { path: '/cp/chain-rollup', label: 'Chain Rollup', icon: BuildingOffice2Icon, moduleKey: 'crm' },
  { path: '/cp/trade-kpis', label: 'Trade KPI', icon: ChartPieIcon, moduleKey: 'reports' },
  { path: '/cp/capital-plan/scoring-models', label: 'Capital Scoring Models', icon: ChartPieIcon, moduleKey: 'capital_plan' },

  sec('Content Management'),
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
        path: '/cp/cms/media',
        label: 'Media Library',
        icon: PhotoIcon,
      },
      {
        path: '/cp/cms/404-manager',
        label: '404 Manager',
        icon: ExclamationTriangleIcon,
      },
    ],
  },
  sec('Admin & Integrations'),
  { path: '/cp/custom-fields', label: 'Custom Fields', icon: AdjustmentsHorizontalIcon, moduleKey: 'custom_fields' },
  { path: '/cp/divisions', label: 'Divisions', icon: BuildingOffice2Icon, moduleKey: 'divisions' },
  { path: '/cp/integrations', label: 'Integrations', icon: PuzzlePieceIcon, moduleKey: 'integrations' },
  { path: '/cp/sso/providers', label: 'SSO Providers', icon: KeyIcon, moduleKey: 'sso' },
  { path: '/cp/security-events', label: 'Security Events', icon: FingerPrintIcon, moduleKey: 'security' },
  { path: '/cp/security/credentials', label: 'Security Credentials', icon: KeyIcon, moduleKey: 'security' },
  { path: '/cp/security', label: 'Security Center', icon: ShieldCheckIcon, moduleKey: 'security' },
  { path: '/cp/it/software', label: 'Software Inventory', icon: CpuChipIcon, moduleKey: 'software_inventory' },
  { path: '/cp/it/change-management', label: 'Change Management', icon: ClipboardDocumentListIcon, moduleKey: 'change_management' },
  { path: '/cp/pos/terminals', label: 'POS Terminals', icon: CreditCardIcon, moduleKey: 'pos' },
  { path: '/cp/bundles', label: 'Preset Bundles', icon: RectangleStackIcon, moduleKey: 'bundles' },
  {
    path: '/cp/retention/policies',
    label: 'Retention Policies',
    icon: TrashIcon,
    moduleKey: 'retention',
    children: [
      { path: '/cp/retention/runs', label: 'Retention Runs', icon: ClockIcon },
    ],
  },
  { path: '/cp/audit', label: 'Audit Logs', icon: DocumentTextIcon, moduleKey: 'security' },
  {
    path: '/cp/settings',
    label: 'Settings',
    icon: Cog6ToothIcon,
    children: [
      { path: '/cp/settings/profile', label: 'Shop Profile', icon: BuildingStorefrontIcon },
      { path: '/cp/settings/terms', label: 'Terms', icon: DocumentTextIcon },
      { path: '/cp/settings/templates', label: 'Templates', icon: DocumentDuplicateIcon },
      { path: '/cp/settings/rejection-reasons', label: 'Rejection Reasons', icon: ClipboardDocumentCheckIcon },
      { path: '/cp/settings/pricing', label: 'Pricing', icon: CurrencyDollarIcon },
      { path: '/cp/settings/security', label: 'Security', icon: ShieldCheckIcon },
      { path: '/cp/settings/notifications', label: 'Notifications', icon: BellAlertIcon },
      { path: '/cp/settings/payments', label: 'Payments', icon: CreditCardIcon },
      { path: '/cp/settings/integrations', label: 'Settings Integrations', icon: PuzzlePieceIcon },
      { path: '/cp/settings/services', label: 'Services', icon: ClipboardDocumentListIcon },
      { path: '/cp/settings/service-lines', label: 'Service Lines', icon: RectangleStackIcon },
      { path: '/cp/settings/modules', label: 'Modules', icon: Squares2X2Icon },
      { path: '/cp/settings/dispatch', label: 'Dispatch Settings', icon: TruckIcon },
      { path: '/cp/settings/vin-decoder', label: 'VIN Decoder', icon: TruckIcon },
      { path: '/cp/settings/property-management', label: 'Property Management', icon: BuildingOfficeIcon },
    ],
  },
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
  const [openGroups, setOpenGroups] = useState({})

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

    const canAccessItem = (item) => {
      if (!item.moduleKey) {
        return true
      }
      return hasModuleAccess(item.moduleKey)
    }

    const isAdmin = user?.role?.toLowerCase() === 'admin'
    const items = isAdmin
      ? adminMenuItems
      : adminMenuItems
          .filter((item) => item.divider || canAccessItem(item))
          .map((item) => {
            if (!item.children?.length) {
              return item
            }
            return {
              ...item,
              children: item.children.filter(canAccessItem),
            }
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
    const hasChildren = item.children?.length > 0
    const groupKey = item.path || `${item.label}-${idx}`
    const isGroupOpen = hasChildren ? (openGroups[groupKey] ?? Boolean(isChildActive)) : false

    const toggleGroup = (event) => {
      event.preventDefault()
      event.stopPropagation()
      setOpenGroups((prev) => ({
        ...prev,
        [groupKey]: !(prev[groupKey] ?? Boolean(isChildActive)),
      }))
    }

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

    const itemClasses = isCurrentActive
      ? 'bg-gray-800 text-white'
      : 'text-gray-300 hover:bg-gray-700 hover:text-white'

    // Expanded mode - show full menu item
    return (
      <div key={item.path} className="space-y-0.5">
        {hasChildren ? (
          <div className={`group flex items-center rounded-md transition-colors ${itemClasses}`}>
            <Link
              to={item.path}
              className="flex min-w-0 flex-1 items-center px-4 py-2 text-sm font-medium"
            >
              {Icon ? <Icon className="h-5 w-5 mr-3 flex-shrink-0" aria-hidden="true" /> : null}
              <span className="truncate">{item.label}</span>
            </Link>
            <button
              type="button"
              onClick={toggleGroup}
              className="mr-1 rounded-md p-1.5 text-gray-400 transition-colors hover:bg-gray-700 hover:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"
              aria-expanded={isGroupOpen}
              aria-label={`${isGroupOpen ? 'Collapse' : 'Expand'} ${item.label}`}
            >
              {isGroupOpen ? (
                <ChevronDownIcon className="h-4 w-4" aria-hidden="true" />
              ) : (
                <ChevronRightIcon className="h-4 w-4" aria-hidden="true" />
              )}
            </button>
          </div>
        ) : (
          <Link
            to={item.path}
            className={`flex items-center rounded-md px-4 py-2 text-sm font-medium transition-colors ${itemClasses}`}
          >
            {Icon ? <Icon className="h-5 w-5 mr-3 flex-shrink-0" aria-hidden="true" /> : null}
            <span className="truncate">{item.label}</span>
          </Link>
        )}

        {hasChildren && isGroupOpen ? (
          <div className="ml-8 space-y-0.5 border-l border-gray-700 pl-2">
            {item.children.map((child) => {
              const ChildIcon = child.icon
              const isChildItemActive = isActiveRoute(pathname, child.path)

              return (
                <Link
                  key={child.path}
                  to={child.path}
                  className={`flex items-center rounded-md px-2 py-1.5 text-xs transition-colors ${
                    isChildItemActive
                      ? 'bg-gray-800 text-white'
                      : 'text-gray-300 hover:bg-gray-700 hover:text-white'
                  }`}
                >
                  {ChildIcon ? <ChildIcon className="h-4 w-4 mr-2 flex-shrink-0" aria-hidden="true" /> : null}
                  <span className="truncate">{child.label}</span>
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
