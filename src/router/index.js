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
    path: '/register',
    name: 'Register',
    component: () => import('@/views/auth/Register.vue'),
    meta: { requiresAuth: true, requiresAdmin: true },
  },
  {
    path: '/dashboard',
    name: 'Dashboard',
    component: () => import('@/views/dashboard/AdminDashboard.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/invoices',
    name: 'InvoiceList',
    component: () => import('@/views/invoices/InvoiceList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/invoices/:id',
    name: 'InvoiceDetail',
    component: () => import('@/views/invoices/InvoiceDetail.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/invoices/create',
    name: 'InvoiceCreate',
    component: () => import('@/views/invoices/InvoiceCreate.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/estimates',
    name: 'EstimateList',
    component: () => import('@/views/estimates/EstimateList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/estimates/create',
    name: 'EstimateCreate',
    component: () => import('@/views/estimates/EstimateCreate.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/estimates/:id',
    name: 'EstimateDetail',
    component: () => import('@/views/estimates/EstimateDetail.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/estimates/:id/edit',
    name: 'EstimateEdit',
    component: () => import('@/views/estimates/EstimateCreate.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/bundles',
    name: 'BundleList',
    component: () => import('@/views/bundles/BundleList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/bundles/create',
    name: 'BundleCreate',
    component: () => import('@/views/bundles/BundleForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/bundles/:id/edit',
    name: 'BundleEdit',
    component: () => import('@/views/bundles/BundleForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/appointments',
    name: 'AppointmentList',
    component: () => import('@/views/appointments/AppointmentList.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/time-logs',
    name: 'TimeLogs',
    component: () => import('@/views/time/TimeLogs.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/my-time',
    name: 'TechnicianTime',
    component: () => import('@/views/time/TechnicianPortal.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/appointments/create',
    name: 'AppointmentBook',
    component: () => import('@/views/appointments/AppointmentBook.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/appointments/availability-settings',
    name: 'AvailabilitySettings',
    component: () => import('@/views/appointments/AvailabilitySettings.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/customers',
    name: 'CustomerList',
    component: () => import('@/views/customers/CustomerList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/customers/:id',
    name: 'CustomerDetail',
    component: () => import('@/views/customers/CustomerDetail.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/vehicles',
    name: 'VehicleList',
    component: () => import('@/views/vehicles/VehicleList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/vehicles/:id',
    name: 'VehicleDetail',
    component: () => import('@/views/vehicles/VehicleDetail.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/inventory',
    name: 'InventoryList',
    component: () => import('@/views/inventory/InventoryList.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/inventory/create',
    name: 'InventoryCreate',
    component: () => import('@/views/inventory/InventoryForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/inventory/:id/edit',
    name: 'InventoryEdit',
    component: () => import('@/views/inventory/InventoryForm.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/inventory/alerts',
    name: 'InventoryAlerts',
    component: () => import('@/views/inventory/InventoryAlerts.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/financial/entries',
    name: 'FinancialEntries',
    component: () => import('@/views/financial/FinancialEntries.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/reports',
    name: 'FinancialReports',
    component: () => import('@/views/financial/Reports.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
  },
  {
    path: '/settings',
    name: 'Settings',
    component: () => import('@/views/settings/SettingsPage.vue'),
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
    return next('/dashboard')
  }

  // Check if route requires staff access
  if (to.meta.requiresStaff && !authStore.isStaff) {
    return next('/portal')
  }

  // Check if route requires customer access
  if (to.meta.requiresCustomer) {
    if (!authStore.isCustomer) {
      return next('/dashboard')
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
    return next('/dashboard')
  }

  next()
})

export default router
