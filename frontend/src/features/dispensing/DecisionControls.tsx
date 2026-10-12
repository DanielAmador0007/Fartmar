import { useState } from 'react'
import type { Dispensation } from '../../api/types'
import { useCurrentUser } from '../../auth/AuthContext'
import { ErrorAlert } from '../../components/ui'
import { useSubmitGuard } from '../../lib/useSubmitGuard'
import { useAuthorizeDispensation, useRejectDispensation } from './dispensingApi'

interface Props {
  dispensationId: number
  createdById: number
  onDone?: (dispensation: Dispensation) => void
}

// Autorizar / rechazar un control especial (RN-05). Si el usuario actual la
// creó, los botones quedan deshabilitados (pista visual: la API y la BD
// rechazan igual con SEGREGACION_FUNCIONES).
export function DecisionControls({ dispensationId, createdById, onDone }: Props) {
  const user = useCurrentUser()
  const authorize = useAuthorizeDispensation()
  const reject = useRejectDispensation()
  const guard = useSubmitGuard()
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')

  const isOwn = createdById === user.id
  const busy = authorize.isPending || reject.isPending

  return (
    <div className="decision">
      <div className="actions">
        <button
          type="button"
          className="primary"
          disabled={busy || isOwn}
          title={isOwn ? 'La creaste tú: debe autorizarla otro regente' : undefined}
          aria-label={`Autorizar dispensación ${dispensationId}`}
          onClick={() => void guard(() => authorize.mutateAsync(dispensationId).then(onDone))}
        >
          {authorize.isPending ? 'Autorizando…' : 'Autorizar'}
        </button>
        <button type="button" disabled={busy || isOwn} onClick={() => setRejecting((r) => !r)} aria-label={`Rechazar dispensación ${dispensationId}`}>
          Rechazar
        </button>
        {isOwn && <span className="muted">La creaste tú: debe autorizarla otro regente.</span>}
      </div>
      {rejecting && !isOwn && (
        <div className="inline-form">
          <label className="grow">
            Motivo del rechazo (mínimo 5 caracteres)
            <input value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
          </label>
          <button
            type="button"
            className="danger"
            disabled={busy || reason.trim().length < 5}
            onClick={() => void guard(() => reject.mutateAsync({ id: dispensationId, reason: reason.trim() }).then(onDone))}
          >
            {reject.isPending ? 'Rechazando…' : 'Confirmar rechazo'}
          </button>
        </div>
      )}
      <ErrorAlert error={authorize.error ?? reject.error} action="autorizar dispensaciones de control especial" />
    </div>
  )
}
