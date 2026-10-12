import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { KardexMovement } from '../../api/types'
import { mockApi } from '../../test/http'
import { acetaminofen, catalogRoutes, page, warehouse } from '../../test/fixtures'
import { makeUser, meRoute, renderApp } from '../../test/render'

function movement(id: number, overrides: Partial<KardexMovement>): KardexMovement {
  return {
    id,
    created_at: '2026-10-11T15:00:00Z',
    type: 'ENTRADA',
    direction: 1,
    quantity: 10,
    signed_quantity: 10,
    balance_after: 60,
    warehouse,
    product: acetaminofen,
    lot: { id: 2, lot_number: 'L-ACE-2402', expires_at: '2026-10-31' },
    reason: null,
    reference: null,
    user: { id: 3, name: 'Regente Demo', role: 'regente_farmacia' },
    ...overrides,
  }
}

const movements = [
  movement(2, { type: 'SALIDA_DISPENSACION', direction: -1, quantity: 5, signed_quantity: -5, balance_after: 55, reference: { type: 'dispensation', id: 77 } }),
  movement(1, { type: 'AJUSTE', direction: 1, quantity: 10, signed_quantity: 10, balance_after: 60, reason: 'Sobrante de conteo' }),
]

describe('Kardex', () => {
  it('muestra dirección con signo, saldo, usuario y referencia o motivo', async () => {
    mockApi({ ...meRoute(makeUser('auditor')), ...catalogRoutes, 'GET /kardex': page(movements) })
    renderApp('/kardex')

    const out = (await screen.findByText('SALIDA_DISPENSACION', { selector: 'td' })).closest('tr') as HTMLElement
    expect(within(out).getByText('−5')).toHaveClass('qty-out')
    expect(within(out).getByText('55')).toBeInTheDocument()
    expect(within(out).getByText('Dispensación #77')).toBeInTheDocument()

    const adj = screen.getByText('AJUSTE', { selector: 'td' }).closest('tr') as HTMLElement
    expect(within(adj).getByText('+10')).toHaveClass('qty-in')
    expect(within(adj).getByText('60')).toBeInTheDocument()
    expect(within(adj).getByText(/Sobrante de conteo/)).toBeInTheDocument()
    expect(within(adj).getByText('Regente Demo')).toBeInTheDocument()
  })

  it('aplica los filtros al pulsar Filtrar y no antes', async () => {
    const api = mockApi({ ...meRoute(makeUser('regente_farmacia')), ...catalogRoutes, 'GET /kardex': page([]) })
    renderApp('/kardex')
    expect(await screen.findByText('No hay movimientos con esos filtros.')).toBeInTheDocument()

    const form = within(screen.getByRole('form', { name: 'Filtros del kardex' }))
    await waitFor(() => expect(within(form.getByLabelText('Bodega')).getAllByRole('option')).toHaveLength(3))
    expect(form.getByLabelText('N.º documento')).toBeDisabled()
    await userEvent.selectOptions(form.getByLabelText('Bodega'), '1')
    await userEvent.selectOptions(form.getByLabelText('Producto'), '1')
    await userEvent.type(form.getByLabelText('Lote (id)'), '2')
    await userEvent.selectOptions(form.getByLabelText('Tipo'), 'SALIDA_TRASLADO')
    await userEvent.selectOptions(form.getByLabelText('Documento'), 'transfer')
    await userEvent.type(form.getByLabelText('N.º documento'), '5')
    await userEvent.type(form.getByLabelText('Desde'), '2026-10-01')
    await userEvent.type(form.getByLabelText('Hasta'), '2026-10-11')
    const callsBefore = api.callsTo('GET', '/kardex').length

    await userEvent.click(form.getByRole('button', { name: 'Filtrar' }))

    await waitFor(() => expect(api.callsTo('GET', '/kardex').length).toBe(callsBefore + 1))
    const query = api.callsTo('GET', '/kardex').at(-1)!.query
    expect(Object.fromEntries(query)).toEqual({
      warehouse_id: '1',
      product_id: '1',
      lot_id: '2',
      type: 'SALIDA_TRASLADO',
      reference_type: 'transfer',
      reference_id: '5',
      from: '2026-10-01',
      to: '2026-10-11',
      page: '1',
      per_page: '50',
    })
  })
})
