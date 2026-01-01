import { createBrowserRouter, redirect, Outlet } from 'react-router-dom'
import CustomerPortalInvoices from '../views/customer-portal/Invoices'

const routePaths = {
  login: '/react/login',
  customerLogin: '/react/customer-login',
  forgotPassword: '/react/forgot-password',
  dashboard: '/react/cp/dashboard',
  invoices: '/react/cp/invoices',
  customerPortalInvoices: '/react/portal/invoices',
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
    <header>
      <h1>React migration staging</h1>
      <p>Public route (matches Vue guest routes)</p>
    </header>
    <Outlet />
  </div>
)

const ProtectedLayout = () => (
  <div className="react-app">
    <header>
      <h1>React migration staging</h1>
      <p>Protected route (matches Vue requiresAuth routes)</p>
    </header>
    <Outlet />
  </div>
)

const Page = ({ title, description }) => (
  <section>
    <h2>{title}</h2>
    <p>{description}</p>
  </section>
)

export const reactRouteSubset = [
  {
    path: routePaths.login,
    name: 'Login',
    auth: 'guest',
  },
  {
    path: routePaths.customerLogin,
    name: 'CustomerLogin',
    auth: 'guest',
  },
  {
    path: routePaths.forgotPassword,
    name: 'ForgotPassword',
    auth: 'guest',
  },
  {
    path: routePaths.dashboard,
    name: 'Dashboard',
    auth: 'requiresAuth',
  },
  {
    path: routePaths.invoices,
    name: 'InvoiceList',
    auth: 'requiresAuth',
  },
  {
    path: routePaths.customerPortalInvoices,
    name: 'CustomerPortalInvoices',
    auth: 'requiresAuth',
  },
]

export const router = createBrowserRouter([
  {
    element: <PublicLayout />,
    children: [
      {
        path: routePaths.login,
        loader: requireGuest,
        element: (
          <Page
            title="Login"
            description="React mirror of Vue /login (guest-only)."
          />
        ),
      },
      {
        path: routePaths.customerLogin,
        loader: requireGuest,
        element: (
          <Page
            title="Customer login"
            description="React mirror of Vue /customer-login (guest-only)."
          />
        ),
      },
      {
        path: routePaths.forgotPassword,
        loader: requireGuest,
        element: (
          <Page
            title="Forgot password"
            description="React mirror of Vue /forgot-password (guest-only)."
          />
        ),
      },
    ],
  },
  {
    element: <ProtectedLayout />,
    children: [
      {
        path: routePaths.dashboard,
        loader: requireAuth,
        element: (
          <Page
            title="Dashboard"
            description="React mirror of Vue /cp/dashboard (requires auth)."
          />
        ),
      },
      {
        path: routePaths.invoices,
        loader: requireAuth,
        element: (
          <Page
            title="Invoices"
            description="React mirror of Vue /cp/invoices (requires auth)."
          />
        ),
      },
      {
        path: routePaths.customerPortalInvoices,
        loader: requireAuth,
        element: <CustomerPortalInvoices />,
      },
    ],
  },
  {
    path: '*',
    element: (
      <Page
        title="Not found"
        description="No React route matched this URL."
      />
    ),
  },
])
