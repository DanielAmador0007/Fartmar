import { Link, useParams } from 'react-router'
import type { Transfer } from '../../api/types'
import { ErrorAlert, Loading, StatusBadge } from '../../components/ui'
import { formatDateTime, orDash, toInt } from '../../lib/format'
import { DiscrepanciesPanel } from './DiscrepanciesPanel'
import { TransferActions } from './TransferActions'
import { useTransfer } from './transfersApi'

function Actors({ transfer: t }: { transfer: Transfer }) {
  return (
    <ul className="actors">
      <li>Solicitó: {t.requested_by?.name ?? '—'}</li>
      <li>Aprobó: {t.approved_by?.name ?? '—'}</li>
      <li>Despachó: {t.dispatched_by?.name ?? '—'}</li>
      <li>Recibió: {t.received_by?.name ?? '—'}</li>
      {t.cancelled_by && (
        <li>
          Anuló: {t.cancelled_by.name} — {orDash(t.cancellation_reason)}
        </li>
      )}
    </ul>
  )
}

export function TransferDetailPage() {
  const id = toInt(useParams().id ?? '')
  const query = useTransfer(id)
  const t = query.data

  return (
    <>
      <p>
        <Link to="/traslados">← Traslados</Link>
      </p>
      {query.isPending && <Loading />}
      <ErrorAlert error={query.error} action="ver traslados" />
      {t && (
        <>
          <h1>
            Traslado #{t.id} <StatusBadge status={t.status} />
          </h1>
          <section className="card">
            <p>
              <strong>{t.origin_warehouse.name}</strong> → <strong>{t.destination_warehouse.name}</strong> · creado {formatDateTime(t.created_at)}
            </p>
            {/* Texto libre no confiable (S-58): React lo escapa. */}
            <p>Observaciones: {orDash(t.notes)}</p>
            <Actors transfer={t} />
            <table>
              <thead>
                <tr>
                  <th>Producto</th>
                  <th className="num">Solicitado</th>
                  <th>Lote</th>
                  <th>Vence</th>
                  <th className="num">Despachado</th>
                  <th className="num">Recibido</th>
                  <th>Discrepancia</th>
                </tr>
              </thead>
              <tbody>
                {(t.items ?? []).flatMap((item) =>
                  item.lots.length === 0
                    ? [
                        <tr key={`i${item.id}`}>
                          <td>{item.product.name}</td>
                          <td className="num">{item.quantity_requested}</td>
                          <td colSpan={5} className="muted">
                            Lotes se asignan al despachar (FEFO)
                          </td>
                        </tr>,
                      ]
                    : item.lots.map((lot, index) => (
                        <tr key={`l${lot.transfer_item_lot_id}`}>
                          <td>{index === 0 ? item.product.name : ''}</td>
                          <td className="num">{index === 0 ? item.quantity_requested : ''}</td>
                          <td>{lot.lot_number}</td>
                          <td>{lot.expires_at}</td>
                          <td className="num">{lot.quantity_dispatched}</td>
                          <td className="num">{orDash(lot.quantity_received)}</td>
                          <td>
                            {lot.discrepancy ? (
                              <>
                                <StatusBadge status={lot.discrepancy.status} /> faltan {lot.discrepancy.quantity_missing}
                              </>
                            ) : (
                              '—'
                            )}
                          </td>
                        </tr>
                      )),
                )}
              </tbody>
            </table>
          </section>
          <TransferActions key={`${t.id}-${t.status}`} transfer={t} />
          {t.status === 'RECIBIDO_PARCIAL' && <DiscrepanciesPanel transferId={t.id} />}
        </>
      )}
    </>
  )
}
