import { useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router'
import { useCurrentUser } from '../../auth/AuthContext'
import { ErrorAlert, Loading } from '../../components/ui'
import { toInt } from '../../lib/format'
import { useSubmitGuard } from '../../lib/useSubmitGuard'
import { useAuthorizeDispensation, useDispensation, useRejectDispensation } from './dispensingApi'
import { DispensationSummary } from './DispensationView'

// Autorizar o rechazar un medicamento de control especial (RN-05).
// La API no tiene un listado de pendientes: se busca por número de dispensación.
export function AuthorizationsPage() {
  const user = useCurrentUser()
  const [params, setParams] = useSearchParams()
  const id = toInt(params.get('id') ?? '')
  const [idInput, setIdInput] = useState(params.get('id') ?? '')
  const [reason, setReason] = useState('')
  const dispensation = useDispensation(id)
  const authorize = useAuthorizeDispensation()
  const reject = useRejectDispensation()
  const guard = useSubmitGuard()

  function handleSearch(event: FormEvent) {
    event.preventDefault()
    authorize.reset()
    reject.reset()
    setParams(idInput.trim() ? { id: idInput.trim() } : {})
  }

  const d = dispensation.data
  const busy = authorize.isPending || reject.isPending
  const isPending = d?.status === 'PENDIENTE_AUTORIZACION'
  // Pista visual; la regla real (RN-05) la exigen la API y la BD.
  const isOwn = d?.created_by.id === user.id

  return (
    <>
      <h1>Autorizaciones de control especial</h1>
      <form className="card inline-form" onSubmit={handleSearch}>
        <label className="grow">
          Número de dispensación
          <input autoFocus inputMode="numeric" value={idInput} onChange={(e) => setIdInput(e.target.value)} placeholder="Número y Enter" />
        </label>
        <button type="submit" className="primary">
          Buscar
        </button>
      </form>

      {dispensation.isFetching && <Loading />}
      <ErrorAlert error={dispensation.error} action="consultar dispensaciones" />

      {d && (
        <>
          <DispensationSummary dispensation={d} />
          {isPending && isOwn && (
            <p className="alert alert-warning">Tú creaste esta dispensación: debe autorizarla otro regente.</p>
          )}
          {isPending && !isOwn && (
            <section className="card" aria-label="Decisión">
              <div className="actions">
                <button
                  type="button"
                  className="primary"
                  disabled={busy}
                  onClick={() => void guard(() => authorize.mutateAsync(d.id))}
                >
                  {authorize.isPending ? 'Autorizando…' : 'Autorizar y dispensar'}
                </button>
              </div>
              <label>
                Motivo del rechazo (mínimo 5 caracteres)
                <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
              </label>
              <button
                type="button"
                className="danger"
                disabled={busy || reason.trim().length < 5}
                onClick={() => void guard(() => reject.mutateAsync({ id: d.id, reason: reason.trim() }))}
              >
                {reject.isPending ? 'Rechazando…' : 'Rechazar'}
              </button>
            </section>
          )}
          <ErrorAlert error={authorize.error ?? reject.error} action="autorizar dispensaciones de control especial" />
        </>
      )}
    </>
  )
}
