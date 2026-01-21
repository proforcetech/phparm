import type { NotificationContent } from 'expo-notifications'
import * as LocalAuthentication from 'expo-local-authentication'
import { StatusBar } from 'expo-status-bar'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native'

import { getEnv } from './src/config/env'
import { AppProviders } from './src/providers/AppProviders'
import { BiometricUnlockScreen } from './src/screens/BiometricUnlockScreen'
import { DriverHomeScreen } from './src/screens/DriverHomeScreen'
import { LoginScreen } from './src/screens/LoginScreen'
import { TechnicianHomeScreen } from './src/screens/TechnicianHomeScreen'
import { listenForPushNotifications, syncDriverPushToken } from './src/services/pushNotifications'
import { useAuthStore } from './src/stores/authStore'
import { useToastStore } from './src/stores/toastStore'
import { useUIStore } from './src/stores/uiStore'
import { getUserRoles, resolvePrimaryInterface } from './src/utils/roles'

const env = getEnv()

export default function App() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated())
  const token = useAuthStore((state) => state.token)
  const user = useAuthStore((state) => state.user)
  const logout = useAuthStore((state) => state.logout)
  const initialized = useAuthStore((state) => state.initialized)
  const offlineAccess = useAuthStore((state) => state.offlineAccess)
  const canRegisterPush = useAuthStore((state) => state.hasPermission('dispatch.tokens.manage'))
  const theme = useUIStore((state) => state.theme)
  const addChatNotification = useUIStore((state) => state.addChatNotification)
  const pushInfo = useToastStore((state) => state.info)
  const [biometricAvailable, setBiometricAvailable] = useState(false)
  const [biometricUnlocked, setBiometricUnlocked] = useState(false)
  const [biometricChecking, setBiometricChecking] = useState(false)
  const [biometricMessage, setBiometricMessage] = useState<string | null>(null)

  const roles = useMemo(() => getUserRoles(user, token), [user, token])
  const primaryInterface = useMemo(() => resolvePrimaryInterface(roles), [roles])

  const promptBiometric = useCallback(async () => {
    setBiometricChecking(true)
    try {
      const result = await LocalAuthentication.authenticateAsync({
        promptMessage: 'Unlock Phparm',
        fallbackLabel: 'Use passcode',
      })
      if (result.success) {
        setBiometricUnlocked(true)
        setBiometricMessage(null)
      } else if (result.error) {
        setBiometricMessage('Biometric authentication was cancelled.')
      }
    } catch (error) {
      console.warn('Biometric auth error', error)
      setBiometricMessage('Unable to use biometrics on this device.')
    } finally {
      setBiometricChecking(false)
    }
  }, [])

  useEffect(() => {
    let isMounted = true

    const prepareBiometrics = async () => {
      if (!isAuthenticated) {
        if (isMounted) {
          setBiometricAvailable(false)
          setBiometricUnlocked(false)
          setBiometricMessage(null)
        }
        return
      }

      const hasHardware = await LocalAuthentication.hasHardwareAsync()
      const isEnrolled = await LocalAuthentication.isEnrolledAsync()
      const available = hasHardware && isEnrolled

      if (!isMounted) return

      setBiometricAvailable(available)
      setBiometricUnlocked(!available)
      setBiometricMessage(null)

      if (available) {
        await promptBiometric()
      }
    }

    prepareBiometrics().catch((error) =>
      console.warn('Biometric setup failed', error)
    )

    return () => {
      isMounted = false
    }
  }, [isAuthenticated, promptBiometric])

  const handlePushNotification = useCallback(
    (content: NotificationContent) => {
      const data = (content.data ?? {}) as Record<string, unknown>
      const type = typeof data.type === 'string' ? data.type : ''

      if (type === 'chat_message') {
        const senderName = typeof data.sender_name === 'string' ? data.sender_name : 'New message'
        const message = typeof data.message === 'string' ? data.message : content.body ?? ''
        addChatNotification({
          id: typeof data.message_id === 'string' ? data.message_id : undefined,
          threadId: typeof data.thread_id === 'string' ? data.thread_id : undefined,
          senderName,
          message,
        })
        if (message || senderName) {
          pushInfo(`${senderName}${message ? `: ${message}` : ''}`)
        }
        return
      }

      if (type === 'job_offer') {
        pushInfo('New job offer received.')
        return
      }

      if (type === 'workorder_assigned') {
        const workorderNumber =
          typeof data.workorder_number === 'string' ? data.workorder_number : undefined
        const suffix = workorderNumber ? ` #${workorderNumber}` : ''
        pushInfo(`Work order${suffix} assigned to you.`)
      }
    },
    [addChatNotification, pushInfo]
  )

  useEffect(() => {
    if (!isAuthenticated || !canRegisterPush) {
      return
    }

    syncDriverPushToken().catch((error) => console.warn('Push token sync failed', error))
    const unsubscribe = listenForPushNotifications(handlePushNotification)

    return () => {
      unsubscribe()
    }
  }, [canRegisterPush, handlePushNotification, isAuthenticated])

  return (
    <AppProviders>
      <View style={styles.container}>
        {!initialized ? (
          <View style={styles.centered}>
            <ActivityIndicator color="#38bdf8" />
            <Text style={styles.helper}>Restoring session...</Text>
          </View>
        ) : !isAuthenticated ? (
          <LoginScreen />
        ) : biometricAvailable && !biometricUnlocked ? (
          <BiometricUnlockScreen
            isChecking={biometricChecking}
            message={biometricMessage ?? undefined}
            onUnlock={promptBiometric}
            onFallback={logout}
          />
        ) : primaryInterface === 'technician' ? (
          <TechnicianHomeScreen offlineAccess={offlineAccess} />
        ) : primaryInterface === 'driver' ? (
          <DriverHomeScreen offlineAccess={offlineAccess} />
        ) : (
          <View style={styles.centered}>
            <Text style={styles.title}>Phparm Mobile</Text>
            <Text style={styles.subtitle}>
              Your account does not have a mobile role assigned.
            </Text>
            <Text style={styles.helper}>
              Roles detected: {roles.length > 0 ? roles.join(', ') : 'None'}
            </Text>
            <Text style={styles.helper}>
              Build Variant: {env.buildVariant} · Theme: {theme}
            </Text>
          </View>
        )}
        <StatusBar style="auto" />
      </View>
    </AppProviders>
  )
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f172a',
  },
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 24,
  },
  title: {
    fontSize: 24,
    fontWeight: '700',
    color: '#f8fafc',
    marginBottom: 12,
  },
  subtitle: {
    fontSize: 16,
    color: '#e2e8f0',
    marginBottom: 6,
    textAlign: 'center',
  },
  helper: {
    fontSize: 14,
    color: '#94a3b8',
    marginTop: 12,
    textAlign: 'center',
  },
})
