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
import { useAuthStore } from './src/stores/authStore'
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
  const theme = useUIStore((state) => state.theme)
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
