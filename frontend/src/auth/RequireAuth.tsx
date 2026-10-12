import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { Loading } from '../components/ui'
import { useAuth } from './AuthContext'
import { permissions, type Permission } from './permissions'

// Exige sesión. Con contraseña temporal solo deja ver la pantalla de cambio (S-77).
export function RequireAuth({ children }: { children: ReactNode }) {
  const { status, user } = useAuth()
  const location = useLocation()

  if (status === 'loading') return <Loading label="Cargando sesión…" />
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
