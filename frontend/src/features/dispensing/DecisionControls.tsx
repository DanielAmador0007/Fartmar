import { useState } from 'react'
import type { Dispensation, DispensationAction, UserSummary } from '../../api/types'
import { useCurrentUser } from '../../auth/AuthContext'
import { ErrorAlert } from '../../components/ui'
import { useSubmitGuard } from '../../lib/useSubmitGuard'
import { useAuthorizeDispensation, useRejectDispensation } from './dispensingApi'

interface Props {
  dispensation: { id: number; created_by: UserSummary; allowed_actions: DispensationAction[] }
  onDone?: (dispensation: Dispensation) => void
}

// Autorizar / rechazar un control especial (RN-05). Qué se habilita lo dice
// `allowed_actions` de la API (estado + Policy); la interfaz no repite la regla.
// Cada endpoint vuelve a autorizar (403 SEGREGACION_FUNCIONES al creador).
export function DecisionControls({ dispensation, onDone }: Props) {
  const user = useCurrentUser()
  const authorize = useAuthorizeDispensation()
  const reject = useRejectDispensation()
  const guard = useSubmitGuard()
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')

  const { id, allowed_actions: allowed } = dispensation
  const canAuthorize = allowed.includes('authorize')
  const canReject = allowed.includes('reject')
  const busy = authorize.isPending || reject.isPending
  // Solo para explicar por qué no hay acciones (no decide nada).
  const isOwn = dispensation.created_by.id === user.id

  // Decidida: se retiran los botones sin esperar la recarga de la bandeja.
  if (authorize.isSuccess || reject.isSuccess) {
    return <p className="muted">Decisión registrada.</p>
  }

  return (
    <div className="decision">
      <div className="actions">
        <button
          type="button"
          className="primary"
          disabled={busy || !canAuthorize}
          aria-label={`Autorizar dispensación ${id}`}
          onClick={() => void guard(() => authorize.mutateAsync(id).then(onDone))}
        >
          {authorize.isPending ? 'Autorizando…' : 'Autorizar'}
        </button>
        <button type="button" disabled={busy || !canReject} onClick={() => setRejecting((r) => !r)} aria-label={`Rechazar dispensación ${id}`}>
          Rechazar
        </button>
        {allowed.length === 0 && (
          <span className="muted">{isOwn ? 'La creaste tú: debe autorizarla otro regente.' : 'No tienes acciones disponibles.'}</span>
        )}
      </div>
      {rejecting && canReject && (
        <div className="inline-form">
          <label className="grow">
            Motivo del rechazo (mínimo 5 caracteres)
            <input value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
          </label>
          <button
            type="button"
            className="danger"
            disabled={busy || reason.trim().length < 5}
            onClick={() => void guard(() => reject.mutateAsync({ id, reason: reason.trim() }).then(onDone))}
          >
            {reject.isPending ? 'Rechazando…' : 'Confirmar rechazo'}
          </button>
        </div>
      )}
      <ErrorAlert error={authorize.error ?? reject.error} action="autorizar dispensaciones de control especial" />
    </div>
  )
}
