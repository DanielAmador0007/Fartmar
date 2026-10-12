import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { mockApi } from '../../test/http'
import { maskedPatient, prescription } from '../../test/fixtures'
import { makeUser, meRoute, renderApp } from '../../test/render'

describe('Pacientes para el auditor (enmascarado)', () => {
  it('muestra los datos tal como llegan y tolera nulos', async () => {
    const api = mockApi({
      ...meRoute(makeUser('auditor')),
      'POST /patients/search': { body: { data: [maskedPatient] } },
      'GET /patients/1/prescriptions': {
        body: { data: [prescription({ items: [{ ...prescription().items[0], instructions: null }] })] },
      },
    })
    renderApp('/pacientes')

    await userEvent.type(await screen.findByLabelText('Documento del paciente'), '9900000001{Enter}')

    expect(await screen.findByRole('heading', { name: 'A*** P***' })).toBeInTheDocument()
    expect(screen.getByText(/CC \*\*\*\*\*\*0001 · Nacimiento: — · Teléfono: —/)).toBeInTheDocument()
    expect(screen.getByText('Datos enmascarados según tu rol.')).toBeInTheDocument()
    expect(await screen.findByText('RX-DEMO-0001')).toBeInTheDocument()

    // El documento va en el cuerpo del POST, nunca en la URL (S-69).
    const search = api.callsTo('POST', '/patients/search')[0]
    expect(search.body).toEqual({ document_type: 'CC', document_number: '9900000001' })
    expect(search.query.toString()).toBe('')
  })

  it('no guarda datos del paciente en el almacenamiento del navegador', async () => {
    mockApi({
      ...meRoute(makeUser('auditor')),
      'POST /patients/search': { body: { data: [maskedPatient] } },
      'GET /patients/1/prescriptions': { body: { data: [] } },
    })
    renderApp('/pacientes')
    await userEvent.type(await screen.findByLabelText('Documento del paciente'), '9900000001{Enter}')
    await screen.findByText('El paciente no tiene prescripciones.')

    expect(localStorage.length).toBe(0)
    expect(Object.keys(sessionStorage)).toEqual(['fartmar.token'])
  })

  it('búsqueda sin resultado lo indica', async () => {
    mockApi({ ...meRoute(makeUser('medico')), 'POST /patients/search': { body: { data: [] } } })
    renderApp('/pacientes')

    await userEvent.type(await screen.findByLabelText('Documento del paciente'), '123456{Enter}')

    expect(await screen.findByText('No se encontró un paciente con ese documento.')).toBeInTheDocument()
  })
})
