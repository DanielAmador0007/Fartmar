import { createContext, useContext } from 'react'
import type { User } from '../api/types'
import { permissions, type Permission } from './permissions'

export interface AuthState {
  // loading = hay token y se está consultando /auth/me
  status: 'loading' | 'anonymous' | 'authenticated'
  user: User | null
  login: (email: string, password: string) => Promise<User>
  logout: () => Promise<void>
  // Tras cambiar la clave, la API devuelve el usuario actualizado.
  updateUser: (user: User) => void
}

export const AuthContext = createContext<AuthState | null>(null)

export function useAuth(): AuthState {
  const value = useContext(AuthContext)
  if (!value) throw new Error('useAuth debe usarse dentro de <AuthProvider>')
  return value
}

// Usuario autenticado (en pantallas protegidas por RequireAuth siempre existe).
export function useCurrentUser(): User {
  const { user } = useAuth()
  if (!user) throw new Error('useCurrentUser requiere sesión iniciada')
  return user
}

export function useCan(permission: Permission): boolean {
  const { user } = useAuth()
  return user ? permissions[permission](user.role) : false
}
