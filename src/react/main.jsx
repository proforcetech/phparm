import { createRoot } from 'react-dom/client'

import '../assets/styles/main.css'

import App from './App'
import { AuthProvider } from './stores/auth.jsx'
import { ToastProvider } from './stores/toast.jsx'
import { UIProvider } from './stores/ui.jsx'
import offlineSync from './services/offlineSync'
import { registerServiceWorker } from './utils/registerSW'

const container = document.getElementById('app')

if (!container) {
  throw new Error('React root element (#app) not found.')
}

createRoot(container).render(
  <AuthProvider>
    <UIProvider>
      <ToastProvider>
        <App />
      </ToastProvider>
    </UIProvider>
  </AuthProvider>
)

offlineSync.start()
registerServiceWorker()
