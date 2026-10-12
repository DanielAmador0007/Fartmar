// Piezas de interfaz reutilizables y sin lógica de negocio.
import type { ReactNode } from 'react'
import { correlationIdOf, errorMessage, type ErrorContext } from '../api/errors'

// Mensaje de error comprensible + código de soporte (X-Correlation-Id).
export function ErrorAlert({ error, action }: { error: unknown; action?: ErrorContext['action'] }) {
  if (!error) return null
  const correlationId = correlationIdOf(error)
  return (
    <div role="alert" className="alert alert-error">
      <p>{errorMessage(error, { action })}</p>
      {correlationId && <p className="support-code">Código de soporte: {correlationId}</p>}
    </div>
  )
}

export function SuccessAlert({ children }: { children: ReactNode }) {
  return (
    <div role="status" className="alert alert-success">
      {children}
    </div>
  )
}

export function Loading({ label = 'Cargando…' }: { label?: string }) {
  return (
    <p className="muted" role="status">
      {label}
    </p>
  )
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="empty">{children}</p>
}

const BADGE_TONE: Record<string, string> = {
  BORRADOR: 'gray',
  SOLICITADO: 'blue',
  APROBADO: 'indigo',
  EN_TRANSITO: 'amber',
  RECIBIDO: 'green',
  RECIBIDO_PARCIAL: 'orange',
  ANULADO: 'red',
  PENDIENTE_AUTORIZACION: 'amber',
  COMPLETADA: 'green',
  RECHAZADA: 'red',
  PENDIENTE: 'amber',
  RESUELTA: 'green',
  ACTIVA: 'green',
  ANULADA: 'red',
}

const BADGE_LABEL: Record<string, string> = {
  EN_TRANSITO: 'En tránsito',
  RECIBIDO_PARCIAL: 'Recibido parcial',
  PENDIENTE_AUTORIZACION: 'Pendiente de autorización',
}

export function StatusBadge({ status }: { status: string }) {
  const label = BADGE_LABEL[status] ?? status.charAt(0) + status.slice(1).toLowerCase()
  return <span className={`badge badge-${BADGE_TONE[status] ?? 'gray'}`}>{label}</span>
}

export function Pagination({ page, lastPage, onChange }: { page: number; lastPage: number; onChange: (page: number) => void }) {
  if (lastPage <= 1) return null
  return (
    <nav className="pagination" aria-label="Paginación">
      <button type="button" disabled={page <= 1} onClick={() => onChange(page - 1)}>
        Anterior
      </button>
      <span>
        Página {page} de {lastPage}
      </span>
      <button type="button" disabled={page >= lastPage} onClick={() => onChange(page + 1)}>
        Siguiente
      </button>
    </nav>
  )
}
