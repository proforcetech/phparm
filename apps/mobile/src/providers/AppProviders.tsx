import type { ReactNode } from 'react'
import { useEffect } from 'react'
import { View } from 'react-native'

import { useAuthStore } from '../stores/authStore'
import { useUIStore } from '../stores/uiStore'

type AppProvidersProps = {
  children: ReactNode
}

export function AppProviders({ children }: AppProvidersProps) {
  const checkAuth = useAuthStore((state) => state.checkAuth)
  const initializeUi = useUIStore((state) => state.initialize)

  useEffect(() => {
    checkAuth().catch((error) => console.warn('Auth bootstrap failed', error))
    initializeUi().catch((error) => console.warn('UI bootstrap failed', error))
  }, [checkAuth, initializeUi])

  return <View style={{ flex: 1 }}>{children}</View>
}
