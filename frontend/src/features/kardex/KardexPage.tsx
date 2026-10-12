import { useQuery } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { apiRequest } from '../../api/client'
import type { KardexMovement, KardexType, Paginated, ReferenceType } from '../../api/types'
import { Empty, ErrorAlert, Loading, Pagination } from '../../components/ui'
import { formatDateTime, orDash, toInt } from '../../lib/format'
import { useCatalog } from '../catalog/useCatalog'
import { ProductSelect, WarehouseSelect } from '../catalog/WarehouseSelect'

const TYPES: KardexType[] = ['ENTRADA', 'SALIDA_DISPENSACION', 'SALIDA_TRASLADO', 'ENTRADA_TRASLADO', 'AJUSTE']
const REFERENCE_LABELS: Record<ReferenceType, string> = {
  dispensation: 'Dispensación',
  transfer: 'Traslado',
  transfer_discrepancy: 'Discrepancia',
}

interface Filters {
  warehouse_id: number | null
  product_id: number | null
  lot_id: string
  type: KardexType | ''
  reference_type: ReferenceType | ''
  reference_id: string
  from: string
  to: string
}

const EMPTY: Filters = { warehouse_id: null, product_id: null, lot_id: '', type: '', reference_type: '', reference_id: '', from: '', to: '' }

function useKardex(filters: Filters, page: number) {
  return useQuery({
    queryKey: ['kardex', filters, page],
    queryFn: () =>
      apiRequest<Paginated<KardexMovement>>('/kardex', {
        query: {
          ...filters,
          lot_id: toInt(filters.lot_id),
          reference_id: filters.reference_type ? toInt(filters.reference_id) : null,
          page,
          per_page: 50,
        },
      }),
  })
}

export function KardexPage() {
  const catalog = useCatalog()
  const [draft, setDraft] = useState<Filters>(EMPTY)
  const [applied, setApplied] = useState<Filters>(EMPTY)
  const [page, setPage] = useState(1)
  const query = useKardex(applied, page)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setApplied(draft)
    setPage(1)
  }

  const set = <K extends keyof Filters>(key: K, value: Filters[K]) => setDraft((d) => ({ ...d, [key]: value }))

  return (
    <>
      <h1>Kardex</h1>
      <form className="card filters" onSubmit={handleSubmit} aria-label="Filtros del kardex">
        <WarehouseSelect label="Bodega" value={draft.warehouse_id} options={catalog.data?.warehouses ?? []} allowEmpty="Todas" onChange={(v) => set('warehouse_id', v)} />
        <ProductSelect label="Producto" value={draft.product_id} options={catalog.data?.products ?? []} allowEmpty="Todos" onChange={(v) => set('product_id', v)} />
        <label>
          Lote (id)
          <input inputMode="numeric" value={draft.lot_id} onChange={(e) => set('lot_id', e.target.value)} />
        </label>
        <label>
          Tipo
          <select value={draft.type} onChange={(e) => set('type', e.target.value as KardexType | '')}>
            <option value="">Todos</option>
            {TYPES.map((t) => (
              <option key={t}>{t}</option>
            ))}
          </select>
        </label>
        <label>
          Documento
          <select value={draft.reference_type} onChange={(e) => set('reference_type', e.target.value as ReferenceType | '')}>
            <option value="">Cualquiera</option>
            {Object.entries(REFERENCE_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <label>
          N.º documento
          <input inputMode="numeric" disabled={!draft.reference_type} value={draft.reference_id} onChange={(e) => set('reference_id', e.target.value)} />
        </label>
        <label>
          Desde
          <input type="date" value={draft.from} onChange={(e) => set('from', e.target.value)} />
        </label>
        <label>
          Hasta
          <input type="date" value={draft.to} onChange={(e) => set('to', e.target.value)} />
        </label>
        <button type="submit" className="primary">
          Filtrar
        </button>
      </form>

      <section className="card">
        {query.isPending && <Loading />}
        <ErrorAlert error={query.error} action="consultar el kardex" />
        {query.data?.data.length === 0 && <Empty>No hay movimientos con esos filtros.</Empty>}
        {query.data && query.data.data.length > 0 && (
          <table>
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Bodega</th>
                <th>Producto</th>
                <th>Lote</th>
                <th className="num">Cantidad</th>
                <th className="num">Saldo</th>
                <th>Usuario</th>
                <th>Referencia / motivo</th>
              </tr>
            </thead>
            <tbody>
              {query.data.data.map((m) => (
                <tr key={m.id}>
                  <td>{formatDateTime(m.created_at)}</td>
                  <td>{m.type}</td>
                  <td>{m.warehouse.code}</td>
                  <td>{m.product.name}</td>
                  <td>{m.lot.lot_number}</td>
                  <td className={`num ${m.direction > 0 ? 'qty-in' : 'qty-out'}`}>
                    {m.direction > 0 ? '+' : '−'}
                    {m.quantity}
                  </td>
                  <td className="num">{m.balance_after}</td>
                  <td>{m.user?.name ?? '—'}</td>
                  <td>
                    {m.reference ? `${REFERENCE_LABELS[m.reference.type]} #${m.reference.id}` : ''}
                    {m.reason ? ` ${orDash(m.reason)}` : ''}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {query.data && <Pagination page={page} lastPage={query.data.meta.last_page} onChange={setPage} />}
      </section>
    </>
  )
}
