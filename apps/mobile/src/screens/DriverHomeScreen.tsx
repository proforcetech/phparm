import { StyleSheet, Text, TouchableOpacity, View } from 'react-native'

import { useAuthStore } from '../stores/authStore'

type DriverHomeScreenProps = {
  offlineAccess: boolean
}

export function DriverHomeScreen({ offlineAccess }: DriverHomeScreenProps) {
  const user = useAuthStore((state) => state.user)
  const logout = useAuthStore((state) => state.logout)

  return (
    <View style={styles.container}>
      {offlineAccess ? (
        <View style={styles.offlineBanner}>
          <Text style={styles.offlineText}>Offline mode enabled — cached token accepted.</Text>
        </View>
      ) : null}
      <Text style={styles.title}>Driver Interface</Text>
      <Text style={styles.subtitle}>Welcome{user?.name ? `, ${user.name}` : ''}.</Text>
      <Text style={styles.helper}>Manage dispatch jobs and driver workflows.</Text>
      <TouchableOpacity style={styles.button} onPress={logout}>
        <Text style={styles.buttonLabel}>Sign out</Text>
      </TouchableOpacity>
    </View>
  )
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    padding: 24,
    backgroundColor: '#0f172a',
    justifyContent: 'center',
  },
  title: {
    fontSize: 24,
    fontWeight: '700',
    color: '#f8fafc',
    marginBottom: 8,
  },
  subtitle: {
    fontSize: 16,
    color: '#e2e8f0',
    marginBottom: 12,
  },
  helper: {
    fontSize: 14,
    color: '#94a3b8',
    marginBottom: 24,
  },
  button: {
    backgroundColor: '#f8fafc',
    paddingVertical: 10,
    borderRadius: 10,
    alignItems: 'center',
  },
  buttonLabel: {
    color: '#0f172a',
    fontWeight: '700',
  },
  offlineBanner: {
    backgroundColor: '#1e3a8a',
    padding: 10,
    borderRadius: 8,
    marginBottom: 16,
  },
  offlineText: {
    color: '#e0f2fe',
    textAlign: 'center',
    fontSize: 12,
  },
})
