import { createBrowserRouter, redirect, Outlet } from 'react-router-dom'

import Login from '../views/auth/Login'
import CustomerLogin from '../views/auth/CustomerLogin'
import ForgotPassword from '../views/auth/ForgotPassword'
import ResetPassword from '../views/auth/ResetPassword'
import Register from '../views/auth/Register'
import AdminDashboard from '../views/dashboard/AdminDashboard'
import StaffProfile from '../views/users/Profile'
import InvoiceList from '../views/invoices/InvoiceList'
import InvoiceDetail from '../views/invoices/InvoiceDetail'
import InvoiceCreate from '../views/invoices/InvoiceCreate'
import EstimateList from '../views/estimates/EstimateList'
import EstimateCreate from '../views/estimates/EstimateCreate'
import EstimateDetail from '../views/estimates/EstimateDetail'
import EstimateEdit from '../views/estimates/EstimateEdit'
import WorkorderList from '../views/workorders/WorkorderList'
import WorkorderDetail from '../views/workorders/WorkorderDetail'
import BundleList from '../views/bundles/BundleList'
import BundleForm from '../views/bundles/BundleForm'
import AppointmentList from '../views/appointments/AppointmentList'
import AppointmentCalendar from '../views/appointments/AppointmentCalendar'
import AppointmentBook from '../views/appointments/AppointmentBook'
import AvailabilitySettings from '../views/appointments/AvailabilitySettings'
import TimeLogs from '../views/time/TimeLogs'
import TechnicianPortal from '../views/time/TechnicianPortal'
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
import InventoryPullRequests from '../views/inventory/PullRequestList'
import FinancialEntries from '../views/financial/FinancialEntries'
import FinancialVendors from '../views/financial/VendorList'
import FinancialVendorForm from '../views/financial/VendorForm'
import FinancialReports from '../views/financial/Reports'
import SettingsLayout from '../views/settings/SettingsLayout'
import SettingsPage from '../views/settings/SettingsPage'
import SettingsShopProfile from '../views/settings/SettingsShopProfile'
import SettingsTerms from '../views/settings/SettingsTerms'
import SettingsTemplates from '../views/settings/SettingsTemplates'
import SettingsRejectionReasons from '../views/settings/SettingsRejectionReasons'
import SettingsPricing from '../views/settings/SettingsPricing'
import SettingsNotifications from '../views/settings/SettingsNotifications'
import SettingsPayments from '../views/settings/SettingsPayments'
import SettingsIntegrations from '../views/settings/SettingsIntegrations'
import ServiceTypes from '../views/settings/ServiceTypes'
import UsersList from '../views/users/UsersList'
import UserForm from '../views/users/UserForm'
import InspectionTemplates from '../views/inspections/TemplateManager'
import TechnicianInspections from '../views/inspections/TechnicianInspections'
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
import EstimateRequestPage from '../views/public/EstimateRequestPage'
import PublicEstimateView from '../views/public/PublicEstimateView'
import CMSPage from '../views/public/CMSPage'
import AdminLayout from '../components/layout/AdminLayout'
import CustomerLayout from '../components/layout/CustomerLayout'
import NotFound from '../views/NotFound'

const reactBasename = import.meta.env.VITE_REACT_BASE || ''

const routePaths = {
  login: '/login',
  dashboard: '/cp/dashboard',
}

const authTokenKey = 'auth_token'

const requireAuth = () => {
  const token = localStorage.getItem(authTokenKey)

  if (!token) {
    return redirect(routePaths.login)
  }

  return null
}

