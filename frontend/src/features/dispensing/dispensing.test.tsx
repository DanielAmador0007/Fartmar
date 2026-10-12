import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { apiError, mockApi, type MockReply } from '../../test/http'
import { catalogRoutes, dispensation, fefoPreview, morfina, patient, prescription } from '../../test/fixtures'
import { makeUser, meRoute, renderApp } from '../../test/render'

function baseRoutes(dispenseHandler: MockReply | (() => MockReply | Promise<MockReply>)) {
  return {
    ...meRoute(makeUser('auxiliar_farmacia')),
    'POST /patients/search': { body: { data: [patient] } },
    'GET /patients/1/prescriptions': { body: { data: [prescription()] } },
    ...catalogRoutes,
    'GET /dispensations/preview': { body: { data: fefoPreview } },
    'POST /dispensations': dispenseHandler,
  }
}

async function chooseWarehouse() {
  const select = await screen.findByLabelText('Bodega')
  await waitFor(() => expect(within(select).getAllByRole('option')).toHaveLength(3))
  await userEvent.selectOptions(select, '1')
}

// Recorre el flujo hasta dejar lista la confirmación.
async function goToConfirm() {
  const search = await screen.findByLabelText('Documento del paciente')
  expect(search).toHaveFocus()
  await userEvent.type(search, '9900000001{Enter}')
  await userEvent.click(await screen.findByRole('button', { name: 'Elegir' }))
  await chooseWarehouse()
  await userEvent.click(await screen.findByRole('button', { name: 'Ver vista previa FEFO' }))
  await screen.findByText('L-ACE-2402')
  const confirm = screen.getByRole('button', { name: 'Confirmar dispensación' })
  await waitFor(() => expect(confirm).toBeEnabled())
  return confirm
}

function deferred() {
  let resolve!: (reply: MockReply) => void
  const promise = new Promise<MockReply>((r) => {
    resolve = r
  })
  return { promise, resolve }
}

