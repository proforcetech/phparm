import { RouterProvider } from 'react-router-dom'

import { router } from './router'
import ErrorBoundary from './components/ErrorBoundary'
import PWAInstallPrompt from './components/PWAInstallPrompt'
import PWAUpdatePrompt from './components/PWAUpdatePrompt'
import PasswordRotationOverlay from './components/auth/PasswordRotationOverlay'
import { useAuthStore } from './stores/auth'

export default function App() {
  const { user } = useAuthStore()

  // Forced password rotation gate. Render ONLY the overlay above the Router
  // so no route-level component (AdminLayout, AdminDashboard, customer portal,
  // ESS) mounts and fires data fetches that the middleware would block with
  // 403, racing the overlay and bouncing the user back to /login.
  if (user?.password_must_change) {
    return (
      <ErrorBoundary>
        <div className="min-h-screen bg-gray-50">
          <PasswordRotationOverlay />
        </div>
      </ErrorBoundary>
    )
  }

  return (
    <ErrorBoundary>
      <RouterProvider router={router} />
      <PWAUpdatePrompt />
      <PWAInstallPrompt />
    </ErrorBoundary>
  )
}
