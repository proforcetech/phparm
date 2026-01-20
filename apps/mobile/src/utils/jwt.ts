export type JwtPayload = {
  exp?: number
  iat?: number
  sub?: number | string
  role?: string
  roles?: string[]
  type?: string
  [key: string]: unknown
}

function base64UrlDecode(input: string): string {
  const normalized = input.replace(/-/g, '+').replace(/_/g, '/')
  const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=')

  if (typeof globalThis.atob === 'function') {
    return globalThis.atob(padded)
  }

  if (typeof Buffer !== 'undefined') {
    return Buffer.from(padded, 'base64').toString('binary')
  }

  throw new Error('Base64 decoder not available')
}

export function decodeJwt(token: string): JwtPayload | null {
  const parts = token.split('.')
  if (parts.length !== 3) {
    return null
  }

  try {
    const payload = base64UrlDecode(parts[1])
    const data = JSON.parse(payload)
    return typeof data === 'object' && data !== null ? (data as JwtPayload) : null
  } catch (error) {
    console.warn('Failed to decode JWT', error)
    return null
  }
}

export function isTokenValid(token: string | null): boolean {
  if (!token) return false
  const payload = decodeJwt(token)
  if (!payload) return false
  if (payload.type && payload.type !== 'access') return false
  if (!payload.exp) return false
  return payload.exp * 1000 > Date.now()
}
