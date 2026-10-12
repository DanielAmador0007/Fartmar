import type { UseQueryResult } from '@tanstack/react-query'
import type { Dispensation, FefoPreview } from '../../api/types'
import { ErrorAlert, Loading, StatusBadge } from '../../components/ui'
import { formatDateTime, orDash } from '../../lib/format'

function LotsTable({ lots }: { lots: { lot_id: number; lot_number: string; expires_at: string; quantity: number }[] }) {
  return (
    <table className="compact">
      <thead>
        <tr>
          <th>Lote</th>
          <th>Vence</th>
          <th className="num">Cantidad</th>
        </tr>
      </thead>
      <tbody>
        {lots.map((lot) => (
          <tr key={lot.lot_id}>
            <td>{lot.lot_number}</td>
            <td>{lot.expires_at}</td>
            <td className="num">{lot.quantity}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

// Vista previa FEFO: qué lotes saldrían (calculado por el backend, no bloquea stock).
export function FefoPreviewList({ results, names }: { results: UseQueryResult<FefoPreview>[]; names: string[] }) {
  return (
    <section className="card" aria-label="Vista previa FEFO">
      <h3>Vista previa FEFO</h3>
      <p className="muted">Orden: vence primero, sale primero. Puede cambiar si otro usuario dispensa antes de confirmar.</p>
      {results.map((result, index) => (
        <div key={names[index]} className="block">
          <h4>{names[index]}</h4>
          {result.isPending && <Loading label="Calculando lotes…" />}
          <ErrorAlert error={result.error} action="ver la vista previa" />
          {result.data && (
            <>
              {result.data.requires_authorization && (
                <p className="alert alert-warning">Control especial: los lotes definitivos se asignan al autorizar.</p>
              )}
              <LotsTable lots={result.data.lots} />
            </>
          )}
        </div>
      ))}
    </section>
  )
}

export function DispensationSummary({ dispensation }: { dispensation: Dispensation }) {
  const pending = dispensation.status === 'PENDIENTE_AUTORIZACION'
  return (
    <section className="card" aria-label="Dispensación">
      <h2>
        Dispensación #{dispensation.id} <StatusBadge status={dispensation.status} />
      </h2>
      <p>
        Prescripción {dispensation.prescription.number} · {dispensation.warehouse.name} · creada por {dispensation.created_by.name} el{' '}
        {formatDateTime(dispensation.created_at)}
      </p>
      {pending && (
        <p className="alert alert-warning">
          Pendiente de autorización: contiene un medicamento de control especial. Un regente distinto de quien la creó debe autorizarla
          con el número #{dispensation.id}. Aún no se ha descontado stock.
        </p>
      )}
      {dispensation.authorized_by && (
        <p>
          Autorizada por {dispensation.authorized_by.name} el {formatDateTime(dispensation.authorized_at)}
        </p>
      )}
      {dispensation.status === 'RECHAZADA' && (
        <p className="alert alert-error">
          Rechazada por {dispensation.rejected_by?.name ?? '—'}: {orDash(dispensation.rejection_reason)}
        </p>
      )}
      {dispensation.items.map((item) => (
        <div key={item.id} className="block">
          <h4>
            {item.product.name} · {item.quantity} unidades
          </h4>
          {item.lots.length > 0 ? <LotsTable lots={item.lots} /> : <p className="muted">Sin lotes asignados todavía.</p>}
        </div>
      ))}
    </section>
  )
}
