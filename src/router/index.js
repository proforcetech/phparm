import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/auth/Login.vue'),
    meta: { guest: true },
  },
  {
    path: '/customer-login',
    name: 'CustomerLogin',
    component: () => import('@/views/auth/CustomerLogin.vue'),
    meta: { guest: true },
  },
  {
    path: '/forgot-password',
    name: 'ForgotPassword',
    component: () => import('@/views/auth/ForgotPassword.vue'),
    meta: { guest: true },
  },
  {
    path: '/reset-password/:token',
    name: 'ResetPassword',
    component: () => import('@/views/auth/ResetPassword.vue'),
    meta: { guest: true },
  },
  {
    path: '/cp/register',
    name: 'Register',
    component: () => import('@/views/auth/Register.vue'),
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/cp/dashboard',
    name: 'Dashboard',
    component: () => import('@/views/dashboard/AdminDashboard.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/invoices',
    name: 'InvoiceList',
    component: () => import('@/views/invoices/InvoiceList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/invoices/:id',
    name: 'InvoiceDetail',
    component: () => import('@/views/invoices/InvoiceDetail.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/cp/invoices/create',
    name: 'InvoiceCreate',
    component: () => import('@/views/invoices/InvoiceCreate.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/estimates',
    name: 'EstimateList',
    component: () => import('@/views/estimates/EstimateList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/estimates/create',
    name: 'EstimateCreate',
    component: () => import('@/views/estimates/EstimateCreate.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/estimates/:id',
    name: 'EstimateDetail',
    component: () => import('@/views/estimates/EstimateDetail.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/cp/estimates/:id/edit',
    name: 'EstimateEdit',
    component: () => import('@/views/estimates/EstimateCreate.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/bundles',
    name: 'BundleList',
    component: () => import('@/views/bundles/BundleList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/bundles/create',
    name: 'BundleCreate',
    component: () => import('@/views/bundles/BundleForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/bundles/:id/edit',
    name: 'BundleEdit',
    component: () => import('@/views/bundles/BundleForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/appointments',
    name: 'AppointmentList',
    component: () => import('@/views/appointments/AppointmentList.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/cp/appointments/calendar',
    name: 'AppointmentCalendar',
    component: () => import('@/views/appointments/AppointmentCalendar.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/cp/time-logs',
    name: 'TimeLogs',
    component: () => import('@/views/time/TimeLogs.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/my-time',
    name: 'TechnicianTime',
    component: () => import('@/views/time/TechnicianPortal.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/cp/appointments/create',
    name: 'AppointmentBook',
    component: () => import('@/views/appointments/AppointmentBook.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/cp/appointments/availability-settings',
    name: 'AvailabilitySettings',
    component: () => import('@/views/appointments/AvailabilitySettings.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/cp/customers',
    name: 'CustomerList',
    component: () => import('@/views/customers/CustomerList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/customers/:id',
    name: 'CustomerDetail',
    component: () => import('@/views/customers/CustomerDetail.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/vehicle-master',
    name: 'VehicleMasterList',
    component: () => import('@/views/vehicle-master/VehicleMasterList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/vehicle-master/create',
    name: 'VehicleMasterCreate',
    component: () => import('@/views/vehicle-master/VehicleMasterForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/vehicle-master/:id/edit',
    name: 'VehicleMasterEdit',
    component: () => import('@/views/vehicle-master/VehicleMasterForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/vehicles',
    name: 'VehicleList',
    component: () => import('@/views/vehicles/VehicleList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/vehicles/create',
    name: 'VehicleCreate',
    component: () => import('@/views/vehicles/VehicleForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/vehicles/:id/edit',
    name: 'VehicleEdit',
    component: () => import('@/views/vehicles/VehicleForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/vehicles/:id',
    name: 'VehicleDetail',
    component: () => import('@/views/vehicles/VehicleDetail.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/cp/inventory',
    name: 'InventoryList',
    component: () => import('@/views/inventory/InventoryList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/inventory/create',
    name: 'InventoryCreate',
    component: () => import('@/views/inventory/InventoryForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/inventory/:id/edit',
    name: 'InventoryEdit',
    component: () => import('@/views/inventory/InventoryForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/inventory/alerts',
    name: 'InventoryAlerts',
    component: () => import('@/views/inventory/InventoryAlerts.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/financial/entries',
    name: 'FinancialEntries',
    component: () => import('@/views/financial/FinancialEntries.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/reports',
    name: 'FinancialReports',
    component: () => import('@/views/financial/Reports.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/settings',
    name: 'Settings',
    component: () => import('@/views/settings/SettingsPage.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  // CMS Routes
  {
    path: '/cp/cms',
    name: 'CMSDashboard',
    component: () => import('@/views/cms/CMSDashboard.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/pages',
    name: 'CMSPageList',
    component: () => import('@/views/cms/CMSPageList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/pages/create',
    name: 'CMSPageCreate',
    component: () => import('@/views/cms/CMSPageForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/pages/:id',
    name: 'CMSPageEdit',
    component: () => import('@/views/cms/CMSPageForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/menus',
    name: 'CMSMenuList',
    component: () => import('@/views/cms/CMSMenuList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/menus/create',
    name: 'CMSMenuCreate',
    component: () => import('@/views/cms/CMSMenuForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/menus/:id',
    name: 'CMSMenuEdit',
    component: () => import('@/views/cms/CMSMenuForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/components',
    name: 'CMSComponentList',
    component: () => import('@/views/cms/CMSComponentList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/components/create',
    name: 'CMSComponentCreate',
    component: () => import('@/views/cms/CMSComponentForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/components/:id',
    name: 'CMSComponentEdit',
    component: () => import('@/views/cms/CMSComponentForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/templates',
    name: 'CMSTemplateList',
    component: () => import('@/views/cms/CMSTemplateList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/templates/create',
    name: 'CMSTemplateCreate',
    component: () => import('@/views/cms/CMSTemplateForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/cp/cms/templates/:id',
    name: 'CMSTemplateEdit',
    component: () => import('@/views/cms/CMSTemplateForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/portal',
    name: 'CustomerPortal',
    component: () => import('@/views/customer-portal/Dashboard.vue'),
    meta: { requiresAuth: true, requiresCustomer: true },
  },
  {
    path: '/portal/invoices',
    name: 'CustomerInvoices',
    component: () => import('@/views/customer-portal/Invoices.vue'),
    meta: { requiresAuth: true, requiresCustomer: true },
  },
  {
    path: '/portal/invoices/:id',
    name: 'CustomerInvoiceDetail',
    component: () => import('@/views/customer-portal/InvoiceDetail.vue'),
    meta: { requiresAuth: true, requiresCustomer: true },
  },
  {
    path: '/portal/credit',
    name: 'CustomerCredit',
    component: () => import('@/views/customer-portal/Credit.vue'),
    meta: { requiresAuth: true, requiresCustomer: true },
  },
  {
    path: '/portal/appointments',
    name: 'CustomerAppointments',
    component: () => import('@/views/customer-portal/Appointments.vue'),
    meta: { requiresAuth: true, requiresCustomer: true },
  },
  {
    path: '/portal/warranty-claims',
    name: 'CustomerWarrantyClaims',
    component: () => import('@/views/customer-portal/WarrantyClaims.vue'),
    meta: { requiresAuth: true, requiresCustomer: true },
  },
  {
    path: '/portal/warranty-claims/:id',
    name: 'CustomerWarrantyClaimDetail',
    component: () => import('@/views/customer-portal/WarrantyClaimDetail.vue'),
    meta: { requiresAuth: true, requiresCustomer: true },
  },
  {
    path: '/portal/vehicles',
    name: 'CustomerVehicles',
    component: () => import('@/views/customer-portal/Vehicles.vue'),
    meta: { requiresAuth: true, requiresCustomer: true },
  },
  {
    path: '/portal/profile',
    name: 'CustomerProfile',
    component: () => import('@/views/customer-portal/Profile.vue'),
    meta: { requiresAuth: true, requiresCustomer: true },
  },
  {
    path: '/',
    redirect: '/login',
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'NotFound',
    component: () => import('@/views/NotFound.vue'),
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Navigation guards
router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()

  authStore.checkAuth()

  if (authStore.isAuthenticated && !authStore.user) {
    try {
      await authStore.fetchCurrentUser()
    } catch (err) {
      console.error('Failed to hydrate user', err)
      return next('/login')
    }
  }

  // Check if route requires authentication
  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return next('/login')
  }

  // Check if route is for guests only (login page)
  if (to.meta.guest && authStore.isAuthenticated) {
    if (authStore.isCustomer) {
      return next('/portal')
    }
    return next('/cp/dashboard')
  }

  // Check if route requires staff access
  if (to.meta.requiresStaff && !authStore.isStaff) {
    return next('/portal')
  }

  // Check if route requires customer access
  if (to.meta.requiresCustomer) {
    if (!authStore.isCustomer) {
      return next('/cp/dashboard')
    }

    if (!authStore.portalReady) {
      try {
        await authStore.bootstrapPortal()
      } catch (err) {
        console.error('Failed to bootstrap portal', err)
        return next('/login')
      }
    }
  }

  // Check if route requires admin access
  if (to.meta.requiresAdmin && !authStore.isAdmin) {
    return next('/cp/dashboard')
  }

  next()
})

export default router
