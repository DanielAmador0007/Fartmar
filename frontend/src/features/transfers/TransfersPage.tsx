import { useState } from 'react'
import { Link } from 'react-router'
import type { TransferStatus } from '../../api/types'
import { useCan } from '../../auth/AuthContext'
import { Empty, ErrorAlert, Loading, Pagination, StatusBadge } from '../../components/ui'
import { formatDateTime } from '../../lib/format'
import { DiscrepanciesPanel } from './DiscrepanciesPanel'
import { TransferCreateForm } from './TransferCreateForm'
import { useTransfers } from './transfersApi'

const STATUSES: TransferStatus[] = ['BORRADOR', 'SOLICITADO', 'APROBADO', 'EN_TRANSITO', 'RECIBIDO', 'RECIBIDO_PARCIAL', 'ANULADO']

export function TransfersPage() {
  const canCreate = useCan('createTransfer')
  const [creating, setCreating] = useState(false)
  const [status, setStatus] = useState<TransferStatus | ''>('')
  const [page, setPage] = useState(1)
  const query = useTransfers({ status, warehouse_id: null, page })

  return (
    <>
      <h1>Traslados</h1>
      <div className="actions">
        <label>
          Estado
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as TransferStatus | '')
              setPage(1)
            }}
          >
            <option value="">Todos</option>
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </label>
        {canCreate && (
          <button type="button" onClick={() => setCreating((c) => !c)}>
            {creating ? 'Cerrar formulario' : 'Nuevo traslado'}
          </button>
        )}
      </div>

      {creating && <TransferCreateForm />}

      <section className="card">
        {query.isPending && <Loading />}
        <ErrorAlert error={query.error} action="ver traslados" />
        {query.data?.data.length === 0 && <Empty>No hay traslados con ese filtro.</Empty>}
        {query.data && query.data.data.length > 0 && (
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Estado</th>
                <th>Origen → Destino</th>
                <th className="num">Productos</th>
                <th>Solicitó</th>
                <th>Creado</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {query.data.data.map((t) => (
                <tr key={t.id}>
                  <td>{t.id}</td>
                  <td>
                    <StatusBadge status={t.status} />
                  </td>
                  <td>
                    {t.origin_warehouse.name} → {t.destination_warehouse.name}
                  </td>
                  <td className="num">{t.items_count ?? '—'}</td>
                  <td>{t.requested_by?.name ?? '—'}</td>
                  <td>{formatDateTime(t.created_at)}</td>
                  <td>
                    <Link to={`/traslados/${t.id}`}>Ver</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {query.data && <Pagination page={page} lastPage={query.data.meta.last_page} onChange={setPage} />}
      </section>

      <DiscrepanciesPanel />
    </>
  )
}