describe('Dispensación', () => {
  it('no preselecciona bodega: hay que elegirla antes de la vista previa', async () => {
    const api = mockApi(baseRoutes({ status: 201, body: { data: dispensation() } }))
    renderApp('/dispensacion')
    await userEvent.type(await screen.findByLabelText('Documento del paciente'), '9900000001{Enter}')
    await userEvent.click(await screen.findByRole('button', { name: 'Elegir' }))

    const select = await screen.findByLabelText('Bodega')
    await waitFor(() => expect(within(select).getAllByRole('option')).toHaveLength(3))
    expect(select).toHaveValue('')
    expect(within(select).getAllByRole('option')[0]).toHaveTextContent('Seleccione…')
    expect(screen.getByRole('button', { name: 'Ver vista previa FEFO' })).toBeDisabled()
    expect(screen.getByText('Elige la bodega desde la que se entrega.')).toBeInTheDocument()

    await userEvent.selectOptions(select, '2')
    await userEvent.click(screen.getByRole('button', { name: 'Ver vista previa FEFO' }))
    await waitFor(() => expect(api.callsTo('GET', '/dispensations/preview')[0].query.get('warehouse_id')).toBe('2'))
  })

  it('muestra la vista previa FEFO con varios lotes calculada por el backend', async () => {
    const api = mockApi(baseRoutes({ status: 201, body: { data: dispensation() } }))
    renderApp('/dispensacion')

    await goToConfirm()

    const preview = screen.getByRole('region', { name: 'Vista previa FEFO' })
    const rows = within(preview).getAllByRole('row').slice(1)
    expect(rows.map((r) => r.textContent)).toEqual(['L-ACE-24022026-10-3115', 'L-ACE-24032027-08-045'])
    const call = api.callsTo('GET', '/dispensations/preview')[0]
    expect(call.query.get('warehouse_id')).toBe('1')
    expect(call.query.get('product_id')).toBe('1')
    expect(call.query.get('quantity')).toBe('20')
  })

  it('envía Idempotency-Key, deshabilita el botón mientras envía y un doble clic hace una sola petición', async () => {
    const pending = deferred()
    const api = mockApi(baseRoutes(() => pending.promise))
    renderApp('/dispensacion')
    const confirm = await goToConfirm()

    await userEvent.dblClick(confirm)

    await waitFor(() => expect(screen.getByRole('button', { name: 'Dispensando…' })).toBeDisabled())
    expect(api.callsTo('POST', '/dispensations')).toHaveLength(1)
    const call = api.callsTo('POST', '/dispensations')[0]
    expect(call.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/)
    expect(call.body).toEqual({ patient_id: 1, prescription_id: 1, warehouse_id: 1, items: [{ prescription_item_id: 11, quantity: 20 }] })

    pending.resolve({ status: 201, body: { data: dispensation() } })
    expect(await screen.findByRole('heading', { name: /Dispensación #77/ })).toBeInTheDocument()
    expect(api.callsTo('POST', '/dispensations')).toHaveLength(1)
  })

  it('reutiliza la misma Idempotency-Key al reintentar el mismo formulario', async () => {
    let attempt = 0
    const api = mockApi(
      baseRoutes(() => {
        attempt += 1
        return attempt === 1 ? apiError(409, 'CONFLICTO_CONCURRENCIA') : { status: 201, body: { data: dispensation() } }
      }),
    )
    renderApp('/dispensacion')
    const confirm = await goToConfirm()

    await userEvent.click(confirm)
    expect(await screen.findByText(/es seguro reintentar/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar dispensación' }))
    await screen.findByRole('heading', { name: /Dispensación #77/ })

    const [first, second] = api.callsTo('POST', '/dispensations')
    expect(second.headers['Idempotency-Key']).toBe(first.headers['Idempotency-Key'])
  })

  it('si cambia la cantidad tras un error, usa una clave nueva', async () => {
    const api = mockApi(baseRoutes(apiError(409, 'STOCK_INSUFICIENTE', 'x', { requested: 20, available: 12 })))
    renderApp('/dispensacion')
    const confirm = await goToConfirm()
    await userEvent.click(confirm)
    await screen.findByRole('alert')

    const input = screen.getByLabelText('Cantidad de Acetaminofén 500 mg')
    await userEvent.clear(input)
    await userEvent.type(input, '12')
    await userEvent.click(screen.getByRole('button', { name: 'Ver vista previa FEFO' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Confirmar dispensación' })).toBeEnabled())
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar dispensación' }))

    await waitFor(() => expect(api.callsTo('POST', '/dispensations')).toHaveLength(2))
    const [first, second] = api.callsTo('POST', '/dispensations')
    expect(second.headers['Idempotency-Key']).not.toBe(first.headers['Idempotency-Key'])
  })

  it('STOCK_INSUFICIENTE muestra disponible vs solicitado y el código de soporte', async () => {
    mockApi(baseRoutes(apiError(409, 'STOCK_INSUFICIENTE', 'x', { requested: 20, available: 12 })))
    renderApp('/dispensacion')
    await userEvent.click(await goToConfirm())

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Stock insuficiente: se solicitaron 20 unidades y solo hay 12 disponibles en lotes no vencidos.')
    expect(alert).toHaveTextContent('Código de soporte: corr-test-123')
    expect(alert).not.toHaveTextContent('{')
  })

  it('LOTE_VENCIDO muestra un mensaje comprensible', async () => {
    mockApi(baseRoutes(apiError(422, 'LOTE_VENCIDO')))
    renderApp('/dispensacion')
    await userEvent.click(await goToConfirm())

    expect(await screen.findByRole('alert')).toHaveTextContent('El lote está vencido: no se puede dispensar ni trasladar.')
  })

  it('PRESCRIPCION_NO_VIGENTE con reason COMPLETADA explica que ya se entregó todo', async () => {
    mockApi(baseRoutes(apiError(422, 'PRESCRIPCION_NO_VIGENTE', 'x', { prescription_id: 1, reason: 'COMPLETADA' })))
    renderApp('/dispensacion')
    await userEvent.click(await goToConfirm())

    expect(await screen.findByRole('alert')).toHaveTextContent('La prescripción ya fue entregada en su totalidad.')
  })

  it('un control especial queda "Pendiente de autorización"', async () => {
    const controlled = prescription({
      items: [{ ...prescription().items[0], product: morfina }],
    })
    mockApi({
      ...baseRoutes({
        status: 201,
        body: {
          data: dispensation({
            status: 'PENDIENTE_AUTORIZACION',
            requires_authorization: true,
            items: [{ id: 1, prescription_item_id: 11, product: morfina, quantity: 20, lots: [] }],
          }),
        },
      }),
      'GET /patients/1/prescriptions': { body: { data: [controlled] } },
      'GET /dispensations/preview': { body: { data: { ...fefoPreview, product: morfina, requires_authorization: true } } },
    })
    renderApp('/dispensacion')
    await userEvent.click(await goToConfirm())

    expect(await screen.findByText('Pendiente de autorización', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText(/Un regente distinto de quien la creó debe autorizarla/)).toBeInTheDocument()
  })
})
