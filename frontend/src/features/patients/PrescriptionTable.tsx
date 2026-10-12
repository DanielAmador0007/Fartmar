import type { Prescription } from '../../api/types'
import { StatusBadge } from '../../components/ui'
import { orDash } from '../../lib/format'

// Líneas de una prescripción con prescrito / entregado / reservado / disponible.
export function PrescriptionLines({ prescription }: { prescription: Prescription }) {
  return (
    <table>
      <thead>
        <tr>
          <th>Medicamento</th>
          <th className="num">Prescrito</th>
          <th className="num">Entregado</th>
          <th className="num">Reservado</th>
          <th className="num">Disponible</th>
          <th>Indicaciones</th>
        </tr>
      </thead>
      <tbody>
        {prescription.items.map((item) => (
          <tr key={item.id}>
            <td>
              {item.product.name} {item.product.is_controlled && <span className="badge badge-red">Control especial</span>}
            </td>
            <td className="num">{item.quantity_prescribed}</td>
            <td className="num">{item.quantity_dispensed}</td>
            <td className="num">{item.quantity_reserved}</td>
            <td className="num">{item.quantity_available}</td>
            <td>{orDash(item.instructions)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

export function PrescriptionHeader({ prescription }: { prescription: Prescription }) {
  return (
    <p>
      <strong>{prescription.number}</strong> <StatusBadge status={prescription.status} />{' '}
      {prescription.is_current ? <span className="badge badge-green">Vigente</span> : <span className="badge badge-gray">No vigente</span>}{' '}
      · vence {prescription.valid_until} · {prescription.prescriber?.name ?? '—'}
    </p>
  )
}
