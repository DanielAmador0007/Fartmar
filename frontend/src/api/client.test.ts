import { afterEach, describe, expect, it, vi } from 'vitest'
import { apiError, mockApi } from '../test/http'
import { ApiError, apiRequest, setSessionHandlers } from './client'
import { correlationIdOf, errorMessage } from './errors'
import { tokenStorage } from './tokenStorage'

afterEach(() => {
  setSessionHandlers(null)
  tokenStorage.clear()
  vi.unstubAllGlobals()
})

async function catchError(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise
  } catch (error) {
    if (error instanceof ApiError) return error
    throw error
  }
  throw new Error('se esperaba un error')
}

describe('apiRequest', () => {
  it('envía el token Bearer y el cuerpo JSON', async () => {
    tokenStorage.set('tok-1')
    const api = mockApi({ 'POST /transfers': { status: 201, body: { data: { id: 1 } } } })

    const result = await apiRequest<{ data: { id: number } }>('/transfers', { method: 'POST', body: { a: 1 } })

    expect(result.data.id).toBe(1)
    expect(api.calls[0].headers.Authorization).toBe('Bearer tok-1')
    expect(api.calls[0].headers['Content-Type']).toBe('application/json')
    expect(api.calls[0].body).toEqual({ a: 1 })
  })

  it('omite parámetros vacíos en la query', async () => {
    const api = mockApi({ 'GET /stocks': { body: { data: [] } } })

    await apiRequest('/stocks', { query: { warehouse_id: 2, product_id: '', lot_id: undefined } })

    expect(api.calls[0].query.toString()).toBe('warehouse_id=2')
  })

  it('normaliza el error de la API con código, detalles y correlation id', async () => {
    mockApi({
      'POST /dispensations': apiError(409, 'STOCK_INSUFICIENTE', 'x', { requested: 3, available: 2 }),
    })

    const error = await catchError(apiRequest('/dispensations', { method: 'POST', body: {} }))

    expect(error.status).toBe(409)
    expect(error.code).toBe('STOCK_INSUFICIENTE')
    expect(error.correlationId).toBe('corr-test-123')
    expect(errorMessage(error)).toBe(
      'Stock insuficiente: se solicitaron 3 unidades y solo hay 2 disponibles en lotes no vencidos.',
    )
    expect(correlationIdOf(error)).toBe('corr-test-123')
  })

  it('una respuesta no JSON se convierte en un error genérico sin contenido crudo', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('<html>Bad Gateway</html>', { status: 502 })))

    const error = await catchError(apiRequest('/stocks'))

    expect(error.code).toBe('ERROR_INTERNO')
    expect(errorMessage(error)).not.toContain('html')
  })

  it('un fallo de red se informa como sin conexión', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('Failed to fetch'))))

    const error = await catchError(apiRequest('/stocks'))

    expect(error.code).toBe('SIN_CONEXION')
    expect(errorMessage(error)).toMatch(/No hay conexión/)
  })

  it('401 borra el token y avisa a la sesión', async () => {
    tokenStorage.set('vencido')
    const onUnauthorized = vi.fn()
    setSessionHandlers({ onUnauthorized, onPasswordChangeRequired: vi.fn() })
    mockApi({ 'GET /stocks': apiError(401, 'NO_AUTENTICADO') })

    await catchError(apiRequest('/stocks'))

    expect(onUnauthorized).toHaveBeenCalledOnce()
    expect(tokenStorage.get()).toBeNull()
  })

  it('401 del login (skipSessionHandling) no dispara el logout global', async () => {
    const onUnauthorized = vi.fn()
    setSessionHandlers({ onUnauthorized, onPasswordChangeRequired: vi.fn() })
    mockApi({ 'POST /auth/login': apiError(401, 'CREDENCIALES_INVALIDAS') })

    const error = await catchError(apiRequest('/auth/login', { method: 'POST', body: {}, skipSessionHandling: true }))

    expect(errorMessage(error)).toBe('Correo o contraseña incorrectos.')
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('403 CAMBIO_CLAVE_REQUERIDO avisa a la sesión', async () => {
    const onPasswordChangeRequired = vi.fn()
    setSessionHandlers({ onUnauthorized: vi.fn(), onPasswordChangeRequired })
    mockApi({ 'GET /stocks': apiError(403, 'CAMBIO_CLAVE_REQUERIDO') })

    await catchError(apiRequest('/stocks'))

    expect(onPasswordChangeRequired).toHaveBeenCalledOnce()
  })
})

describe('errorMessage', () => {
  const make = (code: string, details: Record<string, unknown> = {}) =>
    new ApiError({ status: 409, code, message: 'servidor', details })

  it('LOTE_VENCIDO', () => {
    expect(errorMessage(make('LOTE_VENCIDO'))).toBe('El lote está vencido: no se puede dispensar ni trasladar.')
  })

  it('PRESCRIPCION_EXCEDIDA con cantidades', () => {
    expect(errorMessage(make('PRESCRIPCION_EXCEDIDA', { requested: 6, remaining: 5 }))).toMatch(/se pidieron 6 .* quedan 5/)
  })

  it('NO_AUTORIZADO con la acción intentada', () => {
    expect(errorMessage(make('NO_AUTORIZADO'), { action: 'aprobar traslados' })).toBe('No tienes permiso para aprobar traslados.')
  })

  it('VALIDACION muestra los mensajes por campo', () => {
    expect(errorMessage(make('VALIDACION', { fields: { reason: ['El motivo es obligatorio.'] } }))).toBe(
      'Revisa los datos: El motivo es obligatorio.',
    )
  })

  it('código desconocido → mensaje genérico', () => {
    expect(errorMessage(make('ALGO_RARO'))).toMatch(/error inesperado/)
  })
})
