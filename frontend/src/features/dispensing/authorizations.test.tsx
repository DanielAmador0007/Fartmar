import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Dispensation, DispensationSummary } from '../../api/types'
import { apiError, mockApi } from '../../test/http'
import { catalogRoutes, dispensation, morfina, page } from '../../test/fixtures'
import { makeUser, meRoute, renderApp } from '../../test/render'

function pending(id: number, createdById: number): Dispensation {
  return dispensation({
    id,
    status: 'PENDIENTE_AUTORIZACION',
    requires_authorization: true,
    items: [{ id, prescription_item_id: 11, product: morfina, quantity: 2, lots: [] }],
    created_by: { id: createdById, name: `Creador ${createdById}`, role: 'auxiliar_farmacia' },
  })
}

function summary(d: Dispensation): DispensationSummary {
  const { rejection_reason: _omit, ...rest } = d
  void _omit
  return { ...rest, items: d.items.map(({ id, product, quantity }) => ({ id, product, quantity })) }
}

const REGENTE_ID = 20

describe('Bandeja de autorizaciones', () => {
  it('lista las pendientes con GET /dispensations?status=PENDIENTE_AUTORIZACION', async () => {
    const api = mockApi({
      ...meRoute(makeUser('regente_farmacia', { id: REGENTE_ID })),
      ...catalogRoutes,
      'GET /dispensations': page([summary(pending(77, 10)), summary(pending(78, 11))]),
    })
    renderApp('/autorizaciones')

    const region = within(await screen.findByRole('region', { name: 'Pendientes de autorización' }))
    expect(await region.findByText('Creador 10')).toBeInTheDocument()
    expect(region.getByText('Creador 11')).toBeInTheDocument()
    expect(region.getAllByText('Morfina 10 mg/ml × 2')).toHaveLength(2)
    expect(api.callsTo('GET', '/dispensations')[0].query.get('status')).toBe('PENDIENTE_AUTORIZACION')
  })

  it('filtra por bodega', async () => {
    const api = mockApi({
      ...meRoute(makeUser('regente_farmacia', { id: REGENTE_ID })),
      ...catalogRoutes,
      'GET /dispensations': page([]),
    })
    renderApp('/autorizaciones')
    expect(await screen.findByText('No hay dispensaciones pendientes de autorización.')).toBeInTheDocument()

    await userEvent.selectOptions(await screen.findByLabelText('Bodega'), '2')

    await waitFor(() => expect(api.callsTo('GET', '/dispensations').some((c) => c.query.get('warehouse_id') === '2')).toBe(true))
  })

  it('autoriza desde la lista y la fila desaparece al recargar', async () => {
    let authorized = false
    const api = mockApi({
      ...meRoute(makeUser('regente_farmacia', { id: REGENTE_ID })),
      ...catalogRoutes,
      'GET /dispensations': () => page(authorized ? [] : [summary(pending(77, 10))]),
      'POST /dispensations/77/authorize': () => {
        authorized = true
        return { body: { data: { ...pending(77, 10), status: 'COMPLETADA' } } }
      },
    })
    renderApp('/autorizaciones')

    await userEvent.dblClick(await screen.findByRole('button', { name: 'Autorizar dispensación 77' }))

    expect(await screen.findByText('Dispensación #77 autorizada y entregada.')).toBeInTheDocument()
    expect(await screen.findByText('No hay dispensaciones pendientes de autorización.')).toBeInTheDocument()
    expect(api.callsTo('POST', '/dispensations/77/authorize')).toHaveLength(1)
  })

  it('el creador ve Autorizar y Rechazar deshabilitados', async () => {
    mockApi({
      ...meRoute(makeUser('regente_farmacia', { id: REGENTE_ID })),
      ...catalogRoutes,
      'GET /dispensations': page([summary(pending(77, REGENTE_ID)), summary(pending(78, 10))]),
    })
    renderApp('/autorizaciones')

    expect(await screen.findByRole('button', { name: 'Autorizar dispensación 77' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Rechazar dispensación 77' })).toBeDisabled()
    expect(screen.getByText('La creaste tú: debe autorizarla otro regente.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Autorizar dispensación 78' })).toBeEnabled()
  })

  it('rechaza desde el detalle con motivo obligatorio', async () => {
    const api = mockApi({
      ...meRoute(makeUser('regente_farmacia', { id: REGENTE_ID })),
      ...catalogRoutes,
      'GET /dispensations': page([]),
      'GET /dispensations/77': { body: { data: pending(77, 10) } },
      'POST /dispensations/77/reject': {
        body: { data: { ...pending(77, 10), status: 'RECHAZADA', rejected_by: { id: REGENTE_ID, name: 'Regente', role: 'regente_farmacia' }, rejection_reason: 'Fórmula ilegible' } },
      },
    })
    renderApp('/autorizaciones?id=77')

    const decision = within(await screen.findByRole('region', { name: 'Decisión' }))
    await userEvent.click(decision.getByRole('button', { name: 'Rechazar dispensación 77' }))
    const confirm = decision.getByRole('button', { name: 'Confirmar rechazo' })
    expect(confirm).toBeDisabled()
    await userEvent.type(decision.getByLabelText(/Motivo del rechazo/), 'Fórmula ilegible')
    await userEvent.click(confirm)

    expect(await screen.findByText('Dispensación #77 rechazada.')).toBeInTheDocument()
    expect(await screen.findByText('Rechazada', { selector: '.badge' })).toBeInTheDocument()
    expect(api.callsTo('POST', '/dispensations/77/reject')[0].body).toEqual({ reason: 'Fórmula ilegible' })
  })

  it('SEGREGACION_FUNCIONES del backend se explica aunque la UI no lo anticipe', async () => {
    mockApi({
      ...meRoute(makeUser('regente_farmacia', { id: REGENTE_ID })),
      ...catalogRoutes,
      'GET /dispensations': page([summary(pending(77, 10))]),
      'POST /dispensations/77/authorize': apiError(403, 'SEGREGACION_FUNCIONES'),
    })
    renderApp('/autorizaciones')

    await userEvent.click(await screen.findByRole('button', { name: 'Autorizar dispensación 77' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('usuario distinto de quien creó')
  })
})
