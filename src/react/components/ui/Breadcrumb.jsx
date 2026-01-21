import { Link, useLocation, useParams } from 'react-router-dom'
import { ChevronRightIcon, HomeIcon } from '@heroicons/react/24/outline'

/**
 * Route configuration for breadcrumb labels
 * Maps route segments to human-readable labels
 */
const routeLabels = {
  cp: 'Control Panel',
  portal: 'Customer Portal',
  ess: 'Employee Self-Service',
  dashboard: 'Dashboard',
  invoices: 'Invoices',
  estimates: 'Estimates',
  workorders: 'Work Orders',
  appointments: 'Appointments',
  customers: 'Customers',
  vehicles: 'Vehicles',
  inventory: 'Inventory',
  bundles: 'Preset Bundles',
  warranty: 'Warranty Claims',
  dispatch: 'Dispatch',
  driver: 'Driver',
  storage: 'Storage',
  settings: 'Settings',
  users: 'Users',
  reports: 'Reports',
  cms: 'CMS',
  financial: 'Financial',
  inspections: 'Inspections',
  audit: 'Audit Logs',
  // Sub-routes
  create: 'Create',
  edit: 'Edit',
  detail: 'Details',
  calendar: 'Calendar',
  alerts: 'Alerts',
  categories: 'Categories',
  vendors: 'Vendors',
  locations: 'Locations',
  templates: 'Templates',
  pages: 'Pages',
  menus: 'Menus',
  media: 'Media Library',
  components: 'Components',
  roles: 'Roles',
  groups: 'Groups',
  profile: 'Profile',
  terms: 'Terms',
  pricing: 'Pricing',
  security: 'Security',
  notifications: 'Notifications',
  payments: 'Payments',
  integrations: 'Integrations',
  services: 'Service Types',
  modules: 'Modules',
  'quick-sale': 'Quick Sale',
  'time-logs': 'Time Logs',
  'leave-requests': 'Leave Requests',
  'my-time': 'My Time',
  'document-vault': 'Document Vault',
  'impound-intake': 'Impound Intake',
  'spot-checks': 'Spot Checks',
  'truck-checklists': 'Truck Checklists',
  'qc-check': 'QC Checklist',
  work: 'Work Queue',
  recommendations: 'Recommendations',
  '404-manager': '404 Manager',
  reconciliation: 'Reconciliation',
  entries: 'Entries',
  'stock-orders': 'Stock Orders',
  'pull-requests': 'Pull Requests',
  credit: 'Credit Account',
  'warranty-claims': 'Warranty Claims',
  'time-clock': 'Time Clock',
  schedule: 'Schedule',
  'pay-history': 'Pay History',
  logs: 'Logs',
}

/**
 * Home paths for different sections
 */
const homePaths = {
  cp: '/cp/dashboard',
  portal: '/portal',
  ess: '/ess',
}

/**
 * Get label for a route segment
 * @param {string} segment - The URL segment
 * @param {boolean} isId - Whether this segment appears to be an ID
 * @returns {string} Human-readable label
 */
function getSegmentLabel(segment, isId = false) {
  if (isId) {
    return `#${segment}`
  }
  return routeLabels[segment] || segment.charAt(0).toUpperCase() + segment.slice(1).replace(/-/g, ' ')
}

/**
 * Check if a segment looks like an ID (numeric or UUID-like)
 */
function isIdSegment(segment) {
  return /^\d+$/.test(segment) || /^[a-f0-9-]{36}$/i.test(segment)
}

/**
 * Breadcrumb component for navigation
 * Automatically generates breadcrumbs based on the current URL path
 *
 * @param {Object} props
 * @param {Array} props.items - Optional custom breadcrumb items [{label, path}]
 * @param {string} props.homeLabel - Label for home link (default: "Home")
 * @param {boolean} props.showHome - Whether to show home icon (default: true)
 * @param {string} props.className - Additional CSS classes
 */
export default function Breadcrumb({
  items,
  homeLabel = 'Home',
  showHome = true,
  className = '',
}) {
  const location = useLocation()
  const params = useParams()

  // Generate breadcrumb items from current path if not provided
  const breadcrumbItems = items || (() => {
    const pathSegments = location.pathname.split('/').filter(Boolean)
    const crumbs = []
    let currentPath = ''

    pathSegments.forEach((segment, index) => {
      currentPath += `/${segment}`
      const isId = isIdSegment(segment)

      // Skip showing the root section (cp, portal, ess) as a separate crumb
      // It will be represented by the home icon
      if (index === 0 && homePaths[segment]) {
        return
      }

      // For ID segments, try to get context from the previous segment
      let label = getSegmentLabel(segment, isId)

      // If this is an ID and we have a param value, we could potentially
      // look up the actual name, but for now just show the ID
      if (isId) {
        const prevSegment = pathSegments[index - 1]
        if (prevSegment) {
          // Singularize the previous segment for context
          const singular = prevSegment.endsWith('s')
            ? prevSegment.slice(0, -1)
            : prevSegment
          label = `${singular.charAt(0).toUpperCase() + singular.slice(1)} ${segment}`
        }
      }

      crumbs.push({
        label,
        path: currentPath,
        isLast: index === pathSegments.length - 1,
      })
    })

    return crumbs
  })()

  // Determine home path based on current location
  const getHomePath = () => {
    const firstSegment = location.pathname.split('/').filter(Boolean)[0]
    return homePaths[firstSegment] || '/cp/dashboard'
  }

  if (breadcrumbItems.length === 0 && !showHome) {
    return null
  }

  return (
    <nav aria-label="Breadcrumb" className={`flex items-center ${className}`}>
      <ol className="flex items-center space-x-2">
        {showHome && (
          <li className="flex items-center">
            <Link
              to={getHomePath()}
              className="text-gray-400 hover:text-gray-500 transition-colors"
              aria-label={homeLabel}
            >
              <HomeIcon className="h-5 w-5 flex-shrink-0" aria-hidden="true" />
            </Link>
          </li>
        )}

        {breadcrumbItems.map((item, index) => (
          <li key={item.path || index} className="flex items-center">
            <ChevronRightIcon
              className="h-4 w-4 flex-shrink-0 text-gray-400"
              aria-hidden="true"
            />
            {item.isLast || !item.path ? (
              <span
                className="ml-2 text-sm font-medium text-gray-500"
                aria-current="page"
              >
                {item.label}
              </span>
            ) : (
              <Link
                to={item.path}
                className="ml-2 text-sm font-medium text-gray-500 hover:text-gray-700 transition-colors"
              >
                {item.label}
              </Link>
            )}
          </li>
        ))}
      </ol>
    </nav>
  )
}

/**
 * Export route labels for external use
 */
export { routeLabels }
