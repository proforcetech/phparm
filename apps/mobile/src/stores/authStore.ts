import AsyncStorage from '@react-native-async-storage/async-storage'
import { create } from 'zustand'

import { getEnv } from '../config/env'
import { authService } from '../services/auth.service'
import { portalService } from '../services/portal.service'

type PortalConfig = {
  apiBase: string
  nonce: string | null
}

type PendingChallenge = {
  token: string
  isCustomer: boolean
}

type User = {
  role?: string
  permissions?: string[]
  modules?: string[]
  accessible_modules?: string[]
  [key: string]: any
}

type AuthState = {
  user: User | null
  token: string | null
  impersonation: Record<string, unknown> | null
  portalConfig: PortalConfig
  loading: boolean
  error: string | null
  pendingChallenge: PendingChallenge | null
  isAuthenticated: () => boolean
  isCustomer: () => boolean
  isStaff: () => boolean
  isAdmin: () => boolean
  portalReady: () => boolean
  checkAuth: () => Promise<void>
  fetchCurrentUser: () => Promise<unknown>
  login: (
    email: string,
    password: string,
    isCustomerLogin?: boolean,
    recaptchaToken?: string | null
  ) => Promise<unknown>
  verifyTwoFactor: (code: string) => Promise<unknown>
  logout: () => Promise<void>
  register: (userData: Record<string, unknown>) => Promise<unknown>
  requestPasswordReset: (email: string, recaptchaToken?: string | null) => Promise<unknown>
  resetPassword: (resetToken: string, password: string) => Promise<unknown>
  acceptInvite: (inviteToken: string, password: string) => Promise<unknown>
  updateProfile: (userData: Record<string, unknown>) => Promise<unknown>
  bootstrapPortal: () => Promise<unknown>
  stopImpersonation: () => Promise<unknown>
  hasPermission: (permission: string) => boolean
  hasModule: (moduleName: string) => boolean
  hasModuleAccess: (moduleKey: string) => boolean
}

const { apiBaseUrl } = getEnv()

