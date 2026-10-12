import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Alerts } from '../../api/types'
import { mockApi, type MockReply } from '../../test/http'
import { acetaminofen, catalogRoutes, stock, warehouse } from '../../test/fixtures'
import { makeUser, meRoute, renderApp } from '../../test/render'
import { expiryLevel } from './expiry'

const page = <T,>(data: T[]) => ({ body: { data, meta: { current_page: 1, last_page: 1, per_page: 50, total: data.length } } })

const alerts: Alerts = {
  today: '2026-10-11',
  expiry_days: 90,
  limit: 100,
  expiring_lots_total: 1,
  low_stock_total: 1,
  expiring_lots: [
    {
      stock_id: 1,
      warehouse,
      product: acetaminofen,
      lot: { id: 1, lot_number: 'L-VENC', expires_at: '2026-10-01' },
      quantity: 30,
      days_to_expire: -10,
      is_expired: true,
    },
  ],
  low_stock: [{ warehouse, product: acetaminofen, min_quantity: 500, available: 80, shortfall: 420 }],
}

const stocks = [
  stock(1, { lot: { id: 1, lot_number: 'L-VENC', expires_at: '2026-10-01', is_expired: true } }),
  stock(2, { lot: { id: 2, lot_number: 'L-30', expires_at: '2026-10-31', is_expired: false } }),
  stock(3, { lot: { id: 3, lot_number: 'L-90', expires_at: '2026-12-20', is_expired: false } }),
  stock(4, { lot: { id: 4, lot_number: 'L-LEJOS', expires_at: '2027-12-01', is_expired: false } }),
]

function routes(role: 'auditor' | 'regente_farmacia', extra: Record<string, MockReply | (() => MockReply)> = {}) {
  return {
    ...meRoute(makeUser(role)),
    ...catalogRoutes,
    'GET /stocks': page(stocks),
    'GET /alerts': { body: { data: alerts } },
    'GET /stocks/in-transit': page([]),
    ...extra,
  }
}

describe('expiryLevel', () => {
  it('clasifica vencido, ≤30, ≤90 y lejano', () => {
    expect(expiryLevel('2026-10-10', '2026-10-11')).toBe('expired')
    expect(expiryLevel('2026-10-11', '2026-10-11')).toBe('within30')
    expect(expiryLevel('2026-11-10', '2026-10-11')).toBe('within30')
    expect(expiryLevel('2026-11-11', '2026-10-11')).toBe('withinAlert')
    expect(expiryLevel('2027-01-10', '2026-10-11')).toBe('ok')
  })

  it('la ventana de alerta es configurable (alerts.expiry_days)', () => {
    expect(expiryLevel('2026-12-20', '2026-10-11', false, 60)).toBe('ok')
    expect(expiryLevel('2026-12-20', '2026-10-11', false, 120)).toBe('withinAlert')
  })
})

describe('Inventario', () => {
  it('resalta vencidos, ≤30 y ≤90 días, bajo mínimo y muestra alertas', async () => {
    mockApi(routes('auditor'))
    renderApp('/inventario')

    const table = within(await screen.findByRole('region', { name: 'Existencias' }))
    await waitFor(() => expect(table.getByText('L-VENC').closest('tr')).toHaveClass('expiry-expired'))
    expect(table.getByText('L-30').closest('tr')).toHaveClass('expiry-within30')
    expect(table.getByText('L-90').closest('tr')).toHaveClass('expiry-withinAlert')
    expect(table.getByText('L-LEJOS').closest('tr')).toHaveClass('expiry-ok')
    expect(table.getAllByText('Bajo mínimo')).toHaveLength(4)

    const panel = within(await screen.findByRole('region', { name: 'Alertas' }))
    expect(panel.getByText(/vencido hace 10 días/)).toBeInTheDocument()
    expect(panel.getByText(/disponible 80 de mínimo 500/)).toBeInTheDocument()
    expect(screen.getByText('No hay unidades en tránsito.')).toBeInTheDocument()
  })

  it('los filtros usan el catálogo de GET /warehouses y GET /products, no /stocks', async () => {
    const api = mockApi(routes('auditor'))
    renderApp('/inventario')

    const select = await screen.findByLabelText('Bodega')
    await waitFor(() => expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual([
      'Todas',
      'Farmacia Central (FAR-CEN)',
      'Farmacia Urgencias (FAR-URG)',
    ]))
    expect(within(screen.getByLabelText('Producto')).getAllByRole('option')).toHaveLength(3)
    expect(api.callsTo('GET', '/warehouses')[0].query.get('per_page')).toBe('100')
    expect(api.callsTo('GET', '/stocks').every((c) => !c.query.has('include_empty'))).toBe(true)
  })

  it('con expiry_days = 60 de la API, la leyenda y el resaltado usan 60 días', async () => {
    mockApi(routes('auditor', { 'GET /alerts': { body: { data: { ...alerts, expiry_days: 60 } } } }))
    renderApp('/inventario')

    const table = within(await screen.findByRole('region', { name: 'Existencias' }))
    // L-90 vence en 70 días: fuera de una ventana de 60.
    await waitFor(() => expect(table.getByText('L-90').closest('tr')).toHaveClass('expiry-ok'))
    expect(screen.getByText('≤ 60 días', { selector: '.legend span' })).toBeInTheDocument()
    expect(screen.queryByText('≤ 90 días', { selector: '.legend span' })).not.toBeInTheDocument()
  })

  it('el auditor no ve el formulario de ajuste', async () => {
    mockApi(routes('auditor'))
    renderApp('/inventario')
    await screen.findByText('L-VENC')

    expect(screen.queryByRole('form', { name: 'Ajuste de inventario' })).not.toBeInTheDocument()
  })

  it('el regente registra un ajuste con motivo y un doble clic envía una sola petición', async () => {
    const api = mockApi(
      routes('regente_farmacia', {
        'POST /inventory-adjustments': { status: 201, body: { data: { id: 1, balance_after: 25 } } },
      }),
    )
    renderApp('/inventario')

    const form = within(await screen.findByRole('form', { name: 'Ajuste de inventario' }))
    await userEvent.selectOptions(form.getByLabelText(/Existencia/), '1')
    await userEvent.type(form.getByLabelText(/Cantidad/), '-5')
    const submit = form.getByRole('button', { name: 'Registrar ajuste' })
    expect(submit).toBeDisabled() // falta el motivo
    await userEvent.type(form.getByLabelText(/Motivo/), 'Baja por vencimiento')
    await userEvent.dblClick(submit)

    expect(await screen.findByText(/Nuevo saldo del lote: 25/)).toBeInTheDocument()
    expect(api.callsTo('POST', '/inventory-adjustments')).toHaveLength(1)
    expect(api.callsTo('POST', '/inventory-adjustments')[0].body).toEqual({
      warehouse_id: 1,
      lot_id: 1,
      quantity: -5,
      reason: 'Baja por vencimiento',
    })
  })
})
