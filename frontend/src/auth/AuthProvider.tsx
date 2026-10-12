import { useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { ApiError, setSessionHandlers } from '../api/client'
import { tokenStorage } from '../api/tokenStorage'
import type { User } from '../api/types'
import { AuthContext, type AuthState } from './AuthContext'
import { authApi } from './authApi'

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [user, setUser] = useState<User | null>(null)
  const [status, setStatus] = useState<AuthState['status']>(() => (tokenStorage.get() ? 'loading' : 'anonymous'))
  const [sessionError, setSessionError] = useState<unknown>(null)

  // Al salir se vacía la caché de consultas: no quedan datos de pacientes en memoria.
  const endSession = useCallback(() => {
    tokenStorage.clear()
    queryClient.clear()
    setUser(null)
    setStatus('anonymous')
  }, [queryClient])

  useEffect(() => {
    setSessionHandlers({
      onUnauthorized: endSession,
      onPasswordChangeRequired: () => setUser((u) => (u ? { ...u, must_change_password: true } : u)),
    })
    return () => setSessionHandlers(null)
  }, [endSession])

  // Recarga de página con token guardado: recuperar el usuario.
  useEffect(() => {
    if (status !== 'loading') return
    let cancelled = false
    authApi
      .me()
      .then((me) => {
        if (cancelled) return
        setUser(me)
        setStatus('authenticated')
      })
      .catch((error: unknown) => {
        if (cancelled) return
        // Solo un 401 invalida la sesión. Sin red o con un 5xx el token puede
        // seguir siendo válido: se conserva y se ofrece reintentar (L1).
        if (error instanceof ApiError && error.status === 401) {
          endSession()
        } else {
          setSessionError(error)
          setStatus('error')
        }
      })
    return () => {
      cancelled = true
    }
  }, [status, endSession])

  const login = useCallback(async (email: string, password: string) => {
    const response = await authApi.login(email, password)
    tokenStorage.set(response.token)
    setUser(response.user)
    setStatus('authenticated')
    return response.user
  }, [])

  const retrySession = useCallback(() => {
    setSessionError(null)
    setStatus('loading')
  }, [])

  const logout = useCallback(async () => {
    try {
      await authApi.logout()
    } catch {
      // Aunque falle la revocación, la sesión local se cierra igual.
    }
    endSession()
  }, [endSession])

  const value = useMemo<AuthState>(
    () => ({ status, user, sessionError, retrySession, login, logout, updateUser: setUser }),
    [status, user, sessionError, retrySession, login, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
