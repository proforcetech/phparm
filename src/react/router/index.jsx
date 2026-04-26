import { createBrowserRouter, redirect, Outlet } from 'react-router-dom'

import Login from '../views/auth/Login'
import CustomerLogin from '../views/auth/CustomerLogin'
import ForgotPassword from '../views/auth/ForgotPassword'
import ResetPassword from '../views/auth/ResetPassword'
import AcceptInvite from '../views/auth/AcceptInvite'
import Register from '../views/auth/Register'
import AdminDashboard from '../views/dashboard/AdminDashboard'
import StaffProfile from '../views/users/Profile'
import InvoiceList from '../views/invoices/InvoiceList'
import InvoiceDetail from '../views/invoices/InvoiceDetail'
import InvoiceCreate from '../views/invoices/InvoiceCreate'
import QuickSale from '../views/pos/QuickSale'
import EstimateList from '../views/estimates/EstimateList'
import EstimateCreate from '../views/estimates/EstimateCreate'
import EstimateDetail from '../views/estimates/EstimateDetail'
import EstimateEdit from '../views/estimates/EstimateEdit'
import WorkorderList from '../views/workorders/WorkorderList'
import WorkorderDetail from '../views/workorders/WorkorderDetail'
import QCChecklist from '../views/workorders/QCChecklist'
import DispatchView from '../views/dispatch/DispatchView'
import DriverJobIntake from '../views/driver/DriverJobIntake'
import TruckChecklistForm from '../views/driver/TruckChecklistForm'
import TruckChecklistLogs from '../views/driver/TruckChecklistLogs'
import TruckChecklistTemplates from '../views/driver/TruckChecklistTemplates'
import BundleList from '../views/bundles/BundleList'
import BundleForm from '../views/bundles/BundleForm'
import AppointmentList from '../views/appointments/AppointmentList'
import AppointmentCalendar from '../views/appointments/AppointmentCalendar'
import AppointmentBook from '../views/appointments/AppointmentBook'
import AvailabilitySettings from '../views/appointments/AvailabilitySettings'
import TimeLogs from '../views/time/TimeLogs'
import TechnicianPortal from '../views/time/TechnicianPortal'
import LeaveRequestsAdmin from '../views/time/LeaveRequestsAdmin'
import CustomerList from '../views/customers/CustomerList'
import CustomerForm from '../views/customers/CustomerForm'
import CustomerDetail from '../views/customers/CustomerDetail'
import CustomerPublicDetail from '../views/customers/CustomerPublicDetail'
import VehicleMasterList from '../views/vehicle-master/VehicleMasterList'
import VehicleMasterForm from '../views/vehicle-master/VehicleMasterForm'
import VehicleList from '../views/vehicles/VehicleList'
import VehicleForm from '../views/vehicles/VehicleForm'
import VehicleDetail from '../views/vehicles/VehicleDetail'
import VehiclePublicDetail from '../views/vehicles/VehiclePublicDetail'
import VehiclePublicEdit from '../views/vehicles/VehiclePublicEdit'
import InventoryList from '../views/inventory/InventoryList'
import InventoryLookupManager from '../views/inventory/InventoryLookupManager'
import InventoryForm from '../views/inventory/InventoryForm'
import InventoryAlerts from '../views/inventory/InventoryAlerts'
import InventoryStockOrders from '../views/inventory/InventoryStockOrders'
import InventoryPullRequests from '../views/inventory/PullRequestList'
import WarrantyClaims from '../views/warranty/WarrantyClaims'
import ImpoundIntake from '../views/storage/ImpoundIntake'
import StorageFeeLedger from '../views/storage/StorageFeeLedger'
import NoticeGeneration from '../views/storage/NoticeGeneration'
import ReleaseChecklist from '../views/storage/ReleaseChecklist'
import AuctionManagement from '../views/storage/AuctionManagement'
import InventorySpotChecks from '../views/storage/InventorySpotChecks'
import DocumentVault from '../views/documents/DocumentVault'
import TowingPricingMatrix from '../views/towing/TowingPricingMatrix'
import FinancialEntries from '../views/financial/FinancialEntries'
import Reconciliation from '../views/financial/Reconciliation'
import AccountCategories from '../views/financial/AccountCategories'
import FinancialVendors from '../views/financial/VendorList'
import FinancialVendorForm from '../views/financial/VendorForm'
import FinancialReports from '../views/financial/Reports'
import CustomerRetentionReport from '../views/reports/CustomerRetentionReport'
import Reports from '../views/Reports'
import AuditLogs from '../views/audit/AuditLogs'
import SettingsLayout from '../views/settings/SettingsLayout'
import SettingsPage from '../views/settings/SettingsPage'
import SettingsShopProfile from '../views/settings/SettingsShopProfile'
import SettingsTerms from '../views/settings/SettingsTerms'
import SettingsTemplates from '../views/settings/SettingsTemplates'
import SettingsRejectionReasons from '../views/settings/SettingsRejectionReasons'
import SettingsPricing from '../views/settings/SettingsPricing'
import SettingsSecurity from '../views/settings/SettingsSecurity'
import SettingsNotifications from '../views/settings/SettingsNotifications'
import SettingsPayments from '../views/settings/SettingsPayments'
import SettingsIntegrations from '../views/settings/SettingsIntegrations'
import ServiceTypes from '../views/settings/ServiceTypes'
import SettingsDispatch from '../views/settings/SettingsDispatch'
import SettingsVinDecoder from '../views/settings/SettingsVinDecoder'
import UsersList from '../views/users/UsersList'
import UserForm from '../views/users/UserForm'
import UserGroups from '../views/users/UserGroups'
import RoleManagement from '../views/users/RoleManagement'
import ModuleSettings from '../views/settings/ModuleSettings'
import InspectionTemplates from '../views/inspections/TemplateManager'
import TechnicianInspections from '../views/inspections/TechnicianInspections'
import InspectionRecommendations from '../views/inspections/InspectionRecommendations'
import CMSDashboard from '../views/cms/CMSDashboard'
import CMSPageList from '../views/cms/CMSPageList'
import CMSPageForm from '../views/cms/CMSPageForm'
import CMSCategoryList from '../views/cms/CMSCategoryList'
import CMSCategoryForm from '../views/cms/CMSCategoryForm'
import CMSMenuList from '../views/cms/CMSMenuList'
import CMSMenuForm from '../views/cms/CMSMenuForm'
import CMSComponentList from '../views/cms/CMSComponentList'
import CMSComponentForm from '../views/cms/CMSComponentForm'
import CMSTemplateList from '../views/cms/CMSTemplateList'
import CMSTemplateForm from '../views/cms/CMSTemplateForm'
import CMSMediaLibrary from '../views/cms/CMSMediaLibrary'
import NotFoundManager from '../views/cms/NotFoundManager'
import CustomerPortalDashboard from '../views/customer-portal/Dashboard'
import CustomerPortalInvoices from '../views/customer-portal/Invoices'
import CustomerInvoiceDetail from '../views/customer-portal/InvoiceDetail'
import CustomerCredit from '../views/customer-portal/Credit'
import CustomerAppointments from '../views/customer-portal/Appointments'
import CustomerInspections from '../views/customer-portal/Inspections'
import CustomerWarrantyClaims from '../views/customer-portal/WarrantyClaims'
import CustomerWarrantyClaimDetail from '../views/customer-portal/WarrantyClaimDetail'
import CustomerVehicles from '../views/customer-portal/Vehicles'
import CustomerProfile from '../views/customer-portal/Profile'
import CustomerWorkorders from '../views/customer-portal/Workorders'
import CustomerWorkorderTimeline from '../views/customer-portal/WorkorderTimeline'
import EssDashboard from '../views/ess/Dashboard'
import EssTimeClock from '../views/ess/TimeClock'
import EssSchedule from '../views/ess/Schedule'
import EssPayHistory from '../views/ess/PayHistory'
import EssProfile from '../views/ess/Profile'
import EssLeaveRequests from '../views/ess/LeaveRequests'
import EstimateRequestPage from '../views/public/EstimateRequestPage'
import PublicEstimateView from '../views/public/PublicEstimateView'
import PublicPaymentPortal from '../views/public/PublicPaymentPortal'
import TrackingView from '../views/tracking/TrackingView'
import CMSPage from '../views/public/CMSPage'
import AdminLayout from '../components/layout/AdminLayout'
import CustomerLayout from '../components/layout/CustomerLayout'
import EssLayout from '../components/layout/EssLayout'
import NotFound from '../views/NotFound'
import PlaceholderPage from '../views/PlaceholderPage'

