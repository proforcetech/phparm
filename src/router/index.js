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
    path: '/appointments',
    name: 'AppointmentList',
    component: () => import('@/views/appointments/AppointmentList.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/appointments/book',
    name: 'AppointmentBook',
    component: () => import('@/views/appointments/AppointmentBook.vue'),
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
    path: '/portal/appointments',
    name: 'CustomerAppointments',
    component: () => import('@/views/customer-portal/Appointments.vue'),
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
router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()

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
  if (to.meta.requiresCustomer && !authStore.isCustomer) {
    return next('/dashboard')
  }

  // Check if route requires admin access
  if (to.meta.requiresAdmin && !authStore.isAdmin) {
    return next('/dashboard')
  }

  next()
})

export default router
