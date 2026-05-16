import { Suspense, lazy } from 'react'
import { createBrowserRouter, Navigate, redirect, Outlet } from 'react-router-dom'

import AdminLayout from '../components/layout/AdminLayout'
import CustomerLayout from '../components/layout/CustomerLayout'
import EssLayout from '../components/layout/EssLayout'
import TenantLayout from '../components/layout/TenantLayout'

// Phase 2a/2b — new portal tree (parallel to legacy customer-portal/*)
import { PortalAuthProvider } from '../stores/portalAuth'
import { PortalThemeProvider } from '../stores/portalTheme'
import { PORTAL_TOKEN_KEY } from '../../services/portal/api'

const reactBasename = import.meta.env.VITE_REACT_BASE || ''

const routePaths = {
  login: '/login',
  dashboard: '/cp/dashboard',
}

const authTokenKey = 'auth_token'

const requireAuth = () => {
  // Note: Using session-based authentication via cookies
  // The actual auth check happens on the server side
  // This is just a basic client-side check
  const token = localStorage.getItem(authTokenKey)
  const user = localStorage.getItem('user')

  // Allow access if we have either a token or user data
  // Server will validate the actual session
  if (!token && !user) {
    return redirect(routePaths.login)
  }

  return null
}

const requireGuest = () => {
  const token = localStorage.getItem(authTokenKey)
  const user = localStorage.getItem('user')

  if (token || user) {
    return redirect(routePaths.dashboard)
  }

  return null
}

// Phase 2a — portal-specific auth gates. Reads the namespaced portal token
// (NOT the staff auth_token) so a logged-in staff user does not bypass the
// portal login requirement, and a logged-in portal user is redirected past
// /p/login.
const requirePortalAuth = () => {
  const token = typeof window !== 'undefined' ? window.localStorage.getItem(PORTAL_TOKEN_KEY) : null
  if (!token) return redirect('/p/login')
  return null
}

const requirePortalGuest = () => {
  const token = typeof window !== 'undefined' ? window.localStorage.getItem(PORTAL_TOKEN_KEY) : null
  if (token) return redirect('/p')
  return null
}

const PortalShell = () => (
  <PortalThemeProvider>
    <PortalAuthProvider>
      <Outlet />
    </PortalAuthProvider>
  </PortalThemeProvider>
)

const lazyElement = (loader) => {
  const Component = lazy(loader)

  return (
    <Suspense fallback={<div className="p-6 text-sm text-gray-500">Loading route...</div>}>
      <Component />
    </Suspense>
  )
}

const PublicLayout = () => (
  <div className="react-app">
    <Outlet />
  </div>
)

const guestRoutes = [
  { path: '/login', name: 'Login', auth: 'guest', element: lazyElement(() => import('../views/auth/Login')) },
  { path: '/customer-login', name: 'CustomerLogin', auth: 'guest', element: lazyElement(() => import('../views/auth/CustomerLogin')) },
  { path: '/forgot-password', name: 'ForgotPassword', auth: 'guest', element: lazyElement(() => import('../views/auth/ForgotPassword')) },
  { path: '/reset-password/:token', name: 'ResetPassword', auth: 'guest', element: lazyElement(() => import('../views/auth/ResetPassword')) },
  { path: '/accept-invite/:token', name: 'AcceptInvite', auth: 'guest', element: lazyElement(() => import('../views/auth/AcceptInvite')) },
  { path: '/cp/register', name: 'Register', auth: 'guest', element: lazyElement(() => import('../views/auth/Register')) },
]

const publicRoutes = [
  { path: '/request-estimate', name: 'EstimateRequestForm', auth: 'public', element: lazyElement(() => import('../views/public/EstimateRequestPage')) },
  { path: '/estimate/view', name: 'PublicEstimateView', auth: 'public', element: lazyElement(() => import('../views/public/PublicEstimateView')) },
  { path: '/track/:token', name: 'TrackingView', auth: 'public', element: lazyElement(() => import('../views/tracking/TrackingView')) },
  { path: '/pay/:token', name: 'PublicPaymentPortal', auth: 'public', element: lazyElement(() => import('../views/public/PublicPaymentPortal')) },
  { path: '/c/:shortCode', name: 'PublicContractSignByCode', auth: 'public', element: lazyElement(() => import('../views/public/PublicContractSign')) },
  { path: '/contract/view', name: 'PublicContractSignByToken', auth: 'public', element: lazyElement(() => import('../views/public/PublicContractSign')) },
  // UIG-5 / UIG-6 / UIG-7 — the three public /customers/:id and
  // /vehicles/:id[/edit] placeholders were Vue-mirror scaffolding
  // never linked from anywhere. A grep over notification config,
  // QR-token builders, and the PHP service layer found zero
  // inbound references — all customer / vehicle deep-links go through
  // the authed /cp/* surface instead. Removed 2026-05-13. A future
  // public customer summary should be designed with a token-gated
  // trust model (see UIG-7 disposition) before re-introducing.
  { path: '/sub-portal/:token', name: 'SubPortalToken', auth: 'public', element: lazyElement(() => import('../views/sub-portal/SubPortal')) },
  { path: '/sub-portal', name: 'SubPortal', auth: 'public', element: lazyElement(() => import('../views/sub-portal/SubPortal')) },
  { path: '/vendor-portal/:token', name: 'VendorPortalToken', auth: 'public', element: lazyElement(() => import('../views/vendor-portal/VendorPortal')) },
  { path: '/vendor-portal', name: 'VendorPortal', auth: 'public', element: lazyElement(() => import('../views/vendor-portal/VendorPortal')) },
  { path: '/', name: 'Home', auth: 'public', element: lazyElement(() => import('../views/public/CMSPage')) },
  { path: '/*', name: 'CMSPage', auth: 'public', element: lazyElement(() => import('../views/public/CMSPage')) },
]