// WOMS expansion modules — all stubbed to PlaceholderPage during foundation
// pass (Phase A of the wire-up plan). Deep wire-up happens module-by-module
// in subsequent phases.
const stub = (title, description) => (
  <PlaceholderPage title={title} description={description} />
)

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

const PublicLayout = () => (
  <div className="react-app">
    <Outlet />
  </div>
)

const guestRoutes = [
  { path: '/login', name: 'Login', auth: 'guest', element: <Login /> },
  { path: '/customer-login', name: 'CustomerLogin', auth: 'guest', element: <CustomerLogin /> },
  { path: '/forgot-password', name: 'ForgotPassword', auth: 'guest', element: <ForgotPassword /> },
  { path: '/reset-password/:token', name: 'ResetPassword', auth: 'guest', element: <ResetPassword /> },
  { path: '/accept-invite/:token', name: 'AcceptInvite', auth: 'guest', element: <AcceptInvite /> },
  { path: '/cp/register', name: 'Register', auth: 'guest', element: <Register /> },
]

const publicRoutes = [
  { path: '/request-estimate', name: 'EstimateRequestForm', auth: 'public', element: <EstimateRequestPage /> },
  { path: '/estimate/view', name: 'PublicEstimateView', auth: 'public', element: <PublicEstimateView /> },
  { path: '/track/:token', name: 'TrackingView', auth: 'public', element: <TrackingView /> },
  { path: '/pay/:token', name: 'PublicPaymentPortal', auth: 'public', element: <PublicPaymentPortal /> },
  { path: '/customers/:id', name: 'CustomerPublicDetail', auth: 'public', element: <CustomerPublicDetail /> },
  { path: '/vehicles/:id', name: 'VehiclePublicDetail', auth: 'public', element: <VehiclePublicDetail /> },
  { path: '/vehicles/:id/edit', name: 'VehiclePublicEdit', auth: 'public', element: <VehiclePublicEdit /> },
  { path: '/', name: 'Home', auth: 'public', element: <CMSPage /> },
  { path: '/*', name: 'CMSPage', auth: 'public', element: <CMSPage /> },
]

