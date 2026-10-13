import type { AssistantResult, UntrustedText } from '../../api/types'
import { StatusBadge } from '../../components/ui'

// Tabla compacta con las filas de la última herramienta que usó el asistente.
// Todo se pinta como texto (React escapa): ningún valor del servidor se
// interpreta como HTML, tampoco las observaciones de traslados.
export function AssistantResultTable({ result }: { result: AssistantResult }) {
  if (result.rows.length === 0) return null

  const shown = result.rows.length
  const total = result.count ?? shown

  return (
    <div className="assistant-result">
      {renderTable(result)}
      {result.truncated && (
        <p className="muted">
          Resultado recortado: se muestran {shown} de {total} filas. Afina la pregunta (bodega o producto) para ver el resto.
        </p>
      )}
    </div>
  )
}

function renderTable(result: AssistantResult) {
  switch (result.tool) {
    case 'list_warehouses':
      return (
        <table className="compact" aria-label="Bodegas">
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
            </tr>
          </thead>
          <tbody>
            {result.rows.map((w) => (
              <tr key={w.code}>
                <td>{w.code}</td>
                <td>{w.name}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )
    case 'get_stock':
      return (
        <table className="compact" aria-label="Existencias">
          <thead>
            <tr>
              <th>Bodega</th>
              <th>Producto</th>
              <th>Lote</th>
              <th>Vence</th>
              <th className="num">Cantidad</th>
            </tr>
          </thead>
          <tbody>
            {result.rows.map((r) => (
              <tr key={`${r.warehouse.code}-${r.product.id}-${r.lot_number}`} className={r.is_expired ? 'expiry-expired' : undefined}>
                <td>{r.warehouse.name}</td>
                <td>{r.product.name}</td>
                <td>{r.lot_number}</td>
                <td>
                  {r.expires_at}
                  {r.is_expired && <span className="badge badge-red">Vencido</span>}
                </td>
                <td className="num">{r.quantity}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )
    case 'get_expiring_lots':
    case 'get_expired_lots':
      return (
        <table className="compact" aria-label={result.tool === 'get_expired_lots' ? 'Lotes vencidos' : 'Lotes por vencer'}>
          <thead>
            <tr>
              <th>Bodega</th>
              <th>Producto</th>
              <th>Lote</th>
              <th>Vence</th>
              <th className="num">Días</th>
              <th className="num">Cantidad</th>
            </tr>
          </thead>
          <tbody>
            {result.rows.map((r) => (
              <tr key={`${r.warehouse.code}-${r.product.id}-${r.lot_number}`}>
                <td>{r.warehouse.name}</td>
                <td>{r.product.name}</td>
                <td>{r.lot_number}</td>
                <td>{r.expires_at}</td>
                <td className="num">{r.days_to_expire}</td>
                <td className="num">{r.quantity}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )
    case 'get_below_minimum':
      return (
        <table className="compact" aria-label="Productos bajo mínimo">
          <thead>
            <tr>
              <th>Bodega</th>
              <th>Producto</th>
              <th className="num">Mínimo</th>
              <th className="num">Disponible</th>
              <th className="num">Faltante</th>
            </tr>
          </thead>
          <tbody>
            {result.rows.map((r) => (
              <tr key={`${r.warehouse.code}-${r.product.id}`}>
                <td>{r.warehouse.name}</td>
                <td>{r.product.name}</td>
                <td className="num">{r.min_quantity}</td>
                <td className="num">{r.available}</td>
                <td className="num">{r.shortfall}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )
    case 'get_transfers':
      return (
        <table className="compact" aria-label="Traslados">
          <thead>
            <tr>
              <th>N.º</th>
              <th>Estado</th>
              <th>Origen → destino</th>
              <th>Productos</th>
              <th>Observación</th>
            </tr>
          </thead>
          <tbody>
            {result.rows.map((t) => (
              <tr key={t.id}>
                <td>#{t.id}</td>
                <td>
                  <StatusBadge status={t.status} />
                </td>
                <td>
                  {t.origin.name} → {t.destination.name}
                </td>
                <td>{t.items.map((i) => `${i.product.name} × ${i.quantity_requested}`).join(', ')}</td>
                <td>{t.notes ? <UntrustedNote note={t.notes} /> : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )
    default:
      return null // herramienta que esta versión de la interfaz no conoce
  }
}

// Observación escrita por un usuario: puede traer texto malicioso. Se marca y se muestra como texto plano.
function UntrustedNote({ note }: { note: UntrustedText }) {
  return (
    <span className="untrusted-note">
      <span className="untrusted-label">Observación (dato no confiable):</span> <span>{note.text}</span>
    </span>
  )
}
