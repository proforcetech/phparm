import { decodeJwt } from './jwt'

type UserLike = {
  role?: string | null
  roles?: string[] | null
  [key: string]: unknown
}

const technicianRoles = new Set(['technician'])
const driverRoles = new Set(['driver', 'roadside', 'dispatcher'])

export function getUserRoles(user: UserLike | null, token?: string | null): string[] {
  const rolesFromUser = Array.isArray(user?.roles)
    ? user?.roles
    : user?.role
      ? [user.role]
      : []

  const normalized = rolesFromUser
    .filter((role): role is string => typeof role === 'string')
    .map((role) => role.toLowerCase())

  if (normalized.length > 0) {
    return Array.from(new Set(normalized))
  }

  if (token) {
    const payload = decodeJwt(token)
    const tokenRoles = Array.isArray(payload?.roles)
      ? payload?.roles
      : payload?.role
        ? [payload.role]
        : []
    return tokenRoles
      .filter((role): role is string => typeof role === 'string')
      .map((role) => role.toLowerCase())
  }

  return []
}

export function resolvePrimaryInterface(roles: string[]): 'technician' | 'driver' | null {
  if (roles.some((role) => technicianRoles.has(role))) {
    return 'technician'
  }

  if (roles.some((role) => driverRoles.has(role))) {
    return 'driver'
  }

  return null
}
