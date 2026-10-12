import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Role, TransferAction } from '../../api/types'
import { apiError, mockApi } from '../../test/http'
import { acetaminofen, transfer } from '../../test/fixtures'
import { makeUser, meRoute, renderApp } from '../../test/render'

const BUTTONS: Record<Exclude<TransferAction, 'receive'>, string> = {
  submit: 'Solicitar',
  approve: 'Aprobar',
  dispatch: 'Despachar',
  cancel: 'Anular',
}

const emptyDiscrepancies = { 'GET /transfer-discrepancies': { body: { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } } } }

function renderDetail(role: Role, allowed: TransferAction[], overrides = {}) {
  const api = mockApi({
    ...meRoute(makeUser(role)),
    ...emptyDiscrepancies,
    'GET /transfers/5': { body: { data: transfer({ allowed_actions: allowed, ...overrides }) } },
  })
  renderApp('/traslados/5')
  return api
}

describe('Acciones de traslado según allowed_actions', () => {
  it.each<[Role, TransferAction[]]>([
    ['regente_farmacia', ['approve', 'cancel']],
    ['auxiliar_farmacia', ['submit', 'cancel']],
    ['auxiliar_farmacia', ['dispatch']],
  ])('%s con %j ve exactamente esos botones', async (role, allowed) => {
    renderDetail(role, allowed)
    await screen.findByRole('heading', { name: /Traslado #5/ })

    for (const [action, label] of Object.entries(BUTTONS)) {
      const button = screen.queryByRole('button', { name: label })
      if (allowed.includes(action as TransferAction)) expect(button).toBeInTheDocument()
      else expect(button).not.toBeInTheDocument()
    }
  })

  it('sin acciones permitidas (p. ej. auditor) no muestra botones', async () => {
    renderDetail('auditor', [])
    expect(await screen.findByText('No hay acciones disponibles para ti en este estado.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Aprobar' })).not.toBeInTheDocument()
  })

  it('aprobar llama al endpoint y muestra el nuevo estado', async () => {
    let current = transfer({ allowed_actions: ['approve'] })
    const api = mockApi({
      ...meRoute(makeUser('regente_farmacia')),
      ...emptyDiscrepancies,
      'GET /transfers/5': () => ({ body: { data: current } }),
      'POST /transfers/5/approve': () => {
        current = transfer({ status: 'APROBADO', allowed_actions: ['dispatch'] })
        return { body: { data: current } }
      },
    })
    renderApp('/traslados/5')

    await userEvent.click(await screen.findByRole('button', { name: 'Aprobar' }))
    expect(api.callsTo('POST', '/transfers/5/approve')).toHaveLength(1)

    expect(await screen.findByText('Aprobado', { selector: '.badge' })).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Despachar' })).toBeInTheDocument()
  })

  it('SEGREGACION_FUNCIONES al aprobar se explica en español', async () => {
    mockApi({
      ...meRoute(makeUser('regente_farmacia')),
      ...emptyDiscrepancies,
      'GET /transfers/5': { body: { data: transfer({ allowed_actions: ['approve'] }) } },
      'POST /transfers/5/approve': apiError(403, 'SEGREGACION_FUNCIONES', 'x', { reason: 'MISMO_USUARIO' }),
    })
    renderApp('/traslados/5')

    await userEvent.click(await screen.findByRole('button', { name: 'Aprobar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('usuario distinto de quien creó o solicitó')
  })

  it('anular exige motivo de al menos 5 caracteres', async () => {
    renderDetail('auxiliar_farmacia', ['cancel'])
    const button = await screen.findByRole('button', { name: 'Anular' })
    expect(button).toBeDisabled()

    await userEvent.type(screen.getByLabelText(/Motivo de anulación/), 'Ya no se necesita')
    expect(button).toBeEnabled()
  })

  it('recepción envía la cantidad recibida de cada lote y avisa del faltante', async () => {
    const inTransit = transfer({
      status: 'EN_TRANSITO',
      allowed_actions: ['receive'],
      items: [
        {
          id: 1,
          product: acetaminofen,
          quantity_requested: 10,
          lots: [
            { transfer_item_lot_id: 31, lot_id: 2, lot_number: 'L-ACE-2402', expires_at: '2026-10-31', quantity_dispatched: 6, quantity_received: null, discrepancy: null },
            { transfer_item_lot_id: 32, lot_id: 3, lot_number: 'L-ACE-2403', expires_at: '2027-08-04', quantity_dispatched: 4, quantity_received: null, discrepancy: null },
          ],
        },
      ],
    })
    const api = mockApi({
      ...meRoute(makeUser('auxiliar_farmacia')),
      ...emptyDiscrepancies,
      'GET /transfers/5': { body: { data: inTransit } },
      'POST /transfers/5/receive': { body: { data: { ...inTransit, status: 'RECIBIDO_PARCIAL', allowed_actions: [] } } },
    })
    renderApp('/traslados/5')

    const lot2 = await screen.findByLabelText('Recibido del lote L-ACE-2403')
    await userEvent.clear(lot2)
    await userEvent.type(lot2, '3')
    expect(screen.getByText(/Faltan 1 unidades/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Registrar recepción' }))

    await waitFor(() => expect(api.callsTo('POST', '/transfers/5/receive')).toHaveLength(1))
    expect(api.callsTo('POST', '/transfers/5/receive')[0].body).toEqual({
      lots: [
        { transfer_item_lot_id: 31, quantity_received: 6 },
        { transfer_item_lot_id: 32, quantity_received: 3 },
      ],
    })
  })
})

describe('Lista de traslados', () => {
  it('el auditor no ve "Nuevo traslado"; el auxiliar sí', async () => {
    const list = { body: { data: [transfer({ items_count: 1 })], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } }
    mockApi({ ...meRoute(makeUser('auditor')), ...emptyDiscrepancies, 'GET /transfers': list })
    const { unmount } = renderApp('/traslados')
    expect(await screen.findByText('Farmacia Central → Farmacia Urgencias')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nuevo traslado' })).not.toBeInTheDocument()
    unmount()

    mockApi({ ...meRoute(makeUser('auxiliar_farmacia')), ...emptyDiscrepancies, 'GET /transfers': list })
    renderApp('/traslados')
    expect(await screen.findByRole('button', { name: 'Nuevo traslado' })).toBeInTheDocument()
  })
})
