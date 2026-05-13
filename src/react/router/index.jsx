import { createBrowserRouter, Navigate, redirect, Outlet } from 'react-router-dom'

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
import AssetLeases from '../views/assets/AssetLeases'
import AssetAcquisitions from '../views/assets/AssetAcquisitions'
import AssetDecommissions from '../views/assets/AssetDecommissions'
import AssetImport from '../views/assets/AssetImport'
import Vendors from '../views/procurement/Vendors'
import PurchaseOrders from '../views/procurement/PurchaseOrders'
import PurchaseOrderDetail from '../views/procurement/PurchaseOrderDetail'
import SoftwareInventory from '../views/software-inventory/SoftwareInventory'
import ChangeManagement from '../views/change-management/ChangeManagement'
import ServiceRoutes from '../views/service-routes/ServiceRoutes'
import MyRoutes from '../views/service-routes/MyRoutes'
import CredentialRegister from '../views/security/CredentialRegister'
import PosTerminals from '../views/pos/PosTerminals'
import SkillMatrix from '../views/skills/SkillMatrix'
import DispatchBoard from '../views/dispatch-board/DispatchBoard'
import ConsolidatedStatements from '../views/invoices/ConsolidatedStatements'
import ChainRollup from '../views/chain-rollup/ChainRollup'
import TradeKpis from '../views/trade-kpis/TradeKpis'
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
import SettingsServiceLines from '../views/settings/SettingsServiceLines'
import SettingsDispatch from '../views/settings/SettingsDispatch'
import SettingsVinDecoder from '../views/settings/SettingsVinDecoder'
import PropertyManagement from '../views/settings/PropertyManagement'
import UsersList from '../views/users/UsersList'
import UserForm from '../views/users/UserForm'
import UserGroups from '../views/users/UserGroups'
import RoleManagement from '../views/users/RoleManagement'
import Security from '../views/auth/Security'
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
import CustomerRequestWizard from '../views/customer-portal/RequestWizard'
import EssDashboard from '../views/ess/Dashboard'
import EssTimeClock from '../views/ess/TimeClock'
import EssSchedule from '../views/ess/Schedule'
import EssPayHistory from '../views/ess/PayHistory'
import EssProfile from '../views/ess/Profile'
import EssLeaveRequests from '../views/ess/LeaveRequests'
import TenantMyUnits from '../views/tenant/MyUnits'
import TenantMyRequests from '../views/tenant/MyRequests'
import TenantNewRequest from '../views/tenant/NewRequest'
import EstimateRequestPage from '../views/public/EstimateRequestPage'
import PublicEstimateView from '../views/public/PublicEstimateView'
import PublicPaymentPortal from '../views/public/PublicPaymentPortal'
import PublicContractSign from '../views/public/PublicContractSign'
import SubPortal from '../views/sub-portal/SubPortal'
import SubPortalTokens from '../views/sub-portal/SubPortalTokens'
import VendorPortal from '../views/vendor-portal/VendorPortal'
import VendorPortalTokens from '../views/vendor-portal/VendorPortalTokens'
import TrackingView from '../views/tracking/TrackingView'
import CMSPage from '../views/public/CMSPage'
import AdminLayout from '../components/layout/AdminLayout'
import CustomerLayout from '../components/layout/CustomerLayout'
import EssLayout from '../components/layout/EssLayout'
import TenantLayout from '../components/layout/TenantLayout'
import NotFound from '../views/NotFound'

// Tickets
import TicketList from '../views/tickets/TicketList'
import TicketCreate from '../views/tickets/TicketCreate'
import TicketDetail from '../views/tickets/TicketDetail'
import TicketTriage from '../views/tickets/TicketTriage'
import TicketQueues from '../views/tickets/TicketQueues'
import TicketSlaPolicies from '../views/tickets/TicketSlaPolicies'
import TicketRoutingRules from '../views/tickets/TicketRoutingRules'
import TicketEscalationRules from '../views/tickets/TicketEscalationRules'
import TicketCategories from '../views/tickets/TicketCategories'
import TicketCloseReasons from '../views/tickets/TicketCloseReasons'
import TicketResolutionCodes from '../views/tickets/TicketResolutionCodes'
import TicketFailureCodes from '../views/tickets/TicketFailureCodes'

