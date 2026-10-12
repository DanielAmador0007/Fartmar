import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { User } from '../../api/types'
import { apiError, mockApi, type MockReply, type RecordedCall } from '../../test/http'
import { page } from '../../test/fixtures'
import { makeUser, meRoute, renderApp } from '../../test/render'

const admin = makeUser('admin', { id: 1, name: 'Admin Demo' })
const aux: User = makeUser('auxiliar_farmacia', { id: 2, name: 'Aux Demo' })

function routes(patch: MockReply | ((call: RecordedCall) => MockReply)) {
  return {
    ...meRoute(admin),
    'GET /users': page([admin, aux]),
    'PATCH /users/2': patch,
  }
}

async function auxRow() {
  const cell = await screen.findByText('Aux Demo')
  return within(cell.closest('tr') as HTMLElement)
}

describe('Usuarios (admin)', () => {
  it('cambiar el rol no envía nada hasta pulsar "Guardar rol"', async () => {
    const api = mockApi(routes((call) => ({ body: { data: { ...aux, ...(call.body as object) } } })))
    renderApp('/usuarios')
    const row = await auxRow()

    await userEvent.selectOptions(row.getByLabelText('Rol de Aux Demo'), 'regente_farmacia')
    expect(api.callsTo('PATCH', '/users/2')).toHaveLength(0)

    await userEvent.click(row.getByRole('button', { name: 'Guardar rol' }))
    await waitFor(() => expect(api.callsTo('PATCH', '/users/2')).toHaveLength(1))
    expect(api.callsTo('PATCH', '/users/2')[0].body).toEqual({ role: 'regente_farmacia' })
  })

  it('"Descartar" vuelve al rol original sin llamar a la API', async () => {
    const api = mockApi(routes({ body: { data: aux } }))
    renderApp('/usuarios')
    const row = await auxRow()

    await userEvent.selectOptions(row.getByLabelText('Rol de Aux Demo'), 'medico')
    await userEvent.click(row.getByRole('button', { name: 'Descartar' }))

    expect(row.getByLabelText('Rol de Aux Demo')).toHaveValue('auxiliar_farmacia')
    expect(api.callsTo('PATCH', '/users/2')).toHaveLength(0)
  })

  it('si restablecer la clave falla, el formulario conserva lo escrito', async () => {
    mockApi(routes(apiError(422, 'VALIDACION', 'x', { fields: { password: ['La contraseña debe tener letras y números.'] } })))
    renderApp('/usuarios')
    const row = await auxRow()

    await userEvent.click(row.getByRole('button', { name: 'Restablecer clave' }))
    await userEvent.type(row.getByLabelText('Nueva contraseña temporal de Aux Demo'), 'solamenteletras')
    await userEvent.click(row.getByRole('button', { name: 'Guardar clave' }))

    expect(await row.findByText(/La contraseña debe tener letras y números/)).toBeInTheDocument()
    expect(row.getByLabelText('Nueva contraseña temporal de Aux Demo')).toHaveValue('solamenteletras')
  })

  it('si restablecer la clave funciona, se cierra y limpia el formulario', async () => {
    const api = mockApi(routes({ body: { data: { ...aux, must_change_password: true } } }))
    renderApp('/usuarios')
    const row = await auxRow()

    await userEvent.click(row.getByRole('button', { name: 'Restablecer clave' }))
    await userEvent.type(row.getByLabelText('Nueva contraseña temporal de Aux Demo'), 'Temporal2026x')
    await userEvent.click(row.getByRole('button', { name: 'Guardar clave' }))

    await waitFor(() => expect(row.queryByLabelText('Nueva contraseña temporal de Aux Demo')).not.toBeInTheDocument())
    expect(api.callsTo('PATCH', '/users/2')[0].body).toEqual({ password: 'Temporal2026x' })
  })

  it('el admin no puede cambiarse el rol ni desactivarse a sí mismo desde la interfaz', async () => {
    mockApi(routes({ body: { data: aux } }))
    renderApp('/usuarios')
    const row = within((await screen.findByText('Admin Demo', { selector: 'td' })).closest('tr') as HTMLElement)

    expect(row.getByLabelText('Rol de Admin Demo')).toBeDisabled()
    expect(row.getByRole('button', { name: 'Desactivar' })).toBeDisabled()
  })
})