const protectedRoutes = [
  { path: '/cp/dashboard', name: 'Dashboard', auth: 'requiresAuth', element: lazyElement(() => import('../views/dashboard/AdminDashboard')) },
  { path: '/cp/profile', name: 'StaffProfile', auth: 'requiresAuth', element: lazyElement(() => import('../views/users/Profile')) },
  { path: '/cp/invoices', name: 'InvoiceList', auth: 'requiresAuth', element: lazyElement(() => import('../views/invoices/InvoiceList')) },
  { path: '/cp/quick-sale', name: 'QuickSale', auth: 'requiresAuth', element: lazyElement(() => import('../views/pos/QuickSale')) },
  { path: '/cp/invoices/create', name: 'InvoiceCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/invoices/InvoiceCreate')) },
  { path: '/cp/invoices/:id', name: 'InvoiceDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/invoices/InvoiceDetail')) },
  { path: '/cp/estimates', name: 'EstimateList', auth: 'requiresAuth', element: lazyElement(() => import('../views/estimates/EstimateList')) },
  { path: '/cp/estimates/create', name: 'EstimateCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/estimates/EstimateCreate')) },
  { path: '/cp/estimates/:id', name: 'EstimateDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/estimates/EstimateDetail')) },
  { path: '/cp/estimates/:id/edit', name: 'EstimateEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/estimates/EstimateEdit')) },
  { path: '/cp/workorders', name: 'WorkorderList', auth: 'requiresAuth', element: lazyElement(() => import('../views/workorders/WorkorderList')) },
  { path: '/cp/workorders/:id', name: 'WorkorderDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/workorders/WorkorderDetail')) },
  { path: '/cp/workorders/:id/qc-check', name: 'QCChecklist', auth: 'requiresAuth', element: lazyElement(() => import('../views/workorders/QCChecklist')) },
  { path: '/cp/dispatch', name: 'Dispatch', auth: 'requiresAuth', element: lazyElement(() => import('../views/dispatch/DispatchView')) },
  { path: '/cp/driver/job-intake', name: 'DriverJobIntake', auth: 'requiresAuth', element: lazyElement(() => import('../views/driver/DriverJobIntake')) },
  { path: '/cp/driver/truck-checklists', name: 'TruckChecklists', auth: 'requiresAuth', element: lazyElement(() => import('../views/driver/TruckChecklistForm')) },
  { path: '/cp/driver/truck-checklists/logs', name: 'TruckChecklistLogs', auth: 'requiresAuth', element: lazyElement(() => import('../views/driver/TruckChecklistLogs')) },
  { path: '/cp/driver/truck-checklists/templates', name: 'TruckChecklistTemplates', auth: 'requiresAuth', element: lazyElement(() => import('../views/driver/TruckChecklistTemplates')) },
  { path: '/cp/bundles', name: 'BundleList', auth: 'requiresAuth', element: lazyElement(() => import('../views/bundles/BundleList')) },
  { path: '/cp/bundles/create', name: 'BundleCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/bundles/BundleForm')) },
  { path: '/cp/bundles/:id/edit', name: 'BundleEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/bundles/BundleForm')) },
  { path: '/cp/appointments', name: 'AppointmentList', auth: 'requiresAuth', element: lazyElement(() => import('../views/appointments/AppointmentList')) },
  { path: '/cp/appointments/calendar', name: 'AppointmentCalendar', auth: 'requiresAuth', element: lazyElement(() => import('../views/appointments/AppointmentCalendar')) },
  { path: '/cp/appointments/create', name: 'AppointmentBook', auth: 'requiresAuth', element: lazyElement(() => import('../views/appointments/AppointmentBook')) },
  { path: '/cp/appointments/availability-settings', name: 'AvailabilitySettings', auth: 'requiresAuth', element: lazyElement(() => import('../views/appointments/AvailabilitySettings')) },
  { path: '/cp/time-logs', name: 'TimeLogs', auth: 'requiresAuth', element: lazyElement(() => import('../views/time/TimeLogs')) },
  { path: '/cp/leave-requests', name: 'LeaveRequests', auth: 'requiresAuth', element: lazyElement(() => import('../views/time/LeaveRequestsAdmin')) },
  { path: '/cp/my-time', name: 'TechnicianTime', auth: 'requiresAuth', element: lazyElement(() => import('../views/time/TechnicianPortal')) },
  { path: '/cp/customers', name: 'CustomerList', auth: 'requiresAuth', element: lazyElement(() => import('../views/customers/CustomerList')) },
  { path: '/cp/customers/create', name: 'CustomerCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/customers/CustomerForm')) },
  { path: '/cp/customers/:id', name: 'CustomerDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/customers/CustomerDetail')) },
  { path: '/cp/customers/:id/edit', name: 'CustomerEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/customers/CustomerForm')) },
  { path: '/cp/vehicle-master', name: 'VehicleMasterList', auth: 'requiresAuth', element: lazyElement(() => import('../views/vehicle-master/VehicleMasterList')) },
  { path: '/cp/vehicle-master/create', name: 'VehicleMasterCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/vehicle-master/VehicleMasterForm')) },
  { path: '/cp/vehicle-master/:id/edit', name: 'VehicleMasterEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/vehicle-master/VehicleMasterForm')) },
  { path: '/cp/vehicles', name: 'VehicleList', auth: 'requiresAuth', element: lazyElement(() => import('../views/vehicles/VehicleList')) },
  { path: '/cp/vehicles/create', name: 'VehicleCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/vehicles/VehicleForm')) },
  { path: '/cp/vehicles/:id/edit', name: 'VehicleEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/vehicles/VehicleForm')) },
  { path: '/cp/vehicles/:id', name: 'VehicleDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/vehicles/VehicleDetail')) },
  { path: '/cp/document-vault', name: 'DocumentVault', auth: 'requiresAuth', element: lazyElement(() => import('../views/documents/DocumentVault')) },
  { path: '/cp/inventory', name: 'InventoryList', auth: 'requiresAuth', element: lazyElement(() => import('../views/inventory/InventoryList')) },
  { path: '/cp/inventory/categories', name: 'InventoryCategories', auth: 'requiresAuth', element: lazyElement(() => import('../views/inventory/InventoryLookupManager')) },
  { path: '/cp/inventory/vendors', name: 'InventoryVendors', auth: 'requiresAuth', element: lazyElement(() => import('../views/inventory/InventoryLookupManager')) },
  { path: '/cp/inventory/locations', name: 'InventoryLocations', auth: 'requiresAuth', element: lazyElement(() => import('../views/inventory/InventoryLookupManager')) },
  { path: '/cp/inventory/create', name: 'InventoryCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/inventory/InventoryForm')) },
  { path: '/cp/inventory/:id/edit', name: 'InventoryEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/inventory/InventoryForm')) },
  { path: '/cp/inventory/alerts', name: 'InventoryAlerts', auth: 'requiresAuth', element: lazyElement(() => import('../views/inventory/InventoryAlerts')) },
  { path: '/cp/inventory/stock-orders', name: 'InventoryStockOrders', auth: 'requiresAuth', element: lazyElement(() => import('../views/inventory/InventoryStockOrders')) },
  { path: '/cp/inventory/pull-requests', name: 'InventoryPullRequests', auth: 'requiresAuth', element: lazyElement(() => import('../views/inventory/PullRequestList')) },
  { path: '/cp/warranty', name: 'WarrantyClaims', auth: 'requiresAuth', element: lazyElement(() => import('../views/warranty/WarrantyClaims')) },
  { path: '/cp/storage/impound-intake', name: 'ImpoundIntake', auth: 'requiresAuth', element: lazyElement(() => import('../views/storage/ImpoundIntake')) },
  { path: '/cp/storage/ledger', name: 'StorageFeeLedger', auth: 'requiresAuth', element: lazyElement(() => import('../views/storage/StorageFeeLedger')) },
  { path: '/cp/storage/notices', name: 'StorageNotices', auth: 'requiresAuth', element: lazyElement(() => import('../views/storage/NoticeGeneration')) },
  { path: '/cp/storage/release-checklist', name: 'StorageReleaseChecklist', auth: 'requiresAuth', element: lazyElement(() => import('../views/storage/ReleaseChecklist')) },
  { path: '/cp/storage/auction-management', name: 'AuctionManagement', auth: 'requiresAuth', element: lazyElement(() => import('../views/storage/AuctionManagement')) },
  { path: '/cp/storage/spot-checks', name: 'StorageSpotChecks', auth: 'requiresAuth', element: lazyElement(() => import('../views/storage/InventorySpotChecks')) },
  { path: '/cp/towing/pricing', name: 'TowingPricingMatrix', auth: 'requiresAuth', element: lazyElement(() => import('../views/towing/TowingPricingMatrix')) },
  { path: '/cp/financial/entries', name: 'FinancialEntries', auth: 'requiresAuth', element: lazyElement(() => import('../views/financial/FinancialEntries')) },
  { path: '/cp/financial/reconciliation', name: 'FinancialReconciliation', auth: 'requiresAuth', element: lazyElement(() => import('../views/financial/Reconciliation')) },
  { path: '/cp/financial/categories', name: 'FinancialCategories', auth: 'requiresAuth', element: lazyElement(() => import('../views/financial/AccountCategories')) },
  // UIG-3 / UIG-4 / UIG-8 — the financial/vendors stubs were never wired
  // up to a real backend; the canonical vendor master lives at
  // /cp/procurement/vendors. Keep this path as a redirect so any
  // bookmark / external link continues to land somewhere useful.
  { path: '/cp/financial/vendors', name: 'FinancialVendors', auth: 'requiresAuth', element: <Navigate to="/cp/procurement/vendors" replace /> },
  { path: '/cp/reports', name: 'FinancialReports', auth: 'requiresAuth', element: lazyElement(() => import('../views/financial/Reports')) },
  { path: '/cp/reports/customer-retention', name: 'CustomerRetentionReport', auth: 'requiresAuth', element: lazyElement(() => import('../views/reports/CustomerRetentionReport')) },
  { path: '/cp/reports/overview', name: 'Reports', auth: 'requiresAuth', element: lazyElement(() => import('../views/Reports')) },
  { path: '/cp/audit', name: 'AuditLogs', auth: 'requiresAuth', element: lazyElement(() => import('../views/audit/AuditLogs')) },
  { path: '/cp/security', name: 'Security', auth: 'requiresAuth', element: lazyElement(() => import('../views/auth/Security')) },
  { path: '/cp/users', name: 'UsersList', auth: 'requiresAuth', element: lazyElement(() => import('../views/users/UsersList')) },
  { path: '/cp/users/create', name: 'UserCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/users/UserForm')) },
  { path: '/cp/users/groups', name: 'UserGroups', auth: 'requiresAuth', element: lazyElement(() => import('../views/users/UserGroups')) },
  { path: '/cp/users/roles', name: 'RoleManagement', auth: 'requiresAuth', element: lazyElement(() => import('../views/users/RoleManagement')) },
  { path: '/cp/users/:id', name: 'UserEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/users/UserForm')) },
  { path: '/cp/inspections/templates', name: 'InspectionTemplates', auth: 'requiresAuth', element: lazyElement(() => import('../views/inspections/TemplateManager')) },
  { path: '/cp/inspections/work', name: 'TechnicianInspections', auth: 'requiresAuth', element: lazyElement(() => import('../views/inspections/TechnicianInspections')) },
  { path: '/cp/inspections/:id/recommendations', name: 'InspectionRecommendations', auth: 'requiresAuth', element: lazyElement(() => import('../views/inspections/InspectionRecommendations')) },
  { path: '/cp/cms', name: 'CMSDashboard', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSDashboard')) },
  { path: '/cp/cms/pages', name: 'CMSPageList', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSPageList')) },
  { path: '/cp/cms/pages/create', name: 'CMSPageCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSPageForm')) },
  { path: '/cp/cms/pages/:id', name: 'CMSPageEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSPageForm')) },
  { path: '/cp/cms/categories', name: 'CMSCategoryList', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSCategoryList')) },
  { path: '/cp/cms/categories/create', name: 'CMSCategoryCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSCategoryForm')) },
  { path: '/cp/cms/categories/:id', name: 'CMSCategoryEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSCategoryForm')) },
  { path: '/cp/cms/menus', name: 'CMSMenuList', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSMenuList')) },
  { path: '/cp/cms/menus/create', name: 'CMSMenuCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSMenuForm')) },
  { path: '/cp/cms/menus/:id', name: 'CMSMenuEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSMenuForm')) },
  { path: '/cp/cms/media', name: 'CMSMediaLibrary', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSMediaLibrary')) },
  { path: '/cp/cms/components', name: 'CMSComponentList', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSComponentList')) },
  { path: '/cp/cms/components/create', name: 'CMSComponentCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSComponentForm')) },
  { path: '/cp/cms/components/:id', name: 'CMSComponentEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSComponentForm')) },
  { path: '/cp/cms/templates', name: 'CMSTemplateList', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSTemplateList')) },
  { path: '/cp/cms/templates/create', name: 'CMSTemplateCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSTemplateForm')) },
  { path: '/cp/cms/templates/:id', name: 'CMSTemplateEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/CMSTemplateForm')) },
  { path: '/cp/cms/404-manager', name: 'NotFoundManager', auth: 'requiresAuth', element: lazyElement(() => import('../views/cms/NotFoundManager')) },
  // ── WOMS expansion stubs (Phase A foundation pass) ─────────────────
  // CRM
  { path: '/cp/crm/companies', name: 'CrmCompanies', auth: 'requiresAuth', element: lazyElement(() => import('../views/crm/CompanyList')) },
  { path: '/cp/crm/companies/create', name: 'CrmCompanyCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/crm/CompanyForm')) },
  { path: '/cp/crm/companies/:id', name: 'CrmCompanyDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/crm/CompanyDetail')) },
  { path: '/cp/crm/companies/:id/edit', name: 'CrmCompanyEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/crm/CompanyForm')) },
  { path: '/cp/crm/sites', name: 'CrmSites', auth: 'requiresAuth', element: lazyElement(() => import('../views/crm/SiteList')) },
  { path: '/cp/crm/sites/:id', name: 'CrmSiteDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/crm/SiteDetail')) },

  // Contracts
  { path: '/cp/contracts', name: 'ContractsList', auth: 'requiresAuth', element: lazyElement(() => import('../views/contracts/ContractList')) },
  { path: '/cp/contracts/create', name: 'ContractCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/contracts/ContractForm')) },
  { path: '/cp/contracts/:id', name: 'ContractDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/contracts/ContractDetail')) },
  { path: '/cp/contracts/:id/edit', name: 'ContractEdit', auth: 'requiresAuth', element: lazyElement(() => import('../views/contracts/ContractForm')) },

  // Tickets
  { path: '/cp/tickets', name: 'TicketsList', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketList')) },
  { path: '/cp/tickets/create', name: 'TicketCreate', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketCreate')) },
  { path: '/cp/tickets/triage', name: 'TicketTriage', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketTriage')) },
  { path: '/cp/tickets/queues', name: 'TicketQueues', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketQueues')) },
  { path: '/cp/tickets/sla-policies', name: 'TicketSlaPolicies', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketSlaPolicies')) },
  { path: '/cp/tickets/routing-rules', name: 'TicketRoutingRules', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketRoutingRules')) },
  { path: '/cp/tickets/escalation-rules', name: 'TicketEscalationRules', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketEscalationRules')) },
  { path: '/cp/tickets/categories', name: 'TicketCategories', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketCategories')) },
  { path: '/cp/tickets/close-reasons', name: 'TicketCloseReasons', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketCloseReasons')) },
  { path: '/cp/tickets/resolution-codes', name: 'TicketResolutionCodes', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketResolutionCodes')) },
  { path: '/cp/tickets/failure-codes', name: 'TicketFailureCodes', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketFailureCodes')) },
  { path: '/cp/tickets/:id', name: 'TicketDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/tickets/TicketDetail')) },

  // PM (preventive maintenance)
  { path: '/cp/pm/plans', name: 'PmPlans', auth: 'requiresAuth', element: lazyElement(() => import('../views/pm/PmPlans')) },
  { path: '/cp/pm/schedules', name: 'PmSchedules', auth: 'requiresAuth', element: lazyElement(() => import('../views/pm/PmSchedules')) },
  { path: '/cp/pm/compliance', name: 'PmCompliance', auth: 'requiresAuth', element: lazyElement(() => import('../views/pm/PmCompliance')) },

  // Assets
  { path: '/cp/assets', name: 'AssetsList', auth: 'requiresAuth', element: lazyElement(() => import('../views/assets/AssetList')) },
  { path: '/cp/assets/types', name: 'AssetTypes', auth: 'requiresAuth', element: lazyElement(() => import('../views/assets/AssetTypes')) },
  { path: '/cp/assets/leases', name: 'AssetLeases', auth: 'requiresAuth', element: lazyElement(() => import('../views/assets/AssetLeases')) },
  { path: '/cp/assets/acquisitions', name: 'AssetAcquisitions', auth: 'requiresAuth', element: lazyElement(() => import('../views/assets/AssetAcquisitions')) },
  { path: '/cp/assets/decommissions', name: 'AssetDecommissions', auth: 'requiresAuth', element: lazyElement(() => import('../views/assets/AssetDecommissions')) },
  { path: '/cp/assets/import', name: 'AssetImport', auth: 'requiresAuth', element: lazyElement(() => import('../views/assets/AssetImport')) },
  { path: '/cp/procurement/vendors', name: 'Vendors', auth: 'requiresAuth', element: lazyElement(() => import('../views/procurement/Vendors')) },
  { path: '/cp/procurement/purchase-orders', name: 'PurchaseOrders', auth: 'requiresAuth', element: lazyElement(() => import('../views/procurement/PurchaseOrders')) },
  { path: '/cp/procurement/purchase-orders/:id', name: 'PurchaseOrderDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/procurement/PurchaseOrderDetail')) },
  { path: '/cp/assets/:id', name: 'AssetDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/assets/AssetDetail')) },

  // Software CMDB (Phase 14 / M9)
  { path: '/cp/it/software', name: 'SoftwareInventory', auth: 'requiresAuth', element: lazyElement(() => import('../views/software-inventory/SoftwareInventory')) },

  // Change management — RFC + CAB (Phase 14 / S3)
  { path: '/cp/it/change-management', name: 'ChangeManagement', auth: 'requiresAuth', element: lazyElement(() => import('../views/change-management/ChangeManagement')) },

  // Security credential register (Phase 16 / S1)
  { path: '/cp/security/credentials', name: 'SecurityCredentials', auth: 'requiresAuth', element: lazyElement(() => import('../views/security/CredentialRegister')) },
  { path: '/cp/pos/terminals', name: 'PosTerminals', auth: 'requiresAuth', element: lazyElement(() => import('../views/pos/PosTerminals')) },
  // Technician skill matrix (Phase 17 / S11)
  { path: '/cp/skills/matrix', name: 'SkillMatrix', auth: 'requiresAuth', element: lazyElement(() => import('../views/skills/SkillMatrix')) },
  // Multi-trade dispatch board (Phase 17 / M10)
  { path: '/cp/dispatch-board', name: 'DispatchBoard', auth: 'requiresAuth', element: lazyElement(() => import('../views/dispatch-board/DispatchBoard')) },
  // Consolidated monthly statements (Phase 17 / M11)
  { path: '/cp/billing/consolidated', name: 'ConsolidatedStatements', auth: 'requiresAuth', element: lazyElement(() => import('../views/invoices/ConsolidatedStatements')) },
  // Multi-site chain rollup (Phase 17 / S4)
  { path: '/cp/chain-rollup', name: 'ChainRollup', auth: 'requiresAuth', element: lazyElement(() => import('../views/chain-rollup/ChainRollup')) },
  // Trade-specific KPI dashboard (Phase 17 / S10)
  { path: '/cp/trade-kpis', name: 'TradeKpis', auth: 'requiresAuth', element: lazyElement(() => import('../views/trade-kpis/TradeKpis')) },

  // Fleet
  { path: '/cp/fleet/units', name: 'FleetUnits', auth: 'requiresAuth', element: lazyElement(() => import('../views/fleet/FleetUnits')) },
  { path: '/cp/fleet/external-repairs', name: 'FleetExternalRepairs', auth: 'requiresAuth', element: lazyElement(() => import('../views/fleet/ExternalRepairs')) },
  { path: '/cp/fleet/reports', name: 'FleetReports', auth: 'requiresAuth', element: lazyElement(() => import('../views/fleet/FleetReports')) },
  { path: '/cp/fleet/units/:id', name: 'FleetUnitDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/fleet/FleetUnitDetail')) },

  // Routing
  { path: '/cp/routing/service-routes', name: 'ServiceRoutes', auth: 'requiresAuth', element: lazyElement(() => import('../views/service-routes/ServiceRoutes')) },
  { path: '/cp/my-routes', name: 'MyRoutes', auth: 'requiresAuth', element: lazyElement(() => import('../views/service-routes/MyRoutes')) },
  { path: '/cp/routing/route-plans', name: 'RoutePlans', auth: 'requiresAuth', element: lazyElement(() => import('../views/routing/RoutePlans')) },
  { path: '/cp/routing/geo-fences', name: 'GeoFences', auth: 'requiresAuth', element: lazyElement(() => import('../views/routing/GeoFences')) },
  { path: '/cp/routing/route-plans/:id', name: 'RoutePlanDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/routing/RoutePlanDetail')) },

  // Capital plan
  { path: '/cp/capital-plan/aging', name: 'CapitalPlanAging', auth: 'requiresAuth', element: lazyElement(() => import('../views/capital-plan/AssetAging')) },
  { path: '/cp/capital-plan/scoring-models', name: 'CapitalPlanScoringModels', auth: 'requiresAuth', element: lazyElement(() => import('../views/capital-plan/ScoringModels')) },
  { path: '/cp/capital-plan/plans', name: 'CapitalPlanPlans', auth: 'requiresAuth', element: lazyElement(() => import('../views/capital-plan/CapitalPlans')) },
  { path: '/cp/capital-plan/plans/:id', name: 'CapitalPlanDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/capital-plan/CapitalPlanDetail')) },

  // Org structure
  { path: '/cp/divisions', name: 'Divisions', auth: 'requiresAuth', element: lazyElement(() => import('../views/divisions/Divisions')) },
  { path: '/cp/branches/dashboards', name: 'BranchDashboards', auth: 'requiresAuth', element: lazyElement(() => import('../views/branch-dashboards/BranchDashboards')) },
  { path: '/cp/branches/:id/dashboard', name: 'BranchDashboard', auth: 'requiresAuth', element: lazyElement(() => import('../views/branch-dashboards/BranchDashboard')) },

  // Subcontractors
  { path: '/cp/subcontractors', name: 'Subcontractors', auth: 'requiresAuth', element: lazyElement(() => import('../views/subcontractors/Subcontractors')) },
  { path: '/cp/subcontractors/:id', name: 'SubcontractorDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/subcontractors/SubcontractorDetail')) },
  { path: '/cp/sub-portal-tokens', name: 'SubPortalTokens', auth: 'requiresAuth', element: lazyElement(() => import('../views/sub-portal/SubPortalTokens')) },
  { path: '/cp/vendor-portal-tokens', name: 'VendorPortalTokens', auth: 'requiresAuth', element: lazyElement(() => import('../views/vendor-portal/VendorPortalTokens')) },

  // Voice notes
  { path: '/cp/voice-notes', name: 'VoiceNotes', auth: 'requiresAuth', element: lazyElement(() => import('../views/voice-notes/VoiceNotes')) },
  { path: '/cp/voice-notes/pending', name: 'VoiceNotesPending', auth: 'requiresAuth', element: lazyElement(() => import('../views/voice-notes/VoiceNotesPending')) },

  // Custom fields
  { path: '/cp/custom-fields', name: 'CustomFields', auth: 'requiresAuth', element: lazyElement(() => import('../views/custom-fields/CustomFields')) },

  // Integrations (third-party connections — distinct from settings/integrations stub)
  { path: '/cp/integrations', name: 'IntegrationsList', auth: 'requiresAuth', element: lazyElement(() => import('../views/integrations/IntegrationsList')) },
  { path: '/cp/integrations/:id', name: 'IntegrationDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/integrations/IntegrationDetail')) },

  // SSO providers
  { path: '/cp/sso/providers', name: 'SsoProviders', auth: 'requiresAuth', element: lazyElement(() => import('../views/sso/SsoProviders')) },

  // Security & retention
  { path: '/cp/security-events', name: 'SecurityEvents', auth: 'requiresAuth', element: lazyElement(() => import('../views/security/SecurityEvents')) },
  { path: '/cp/retention/policies', name: 'RetentionPolicies', auth: 'requiresAuth', element: lazyElement(() => import('../views/retention/RetentionPolicies')) },
  { path: '/cp/retention/runs', name: 'RetentionRuns', auth: 'requiresAuth', element: lazyElement(() => import('../views/retention/RetentionRuns')) },

  // ETA promises
  { path: '/cp/eta/promises', name: 'EtaPromises', auth: 'requiresAuth', element: lazyElement(() => import('../views/eta/EtaPromises')) },

  { path: '/portal', name: 'CustomerPortal', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/Dashboard')) },
  { path: '/portal/invoices', name: 'CustomerInvoices', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/Invoices')) },
  { path: '/portal/invoices/:id', name: 'CustomerInvoiceDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/InvoiceDetail')) },
  { path: '/portal/credit', name: 'CustomerCredit', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/Credit')) },
  { path: '/portal/appointments', name: 'CustomerAppointments', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/Appointments')) },
  { path: '/portal/inspections', name: 'CustomerInspections', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/Inspections')) },
  { path: '/portal/warranty-claims', name: 'CustomerWarrantyClaims', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/WarrantyClaims')) },
  { path: '/portal/warranty-claims/:id', name: 'CustomerWarrantyClaimDetail', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/WarrantyClaimDetail')) },
  { path: '/portal/vehicles', name: 'CustomerVehicles', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/Vehicles')) },
  { path: '/portal/profile', name: 'CustomerProfile', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/Profile')) },
  { path: '/portal/workorders', name: 'CustomerWorkorders', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/Workorders')) },
  { path: '/portal/workorders/:id', name: 'CustomerWorkorderTimeline', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/WorkorderTimeline')) },
  { path: '/portal/request', name: 'CustomerRequestWizard', auth: 'requiresAuth', element: lazyElement(() => import('../views/customer-portal/RequestWizard')) },
  { path: '/ess', name: 'EssDashboard', auth: 'requiresAuth', element: lazyElement(() => import('../views/ess/Dashboard')) },
  { path: '/ess/time-clock', name: 'EssTimeClock', auth: 'requiresAuth', element: lazyElement(() => import('../views/ess/TimeClock')) },
  { path: '/ess/schedule', name: 'EssSchedule', auth: 'requiresAuth', element: lazyElement(() => import('../views/ess/Schedule')) },
  { path: '/ess/pay-history', name: 'EssPayHistory', auth: 'requiresAuth', element: lazyElement(() => import('../views/ess/PayHistory')) },
  { path: '/ess/leave-requests', name: 'EssLeaveRequests', auth: 'requiresAuth', element: lazyElement(() => import('../views/ess/LeaveRequests')) },
  { path: '/ess/profile', name: 'EssProfile', auth: 'requiresAuth', element: lazyElement(() => import('../views/ess/Profile')) },
  { path: '/tenant', name: 'TenantMyUnits', auth: 'requiresAuth', element: lazyElement(() => import('../views/tenant/MyUnits')) },
  { path: '/tenant/requests', name: 'TenantMyRequests', auth: 'requiresAuth', element: lazyElement(() => import('../views/tenant/MyRequests')) },
  { path: '/tenant/requests/new', name: 'TenantNewRequest', auth: 'requiresAuth', element: lazyElement(() => import('../views/tenant/NewRequest')) },
]

const settingsRoutes = [
  { path: '/cp/settings', name: 'Settings', element: lazyElement(() => import('../views/settings/SettingsPage')) },
  { path: '/cp/settings/profile', name: 'SettingsShopProfile', element: lazyElement(() => import('../views/settings/SettingsShopProfile')) },
  { path: '/cp/settings/terms', name: 'SettingsTerms', element: lazyElement(() => import('../views/settings/SettingsTerms')) },
  { path: '/cp/settings/templates', name: 'SettingsTemplates', element: lazyElement(() => import('../views/settings/SettingsTemplates')) },
  { path: '/cp/settings/rejection-reasons', name: 'SettingsRejectionReasons', element: lazyElement(() => import('../views/settings/SettingsRejectionReasons')) },
  { path: '/cp/settings/pricing', name: 'SettingsPricing', element: lazyElement(() => import('../views/settings/SettingsPricing')) },
  { path: '/cp/settings/security', name: 'SettingsSecurity', element: lazyElement(() => import('../views/settings/SettingsSecurity')) },
  { path: '/cp/settings/notifications', name: 'SettingsNotifications', element: lazyElement(() => import('../views/settings/SettingsNotifications')) },
  { path: '/cp/settings/payments', name: 'SettingsPayments', element: lazyElement(() => import('../views/settings/SettingsPayments')) },
  { path: '/cp/settings/integrations', name: 'SettingsIntegrations', element: lazyElement(() => import('../views/settings/SettingsIntegrations')) },
  { path: '/cp/settings/services', name: 'ServiceTypes', element: lazyElement(() => import('../views/settings/ServiceTypes')) },
  { path: '/cp/settings/service-lines', name: 'SettingsServiceLines', element: lazyElement(() => import('../views/settings/SettingsServiceLines')) },
  { path: '/cp/settings/modules', name: 'ModuleSettings', element: lazyElement(() => import('../views/settings/ModuleSettings')) },
  { path: '/cp/settings/dispatch', name: 'SettingsDispatch', element: lazyElement(() => import('../views/settings/SettingsDispatch')) },
  { path: '/cp/settings/vin-decoder', name: 'SettingsVinDecoder', element: lazyElement(() => import('../views/settings/SettingsVinDecoder')) },
  { path: '/cp/settings/property-management', name: 'PropertyManagement', element: lazyElement(() => import('../views/settings/PropertyManagement')) },
]

const withAuthLoader = (route) => ({
  path: route.path,
  loader: route.auth === 'guest' ? requireGuest : route.auth === 'requiresAuth' ? requireAuth : undefined,
  element: route.element,
})

const publicChildren = [...guestRoutes, ...publicRoutes].map(withAuthLoader)

const adminRoutes = protectedRoutes.filter((route) => route.path.startsWith('/cp'))
const customerRoutes = protectedRoutes.filter((route) => route.path.startsWith('/portal'))
const essRoutes = protectedRoutes.filter((route) => route.path.startsWith('/ess'))
const tenantRoutes = protectedRoutes.filter((route) => route.path.startsWith('/tenant'))

const toChildRoute = (route, basePath) => {
  const suffix = route.path.replace(basePath, '')
  if (!suffix || suffix === '/') {
    return { index: true, element: route.element }
  }

  return { path: suffix.replace(/^\//, ''), element: route.element }
}

const adminChildren = adminRoutes.map((route) => toChildRoute(route, '/cp'))
const customerChildren = customerRoutes.map((route) => toChildRoute(route, '/portal'))
const essChildren = essRoutes.map((route) => toChildRoute(route, '/ess'))
const tenantChildren = tenantRoutes.map((route) => toChildRoute(route, '/tenant'))

adminChildren.push({
  path: 'settings',
  element: lazyElement(() => import('../views/settings/SettingsLayout')),
  children: [
    { index: true, element: lazyElement(() => import('../views/settings/SettingsPage')) },
    { path: 'profile', element: lazyElement(() => import('../views/settings/SettingsShopProfile')) },
    { path: 'terms', element: lazyElement(() => import('../views/settings/SettingsTerms')) },
    { path: 'templates', element: lazyElement(() => import('../views/settings/SettingsTemplates')) },
    { path: 'rejection-reasons', element: lazyElement(() => import('../views/settings/SettingsRejectionReasons')) },
    { path: 'pricing', element: lazyElement(() => import('../views/settings/SettingsPricing')) },
    { path: 'security', element: lazyElement(() => import('../views/settings/SettingsSecurity')) },
    { path: 'notifications', element: lazyElement(() => import('../views/settings/SettingsNotifications')) },
    { path: 'payments', element: lazyElement(() => import('../views/settings/SettingsPayments')) },
    { path: 'integrations', element: lazyElement(() => import('../views/settings/SettingsIntegrations')) },
    { path: 'services', element: lazyElement(() => import('../views/settings/ServiceTypes')) },
    { path: 'service-lines', element: lazyElement(() => import('../views/settings/SettingsServiceLines')) },
    { path: 'modules', element: lazyElement(() => import('../views/settings/ModuleSettings')) },
    { path: 'dispatch', element: lazyElement(() => import('../views/settings/SettingsDispatch')) },
    { path: 'vin-decoder', element: lazyElement(() => import('../views/settings/SettingsVinDecoder')) },
    { path: 'property-management', element: lazyElement(() => import('../views/settings/PropertyManagement')) },
  ],
})

adminChildren.push({
  path: '*',
  element: lazyElement(() => import('../views/NotFound')),
})

customerChildren.push({
  path: '*',
  element: lazyElement(() => import('../views/NotFound')),
})

essChildren.push({
  path: '*',
  element: lazyElement(() => import('../views/NotFound')),
})

tenantChildren.push({
  path: '*',
  element: lazyElement(() => import('../views/NotFound')),
})

export const reactRouteSubset = [
  ...guestRoutes,
  ...publicRoutes,
  ...protectedRoutes,
  ...settingsRoutes.map((route) => ({ ...route, auth: 'requiresAuth' })),
].map((route) => ({
  path: route.path,
  name: route.name,
  auth: route.auth || 'requiresAuth',
}))

export const router = createBrowserRouter([
  {
    element: <PublicLayout />,
    children: publicChildren,
  },
  {
    path: '/cp',
    loader: requireAuth,
    element: <AdminLayout />,
    children: adminChildren,
  },
  {
    path: '/portal',
    loader: requireAuth,
    element: <CustomerLayout />,
    children: customerChildren,
  },
  {
    path: '/ess',
    loader: requireAuth,
    element: <EssLayout />,
    children: essChildren,
  },
  {
    path: '/tenant',
    loader: requireAuth,
    element: <TenantLayout />,
    children: tenantChildren,
  },
  // Phase 2a — new portal tree under /p/* (parallel to legacy /portal/*).
  // PortalShell mounts both the theme provider (host-resolved branding) and
  // the portal auth provider (namespaced JWT). Guards differ from staff:
  // requirePortalAuth reads the portal-namespaced token, not auth_token.
  {
    path: '/p',
    element: <PortalShell />,
    children: [
      { path: 'login', loader: requirePortalGuest, element: lazyElement(() => import('../views/portal/PortalLogin')) },
      // Phase 2e — IdP redirect-back lands here. No guard so an
      // unauthenticated user can complete the OIDC dance.
      { path: 'auth/sso/callback', element: lazyElement(() => import('../views/portal/PortalSsoCallback')) },
      {
        element: lazyElement(() => import('../views/portal/PortalLayout')),
        loader: requirePortalAuth,
        children: [
          { index: true, element: lazyElement(() => import('../views/portal/PortalDashboard')) },
          { path: 'approvals', element: lazyElement(() => import('../views/portal/PortalApprovals')) },
          { path: 'requests', element: lazyElement(() => import('../views/portal/PortalRequest')) },
          { path: 'invoices', element: lazyElement(() => import('../views/portal/PortalInvoices')) },
          { path: 'invoices/:id', element: lazyElement(() => import('../views/portal/PortalInvoiceDetail')) },
          { path: 'payment-methods', element: lazyElement(() => import('../views/portal/PortalPaymentMethods')) },
          { path: 'sites', element: lazyElement(() => import('../views/portal/PortalSites')) },
          { path: 'work-orders', element: lazyElement(() => import('../views/portal/PortalWorkorders')) },
          { path: 'work-orders/:id', element: lazyElement(() => import('../views/portal/PortalWorkorderDetail')) },
          { path: 'contracts', element: lazyElement(() => import('../views/portal/PortalContracts')) },
          { path: 'contracts/:id', element: lazyElement(() => import('../views/portal/PortalContractDetail')) },
          { path: 'messages', element: lazyElement(() => import('../views/portal/PortalMessages')) },
          { path: 'satisfaction', element: lazyElement(() => import('../views/portal/PortalCsat')) },
          { path: 'notifications', element: lazyElement(() => import('../views/portal/PortalNotificationPreferences')) },
          { path: 'activity', element: lazyElement(() => import('../views/portal/PortalAuditTrail')) },
          { path: 'api-tokens', element: lazyElement(() => import('../views/portal/PortalApiTokens')) },
          { path: '*', element: lazyElement(() => import('../views/NotFound')) },
        ],
      },
    ],
  },
], { basename: reactBasename })
