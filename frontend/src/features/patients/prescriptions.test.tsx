import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { apiError, mockApi, type MockReply, type RecordedCall } from '../../test/http'
import { acetaminofen, morfina, page, patient, prescription, product } from '../../test/fixtures'
import { makeUser, meRoute, renderApp } from '../../test/render'
import { addDays, buildPrescription, validUntilRange } from './prescriptionLines'

function routes(createHandler: MockReply | ((call: RecordedCall) => MockReply | Promise<MockReply>)) {
  return {
    ...meRoute(makeUser('medico')),
    'POST /patients/search': { body: { data: [patient] } },
    'GET /patients/1/prescriptions': { body: { data: [] } },
    'GET /products': (call: RecordedCall) => {
      const q = (call.query.get('q') ?? '').toLowerCase()
      return page([product(acetaminofen), product(morfina)].filter((p) => p.name.toLowerCase().includes(q)))
    },
    'POST /prescriptions': createHandler,
  }
}

async function openForm() {
  renderApp('/pacientes')
  await userEvent.type(await screen.findByLabelText('Documento del paciente'), '9900000001{Enter}')
  return within(await screen.findByRole('form', { name: 'Nueva prescripción' }))
}

async function addProduct(form: ReturnType<typeof within>, text: string, name: string) {
  await userEvent.type(form.getByLabelText(/Buscar medicamento/), text)
  await userEvent.click(await form.findByRole('button', { name: `Agregar ${name}` }))
}

describe('buildPrescription', () => {
  it('arma el cuerpo con indicaciones vacías en null', () => {
    const { request } = buildPrescription(1, '2026-12-01', [
      { product: product(acetaminofen), quantity: '10', instructions: '  ' },
    ])
    expect(request).toEqual({ patient_id: 1, valid_until: '2026-12-01', items: [{ product_id: 1, quantity: 10, instructions: null }] })
  })

  it('rechaza cantidades fuera de 1..10000 y la lista vacía', () => {
    expect(buildPrescription(1, '2026-12-01', []).problems).toContain('Agrega al menos un medicamento.')
    expect(buildPrescription(1, '2026-12-01', [{ product: product(acetaminofen), quantity: '0', instructions: '' }]).request).toBeNull()
  })

  it('rango de fecha: mañana a hoy + 365', () => {
    expect(validUntilRange('2026-10-11')).toEqual({ min: '2026-10-12', max: addDays('2026-10-11', 365), suggested: '2026-11-10' })
  })
})

describe('Prescripción del médico', () => {
  it('busca productos por q, envía la prescripción y la muestra en la lista', async () => {
    let created = false
    const api = mockApi({
      ...routes(() => {
        created = true
        return { status: 201, body: { data: prescription({ number: 'RX-NUEVA' }) } }
      }),
      'GET /patients/1/prescriptions': () => ({ body: { data: created ? [prescription({ number: 'RX-NUEVA' })] : [] } }),
    })
    const form = await openForm()

    await addProduct(form, 'acet', 'Acetaminofén 500 mg')
    await userEvent.type(form.getByLabelText('Cantidad de Acetaminofén 500 mg'), '20')
    await userEvent.type(form.getByLabelText('Indicaciones de Acetaminofén 500 mg'), 'Cada 8 horas')
    await userEvent.click(form.getByRole('button', { name: 'Crear prescripción' }))

    expect(await screen.findByText('Prescripción RX-NUEVA creada.')).toBeInTheDocument()
    expect(api.callsTo('GET', '/products').some((c) => c.query.get('q') === 'acet')).toBe(true)
    const body = api.callsTo('POST', '/prescriptions')[0].body as { valid_until: string }
    expect(body).toEqual({
      patient_id: 1,
      valid_until: body.valid_until,
      items: [{ product_id: 1, quantity: 20, instructions: 'Cada 8 horas' }],
    })
    expect(body.valid_until).toBe(validUntilRange().suggested)
    expect(await within(screen.getByRole('region', { name: 'Prescripciones' })).findByText('RX-NUEVA')).toBeInTheDocument()
  })

  it('valida antes de enviar: sin productos o con cantidad inválida no llama a la API', async () => {
    const api = mockApi(routes({ status: 201, body: { data: prescription() } }))
    const form = await openForm()

    await userEvent.click(form.getByRole('button', { name: 'Crear prescripción' }))
    expect(form.getByRole('alert')).toHaveTextContent('Agrega al menos un medicamento.')

    await addProduct(form, 'mor', 'Morfina 10 mg/ml')
    await userEvent.type(form.getByLabelText('Cantidad de Morfina 10 mg/ml'), '0')
    await userEvent.click(form.getByRole('button', { name: 'Crear prescripción' }))
    expect(form.getByRole('alert')).toHaveTextContent('Cantidad inválida para Morfina 10 mg/ml')
    expect(api.callsTo('POST', '/prescriptions')).toHaveLength(0)
  })

  it('un doble clic crea una sola prescripción y el botón queda deshabilitado mientras guarda', async () => {
    let release!: (reply: MockReply) => void
    const api = mockApi(routes(() => new Promise<MockReply>((r) => (release = r))))
    const form = await openForm()
    await addProduct(form, 'acet', 'Acetaminofén 500 mg')
    await userEvent.type(form.getByLabelText('Cantidad de Acetaminofén 500 mg'), '5')

    await userEvent.dblClick(form.getByRole('button', { name: 'Crear prescripción' }))

    await waitFor(() => expect(form.getByRole('button', { name: 'Guardando…' })).toBeDisabled())
    expect(api.callsTo('POST', '/prescriptions')).toHaveLength(1)
    release({ status: 201, body: { data: prescription() } })
    expect(await screen.findByText(/creada/)).toBeInTheDocument()
    expect(api.callsTo('POST', '/prescriptions')).toHaveLength(1)
  })

  it('un error de validación del servidor se muestra en español', async () => {
    mockApi(routes(apiError(422, 'VALIDACION', 'x', { fields: { valid_until: ['La fecha debe ser posterior a hoy.'] } })))
    const form = await openForm()
    await addProduct(form, 'acet', 'Acetaminofén 500 mg')
    await userEvent.type(form.getByLabelText('Cantidad de Acetaminofén 500 mg'), '5')
    await userEvent.click(form.getByRole('button', { name: 'Crear prescripción' }))

    expect(await form.findByText('Revisa los datos: La fecha debe ser posterior a hoy.')).toBeInTheDocument()
  })

  it('el auditor no ve el formulario de prescripción', async () => {
    mockApi({ ...routes({}), ...meRoute(makeUser('auditor')) })
    renderApp('/pacientes')
    await userEvent.type(await screen.findByLabelText('Documento del paciente'), '9900000001{Enter}')
    await screen.findByText('El paciente no tiene prescripciones.')

    expect(screen.queryByRole('form', { name: 'Nueva prescripción' })).not.toBeInTheDocument()
  })
})