const protectedRoutes = [
  { path: '/cp/dashboard', name: 'Dashboard', auth: 'requiresAuth', element: <AdminDashboard /> },
  { path: '/cp/profile', name: 'StaffProfile', auth: 'requiresAuth', element: <StaffProfile /> },
  { path: '/cp/invoices', name: 'InvoiceList', auth: 'requiresAuth', element: <InvoiceList /> },
  { path: '/cp/quick-sale', name: 'QuickSale', auth: 'requiresAuth', element: <QuickSale /> },
  { path: '/cp/invoices/create', name: 'InvoiceCreate', auth: 'requiresAuth', element: <InvoiceCreate /> },
  { path: '/cp/invoices/:id', name: 'InvoiceDetail', auth: 'requiresAuth', element: <InvoiceDetail /> },
  { path: '/cp/estimates', name: 'EstimateList', auth: 'requiresAuth', element: <EstimateList /> },
  { path: '/cp/estimates/create', name: 'EstimateCreate', auth: 'requiresAuth', element: <EstimateCreate /> },
  { path: '/cp/estimates/:id', name: 'EstimateDetail', auth: 'requiresAuth', element: <EstimateDetail /> },
  { path: '/cp/estimates/:id/edit', name: 'EstimateEdit', auth: 'requiresAuth', element: <EstimateEdit /> },
  { path: '/cp/workorders', name: 'WorkorderList', auth: 'requiresAuth', element: <WorkorderList /> },
  { path: '/cp/workorders/:id', name: 'WorkorderDetail', auth: 'requiresAuth', element: <WorkorderDetail /> },
  { path: '/cp/workorders/:id/qc-check', name: 'QCChecklist', auth: 'requiresAuth', element: <QCChecklist /> },
  { path: '/cp/dispatch', name: 'Dispatch', auth: 'requiresAuth', element: <DispatchView /> },
  { path: '/cp/driver/job-intake', name: 'DriverJobIntake', auth: 'requiresAuth', element: <DriverJobIntake /> },
  { path: '/cp/driver/truck-checklists', name: 'TruckChecklists', auth: 'requiresAuth', element: <TruckChecklistForm /> },
  { path: '/cp/driver/truck-checklists/logs', name: 'TruckChecklistLogs', auth: 'requiresAuth', element: <TruckChecklistLogs /> },
  { path: '/cp/driver/truck-checklists/templates', name: 'TruckChecklistTemplates', auth: 'requiresAuth', element: <TruckChecklistTemplates /> },
  { path: '/cp/bundles', name: 'BundleList', auth: 'requiresAuth', element: <BundleList /> },
  { path: '/cp/bundles/create', name: 'BundleCreate', auth: 'requiresAuth', element: <BundleForm /> },
  { path: '/cp/bundles/:id/edit', name: 'BundleEdit', auth: 'requiresAuth', element: <BundleForm /> },
  { path: '/cp/appointments', name: 'AppointmentList', auth: 'requiresAuth', element: <AppointmentList /> },
  { path: '/cp/appointments/calendar', name: 'AppointmentCalendar', auth: 'requiresAuth', element: <AppointmentCalendar /> },
  { path: '/cp/appointments/create', name: 'AppointmentBook', auth: 'requiresAuth', element: <AppointmentBook /> },
  { path: '/cp/appointments/availability-settings', name: 'AvailabilitySettings', auth: 'requiresAuth', element: <AvailabilitySettings /> },
  { path: '/cp/time-logs', name: 'TimeLogs', auth: 'requiresAuth', element: <TimeLogs /> },
  { path: '/cp/leave-requests', name: 'LeaveRequests', auth: 'requiresAuth', element: <LeaveRequestsAdmin /> },
  { path: '/cp/my-time', name: 'TechnicianTime', auth: 'requiresAuth', element: <TechnicianPortal /> },
  { path: '/cp/customers', name: 'CustomerList', auth: 'requiresAuth', element: <CustomerList /> },
  { path: '/cp/customers/create', name: 'CustomerCreate', auth: 'requiresAuth', element: <CustomerForm /> },
  { path: '/cp/customers/:id', name: 'CustomerDetail', auth: 'requiresAuth', element: <CustomerDetail /> },
  { path: '/cp/customers/:id/edit', name: 'CustomerEdit', auth: 'requiresAuth', element: <CustomerForm /> },
  { path: '/cp/vehicle-master', name: 'VehicleMasterList', auth: 'requiresAuth', element: <VehicleMasterList /> },
  { path: '/cp/vehicle-master/create', name: 'VehicleMasterCreate', auth: 'requiresAuth', element: <VehicleMasterForm /> },
  { path: '/cp/vehicle-master/:id/edit', name: 'VehicleMasterEdit', auth: 'requiresAuth', element: <VehicleMasterForm /> },
  { path: '/cp/vehicles', name: 'VehicleList', auth: 'requiresAuth', element: <VehicleList /> },
  { path: '/cp/vehicles/create', name: 'VehicleCreate', auth: 'requiresAuth', element: <VehicleForm /> },
  { path: '/cp/vehicles/:id/edit', name: 'VehicleEdit', auth: 'requiresAuth', element: <VehicleForm /> },
  { path: '/cp/vehicles/:id', name: 'VehicleDetail', auth: 'requiresAuth', element: <VehicleDetail /> },
  { path: '/cp/document-vault', name: 'DocumentVault', auth: 'requiresAuth', element: <DocumentVault /> },
  { path: '/cp/inventory', name: 'InventoryList', auth: 'requiresAuth', element: <InventoryList /> },
  { path: '/cp/inventory/categories', name: 'InventoryCategories', auth: 'requiresAuth', element: <InventoryLookupManager /> },
  { path: '/cp/inventory/vendors', name: 'InventoryVendors', auth: 'requiresAuth', element: <InventoryLookupManager /> },
  { path: '/cp/inventory/locations', name: 'InventoryLocations', auth: 'requiresAuth', element: <InventoryLookupManager /> },
  { path: '/cp/inventory/create', name: 'InventoryCreate', auth: 'requiresAuth', element: <InventoryForm /> },
  { path: '/cp/inventory/:id/edit', name: 'InventoryEdit', auth: 'requiresAuth', element: <InventoryForm /> },
  { path: '/cp/inventory/alerts', name: 'InventoryAlerts', auth: 'requiresAuth', element: <InventoryAlerts /> },
  { path: '/cp/inventory/stock-orders', name: 'InventoryStockOrders', auth: 'requiresAuth', element: <InventoryStockOrders /> },
  { path: '/cp/inventory/pull-requests', name: 'InventoryPullRequests', auth: 'requiresAuth', element: <InventoryPullRequests /> },
  { path: '/cp/warranty', name: 'WarrantyClaims', auth: 'requiresAuth', element: <WarrantyClaims /> },
  { path: '/cp/storage/impound-intake', name: 'ImpoundIntake', auth: 'requiresAuth', element: <ImpoundIntake /> },
  { path: '/cp/storage/ledger', name: 'StorageFeeLedger', auth: 'requiresAuth', element: <StorageFeeLedger /> },
  { path: '/cp/storage/notices', name: 'StorageNotices', auth: 'requiresAuth', element: <NoticeGeneration /> },
  { path: '/cp/storage/release-checklist', name: 'StorageReleaseChecklist', auth: 'requiresAuth', element: <ReleaseChecklist /> },
  { path: '/cp/storage/auction-management', name: 'AuctionManagement', auth: 'requiresAuth', element: <AuctionManagement /> },
  { path: '/cp/storage/spot-checks', name: 'StorageSpotChecks', auth: 'requiresAuth', element: <InventorySpotChecks /> },
  { path: '/cp/towing/pricing', name: 'TowingPricingMatrix', auth: 'requiresAuth', element: <TowingPricingMatrix /> },
  { path: '/cp/financial/entries', name: 'FinancialEntries', auth: 'requiresAuth', element: <FinancialEntries /> },
  { path: '/cp/financial/reconciliation', name: 'FinancialReconciliation', auth: 'requiresAuth', element: <Reconciliation /> },
  { path: '/cp/financial/categories', name: 'FinancialCategories', auth: 'requiresAuth', element: <AccountCategories /> },
  { path: '/cp/financial/vendors', name: 'FinancialVendors', auth: 'requiresAuth', element: <FinancialVendors /> },
  { path: '/cp/financial/vendors/create', name: 'FinancialVendorCreate', auth: 'requiresAuth', element: <FinancialVendorForm /> },
  { path: '/cp/financial/vendors/:id/edit', name: 'FinancialVendorEdit', auth: 'requiresAuth', element: <FinancialVendorForm /> },
  { path: '/cp/reports', name: 'FinancialReports', auth: 'requiresAuth', element: <FinancialReports /> },
  { path: '/cp/reports/customer-retention', name: 'CustomerRetentionReport', auth: 'requiresAuth', element: <CustomerRetentionReport /> },
  { path: '/cp/reports/overview', name: 'Reports', auth: 'requiresAuth', element: <Reports /> },
  { path: '/cp/audit', name: 'AuditLogs', auth: 'requiresAuth', element: <AuditLogs /> },
  { path: '/cp/users', name: 'UsersList', auth: 'requiresAuth', element: <UsersList /> },
  { path: '/cp/users/create', name: 'UserCreate', auth: 'requiresAuth', element: <UserForm /> },
  { path: '/cp/users/groups', name: 'UserGroups', auth: 'requiresAuth', element: <UserGroups /> },
  { path: '/cp/users/roles', name: 'RoleManagement', auth: 'requiresAuth', element: <RoleManagement /> },
  { path: '/cp/users/:id', name: 'UserEdit', auth: 'requiresAuth', element: <UserForm /> },
  { path: '/cp/inspections/templates', name: 'InspectionTemplates', auth: 'requiresAuth', element: <InspectionTemplates /> },
  { path: '/cp/inspections/work', name: 'TechnicianInspections', auth: 'requiresAuth', element: <TechnicianInspections /> },
  { path: '/cp/inspections/:id/recommendations', name: 'InspectionRecommendations', auth: 'requiresAuth', element: <InspectionRecommendations /> },
  { path: '/cp/cms', name: 'CMSDashboard', auth: 'requiresAuth', element: <CMSDashboard /> },
  { path: '/cp/cms/pages', name: 'CMSPageList', auth: 'requiresAuth', element: <CMSPageList /> },
  { path: '/cp/cms/pages/create', name: 'CMSPageCreate', auth: 'requiresAuth', element: <CMSPageForm /> },
  { path: '/cp/cms/pages/:id', name: 'CMSPageEdit', auth: 'requiresAuth', element: <CMSPageForm /> },
  { path: '/cp/cms/categories', name: 'CMSCategoryList', auth: 'requiresAuth', element: <CMSCategoryList /> },
  { path: '/cp/cms/categories/create', name: 'CMSCategoryCreate', auth: 'requiresAuth', element: <CMSCategoryForm /> },
  { path: '/cp/cms/categories/:id', name: 'CMSCategoryEdit', auth: 'requiresAuth', element: <CMSCategoryForm /> },
  { path: '/cp/cms/menus', name: 'CMSMenuList', auth: 'requiresAuth', element: <CMSMenuList /> },
  { path: '/cp/cms/menus/create', name: 'CMSMenuCreate', auth: 'requiresAuth', element: <CMSMenuForm /> },
  { path: '/cp/cms/menus/:id', name: 'CMSMenuEdit', auth: 'requiresAuth', element: <CMSMenuForm /> },
  { path: '/cp/cms/media', name: 'CMSMediaLibrary', auth: 'requiresAuth', element: <CMSMediaLibrary /> },
  { path: '/cp/cms/components', name: 'CMSComponentList', auth: 'requiresAuth', element: <CMSComponentList /> },
  { path: '/cp/cms/components/create', name: 'CMSComponentCreate', auth: 'requiresAuth', element: <CMSComponentForm /> },
  { path: '/cp/cms/components/:id', name: 'CMSComponentEdit', auth: 'requiresAuth', element: <CMSComponentForm /> },
  { path: '/cp/cms/templates', name: 'CMSTemplateList', auth: 'requiresAuth', element: <CMSTemplateList /> },
  { path: '/cp/cms/templates/create', name: 'CMSTemplateCreate', auth: 'requiresAuth', element: <CMSTemplateForm /> },
  { path: '/cp/cms/templates/:id', name: 'CMSTemplateEdit', auth: 'requiresAuth', element: <CMSTemplateForm /> },
  { path: '/cp/cms/404-manager', name: 'NotFoundManager', auth: 'requiresAuth', element: <NotFoundManager /> },
  // ── WOMS expansion stubs (Phase A foundation pass) ─────────────────
  // CRM
  { path: '/cp/crm/companies', name: 'CrmCompanies', auth: 'requiresAuth', element: stub('Companies', 'B2B / commercial customer accounts. Each company has one or more sites.') },
  { path: '/cp/crm/companies/create', name: 'CrmCompanyCreate', auth: 'requiresAuth', element: stub('New Company', 'Create a B2B company record.') },
  { path: '/cp/crm/companies/:id', name: 'CrmCompanyDetail', auth: 'requiresAuth', element: stub('Company Detail', 'Sites, billing contacts, contracts, and entitlements for one company.') },
  { path: '/cp/crm/sites', name: 'CrmSites', auth: 'requiresAuth', element: stub('Sites', 'Service locations across all companies. Each site can host installed assets and contracts.') },
  { path: '/cp/crm/sites/:id', name: 'CrmSiteDetail', auth: 'requiresAuth', element: stub('Site Detail', 'Contacts, blackout windows, codes, assets, and contracts for one site.') },

  // Contracts
  { path: '/cp/contracts', name: 'ContractsList', auth: 'requiresAuth', element: stub('Contracts', 'Master service agreements with sites, entitlements, billing schedules, and renewals.') },
  { path: '/cp/contracts/create', name: 'ContractCreate', auth: 'requiresAuth', element: stub('New Contract', 'Draft a new service contract.') },
  { path: '/cp/contracts/:id', name: 'ContractDetail', auth: 'requiresAuth', element: stub('Contract Detail', 'Sites, entitlements, amendments, billing, and signing status.') },

  // Tickets
  { path: '/cp/tickets', name: 'TicketsList', auth: 'requiresAuth', element: stub('Tickets', 'Service intake — requests routed to queues, scoped by SLA, and converted to workorders.') },
  { path: '/cp/tickets/create', name: 'TicketCreate', auth: 'requiresAuth', element: stub('New Ticket', 'Open a new service ticket.') },
  { path: '/cp/tickets/:id', name: 'TicketDetail', auth: 'requiresAuth', element: stub('Ticket Detail', 'Comments, SLA, ETA promises, triage suggestions, and linked workorders.') },
  { path: '/cp/tickets/triage', name: 'TicketTriage', auth: 'requiresAuth', element: stub('Triage Suggestions', 'AI / rule-driven recommendations awaiting accept/reject.') },
  { path: '/cp/tickets/queues', name: 'TicketQueues', auth: 'requiresAuth', element: stub('Ticket Queues', 'Routing queues for ticket assignment.') },
  { path: '/cp/tickets/sla-policies', name: 'TicketSlaPolicies', auth: 'requiresAuth', element: stub('SLA Policies', 'Response and resolution targets per category/priority.') },
  { path: '/cp/tickets/routing-rules', name: 'TicketRoutingRules', auth: 'requiresAuth', element: stub('Routing Rules', 'Rules that auto-route inbound tickets into queues.') },
  { path: '/cp/tickets/escalation-rules', name: 'TicketEscalationRules', auth: 'requiresAuth', element: stub('Escalation Rules', 'When SLA risk thresholds trip, escalate via these rules.') },
  { path: '/cp/tickets/categories', name: 'TicketCategories', auth: 'requiresAuth', element: stub('Ticket Categories', 'Top-level categorization for incoming requests.') },
  { path: '/cp/tickets/close-reasons', name: 'TicketCloseReasons', auth: 'requiresAuth', element: stub('Close Reasons', 'Catalog of values used to close a ticket.') },
  { path: '/cp/tickets/resolution-codes', name: 'TicketResolutionCodes', auth: 'requiresAuth', element: stub('Resolution Codes', 'Catalog of how a ticket was resolved.') },
  { path: '/cp/tickets/failure-codes', name: 'TicketFailureCodes', auth: 'requiresAuth', element: stub('Failure Codes', 'Catalog of failure-mode codes for analytics.') },

  // PM (preventive maintenance)
  { path: '/cp/pm/plans', name: 'PmPlans', auth: 'requiresAuth', element: stub('PM Plans', 'Preventive-maintenance templates.') },
  { path: '/cp/pm/schedules', name: 'PmSchedules', auth: 'requiresAuth', element: stub('PM Schedules', 'Plans applied to specific targets on a cadence.') },
  { path: '/cp/pm/compliance', name: 'PmCompliance', auth: 'requiresAuth', element: stub('PM Compliance', 'Overdue and at-risk PM rollups.') },

  // Assets
  { path: '/cp/assets', name: 'AssetsList', auth: 'requiresAuth', element: stub('Installed Assets', 'Per-site asset registry with QR labels, documents, and links.') },
  { path: '/cp/assets/types', name: 'AssetTypes', auth: 'requiresAuth', element: stub('Asset Types', 'Catalog of asset categories per division.') },
  { path: '/cp/assets/:id', name: 'AssetDetail', auth: 'requiresAuth', element: stub('Asset Detail', 'Lifecycle, documents, links, and QR for one installed asset.') },

  // Fleet
  { path: '/cp/fleet/units', name: 'FleetUnits', auth: 'requiresAuth', element: stub('Fleet Units', 'Vehicles, trailers, and equipment with readings, assignments, and downtime.') },
  { path: '/cp/fleet/units/:id', name: 'FleetUnitDetail', auth: 'requiresAuth', element: stub('Unit Detail', 'Readings, assignments, downtime, external repairs, and PM bindings.') },
  { path: '/cp/fleet/external-repairs', name: 'FleetExternalRepairs', auth: 'requiresAuth', element: stub('External Repairs', 'Work farmed out to third-party shops.') },
  { path: '/cp/fleet/reports', name: 'FleetReports', auth: 'requiresAuth', element: stub('Fleet Reports', 'Cost-per-mile, cost-per-hour, and utilization rollups.') },

  // Routing
  { path: '/cp/routing/route-plans', name: 'RoutePlans', auth: 'requiresAuth', element: stub('Route Plans', 'Multi-stop dispatch routes with optimization and stop lifecycle.') },
  { path: '/cp/routing/route-plans/:id', name: 'RoutePlanDetail', auth: 'requiresAuth', element: stub('Route Plan Detail', 'Stops, assignments, and live progress.') },
  { path: '/cp/routing/geo-fences', name: 'GeoFences', auth: 'requiresAuth', element: stub('Geo-Fences', 'Boundaries that emit events as units enter/exit.') },

  // Capital plan
  { path: '/cp/capital-plan/aging', name: 'CapitalPlanAging', auth: 'requiresAuth', element: stub('Asset Aging', 'Replacement-readiness rollups by company, division, or portfolio.') },
  { path: '/cp/capital-plan/scoring-models', name: 'CapitalPlanScoringModels', auth: 'requiresAuth', element: stub('Scoring Models', 'Tunable models that drive aging classification.') },
  { path: '/cp/capital-plan/plans', name: 'CapitalPlanPlans', auth: 'requiresAuth', element: stub('Capital Plans', 'Multi-year replacement plans with scenarios.') },
  { path: '/cp/capital-plan/plans/:id', name: 'CapitalPlanDetail', auth: 'requiresAuth', element: stub('Capital Plan Detail', 'Scenarios, line items, and financial rollups for one plan.') },

  // Org structure
  { path: '/cp/divisions', name: 'Divisions', auth: 'requiresAuth', element: stub('Divisions', 'Top-level org partitions above branches.') },
  { path: '/cp/branches/dashboards', name: 'BranchDashboards', auth: 'requiresAuth', element: stub('Branch Dashboards', 'Per-branch operational KPIs.') },
  { path: '/cp/branches/:id/dashboard', name: 'BranchDashboard', auth: 'requiresAuth', element: stub('Branch Dashboard', 'Live ops snapshot for one branch.') },

  // Subcontractors
  { path: '/cp/subcontractors', name: 'Subcontractors', auth: 'requiresAuth', element: stub('Subcontractors', 'External vendors that perform work on our behalf.') },
  { path: '/cp/subcontractors/:id', name: 'SubcontractorDetail', auth: 'requiresAuth', element: stub('Subcontractor Detail', 'Contacts, assignments, and performance.') },

  // Voice notes
  { path: '/cp/voice-notes', name: 'VoiceNotes', auth: 'requiresAuth', element: stub('Voice Notes', 'Audio attachments on tickets and workorders, with transcription.') },
  { path: '/cp/voice-notes/pending', name: 'VoiceNotesPending', auth: 'requiresAuth', element: stub('Pending Voice Notes', 'Voice notes awaiting review or transcription.') },

  // Custom fields
  { path: '/cp/custom-fields', name: 'CustomFields', auth: 'requiresAuth', element: stub('Custom Fields', 'Per-entity field definitions and values.') },

  // Integrations (third-party connections — distinct from settings/integrations stub)
  { path: '/cp/integrations', name: 'IntegrationsList', auth: 'requiresAuth', element: stub('Integrations', 'Third-party providers — accounting, telematics, mapping, access control.') },
  { path: '/cp/integrations/:id', name: 'IntegrationDetail', auth: 'requiresAuth', element: stub('Integration Detail', 'Connection settings, sync history, and webhook events.') },

  // SSO providers
  { path: '/cp/sso/providers', name: 'SsoProviders', auth: 'requiresAuth', element: stub('SSO Providers', 'Identity providers for single sign-on.') },

  // Security & retention
  { path: '/cp/security-events', name: 'SecurityEvents', auth: 'requiresAuth', element: stub('Security Events', 'Auth failures, MFA challenges, and suspicious activity.') },
  { path: '/cp/retention/policies', name: 'RetentionPolicies', auth: 'requiresAuth', element: stub('Retention Policies', 'Data pruning rules with execution history.') },
  { path: '/cp/retention/runs', name: 'RetentionRuns', auth: 'requiresAuth', element: stub('Retention Runs', 'History of retention-policy executions.') },

  // ETA promises
  { path: '/cp/eta/promises', name: 'EtaPromises', auth: 'requiresAuth', element: stub('ETA Promises', 'Customer-facing arrival commitments across tickets and workorders.') },

  { path: '/portal', name: 'CustomerPortal', auth: 'requiresAuth', element: <CustomerPortalDashboard /> },
  { path: '/portal/invoices', name: 'CustomerInvoices', auth: 'requiresAuth', element: <CustomerPortalInvoices /> },
  { path: '/portal/invoices/:id', name: 'CustomerInvoiceDetail', auth: 'requiresAuth', element: <CustomerInvoiceDetail /> },
  { path: '/portal/credit', name: 'CustomerCredit', auth: 'requiresAuth', element: <CustomerCredit /> },
  { path: '/portal/appointments', name: 'CustomerAppointments', auth: 'requiresAuth', element: <CustomerAppointments /> },
  { path: '/portal/inspections', name: 'CustomerInspections', auth: 'requiresAuth', element: <CustomerInspections /> },
  { path: '/portal/warranty-claims', name: 'CustomerWarrantyClaims', auth: 'requiresAuth', element: <CustomerWarrantyClaims /> },
  { path: '/portal/warranty-claims/:id', name: 'CustomerWarrantyClaimDetail', auth: 'requiresAuth', element: <CustomerWarrantyClaimDetail /> },
  { path: '/portal/vehicles', name: 'CustomerVehicles', auth: 'requiresAuth', element: <CustomerVehicles /> },
  { path: '/portal/profile', name: 'CustomerProfile', auth: 'requiresAuth', element: <CustomerProfile /> },
  { path: '/portal/workorders', name: 'CustomerWorkorders', auth: 'requiresAuth', element: <CustomerWorkorders /> },
  { path: '/portal/workorders/:id', name: 'CustomerWorkorderTimeline', auth: 'requiresAuth', element: <CustomerWorkorderTimeline /> },
  { path: '/ess', name: 'EssDashboard', auth: 'requiresAuth', element: <EssDashboard /> },
  { path: '/ess/time-clock', name: 'EssTimeClock', auth: 'requiresAuth', element: <EssTimeClock /> },
  { path: '/ess/schedule', name: 'EssSchedule', auth: 'requiresAuth', element: <EssSchedule /> },
  { path: '/ess/pay-history', name: 'EssPayHistory', auth: 'requiresAuth', element: <EssPayHistory /> },
  { path: '/ess/leave-requests', name: 'EssLeaveRequests', auth: 'requiresAuth', element: <EssLeaveRequests /> },
  { path: '/ess/profile', name: 'EssProfile', auth: 'requiresAuth', element: <EssProfile /> },
]

