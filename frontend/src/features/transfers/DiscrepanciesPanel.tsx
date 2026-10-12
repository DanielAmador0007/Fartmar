import { useState } from 'react'
import { Link } from 'react-router'
import type { DiscrepancyAction, TransferDiscrepancy } from '../../api/types'
import { useCan } from '../../auth/AuthContext'
import { Empty, ErrorAlert, Loading, StatusBadge } from '../../components/ui'
import { useSubmitGuard } from '../../lib/useSubmitGuard'
import { useDiscrepancies, useResolveDiscrepancy } from './transfersApi'

function ResolveForm({ discrepancy }: { discrepancy: TransferDiscrepancy }) {
  const [action, setAction] = useState<DiscrepancyAction>('REINTEGRAR_ORIGEN')
  const [resolution, setResolution] = useState('')
  const mutation = useResolveDiscrepancy()
  const guard = useSubmitGuard()

  // Ya resuelta: se retira el formulario de inmediato (sin esperar la recarga de
  // la lista) para que un segundo clic no reenvíe la resolución.
  if (mutation.isSuccess) {
    return <p className="muted">Discrepancia resuelta.</p>
  }

  return (
    <div className="inline-form">
      <label>
        Resolución
        <select value={action} onChange={(e) => setAction(e.target.value as DiscrepancyAction)}>
          <option value="REINTEGRAR_ORIGEN">Reintegrar al origen</option>
          <option value="PERDIDA">Pérdida</option>
        </select>
      </label>
      <label className="grow">
        Detalle (mínimo 5 caracteres)
        <input value={resolution} maxLength={1000} onChange={(e) => setResolution(e.target.value)} />
      </label>
      <button
        type="button"
        className="primary"
        disabled={mutation.isPending || resolution.trim().length < 5}
        onClick={() => void guard(() => mutation.mutateAsync({ id: discrepancy.id, action, resolution: resolution.trim() }))}
      >
        {mutation.isPending ? 'Resolviendo…' : 'Resolver'}
      </button>
      <div className="full">
        <ErrorAlert error={mutation.error} action="resolver discrepancias" />
      </div>
    </div>
  )
}

// Discrepancias (faltantes de recepción). Solo el regente ve el formulario de
// resolución; quien recibió no puede resolver (lo valida la API, S-60).
export function DiscrepanciesPanel({ transferId }: { transferId?: number }) {
  const canResolve = useCan('resolveDiscrepancy')
  const query = useDiscrepancies(transferId ? { transfer_id: transferId } : { status: 'PENDIENTE' })

  return (
    <section className="card" aria-label="Discrepancias">
      <h2>{transferId ? 'Discrepancias del traslado' : 'Discrepancias pendientes'}</h2>
      {query.isPending && <Loading />}
      <ErrorAlert error={query.error} action="ver discrepancias" />
      {query.data?.data.length === 0 && <Empty>No hay discrepancias.</Empty>}
      {query.data?.data.map((d) => (
        <div key={d.id} className="block">
          <p>
            <StatusBadge status={d.status} /> <Link to={`/traslados/${d.transfer_id}`}>Traslado #{d.transfer_id}</Link> · {d.product.name} · lote{' '}
            {d.lot.lot_number} · despachado {d.quantity_dispatched}, recibido {d.quantity_received},{' '}
            <strong>faltan {d.quantity_missing}</strong>
          </p>
          {d.status === 'RESUELTA' && (
            <p className="muted">
              {d.resolution_action === 'REINTEGRAR_ORIGEN' ? 'Reintegrado al origen' : 'Registrado como pérdida'} por {d.resolved_by?.name ?? '—'}:{' '}
              {d.resolution}
            </p>
          )}
          {d.status === 'PENDIENTE' && canResolve && <ResolveForm discrepancy={d} />}
        </div>
      ))}
    </section>
  )
}
