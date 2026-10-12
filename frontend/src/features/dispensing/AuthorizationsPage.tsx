import { useState } from 'react'
import { useSearchParams } from 'react-router'
import type { Dispensation } from '../../api/types'
import { Empty, ErrorAlert, Loading, Pagination, SuccessAlert } from '../../components/ui'
import { formatDateTime, toInt } from '../../lib/format'
import { useCatalog } from '../catalog/useCatalog'
import { WarehouseSelect } from '../catalog/WarehouseSelect'
import { DecisionControls } from './DecisionControls'
import { useDispensation, useDispensations } from './dispensingApi'
import { DispensationSummary } from './DispensationView'

// Detalle completo (GET /dispensations/{id}, deja bitácora de acceso, S-75).
function DetailPanel({ id, onDone }: { id: number; onDone: (d: Dispensation) => void }) {
  const query = useDispensation(id)
  const d = query.data
  return (
    <>
      {query.isPending && <Loading />}
      <ErrorAlert error={query.error} action="consultar dispensaciones" />
      {d && (
        <>
          <DispensationSummary dispensation={d} />
          {d.status === 'PENDIENTE_AUTORIZACION' && (
            <section className="card" aria-label="Decisión">
              <DecisionControls dispensation={d} onDone={onDone} />
            </section>
          )}
        </>
      )}
    </>
  )
}

// Bandeja de controles especiales pendientes (GET /dispensations, S-87).
export function AuthorizationsPage() {
  const catalog = useCatalog()
  const [params, setParams] = useSearchParams()
  const selectedId = toInt(params.get('id') ?? '')
  const [warehouseId, setWarehouseId] = useState<number | null>(null)
  const [page, setPage] = useState(1)
  const [lastDone, setLastDone] = useState<Dispensation | null>(null)
  const list = useDispensations({ status: 'PENDIENTE_AUTORIZACION', warehouse_id: warehouseId, page })

  return (
    <>
      <h1>Autorizaciones de control especial</h1>
      <div className="actions">
        <WarehouseSelect
          label="Bodega"
          value={warehouseId}
          options={catalog.data?.warehouses ?? []}
          allowEmpty="Todas"
          onChange={(id) => {
            setWarehouseId(id)
            setPage(1)
          }}
        />
      </div>

      {lastDone && (
        <SuccessAlert>
          Dispensación #{lastDone.id} {lastDone.status === 'COMPLETADA' ? 'autorizada y entregada' : 'rechazada'}.
        </SuccessAlert>
      )}

      <section className="card" aria-label="Pendientes de autorización">
        <h2>Pendientes de autorización</h2>
        {list.isPending && <Loading />}
        <ErrorAlert error={list.error} action="ver dispensaciones pendientes" />
        {list.data?.data.length === 0 && <Empty>No hay dispensaciones pendientes de autorización.</Empty>}
        {list.data && list.data.data.length > 0 && (
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Bodega</th>
                <th>Prescripción</th>
                <th>Medicamentos</th>
                <th>Creada por</th>
                <th>Fecha</th>
                <th>Decisión</th>
              </tr>
            </thead>
            <tbody>
              {list.data.data.map((d) => (
                <tr key={d.id}>
                  <td>
                    <button type="button" className="link" onClick={() => setParams({ id: String(d.id) })}>
                      #{d.id}
                    </button>
                  </td>
                  <td>{d.warehouse.code}</td>
                  <td>{d.prescription.number}</td>
                  <td>{d.items.map((i) => `${i.product.name} × ${i.quantity}`).join(', ')}</td>
                  <td>{d.created_by.name}</td>
                  <td>{formatDateTime(d.created_at)}</td>
                  <td>
                    <DecisionControls dispensation={d} onDone={setLastDone} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {list.data && <Pagination page={page} lastPage={list.data.meta.last_page} onChange={setPage} />}
      </section>

      {selectedId !== null && <DetailPanel key={selectedId} id={selectedId} onDone={setLastDone} />}
    </>
  )
}
