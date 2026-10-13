import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { tokenStorage } from '../api/tokenStorage'
import type { Role } from '../api/types'
import { apiError, mockApi } from '../test/http'
import { makeUser, meRoute, renderApp } from '../test/render'

describe('Login', () => {
  it('sin sesión redirige a login con foco en el correo', async () => {
    mockApi({})
    renderApp('/inventario', { loggedIn: false })

    const email = await screen.findByLabelText('Correo')
    expect(email).toHaveFocus()
  })

  it('inicia sesión, guarda el token y entra a la primera sección del rol', async () => {
    const user = makeUser('auxiliar_farmacia')
    const api = mockApi({
      'POST /auth/login': { body: { token: 'tok-nuevo', token_type: 'Bearer', expires_at: null, user } },
    })
    renderApp('/login', { loggedIn: false })

    await userEvent.type(screen.getByLabelText('Correo'), 'auxiliar@fartmar.test')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'password')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(await screen.findByRole('heading', { name: 'Dispensación' })).toBeInTheDocument()
    expect(tokenStorage.get()).toBe('tok-nuevo')
    expect(api.callsTo('POST', '/auth/login')[0].body).toMatchObject({ email: 'auxiliar@fartmar.test', password: 'password' })
  })

  it('credenciales inválidas muestran un mensaje en español y el código de soporte', async () => {
    mockApi({ 'POST /auth/login': apiError(401, 'CREDENCIALES_INVALIDAS') })
    renderApp('/login', { loggedIn: false })

    await userEvent.type(screen.getByLabelText('Correo'), 'x@fartmar.test')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'mala')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Correo o contraseña incorrectos.')
    expect(alert).toHaveTextContent('Código de soporte: corr-test-123')
    expect(tokenStorage.get()).toBeNull()
  })

  it('si /auth/me falla por red al recargar, conserva el token y permite reintentar', async () => {
    mockApi({
      ...meRoute(makeUser('auditor')),
      'GET /transfers': { body: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } },
      'GET /transfer-discrepancies': { body: { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } } },
    })
    // Simula la caída de red en el primer intento.
    const realFetch = globalThis.fetch
    let first = true
    vi.stubGlobal('fetch', (input: RequestInfo | URL, init?: RequestInit) => {
      if (first) {
        first = false
        return Promise.reject(new TypeError('Failed to fetch'))
      }
      return realFetch(input, init)
    })
    renderApp('/traslados')

    expect(await screen.findByRole('heading', { name: 'No se pudo recuperar la sesión' })).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('No hay conexión con el servidor')
    expect(tokenStorage.get()).toBe('tok-test')

    await userEvent.click(screen.getByRole('button', { name: 'Reintentar' }))

    expect(await screen.findByRole('heading', { name: 'Traslados' })).toBeInTheDocument()
  })

  it('un 401 en cualquier consulta cierra la sesión y vuelve a login', async () => {
    mockApi({
      ...meRoute(makeUser('auditor')),
      'GET /stocks': apiError(401, 'NO_AUTENTICADO'),
      'GET /alerts': apiError(401, 'NO_AUTENTICADO'),
      'GET /stocks/in-transit': apiError(401, 'NO_AUTENTICADO'),
    })
    renderApp('/inventario')

    expect(await screen.findByLabelText('Correo')).toBeInTheDocument()
    expect(tokenStorage.get()).toBeNull()
  })
})

describe('Cambio de clave obligatorio', () => {
  it('con must_change_password lleva a la pantalla de cambio y bloquea el resto', async () => {
    mockApi(meRoute(makeUser('regente_farmacia', { must_change_password: true })))
    renderApp('/inventario')

    expect(await screen.findByRole('heading', { name: 'Cambiar contraseña' })).toBeInTheDocument()
    expect(screen.getByText(/contraseña temporal/)).toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Principal' })).not.toBeInTheDocument()
  })

  it('valida longitud y confirmación antes de enviar', async () => {
    const api = mockApi(meRoute(makeUser('regente_farmacia', { must_change_password: true })))
    renderApp('/cambiar-clave')

    await userEvent.type(await screen.findByLabelText('Contraseña actual'), 'Temporal12345')
    await userEvent.type(screen.getByLabelText(/^Nueva contraseña/), 'corta1')
    await userEvent.type(screen.getByLabelText('Confirmar nueva contraseña'), 'corta1')
    await userEvent.click(screen.getByRole('button', { name: 'Cambiar contraseña' }))

    expect(screen.getByRole('alert')).toHaveTextContent('al menos 12 caracteres')
    expect(api.callsTo('POST', '/auth/password')).toHaveLength(0)
  })

  it('cambia la clave y entra a la aplicación', async () => {
    const user = makeUser('regente_farmacia', { must_change_password: true })
    const api = mockApi({
      ...meRoute(user),
      'POST /auth/password': { body: { data: { ...user, must_change_password: false } } },
      'GET /dispensations/1': apiError(404, 'NO_ENCONTRADO'),
    })
    renderApp('/cambiar-clave')

    await userEvent.type(await screen.findByLabelText('Contraseña actual'), 'Temporal12345')
    await userEvent.type(screen.getByLabelText(/^Nueva contraseña/), 'NuevaClave2026')
    await userEvent.type(screen.getByLabelText('Confirmar nueva contraseña'), 'NuevaClave2026')
    await userEvent.click(screen.getByRole('button', { name: 'Cambiar contraseña' }))

    expect(await screen.findByRole('navigation', { name: 'Principal' })).toBeInTheDocument()
    expect(api.callsTo('POST', '/auth/password')[0].body).toEqual({
      current_password: 'Temporal12345',
      password: 'NuevaClave2026',
      password_confirmation: 'NuevaClave2026',
    })
  })

  it('un 403 CAMBIO_CLAVE_REQUERIDO en plena sesión lleva al cambio de clave', async () => {
    mockApi({
      ...meRoute(makeUser('auditor')),
      'GET /stocks': apiError(403, 'CAMBIO_CLAVE_REQUERIDO'),
      'GET /alerts': apiError(403, 'CAMBIO_CLAVE_REQUERIDO'),
      'GET /stocks/in-transit': apiError(403, 'CAMBIO_CLAVE_REQUERIDO'),
    })
    renderApp('/inventario')

    expect(await screen.findByRole('heading', { name: 'Cambiar contraseña' })).toBeInTheDocument()
  })
})

describe('Navegación por rol', () => {
  const cases: [Role, string[]][] = [
    ['auxiliar_farmacia', ['Dispensación', 'Traslados', 'Inventario', 'Kardex', 'Pacientes', 'Asistente']],
    ['regente_farmacia', ['Dispensación', 'Autorizaciones', 'Traslados', 'Inventario', 'Kardex', 'Pacientes', 'Asistente']],
    ['medico', ['Pacientes']],
    ['auditor', ['Traslados', 'Inventario', 'Kardex', 'Pacientes', 'Asistente']],
    ['admin', ['Usuarios']],
  ]

  it.each(cases)('%s ve solo sus secciones', async (role, expected) => {
    mockApi(meRoute(makeUser(role)))
    renderApp('/pagina-inexistente')

    const nav = await screen.findByRole('navigation', { name: 'Principal' })
    const labels = within(nav).getAllByRole('link').map((link) => link.textContent)
    expect(labels).toEqual(expected)
  })

  it('una sección no permitida muestra "No tienes permiso"', async () => {
    mockApi(meRoute(makeUser('medico')))
    renderApp('/inventario')

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('No tienes permiso para ver esta sección.'))
  })
})
