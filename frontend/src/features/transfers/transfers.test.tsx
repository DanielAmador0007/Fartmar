import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Role, TransferAction, TransferDiscrepancy } from '../../api/types'
import { apiError, mockApi } from '../../test/http'
import { acetaminofen, catalogRoutes, page, transfer } from '../../test/fixtures'
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

  it('tras un 409 TRANSICION_INVALIDA el mensaje sigue visible aunque el detalle se recargue con otro estado', async () => {
    let current = transfer({ allowed_actions: ['approve'] })
    const api = mockApi({
      ...meRoute(makeUser('regente_farmacia')),
      ...emptyDiscrepancies,
      'GET /transfers/5': () => ({ body: { data: current } }),
      'POST /transfers/5/approve': () => {
        // Otro regente lo aprobó primero.
        current = transfer({ status: 'APROBADO', allowed_actions: [] })
        return apiError(409, 'TRANSICION_INVALIDA', 'x', { from: 'APROBADO', to: 'APROBADO' })
      },
    })
    renderApp('/traslados/5')

    await userEvent.click(await screen.findByRole('button', { name: 'Aprobar' }))

    expect(await screen.findByText('Aprobado', { selector: '.badge' })).toBeInTheDocument()
    await waitFor(() => expect(api.callsTo('GET', '/transfers/5').length).toBeGreaterThanOrEqual(2))
    expect(screen.getByRole('alert')).toHaveTextContent('Esta acción ya no es válida en el estado actual')
    expect(screen.queryByRole('button', { name: 'Aprobar' })).not.toBeInTheDocument()
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

describe('Crear traslado', () => {
  async function openForm() {
    renderApp('/traslados')
    await userEvent.click(await screen.findByRole('button', { name: 'Nuevo traslado' }))
    const form = within(await screen.findByRole('form', { name: 'Nuevo traslado' }))
    await waitFor(() => expect(within(form.getByLabelText('Origen')).getAllByRole('option')).toHaveLength(3))
    return form
  }

  const listRoutes = {
    ...meRoute(makeUser('auxiliar_farmacia')),
    ...catalogRoutes,
    ...emptyDiscrepancies,
    'GET /transfers': page([]),
  }

  it('crea el borrador con varias líneas y abre su detalle', async () => {
    const created = transfer({ id: 9, status: 'BORRADOR', allowed_actions: ['submit', 'cancel'] })
    const api = mockApi({
      ...listRoutes,
      'POST /transfers': { status: 201, body: { data: created } },
      'GET /transfers/9': { body: { data: created } },
    })
    const form = await openForm()

    await userEvent.selectOptions(form.getByLabelText('Origen'), '1')
    await userEvent.selectOptions(form.getByLabelText('Destino'), '2')
    await userEvent.selectOptions(form.getByLabelText('Producto 1'), '1')
    await userEvent.type(form.getAllByLabelText('Cantidad')[0], '10')
    await userEvent.click(form.getByRole('button', { name: 'Agregar producto' }))
    await userEvent.selectOptions(form.getByLabelText('Producto 2'), '6')
    await userEvent.type(form.getAllByLabelText('Cantidad')[1], '2')
    await userEvent.type(form.getByLabelText('Observaciones'), 'Reposición semanal')
    await userEvent.click(form.getByRole('button', { name: 'Crear borrador' }))

    expect(await screen.findByRole('heading', { name: /Traslado #9/ })).toBeInTheDocument()
    expect(api.callsTo('POST', '/transfers')).toHaveLength(1)
    expect(api.callsTo('POST', '/transfers')[0].body).toEqual({
      origin_warehouse_id: 1,
      destination_warehouse_id: 2,
      notes: 'Reposición semanal',
      items: [
        { product_id: 1, quantity: 10 },
        { product_id: 6, quantity: 2 },
      ],
    })
  })

  it('no permite enviar con origen igual a destino', async () => {
    const api = mockApi(listRoutes)
    const form = await openForm()

    await userEvent.selectOptions(form.getByLabelText('Origen'), '1')
    await userEvent.selectOptions(form.getByLabelText('Destino'), '1')
    await userEvent.selectOptions(form.getByLabelText('Producto 1'), '1')
    await userEvent.type(form.getByLabelText('Cantidad'), '3')

    expect(form.getByText('El origen y el destino deben ser distintos.')).toBeInTheDocument()
    expect(form.getByRole('button', { name: 'Crear borrador' })).toBeDisabled()
    expect(api.callsTo('POST', '/transfers')).toHaveLength(0)
  })

  it('muestra el mensaje específico de TRASLADO_INVALIDO (PRODUCTO_REPETIDO)', async () => {
    mockApi({
      ...listRoutes,
      'POST /transfers': apiError(422, 'TRASLADO_INVALIDO', 'Hay productos repetidos en el traslado.', { reason: 'PRODUCTO_REPETIDO' }),
    })
    const form = await openForm()
    await userEvent.selectOptions(form.getByLabelText('Origen'), '1')
    await userEvent.selectOptions(form.getByLabelText('Destino'), '2')
    await userEvent.selectOptions(form.getByLabelText('Producto 1'), '1')
    await userEvent.type(form.getByLabelText('Cantidad'), '3')
    await userEvent.click(form.getByRole('button', { name: 'Crear borrador' }))

    expect(await form.findByRole('alert')).toHaveTextContent('Hay productos repetidos en el traslado.')
  })
})

describe('Resolver discrepancias', () => {
  const discrepancy: TransferDiscrepancy = {
    id: 4,
    status: 'PENDIENTE',
    transfer_id: 5,
    transfer_status: 'RECIBIDO_PARCIAL',
    origin_warehouse_id: 1,
    destination_warehouse_id: 2,
    transfer_item_lot_id: 32,
    product: acetaminofen,
    lot: { id: 3, lot_number: 'L-ACE-2403', expires_at: '2027-08-04' },
    quantity_dispatched: 4,
    quantity_received: 3,
    quantity_missing: 1,
    resolution_action: null,
    resolution: null,
    resolved_by: null,
    resolved_at: null,
    created_at: '2026-10-11T16:00:00Z',
  }

  it('el regente resuelve como pérdida con detalle obligatorio y un doble clic envía una vez', async () => {
    let resolved = false
    const api = mockApi({
      ...meRoute(makeUser('regente_farmacia')),
      'GET /transfers': page([]),
      'GET /transfer-discrepancies': () => page(resolved ? [] : [discrepancy]),
      'POST /transfer-discrepancies/4/resolve': () => {
        resolved = true
        return { body: { data: { ...discrepancy, status: 'RESUELTA', resolution_action: 'PERDIDA' } } }
      },
    })
    renderApp('/traslados')

    const panel = within(await screen.findByRole('region', { name: 'Discrepancias' }))
    expect(await panel.findByText(/faltan 1/)).toBeInTheDocument()
    const resolve = panel.getByRole('button', { name: 'Resolver' })
    expect(resolve).toBeDisabled()
    await userEvent.selectOptions(panel.getByLabelText('Resolución'), 'PERDIDA')
    await userEvent.type(panel.getByLabelText(/Detalle/), 'Unidad rota en el transporte')
    await userEvent.dblClick(resolve)

    expect(await panel.findByText('No hay discrepancias.')).toBeInTheDocument()
    expect(api.callsTo('POST', '/transfer-discrepancies/4/resolve')).toHaveLength(1)
    expect(api.callsTo('POST', '/transfer-discrepancies/4/resolve')[0].body).toEqual({
      action: 'PERDIDA',
      resolution: 'Unidad rota en el transporte',
    })
  })

  it('quien recibió recibe SEGREGACION_FUNCIONES explicado', async () => {
    mockApi({
      ...meRoute(makeUser('regente_farmacia')),
      'GET /transfers': page([]),
      'GET /transfer-discrepancies': page([discrepancy]),
      'POST /transfer-discrepancies/4/resolve': apiError(403, 'SEGREGACION_FUNCIONES', 'x', { reason: 'ES_RECEPTOR' }),
    })
    renderApp('/traslados')
    const panel = within(await screen.findByRole('region', { name: 'Discrepancias' }))
    await userEvent.type(await panel.findByLabelText(/Detalle/), 'Reintegro al origen')
    await userEvent.click(panel.getByRole('button', { name: 'Resolver' }))

    expect(await panel.findByRole('alert')).toHaveTextContent('Quien recibió el traslado no puede resolver su discrepancia')
  })

  it('el auxiliar ve la discrepancia pero no el formulario de resolución', async () => {
    mockApi({
      ...meRoute(makeUser('auxiliar_farmacia')),
      'GET /transfers': page([]),
      'GET /transfer-discrepancies': page([discrepancy]),
    })
    renderApp('/traslados')
    const panel = within(await screen.findByRole('region', { name: 'Discrepancias' }))

    expect(await panel.findByText(/faltan 1/)).toBeInTheDocument()
    expect(panel.queryByRole('button', { name: 'Resolver' })).not.toBeInTheDocument()
  })
})
