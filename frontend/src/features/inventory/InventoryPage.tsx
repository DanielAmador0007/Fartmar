import { useState } from 'react'
import type { Alerts, InTransitStock } from '../../api/types'
import { useCan } from '../../auth/AuthContext'
import { Empty, ErrorAlert, Loading, Pagination } from '../../components/ui'
import { formatDateTime } from '../../lib/format'
import { useCatalog } from '../catalog/useCatalog'
import { ProductSelect, WarehouseSelect } from '../catalog/WarehouseSelect'
import { AdjustmentForm } from './AdjustmentForm'
import { CRITICAL_DAYS, DEFAULT_ALERT_DAYS, expiryLabel, expiryLevel, todayInBogota } from './expiry'
import { useAlerts, useInTransit, useStocks } from './inventoryApi'

function AlertsPanel({ alerts }: { alerts: Alerts }) {
  return (
    <section className="card" aria-label="Alertas">
      <h2>Alertas</h2>
      <h3>
        Lotes vencidos o por vencer (≤ {alerts.expiry_days} días): {alerts.expiring_lots_total}
      </h3>
      {alerts.expiring_lots.length === 0 ? (
        <Empty>Sin lotes por vencer.</Empty>
      ) : (
        <ul className="alert-list">
          {alerts.expiring_lots.map((a) => (
            <li key={a.stock_id} className={`expiry-${expiryLevel(a.lot.expires_at, alerts.today, a.is_expired, alerts.expiry_days)}`}>
              {a.warehouse.code} · {a.product.name} · lote {a.lot.lot_number} · {a.quantity} u ·{' '}
              {a.is_expired ? `vencido hace ${-a.days_to_expire} días` : a.days_to_expire === 0 ? 'vence hoy' : `vence en ${a.days_to_expire} días`}
            </li>
          ))}
        </ul>
      )}
      <h3>Productos bajo stock mínimo: {alerts.low_stock_total}</h3>
      {alerts.low_stock.length === 0 ? (
        <Empty>Ningún producto bajo el mínimo.</Empty>
      ) : (
        <ul className="alert-list">
          {alerts.low_stock.map((a) => (
            <li key={`${a.warehouse.id}-${a.product.id}`} className="low-stock">
              {a.warehouse.code} · {a.product.name}: disponible {a.available} de mínimo {a.min_quantity} (faltan {a.shortfall})
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function InTransitTable({ rows }: { rows: InTransitStock[] }) {
  if (rows.length === 0) return <Empty>No hay unidades en tránsito.</Empty>
  return (
    <table>
      <thead>
        <tr>
          <th>Traslado</th>
          <th>Origen → Destino</th>
          <th>Producto</th>
          <th>Lote</th>
          <th className="num">Cantidad</th>
          <th>Despachado</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.transfer_item_lot_id}>
            <td>#{r.transfer_id}</td>
            <td>
              {r.origin_warehouse.code} → {r.destination_warehouse.code}
            </td>
            <td>{r.product.name}</td>
            <td>
              {r.lot.lot_number} ({r.lot.expires_at})
            </td>
            <td className="num">{r.quantity}</td>
            <td>{formatDateTime(r.dispatched_at)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

export function InventoryPage() {
  const canAdjust = useCan('adjustInventory')
  const catalog = useCatalog()
  const [warehouseId, setWarehouseId] = useState<number | null>(null)
  const [productId, setProductId] = useState<number | null>(null)
  const [page, setPage] = useState(1)
  const stocks = useStocks({ warehouse_id: warehouseId, product_id: productId, page })
  const alerts = useAlerts(warehouseId)
  const inTransit = useInTransit(warehouseId)

  const today = alerts.data?.today ?? todayInBogota()
  // Ventana de alerta configurable en el backend (ALERT_EXPIRY_DAYS, RN-11).
  const alertDays = alerts.data?.expiry_days ?? DEFAULT_ALERT_DAYS
  const lowStock = new Set((alerts.data?.low_stock ?? []).map((a) => `${a.warehouse.id}-${a.product.id}`))

  return (
    <>
      <h1>Inventario</h1>
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
        <ProductSelect
          label="Producto"
          value={productId}
          options={catalog.data?.products ?? []}
          allowEmpty="Todos"
          onChange={(id) => {
            setProductId(id)
            setPage(1)
          }}
        />
      </div>

      <div className="legend">
        <span className="expiry-expired">Vencido</span>
        <span className="expiry-within30">≤ {CRITICAL_DAYS} días</span>
        <span className="expiry-withinAlert">≤ {alertDays} días</span>
        <span className="low-stock">Bajo mínimo</span>
      </div>

      <section className="card" aria-label="Existencias">
        <h2>Existencias</h2>
        {stocks.isPending && <Loading />}
        <ErrorAlert error={stocks.error} action="consultar el inventario" />
        {stocks.data?.data.length === 0 && <Empty>No hay existencias con ese filtro.</Empty>}
        {stocks.data && stocks.data.data.length > 0 && (
          <table>
            <thead>
              <tr>
                <th>Bodega</th>
                <th>Producto</th>
                <th>Lote</th>
                <th>Vence</th>
                <th className="num">Cantidad</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              {stocks.data.data.map((s) => {
                const level = expiryLevel(s.lot.expires_at, today, s.lot.is_expired, alertDays)
                const isLow = lowStock.has(`${s.warehouse.id}-${s.product.id}`)
                return (
                  <tr key={s.id} className={`expiry-${level}`}>
                    <td>{s.warehouse.name}</td>
                    <td>
                      {s.product.name} {s.product.is_controlled && <span className="badge badge-red">Control</span>}
                    </td>
                    <td>{s.lot.lot_number}</td>
                    <td>{s.lot.expires_at}</td>
                    <td className="num">{s.quantity}</td>
                    <td>
                      {expiryLabel(level, alertDays)}
                      {isLow && <span className="badge badge-orange">Bajo mínimo</span>}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
        {stocks.data && <Pagination page={page} lastPage={stocks.data.meta.last_page} onChange={setPage} />}
      </section>

      {alerts.isPending && <Loading label="Cargando alertas…" />}
      <ErrorAlert error={alerts.error} action="ver alertas" />
      {alerts.data && <AlertsPanel alerts={alerts.data} />}

      <section className="card" aria-label="En tránsito">
        <h2>Stock en tránsito</h2>
        {inTransit.isPending && <Loading />}
        <ErrorAlert error={inTransit.error} action="ver el stock en tránsito" />
        {inTransit.data && <InTransitTable rows={inTransit.data.data} />}
      </section>

      {canAdjust && stocks.data && <AdjustmentForm stocks={stocks.data.data} />}
    </>
  )
}
