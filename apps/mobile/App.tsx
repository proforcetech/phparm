import { StatusBar } from 'expo-status-bar'
import { StyleSheet, Text, View } from 'react-native'

import { getEnv } from './src/config/env'
import { AppProviders } from './src/providers/AppProviders'
import { useAuthStore } from './src/stores/authStore'
import { useUIStore } from './src/stores/uiStore'

const env = getEnv()

export default function App() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated())
  const theme = useUIStore((state) => state.theme)

  return (
    <AppProviders>
      <View style={styles.container}>
        <Text style={styles.title}>Phparm Mobile</Text>
        <Text style={styles.subtitle}>Build Variant: {env.buildVariant}</Text>
        <Text style={styles.subtitle}>API Base URL: {env.apiBaseUrl}</Text>
        <Text style={styles.subtitle}>
          Authenticated: {isAuthenticated ? 'Yes' : 'No'}
        </Text>
        <Text style={styles.subtitle}>Theme: {theme}</Text>
        <Text style={styles.helper}>State and services are wired for mobile.</Text>
        <StatusBar style="auto" />
      </View>
    </AppProviders>
  )
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 24,
    backgroundColor: '#0f172a',
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