const settingsRoutes = [
  { path: '/cp/settings', name: 'Settings', element: <SettingsPage /> },
  { path: '/cp/settings/profile', name: 'SettingsShopProfile', element: <SettingsShopProfile /> },
  { path: '/cp/settings/terms', name: 'SettingsTerms', element: <SettingsTerms /> },
  { path: '/cp/settings/templates', name: 'SettingsTemplates', element: <SettingsTemplates /> },
  { path: '/cp/settings/rejection-reasons', name: 'SettingsRejectionReasons', element: <SettingsRejectionReasons /> },
  { path: '/cp/settings/pricing', name: 'SettingsPricing', element: <SettingsPricing /> },
  { path: '/cp/settings/security', name: 'SettingsSecurity', element: <SettingsSecurity /> },
  { path: '/cp/settings/notifications', name: 'SettingsNotifications', element: <SettingsNotifications /> },
  { path: '/cp/settings/payments', name: 'SettingsPayments', element: <SettingsPayments /> },
  { path: '/cp/settings/integrations', name: 'SettingsIntegrations', element: <SettingsIntegrations /> },
  { path: '/cp/settings/services', name: 'ServiceTypes', element: <ServiceTypes /> },
  { path: '/cp/settings/modules', name: 'ModuleSettings', element: <ModuleSettings /> },
  { path: '/cp/settings/dispatch', name: 'SettingsDispatch', element: <SettingsDispatch /> },
  { path: '/cp/settings/vin-decoder', name: 'SettingsVinDecoder', element: <SettingsVinDecoder /> },
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

adminChildren.push({
  path: 'settings',
  element: <SettingsLayout />,
  children: [
    { index: true, element: <SettingsPage /> },
    { path: 'profile', element: <SettingsShopProfile /> },
    { path: 'terms', element: <SettingsTerms /> },
    { path: 'templates', element: <SettingsTemplates /> },
    { path: 'rejection-reasons', element: <SettingsRejectionReasons /> },
    { path: 'pricing', element: <SettingsPricing /> },
    { path: 'security', element: <SettingsSecurity /> },
    { path: 'notifications', element: <SettingsNotifications /> },
    { path: 'payments', element: <SettingsPayments /> },
    { path: 'integrations', element: <SettingsIntegrations /> },
    { path: 'services', element: <ServiceTypes /> },
    { path: 'modules', element: <ModuleSettings /> },
    { path: 'dispatch', element: <SettingsDispatch /> },
    { path: 'vin-decoder', element: <SettingsVinDecoder /> },
  ],
})

adminChildren.push({
  path: '*',
  element: <NotFound />,
})

customerChildren.push({
  path: '*',
  element: <NotFound />,
})

essChildren.push({
  path: '*',
  element: <NotFound />,
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
], { basename: reactBasename })
