import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { ErrorAlert, Loading } from '../components/ui'
import { useAuth } from './AuthContext'
import { permissions, type Permission } from './permissions'

// Exige sesión. Con contraseña temporal solo deja ver la pantalla de cambio (S-77).
export function RequireAuth({ children }: { children: ReactNode }) {
  const { status, user, sessionError, retrySession, logout } = useAuth()
  const location = useLocation()

  if (status === 'loading') return <Loading label="Cargando sesión…" />
  if (status === 'error') {
    return (
      <main className="auth-page">
        <section className="card auth-card">
          <h1>No se pudo recuperar la sesión</h1>
          <ErrorAlert error={sessionError} />
          <button type="button" className="primary" onClick={retrySession}>
            Reintentar
          </button>
          <button type="button" className="link" onClick={() => void logout()}>
            Cerrar sesión
          </button>
        </section>
      </main>
    )
  }
  if (!user) return <Navigate to="/login" replace />
  if (user.must_change_password && location.pathname !== '/cambiar-clave') {
    return <Navigate to="/cambiar-clave" replace />
  }
  return <>{children}</>
}

// Oculta una sección a roles sin permiso (el backend igual respondería 403).
export function RequirePermission({ permission, children }: { permission: Permission; children: ReactNode }) {
  const { user } = useAuth()
  if (!user || !permissions[permission](user.role)) {
    return (
      <p role="alert" className="alert alert-error">
        No tienes permiso para ver esta sección.
      </p>
    )
  }
  return <>{children}</>
}