// CRM
import CompanyList from '../views/crm/CompanyList'
import CompanyForm from '../views/crm/CompanyForm'
import CompanyDetail from '../views/crm/CompanyDetail'
import SiteList from '../views/crm/SiteList'
import SiteDetail from '../views/crm/SiteDetail'

// Contracts
import ContractList from '../views/contracts/ContractList'
import ContractForm from '../views/contracts/ContractForm'
import ContractDetail from '../views/contracts/ContractDetail'

// ETA
import EtaPromises from '../views/eta/EtaPromises'

// PM
import PmPlans from '../views/pm/PmPlans'
import PmSchedules from '../views/pm/PmSchedules'
import PmCompliance from '../views/pm/PmCompliance'

// Installed Assets
import AssetList from '../views/assets/AssetList'
import AssetTypes from '../views/assets/AssetTypes'
import AssetDetail from '../views/assets/AssetDetail'

// Fleet
import FleetUnits from '../views/fleet/FleetUnits'
import FleetUnitDetail from '../views/fleet/FleetUnitDetail'
import FleetExternalRepairs from '../views/fleet/ExternalRepairs'
import FleetReports from '../views/fleet/FleetReports'

// Routing
import RoutePlans from '../views/routing/RoutePlans'
import RoutePlanDetail from '../views/routing/RoutePlanDetail'
import GeoFences from '../views/routing/GeoFences'

// Capital Plan
import CapitalPlanAging from '../views/capital-plan/AssetAging'
import CapitalPlanScoringModels from '../views/capital-plan/ScoringModels'
import CapitalPlans from '../views/capital-plan/CapitalPlans'
import CapitalPlanDetail from '../views/capital-plan/CapitalPlanDetail'

// Org / branches / subs
import Divisions from '../views/divisions/Divisions'
import BranchDashboards from '../views/branch-dashboards/BranchDashboards'
import BranchDashboard from '../views/branch-dashboards/BranchDashboard'
import Subcontractors from '../views/subcontractors/Subcontractors'
import SubcontractorDetail from '../views/subcontractors/SubcontractorDetail'

// Voice notes & Custom fields
import VoiceNotes from '../views/voice-notes/VoiceNotes'
import VoiceNotesPending from '../views/voice-notes/VoiceNotesPending'
import CustomFields from '../views/custom-fields/CustomFields'

// Platform admin
import IntegrationsList from '../views/integrations/IntegrationsList'
import IntegrationDetail from '../views/integrations/IntegrationDetail'
import SsoProviders from '../views/sso/SsoProviders'
import SecurityEvents from '../views/security/SecurityEvents'
import RetentionPolicies from '../views/retention/RetentionPolicies'
import RetentionRuns from '../views/retention/RetentionRuns'

