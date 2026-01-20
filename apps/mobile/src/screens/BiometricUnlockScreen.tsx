import { ActivityIndicator, StyleSheet, Text, TouchableOpacity, View } from 'react-native'

type BiometricUnlockScreenProps = {
  onUnlock: () => void
  onFallback: () => void
  isChecking?: boolean
  message?: string
}

export function BiometricUnlockScreen({
  onUnlock,
  onFallback,
  isChecking = false,
  message = 'Use Face ID or fingerprint to continue.',
}: BiometricUnlockScreenProps) {
  return (
    <View style={styles.container}>
      <Text style={styles.title}>Unlock Phparm</Text>
      <Text style={styles.subtitle}>{message}</Text>
      <TouchableOpacity style={styles.button} onPress={onUnlock} disabled={isChecking}>
        {isChecking ? (
          <ActivityIndicator color="#0f172a" />
        ) : (
          <Text style={styles.buttonLabel}>Unlock with biometrics</Text>
        )}
      </TouchableOpacity>
      <TouchableOpacity style={styles.linkButton} onPress={onFallback} disabled={isChecking}>
        <Text style={styles.linkLabel}>Use password instead</Text>
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
    alignItems: 'center',
  },
  title: {
    fontSize: 26,
    fontWeight: '700',
    color: '#f8fafc',
    marginBottom: 12,
  },
  subtitle: {
    fontSize: 16,
    color: '#cbd5f5',
    textAlign: 'center',
    marginBottom: 24,
  },
  button: {
    backgroundColor: '#38bdf8',
    paddingVertical: 12,
    paddingHorizontal: 24,
    borderRadius: 10,
  },
  buttonLabel: {
    color: '#0f172a',
    fontWeight: '700',
    fontSize: 16,
  },
  linkButton: {
    marginTop: 16,
  },
  linkLabel: {
    color: '#93c5fd',
    fontWeight: '600',
  },
})
