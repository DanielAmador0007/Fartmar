import { useState } from 'react'
import type { Transfer, TransferAction } from '../../api/types'
import { ErrorAlert } from '../../components/ui'
import { useSubmitGuard } from '../../lib/useSubmitGuard'
import { ReceiveForm } from './ReceiveForm'
import { useTransferAction } from './transfersApi'

const LABELS: Record<Exclude<TransferAction, 'cancel' | 'receive'>, string> = {
  submit: 'Solicitar',
  approve: 'Aprobar',
  dispatch: 'Despachar',
}

const ACTION_TEXT: Record<TransferAction, string> = {
  submit: 'solicitar este traslado',
  approve: 'aprobar traslados',
  dispatch: 'despachar traslados',
  receive: 'recibir traslados',
  cancel: 'anular este traslado',
}

// Los botones salen de `allowed_actions` (S-67): la API ya combinó estado y
// permisos del usuario. La interfaz no repite esas reglas.
export function TransferActions({ transfer }: { transfer: Transfer }) {
  const mutation = useTransferAction(transfer.id)
  const guard = useSubmitGuard()
  const [cancelReason, setCancelReason] = useState('')
  const [lastAction, setLastAction] = useState<TransferAction | null>(null)
  const allowed = transfer.allowed_actions
  const busy = mutation.isPending

  function run(action: TransferAction, input: Parameters<typeof mutation.mutateAsync>[0]) {
    setLastAction(action)
    void guard(() => mutation.mutateAsync(input))
  }

  // El error se muestra aunque ya no queden acciones: tras un 409 el detalle se
  // recarga con el estado nuevo y el usuario debe ver por qué falló (M4).
  if (allowed.length === 0) {
    return (
      <>
        <p className="muted">No hay acciones disponibles para ti en este estado.</p>
        <ErrorAlert error={mutation.error} action={lastAction ? ACTION_TEXT[lastAction] : undefined} />
      </>
    )
  }

  return (
    <section className="card" aria-label="Acciones del traslado">
      <h3>Acciones</h3>
      <div className="actions">
        {(['submit', 'approve', 'dispatch'] as const)
          .filter((action) => allowed.includes(action))
          .map((action) => (
            <button key={action} type="button" className="primary" disabled={busy} onClick={() => run(action, { kind: action })}>
              {busy && lastAction === action ? 'Procesando…' : LABELS[action]}
            </button>
          ))}
      </div>

      {allowed.includes('cancel') && (
        <div className="inline-form">
          <label className="grow">
            Motivo de anulación (mínimo 5 caracteres)
            <input value={cancelReason} maxLength={500} onChange={(e) => setCancelReason(e.target.value)} />
          </label>
          <button
            type="button"
            className="danger"
            disabled={busy || cancelReason.trim().length < 5}
            onClick={() => run('cancel', { kind: 'cancel', reason: cancelReason.trim() })}
          >
            Anular
          </button>
        </div>
      )}

      {allowed.includes('receive') && (
        <ReceiveForm transfer={transfer} busy={busy} onSubmit={(lots) => run('receive', { kind: 'receive', lots })} />
      )}

      <ErrorAlert error={mutation.error} action={lastAction ? ACTION_TEXT[lastAction] : undefined} />
    </section>
  )
}