// Phase 2a/2b — new portal tree (parallel to legacy customer-portal/*)
import PortalLayout from '../views/portal/PortalLayout'
import PortalLogin from '../views/portal/PortalLogin'
import PortalSsoCallback from '../views/portal/PortalSsoCallback'
import PortalDashboard from '../views/portal/PortalDashboard'
import PortalApprovals from '../views/portal/PortalApprovals'
import PortalRequest from '../views/portal/PortalRequest'
import PortalInvoices from '../views/portal/PortalInvoices'
import PortalInvoiceDetail from '../views/portal/PortalInvoiceDetail'
import PortalPaymentMethods from '../views/portal/PortalPaymentMethods'
import PortalSites from '../views/portal/PortalSites'
import PortalContracts from '../views/portal/PortalContracts'
import PortalContractDetail from '../views/portal/PortalContractDetail'
import PortalWorkorders from '../views/portal/PortalWorkorders'
import PortalWorkorderDetail from '../views/portal/PortalWorkorderDetail'
import PortalMessages from '../views/portal/PortalMessages'
import PortalCsat from '../views/portal/PortalCsat'
import PortalNotificationPreferences from '../views/portal/PortalNotificationPreferences'
import PortalAuditTrail from '../views/portal/PortalAuditTrail'
import PortalApiTokens from '../views/portal/PortalApiTokens'
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
  { path: '/c/:shortCode', name: 'PublicContractSignByCode', auth: 'public', element: <PublicContractSign /> },
  { path: '/contract/view', name: 'PublicContractSignByToken', auth: 'public', element: <PublicContractSign /> },
  { path: '/customers/:id', name: 'CustomerPublicDetail', auth: 'public', element: <CustomerPublicDetail /> },
  { path: '/vehicles/:id', name: 'VehiclePublicDetail', auth: 'public', element: <VehiclePublicDetail /> },
  { path: '/vehicles/:id/edit', name: 'VehiclePublicEdit', auth: 'public', element: <VehiclePublicEdit /> },
  { path: '/sub-portal/:token', name: 'SubPortalToken', auth: 'public', element: <SubPortal /> },
  { path: '/sub-portal', name: 'SubPortal', auth: 'public', element: <SubPortal /> },
  { path: '/vendor-portal/:token', name: 'VendorPortalToken', auth: 'public', element: <VendorPortal /> },
  { path: '/vendor-portal', name: 'VendorPortal', auth: 'public', element: <VendorPortal /> },
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
  // UIG-3 / UIG-4 / UIG-8 — the financial/vendors stubs were never wired
  // up to a real backend; the canonical vendor master lives at
  // /cp/procurement/vendors. Keep this path as a redirect so any
  // bookmark / external link continues to land somewhere useful.
  { path: '/cp/financial/vendors', name: 'FinancialVendors', auth: 'requiresAuth', element: <Navigate to="/cp/procurement/vendors" replace /> },
  { path: '/cp/reports', name: 'FinancialReports', auth: 'requiresAuth', element: <FinancialReports /> },
  { path: '/cp/reports/customer-retention', name: 'CustomerRetentionReport', auth: 'requiresAuth', element: <CustomerRetentionReport /> },
  { path: '/cp/reports/overview', name: 'Reports', auth: 'requiresAuth', element: <Reports /> },
  { path: '/cp/audit', name: 'AuditLogs', auth: 'requiresAuth', element: <AuditLogs /> },
  { path: '/cp/security', name: 'Security', auth: 'requiresAuth', element: <Security /> },
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
  { path: '/cp/crm/companies', name: 'CrmCompanies', auth: 'requiresAuth', element: <CompanyList /> },
  { path: '/cp/crm/companies/create', name: 'CrmCompanyCreate', auth: 'requiresAuth', element: <CompanyForm /> },
  { path: '/cp/crm/companies/:id', name: 'CrmCompanyDetail', auth: 'requiresAuth', element: <CompanyDetail /> },
  { path: '/cp/crm/companies/:id/edit', name: 'CrmCompanyEdit', auth: 'requiresAuth', element: <CompanyForm /> },
  { path: '/cp/crm/sites', name: 'CrmSites', auth: 'requiresAuth', element: <SiteList /> },
  { path: '/cp/crm/sites/:id', name: 'CrmSiteDetail', auth: 'requiresAuth', element: <SiteDetail /> },

  // Contracts
  { path: '/cp/contracts', name: 'ContractsList', auth: 'requiresAuth', element: <ContractList /> },
  { path: '/cp/contracts/create', name: 'ContractCreate', auth: 'requiresAuth', element: <ContractForm /> },
  { path: '/cp/contracts/:id', name: 'ContractDetail', auth: 'requiresAuth', element: <ContractDetail /> },
  { path: '/cp/contracts/:id/edit', name: 'ContractEdit', auth: 'requiresAuth', element: <ContractForm /> },

  // Tickets
  { path: '/cp/tickets', name: 'TicketsList', auth: 'requiresAuth', element: <TicketList /> },
  { path: '/cp/tickets/create', name: 'TicketCreate', auth: 'requiresAuth', element: <TicketCreate /> },
  { path: '/cp/tickets/triage', name: 'TicketTriage', auth: 'requiresAuth', element: <TicketTriage /> },
  { path: '/cp/tickets/queues', name: 'TicketQueues', auth: 'requiresAuth', element: <TicketQueues /> },
  { path: '/cp/tickets/sla-policies', name: 'TicketSlaPolicies', auth: 'requiresAuth', element: <TicketSlaPolicies /> },
  { path: '/cp/tickets/routing-rules', name: 'TicketRoutingRules', auth: 'requiresAuth', element: <TicketRoutingRules /> },
  { path: '/cp/tickets/escalation-rules', name: 'TicketEscalationRules', auth: 'requiresAuth', element: <TicketEscalationRules /> },
  { path: '/cp/tickets/categories', name: 'TicketCategories', auth: 'requiresAuth', element: <TicketCategories /> },
  { path: '/cp/tickets/close-reasons', name: 'TicketCloseReasons', auth: 'requiresAuth', element: <TicketCloseReasons /> },
  { path: '/cp/tickets/resolution-codes', name: 'TicketResolutionCodes', auth: 'requiresAuth', element: <TicketResolutionCodes /> },
  { path: '/cp/tickets/failure-codes', name: 'TicketFailureCodes', auth: 'requiresAuth', element: <TicketFailureCodes /> },
  { path: '/cp/tickets/:id', name: 'TicketDetail', auth: 'requiresAuth', element: <TicketDetail /> },

  // PM (preventive maintenance)
  { path: '/cp/pm/plans', name: 'PmPlans', auth: 'requiresAuth', element: <PmPlans /> },
  { path: '/cp/pm/schedules', name: 'PmSchedules', auth: 'requiresAuth', element: <PmSchedules /> },
  { path: '/cp/pm/compliance', name: 'PmCompliance', auth: 'requiresAuth', element: <PmCompliance /> },

  // Assets
  { path: '/cp/assets', name: 'AssetsList', auth: 'requiresAuth', element: <AssetList /> },
  { path: '/cp/assets/types', name: 'AssetTypes', auth: 'requiresAuth', element: <AssetTypes /> },
  { path: '/cp/assets/leases', name: 'AssetLeases', auth: 'requiresAuth', element: <AssetLeases /> },
  { path: '/cp/assets/acquisitions', name: 'AssetAcquisitions', auth: 'requiresAuth', element: <AssetAcquisitions /> },
  { path: '/cp/assets/decommissions', name: 'AssetDecommissions', auth: 'requiresAuth', element: <AssetDecommissions /> },
  { path: '/cp/assets/import', name: 'AssetImport', auth: 'requiresAuth', element: <AssetImport /> },
  { path: '/cp/procurement/vendors', name: 'Vendors', auth: 'requiresAuth', element: <Vendors /> },
  { path: '/cp/procurement/purchase-orders', name: 'PurchaseOrders', auth: 'requiresAuth', element: <PurchaseOrders /> },
  { path: '/cp/procurement/purchase-orders/:id', name: 'PurchaseOrderDetail', auth: 'requiresAuth', element: <PurchaseOrderDetail /> },
  { path: '/cp/assets/:id', name: 'AssetDetail', auth: 'requiresAuth', element: <AssetDetail /> },

  // Software CMDB (Phase 14 / M9)
  { path: '/cp/it/software', name: 'SoftwareInventory', auth: 'requiresAuth', element: <SoftwareInventory /> },

  // Change management — RFC + CAB (Phase 14 / S3)
  { path: '/cp/it/change-management', name: 'ChangeManagement', auth: 'requiresAuth', element: <ChangeManagement /> },

  // Security credential register (Phase 16 / S1)
  { path: '/cp/security/credentials', name: 'SecurityCredentials', auth: 'requiresAuth', element: <CredentialRegister /> },
  { path: '/cp/pos/terminals', name: 'PosTerminals', auth: 'requiresAuth', element: <PosTerminals /> },
  // Technician skill matrix (Phase 17 / S11)
  { path: '/cp/skills/matrix', name: 'SkillMatrix', auth: 'requiresAuth', element: <SkillMatrix /> },
  // Multi-trade dispatch board (Phase 17 / M10)
  { path: '/cp/dispatch-board', name: 'DispatchBoard', auth: 'requiresAuth', element: <DispatchBoard /> },
  // Consolidated monthly statements (Phase 17 / M11)
  { path: '/cp/billing/consolidated', name: 'ConsolidatedStatements', auth: 'requiresAuth', element: <ConsolidatedStatements /> },
  // Multi-site chain rollup (Phase 17 / S4)
  { path: '/cp/chain-rollup', name: 'ChainRollup', auth: 'requiresAuth', element: <ChainRollup /> },
  // Trade-specific KPI dashboard (Phase 17 / S10)
  { path: '/cp/trade-kpis', name: 'TradeKpis', auth: 'requiresAuth', element: <TradeKpis /> },

  // Fleet
  { path: '/cp/fleet/units', name: 'FleetUnits', auth: 'requiresAuth', element: <FleetUnits /> },
  { path: '/cp/fleet/external-repairs', name: 'FleetExternalRepairs', auth: 'requiresAuth', element: <FleetExternalRepairs /> },
  { path: '/cp/fleet/reports', name: 'FleetReports', auth: 'requiresAuth', element: <FleetReports /> },
  { path: '/cp/fleet/units/:id', name: 'FleetUnitDetail', auth: 'requiresAuth', element: <FleetUnitDetail /> },

  // Routing
  { path: '/cp/routing/service-routes', name: 'ServiceRoutes', auth: 'requiresAuth', element: <ServiceRoutes /> },
  { path: '/cp/my-routes', name: 'MyRoutes', auth: 'requiresAuth', element: <MyRoutes /> },
  { path: '/cp/routing/route-plans', name: 'RoutePlans', auth: 'requiresAuth', element: <RoutePlans /> },
  { path: '/cp/routing/geo-fences', name: 'GeoFences', auth: 'requiresAuth', element: <GeoFences /> },
  { path: '/cp/routing/route-plans/:id', name: 'RoutePlanDetail', auth: 'requiresAuth', element: <RoutePlanDetail /> },

  // Capital plan
  { path: '/cp/capital-plan/aging', name: 'CapitalPlanAging', auth: 'requiresAuth', element: <CapitalPlanAging /> },
  { path: '/cp/capital-plan/scoring-models', name: 'CapitalPlanScoringModels', auth: 'requiresAuth', element: <CapitalPlanScoringModels /> },
  { path: '/cp/capital-plan/plans', name: 'CapitalPlanPlans', auth: 'requiresAuth', element: <CapitalPlans /> },
  { path: '/cp/capital-plan/plans/:id', name: 'CapitalPlanDetail', auth: 'requiresAuth', element: <CapitalPlanDetail /> },

  // Org structure
  { path: '/cp/divisions', name: 'Divisions', auth: 'requiresAuth', element: <Divisions /> },
  { path: '/cp/branches/dashboards', name: 'BranchDashboards', auth: 'requiresAuth', element: <BranchDashboards /> },
  { path: '/cp/branches/:id/dashboard', name: 'BranchDashboard', auth: 'requiresAuth', element: <BranchDashboard /> },

  // Subcontractors
  { path: '/cp/subcontractors', name: 'Subcontractors', auth: 'requiresAuth', element: <Subcontractors /> },
  { path: '/cp/subcontractors/:id', name: 'SubcontractorDetail', auth: 'requiresAuth', element: <SubcontractorDetail /> },
  { path: '/cp/sub-portal-tokens', name: 'SubPortalTokens', auth: 'requiresAuth', element: <SubPortalTokens /> },
  { path: '/cp/vendor-portal-tokens', name: 'VendorPortalTokens', auth: 'requiresAuth', element: <VendorPortalTokens /> },

  // Voice notes
  { path: '/cp/voice-notes', name: 'VoiceNotes', auth: 'requiresAuth', element: <VoiceNotes /> },
  { path: '/cp/voice-notes/pending', name: 'VoiceNotesPending', auth: 'requiresAuth', element: <VoiceNotesPending /> },

  // Custom fields
  { path: '/cp/custom-fields', name: 'CustomFields', auth: 'requiresAuth', element: <CustomFields /> },

  // Integrations (third-party connections — distinct from settings/integrations stub)
  { path: '/cp/integrations', name: 'IntegrationsList', auth: 'requiresAuth', element: <IntegrationsList /> },
  { path: '/cp/integrations/:id', name: 'IntegrationDetail', auth: 'requiresAuth', element: <IntegrationDetail /> },

  // SSO providers
  { path: '/cp/sso/providers', name: 'SsoProviders', auth: 'requiresAuth', element: <SsoProviders /> },

  // Security & retention
  { path: '/cp/security-events', name: 'SecurityEvents', auth: 'requiresAuth', element: <SecurityEvents /> },
  { path: '/cp/retention/policies', name: 'RetentionPolicies', auth: 'requiresAuth', element: <RetentionPolicies /> },
  { path: '/cp/retention/runs', name: 'RetentionRuns', auth: 'requiresAuth', element: <RetentionRuns /> },

  // ETA promises
  { path: '/cp/eta/promises', name: 'EtaPromises', auth: 'requiresAuth', element: <EtaPromises /> },

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
  { path: '/portal/request', name: 'CustomerRequestWizard', auth: 'requiresAuth', element: <CustomerRequestWizard /> },
  { path: '/ess', name: 'EssDashboard', auth: 'requiresAuth', element: <EssDashboard /> },
  { path: '/ess/time-clock', name: 'EssTimeClock', auth: 'requiresAuth', element: <EssTimeClock /> },
  { path: '/ess/schedule', name: 'EssSchedule', auth: 'requiresAuth', element: <EssSchedule /> },
  { path: '/ess/pay-history', name: 'EssPayHistory', auth: 'requiresAuth', element: <EssPayHistory /> },
  { path: '/ess/leave-requests', name: 'EssLeaveRequests', auth: 'requiresAuth', element: <EssLeaveRequests /> },
  { path: '/ess/profile', name: 'EssProfile', auth: 'requiresAuth', element: <EssProfile /> },
  { path: '/tenant', name: 'TenantMyUnits', auth: 'requiresAuth', element: <TenantMyUnits /> },
  { path: '/tenant/requests', name: 'TenantMyRequests', auth: 'requiresAuth', element: <TenantMyRequests /> },
  { path: '/tenant/requests/new', name: 'TenantNewRequest', auth: 'requiresAuth', element: <TenantNewRequest /> },
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
  { path: '/cp/settings/service-lines', name: 'SettingsServiceLines', element: <SettingsServiceLines /> },
  { path: '/cp/settings/modules', name: 'ModuleSettings', element: <ModuleSettings /> },
  { path: '/cp/settings/dispatch', name: 'SettingsDispatch', element: <SettingsDispatch /> },
  { path: '/cp/settings/vin-decoder', name: 'SettingsVinDecoder', element: <SettingsVinDecoder /> },
  { path: '/cp/settings/property-management', name: 'PropertyManagement', element: <PropertyManagement /> },
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
    { path: 'service-lines', element: <SettingsServiceLines /> },
    { path: 'modules', element: <ModuleSettings /> },
    { path: 'dispatch', element: <SettingsDispatch /> },
    { path: 'vin-decoder', element: <SettingsVinDecoder /> },
    { path: 'property-management', element: <PropertyManagement /> },
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