const requireGuest = () => {
  const token = localStorage.getItem(authTokenKey)

  if (token) {
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
  { path: '/cp/register', name: 'Register', auth: 'guest', element: <Register /> },
]

const publicRoutes = [
  { path: '/request-estimate', name: 'EstimateRequestForm', auth: 'public', element: <EstimateRequestPage /> },
  { path: '/estimate/view', name: 'PublicEstimateView', auth: 'public', element: <PublicEstimateView /> },
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
  { path: '/cp/invoices/create', name: 'InvoiceCreate', auth: 'requiresAuth', element: <InvoiceCreate /> },
  { path: '/cp/invoices/:id', name: 'InvoiceDetail', auth: 'requiresAuth', element: <InvoiceDetail /> },
  { path: '/cp/estimates', name: 'EstimateList', auth: 'requiresAuth', element: <EstimateList /> },
  { path: '/cp/estimates/create', name: 'EstimateCreate', auth: 'requiresAuth', element: <EstimateCreate /> },
  { path: '/cp/estimates/:id', name: 'EstimateDetail', auth: 'requiresAuth', element: <EstimateDetail /> },
  { path: '/cp/estimates/:id/edit', name: 'EstimateEdit', auth: 'requiresAuth', element: <EstimateEdit /> },
  { path: '/cp/workorders', name: 'WorkorderList', auth: 'requiresAuth', element: <WorkorderList /> },
  { path: '/cp/workorders/:id', name: 'WorkorderDetail', auth: 'requiresAuth', element: <WorkorderDetail /> },
  { path: '/cp/bundles', name: 'BundleList', auth: 'requiresAuth', element: <BundleList /> },
  { path: '/cp/bundles/create', name: 'BundleCreate', auth: 'requiresAuth', element: <BundleForm /> },
  { path: '/cp/bundles/:id/edit', name: 'BundleEdit', auth: 'requiresAuth', element: <BundleForm /> },
  { path: '/cp/appointments', name: 'AppointmentList', auth: 'requiresAuth', element: <AppointmentList /> },
  { path: '/cp/appointments/calendar', name: 'AppointmentCalendar', auth: 'requiresAuth', element: <AppointmentCalendar /> },
  { path: '/cp/appointments/create', name: 'AppointmentBook', auth: 'requiresAuth', element: <AppointmentBook /> },
  { path: '/cp/appointments/availability-settings', name: 'AvailabilitySettings', auth: 'requiresAuth', element: <AvailabilitySettings /> },
  { path: '/cp/time-logs', name: 'TimeLogs', auth: 'requiresAuth', element: <TimeLogs /> },
  { path: '/cp/my-time', name: 'TechnicianTime', auth: 'requiresAuth', element: <TechnicianPortal /> },
  { path: '/cp/customers', name: 'CustomerList', auth: 'requiresAuth', element: <CustomerList /> },
  { path: '/cp/customers/create', name: 'CustomerCreate', auth: 'requiresAuth', element: <CustomerForm /> },
  { path: '/cp/customers/:id', name: 'CustomerDetail', auth: 'requiresAuth', element: <CustomerDetail /> },
  { path: '/cp/vehicle-master', name: 'VehicleMasterList', auth: 'requiresAuth', element: <VehicleMasterList /> },
  { path: '/cp/vehicle-master/create', name: 'VehicleMasterCreate', auth: 'requiresAuth', element: <VehicleMasterForm /> },
  { path: '/cp/vehicle-master/:id/edit', name: 'VehicleMasterEdit', auth: 'requiresAuth', element: <VehicleMasterForm /> },
  { path: '/cp/vehicles', name: 'VehicleList', auth: 'requiresAuth', element: <VehicleList /> },
  { path: '/cp/vehicles/create', name: 'VehicleCreate', auth: 'requiresAuth', element: <VehicleForm /> },
  { path: '/cp/vehicles/:id/edit', name: 'VehicleEdit', auth: 'requiresAuth', element: <VehicleForm /> },
  { path: '/cp/vehicles/:id', name: 'VehicleDetail', auth: 'requiresAuth', element: <VehicleDetail /> },
  { path: '/cp/inventory', name: 'InventoryList', auth: 'requiresAuth', element: <InventoryList /> },
  { path: '/cp/inventory/categories', name: 'InventoryCategories', auth: 'requiresAuth', element: <InventoryLookupManager /> },
  { path: '/cp/inventory/vendors', name: 'InventoryVendors', auth: 'requiresAuth', element: <InventoryLookupManager /> },
  { path: '/cp/inventory/locations', name: 'InventoryLocations', auth: 'requiresAuth', element: <InventoryLookupManager /> },
  { path: '/cp/inventory/create', name: 'InventoryCreate', auth: 'requiresAuth', element: <InventoryForm /> },
  { path: '/cp/inventory/:id/edit', name: 'InventoryEdit', auth: 'requiresAuth', element: <InventoryForm /> },
  { path: '/cp/inventory/alerts', name: 'InventoryAlerts', auth: 'requiresAuth', element: <InventoryAlerts /> },
  { path: '/cp/inventory/pull-requests', name: 'InventoryPullRequests', auth: 'requiresAuth', element: <InventoryPullRequests /> },
  { path: '/cp/financial/entries', name: 'FinancialEntries', auth: 'requiresAuth', element: <FinancialEntries /> },
  { path: '/cp/financial/vendors', name: 'FinancialVendors', auth: 'requiresAuth', element: <FinancialVendors /> },
  { path: '/cp/financial/vendors/create', name: 'FinancialVendorCreate', auth: 'requiresAuth', element: <FinancialVendorForm /> },
  { path: '/cp/financial/vendors/:id/edit', name: 'FinancialVendorEdit', auth: 'requiresAuth', element: <FinancialVendorForm /> },
  { path: '/cp/reports', name: 'FinancialReports', auth: 'requiresAuth', element: <FinancialReports /> },
  { path: '/cp/users', name: 'UsersList', auth: 'requiresAuth', element: <UsersList /> },
  { path: '/cp/users/create', name: 'UserCreate', auth: 'requiresAuth', element: <UserForm /> },
  { path: '/cp/users/:id', name: 'UserEdit', auth: 'requiresAuth', element: <UserForm /> },
  { path: '/cp/inspections/templates', name: 'InspectionTemplates', auth: 'requiresAuth', element: <InspectionTemplates /> },
  { path: '/cp/inspections/work', name: 'TechnicianInspections', auth: 'requiresAuth', element: <TechnicianInspections /> },
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
  { path: '/cp/cms/components', name: 'CMSComponentList', auth: 'requiresAuth', element: <CMSComponentList /> },
  { path: '/cp/cms/components/create', name: 'CMSComponentCreate', auth: 'requiresAuth', element: <CMSComponentForm /> },
  { path: '/cp/cms/components/:id', name: 'CMSComponentEdit', auth: 'requiresAuth', element: <CMSComponentForm /> },
  { path: '/cp/cms/templates', name: 'CMSTemplateList', auth: 'requiresAuth', element: <CMSTemplateList /> },
  { path: '/cp/cms/templates/create', name: 'CMSTemplateCreate', auth: 'requiresAuth', element: <CMSTemplateForm /> },
  { path: '/cp/cms/templates/:id', name: 'CMSTemplateEdit', auth: 'requiresAuth', element: <CMSTemplateForm /> },
  { path: '/cp/cms/404-manager', name: 'NotFoundManager', auth: 'requiresAuth', element: <NotFoundManager /> },
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
]

const settingsRoutes = [
  { path: '/cp/settings', name: 'Settings', element: <SettingsPage /> },
  { path: '/cp/settings/terms', name: 'SettingsTerms', element: <SettingsTerms /> },
  { path: '/cp/settings/templates', name: 'SettingsTemplates', element: <SettingsTemplates /> },
  { path: '/cp/settings/rejection-reasons', name: 'SettingsRejectionReasons', element: <SettingsRejectionReasons /> },
  { path: '/cp/settings/pricing', name: 'SettingsPricing', element: <SettingsPricing /> },
  { path: '/cp/settings/notifications', name: 'SettingsNotifications', element: <SettingsNotifications /> },
  { path: '/cp/settings/payments', name: 'SettingsPayments', element: <SettingsPayments /> },
  { path: '/cp/settings/integrations', name: 'SettingsIntegrations', element: <SettingsIntegrations /> },
  { path: '/cp/settings/services', name: 'ServiceTypes', element: <ServiceTypes /> },
]

const withAuthLoader = (route) => ({
  path: route.path,
  loader: route.auth === 'guest' ? requireGuest : route.auth === 'requiresAuth' ? requireAuth : undefined,
  element: route.element,
})

const publicChildren = [...guestRoutes, ...publicRoutes].map(withAuthLoader)

const adminRoutes = protectedRoutes.filter((route) => route.path.startsWith('/cp'))
const customerRoutes = protectedRoutes.filter((route) => route.path.startsWith('/portal'))

const toChildRoute = (route, basePath) => {
  const suffix = route.path.replace(basePath, '')
  if (!suffix || suffix === '/') {
    return { index: true, element: route.element }
  }

  return { path: suffix.replace(/^\//, ''), element: route.element }
}

const adminChildren = adminRoutes.map((route) => toChildRoute(route, '/cp'))
const customerChildren = customerRoutes.map((route) => toChildRoute(route, '/portal'))

adminChildren.push({
  path: 'settings',
  element: <SettingsLayout />,
  children: [
    { index: true, element: <SettingsPage /> },
    { path: 'terms', element: <SettingsTerms /> },
    { path: 'templates', element: <SettingsTemplates /> },
    { path: 'rejection-reasons', element: <SettingsRejectionReasons /> },
    { path: 'pricing', element: <SettingsPricing /> },
    { path: 'notifications', element: <SettingsNotifications /> },
    { path: 'payments', element: <SettingsPayments /> },
    { path: 'integrations', element: <SettingsIntegrations /> },
    { path: 'services', element: <ServiceTypes /> },
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
], { basename: reactBasename })
