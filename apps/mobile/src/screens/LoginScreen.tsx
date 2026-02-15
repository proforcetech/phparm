import AsyncStorage from '@react-native-async-storage/async-storage'
import { useEffect, useState } from 'react'
import {
  ActivityIndicator,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native'

import { getEnv } from '../config/env'
import { getApiBaseUrl, setApiBaseUrl } from '../services/api'
import { useAuthStore } from '../stores/authStore'

const SERVER_URL_KEY = 'server_url'

export function LoginScreen() {
  const login = useAuthStore((state) => state.login)
  const verifyTwoFactor = useAuthStore((state) => state.verifyTwoFactor)
  const pendingChallenge = useAuthStore((state) => state.pendingChallenge)
  const loading = useAuthStore((state) => state.loading)
  const error = useAuthStore((state) => state.error)

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [serverUrl, setServerUrl] = useState(getApiBaseUrl())
  const [serverExpanded, setServerExpanded] = useState(false)

  useEffect(() => {
    AsyncStorage.getItem(SERVER_URL_KEY).then((saved) => {
      if (saved) {
        setServerUrl(saved)
        setApiBaseUrl(saved)
      }
    })
  }, [])

  const handleLogin = async () => {
    const url = serverUrl.trim()
    try {
      if (url) {
        setApiBaseUrl(url)
        await AsyncStorage.setItem(SERVER_URL_KEY, url)
      } else {
        const defaultUrl = getEnv().apiBaseUrl
        setApiBaseUrl(defaultUrl)
        await AsyncStorage.removeItem(SERVER_URL_KEY)
        setServerUrl(defaultUrl)
      }
    } catch (err) {
      console.warn('Failed to persist server URL', err)
      // Still proceed with login — URL was set synchronously via setApiBaseUrl
    }
    try {
      await login(email.trim(), password)
    } catch (err) {
      console.warn('Login failed', err)
    }
  }

  const handleVerify = async () => {
    try {
      await verifyTwoFactor(code.trim())
    } catch (err) {
      console.warn('Two-factor verification failed', err)
    }
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Welcome back</Text>
      <Text style={styles.subtitle}>Sign in to continue</Text>

      {!pendingChallenge ? (
        <>
          {serverExpanded ? (
            <TextInput
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="url"
              placeholder="https://yourserver.com/api"
              placeholderTextColor="#94a3b8"
              style={styles.input}
              value={serverUrl}
              onChangeText={setServerUrl}
              onBlur={() => setServerExpanded(false)}
            />
          ) : (
            <TouchableOpacity onPress={() => setServerExpanded(true)}>
              <Text style={styles.serverLabel} numberOfLines={1}>
                Server: {serverUrl}
              </Text>
            </TouchableOpacity>
          )}
          <TextInput
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            placeholder="Email"
            placeholderTextColor="#94a3b8"
            style={styles.input}
            value={email}
            onChangeText={setEmail}
          />
          <TextInput
            placeholder="Password"
            placeholderTextColor="#94a3b8"
            secureTextEntry
            style={styles.input}
            value={password}
            onChangeText={setPassword}
          />
          {error ? <Text style={styles.error}>{error}</Text> : null}
          <TouchableOpacity
            style={[styles.button, loading && styles.buttonDisabled]}
            onPress={handleLogin}
            disabled={loading}
          >
            {loading ? (
              <ActivityIndicator color="#0f172a" />
            ) : (
              <Text style={styles.buttonLabel}>Login</Text>
            )}
          </TouchableOpacity>
        </>
      ) : (
        <>
          <Text style={styles.notice}>
            Two-factor authentication is required. Enter your code to continue.
          </Text>
          <TextInput
            placeholder="Authentication code"
            placeholderTextColor="#94a3b8"
            keyboardType="numeric"
            style={styles.input}
            value={code}
            onChangeText={setCode}
          />
          {error ? <Text style={styles.error}>{error}</Text> : null}
          <TouchableOpacity
            style={[styles.button, loading && styles.buttonDisabled]}
            onPress={handleVerify}
            disabled={loading}
          >
            {loading ? (
              <ActivityIndicator color="#0f172a" />
            ) : (
              <Text style={styles.buttonLabel}>Verify</Text>
            )}
          </TouchableOpacity>
        </>
      )}
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
    fontSize: 28,
    fontWeight: '700',
    color: '#f8fafc',
    marginBottom: 8,
  },
  subtitle: {
    fontSize: 16,
    color: '#cbd5f5',
    marginBottom: 12,
  },
  serverLabel: {
    fontSize: 13,
    color: '#64748b',
    marginBottom: 12,
  },
  input: {
    borderWidth: 1,
    borderColor: '#334155',
    borderRadius: 10,
    padding: 12,
    fontSize: 16,
    color: '#f8fafc',
    marginBottom: 12,
    backgroundColor: '#1e293b',
  },
  button: {
    backgroundColor: '#38bdf8',
    paddingVertical: 12,
    borderRadius: 10,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonDisabled: {
    opacity: 0.7,
  },
  buttonLabel: {
    color: '#0f172a',
    fontWeight: '700',
    fontSize: 16,
  },
  error: {
    color: '#f87171',
    marginBottom: 8,
  },
  notice: {
    color: '#f8fafc',
    marginBottom: 12,
  },
})