tenantChildren.push({
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
      { path: 'login', loader: requirePortalGuest, element: <PortalLogin /> },
      // Phase 2e — IdP redirect-back lands here. No guard so an
      // unauthenticated user can complete the OIDC dance.
      { path: 'auth/sso/callback', element: <PortalSsoCallback /> },
      {
        element: <PortalLayout />,
        loader: requirePortalAuth,
        children: [
          { index: true, element: <PortalDashboard /> },
          { path: 'approvals', element: <PortalApprovals /> },
          { path: 'requests', element: <PortalRequest /> },
          { path: 'invoices', element: <PortalInvoices /> },
          { path: 'invoices/:id', element: <PortalInvoiceDetail /> },
          { path: 'payment-methods', element: <PortalPaymentMethods /> },
          { path: 'sites', element: <PortalSites /> },
          { path: 'work-orders', element: <PortalWorkorders /> },
          { path: 'work-orders/:id', element: <PortalWorkorderDetail /> },
          { path: 'contracts', element: <PortalContracts /> },
          { path: 'contracts/:id', element: <PortalContractDetail /> },
          { path: 'messages', element: <PortalMessages /> },
          { path: 'satisfaction', element: <PortalCsat /> },
          { path: 'notifications', element: <PortalNotificationPreferences /> },
          { path: 'activity', element: <PortalAuditTrail /> },
          { path: 'api-tokens', element: <PortalApiTokens /> },
          { path: '*', element: <NotFound /> },
        ],
      },
    ],
  },
], { basename: reactBasename })
