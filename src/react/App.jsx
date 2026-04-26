import { RouterProvider } from 'react-router-dom'

import { router } from './router'
import ErrorBoundary from './components/ErrorBoundary'
import PWAInstallPrompt from './components/PWAInstallPrompt'
import PWAUpdatePrompt from './components/PWAUpdatePrompt'

export default function App() {
  return (
    <ErrorBoundary>
      <RouterProvider router={router} />
      <PWAUpdatePrompt />
      <PWAInstallPrompt />
    </ErrorBoundary>
  )
}