export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  token: null,
  impersonation: null,
  portalConfig: {
    apiBase: apiBaseUrl,
    nonce: null,
  },
  loading: false,
  error: null,
  pendingChallenge: null,
  isAuthenticated: () => Boolean(get().token),
  isCustomer: () => get().user?.role === 'customer',
  isStaff: () => Boolean(get().user && get().user?.role !== 'customer'),
  isAdmin: () => get().user?.role === 'admin',
  portalReady: () => get().isCustomer() && Boolean(get().portalConfig.nonce),
  checkAuth: async () => {
    const storedToken = await AsyncStorage.getItem('auth_token')
    const storedUser = await AsyncStorage.getItem('user')
    const storedImpersonation = await AsyncStorage.getItem('impersonation')
    const storedNonce = await AsyncStorage.getItem('portal_nonce')

    if (storedToken && storedUser) {
      set({ token: storedToken, user: JSON.parse(storedUser) })
    }

    if (storedImpersonation) {
      set({ impersonation: JSON.parse(storedImpersonation) })
    }

    if (storedNonce) {
      set((state) => ({
        portalConfig: { ...state.portalConfig, nonce: storedNonce },
      }))
    }

    if (storedToken) {
      const data = await authService.me()
      if (data.user) {
        set({ user: data.user })
        await AsyncStorage.setItem('user', JSON.stringify(data.user))
      }

      if (data.impersonation !== undefined) {
        set({ impersonation: data.impersonation })
        if (data.impersonation) {
          await AsyncStorage.setItem('impersonation', JSON.stringify(data.impersonation))
        } else {
          await AsyncStorage.removeItem('impersonation')
        }
      }
    }
  },
  fetchCurrentUser: async () => {
    try {
      const data = await authService.me()
      if (data.user) {
        set({ user: data.user })
        await AsyncStorage.setItem('user', JSON.stringify(data.user))
      }
      if (data.impersonation !== undefined) {
        set({ impersonation: data.impersonation })
        if (data.impersonation) {
          await AsyncStorage.setItem('impersonation', JSON.stringify(data.impersonation))
        } else {
          await AsyncStorage.removeItem('impersonation')
        }
      }
      return data
    } catch (err) {
      await get().logout()
      throw err
    }
  },
  login: async (email, password, isCustomerLogin = false, recaptchaToken = null) => {
    set({ loading: true, error: null })

    try {
      const data = isCustomerLogin
        ? await authService.customerLogin(email, password, recaptchaToken)
        : await authService.login(email, password, recaptchaToken)

      if (data.status === '2fa_required') {
        set({
          pendingChallenge: {
            token: data.challenge_token,
            isCustomer: isCustomerLogin,
          },
        })
        return data
      }

      await handleLoginSuccess(data, set)
      return data
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Login failed' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  verifyTwoFactor: async (code) => {
    const pendingChallenge = get().pendingChallenge
    if (!pendingChallenge) {
      throw new Error('No pending two-factor challenge')
    }

    set({ loading: true, error: null })

    try {
      const data = await authService.verifyTwoFactor(
        pendingChallenge.token,
        code,
        pendingChallenge.isCustomer
      )

      set({ pendingChallenge: null })
      await handleLoginSuccess(data, set)
      return data
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Two-factor verification failed' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  logout: async () => {
    try {
      await authService.logout()
    } catch (err) {
      console.error('Logout error:', err)
    } finally {
      set((state) => ({
        user: null,
        token: null,
        impersonation: null,
        portalConfig: { ...state.portalConfig, nonce: null },
      }))
      await AsyncStorage.multiRemove([
        'auth_token',
        'user',
        'portal_nonce',
        'impersonation',
      ])
    }
  },
  register: async (userData) => {
    set({ loading: true, error: null })
    try {
      const data = await authService.register(userData)
      return data
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Registration failed' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  requestPasswordReset: async (email, recaptchaToken = null) => {
    set({ loading: true, error: null })
    try {
      const data = await authService.requestPasswordReset(email, recaptchaToken)
      return data
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Password reset request failed' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  resetPassword: async (resetToken, password) => {
    set({ loading: true, error: null })
    try {
      const data = await authService.resetPassword(resetToken, password)
      return data
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Password reset failed' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  acceptInvite: async (inviteToken, password) => {
    set({ loading: true, error: null })
    try {
      const data = await authService.acceptInvite(inviteToken, password)
      return data
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Invitation acceptance failed' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  updateProfile: async (userData) => {
    set({ loading: true, error: null })
    try {
      const data = await authService.updateProfile(userData)
      if (data.user) {
        set({ user: data.user })
        await AsyncStorage.setItem('user', JSON.stringify(data.user))
      }
      return data
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Profile update failed' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  bootstrapPortal: async () => {
    if (!get().isCustomer()) {
      return null
    }

    const data = await portalService.bootstrap()

    if (data.user) {
      set({ user: data.user })
      await AsyncStorage.setItem('user', JSON.stringify(data.user))
    }

    if (data.token) {
      set({ token: data.token })
      await AsyncStorage.setItem('auth_token', data.token)
    }

    if (data.api_base) {
      set((state) => ({
        portalConfig: { ...state.portalConfig, apiBase: data.api_base },
      }))
    }

    if (data.nonce) {
      set((state) => ({
        portalConfig: { ...state.portalConfig, nonce: data.nonce },
      }))
      await AsyncStorage.setItem('portal_nonce', data.nonce)
    }

    return data
  },
  stopImpersonation: async () => {
    const data = await authService.stopImpersonation()
    if (data.user) {
      set({ user: data.user })
      await AsyncStorage.setItem('user', JSON.stringify(data.user))
    }
    set({ impersonation: data.impersonation ?? null })
    if (data.impersonation) {
      await AsyncStorage.setItem('impersonation', JSON.stringify(data.impersonation))
    } else {
      await AsyncStorage.removeItem('impersonation')
    }
    return data
  },
  hasPermission: (permission) => {
    const user = get().user
    if (!user) return false
    if (user.role?.toLowerCase() === 'admin') return true
    const permissions = user.permissions ?? []
    if (permissions.includes('*')) return true
    if (permissions.includes(permission)) return true
    return permissions.some((granted: string) => {
      if (granted.endsWith('.*')) {
        const prefix = granted.slice(0, -2)
        return permission.startsWith(`${prefix}.`)
      }
      return false
    })
  },
  hasModule: (moduleName) => {
    const user = get().user
    if (!user || !user.modules) return false
    if (user.role?.toLowerCase() === 'admin') return true
    return user.modules.includes(moduleName)
  },
  hasModuleAccess: (moduleKey) => {
    const user = get().user
    if (user?.role?.toLowerCase() === 'admin') return true
    if (user?.accessible_modules) {
      return user.accessible_modules.includes(moduleKey)
    }
    return get().hasModule(moduleKey)
  },
}))

async function handleLoginSuccess(
  data: any,
  set: (partial: Partial<AuthState> | ((state: AuthState) => Partial<AuthState>)) => void
) {
  if (data.token && data.user) {
    set({ token: data.token, user: data.user, impersonation: null })

    await AsyncStorage.multiSet([
      ['auth_token', data.token],
      ['user', JSON.stringify(data.user)],
    ])
    await AsyncStorage.removeItem('impersonation')

    if (data.api_base) {
      set((state) => ({
        portalConfig: { ...state.portalConfig, apiBase: data.api_base },
      }))
    }

    if (data.user.role === 'customer' && data.nonce) {
      set((state) => ({
        portalConfig: { ...state.portalConfig, nonce: data.nonce },
      }))
      await AsyncStorage.setItem('portal_nonce', data.nonce)
    } else {
      set((state) => ({
        portalConfig: { ...state.portalConfig, nonce: null },
      }))
      await AsyncStorage.removeItem('portal_nonce')
    }
  }
}
