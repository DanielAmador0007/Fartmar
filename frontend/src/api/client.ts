// Cliente HTTP único de la aplicación.
//
// - Agrega el token Bearer y Accept: application/json.
// - Normaliza TODO error a ApiError { status, code, message, details,
//   correlationId }: errores de la API ({ error: { code, message, details } }),
//   respuestas no JSON (p. ej. un 502 del proxy) y fallos de red.
//   La interfaz nunca muestra JSON crudo ni trazas.
// - 401 → avisa a la sesión (logout + login).
// - 403 CAMBIO_CLAVE_REQUERIDO → avisa a la sesión (pantalla de cambio de clave).
//
// URL base relativa: en desarrollo Vite redirige /api a la API (vite.config.ts),
// así el navegador ve un solo origen.
import { tokenStorage } from './tokenStorage'

const BASE_URL = '/api/v1'

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly details: Record<string, unknown>
  readonly correlationId: string | null
  readonly retryAfter: number | null

  constructor(params: {
    status: number
    code: string
    message: string
    details?: Record<string, unknown>
    correlationId?: string | null
    retryAfter?: number | null
  }) {
    super(params.message)
    this.name = 'ApiError'
    this.status = params.status
    this.code = params.code
    this.details = params.details ?? {}
    this.correlationId = params.correlationId ?? null
    this.retryAfter = params.retryAfter ?? null
  }
}

// La sesión (AuthProvider) registra aquí qué hacer ante 401 y clave temporal.
interface SessionHandlers {
  onUnauthorized: () => void
  onPasswordChangeRequired: () => void
}

let sessionHandlers: SessionHandlers | null = null

export function setSessionHandlers(handlers: SessionHandlers | null): void {
  sessionHandlers = handlers
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH'
  body?: unknown
  query?: Record<string, string | number | boolean | null | undefined>
  headers?: Record<string, string>
  // El login responde 401 por credenciales: no debe disparar el logout global.
  skipSessionHandling?: boolean
}

export function buildQuery(query: RequestOptions['query']): string {
  if (!query) return ''
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') continue
    params.set(key, String(value))
  }
  const text = params.toString()
  return text ? `?${text}` : ''
}

function isErrorBody(value: unknown): value is { error: { code: string; message: string; details?: unknown } } {
  if (typeof value !== 'object' || value === null || !('error' in value)) return false
  const error = (value as { error: unknown }).error
  return typeof error === 'object' && error !== null && typeof (error as { code?: unknown }).code === 'string'
}

async function readJson(response: Response): Promise<unknown> {
  const text = await response.text()
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch {
    return undefined // no era JSON (HTML de un proxy, etc.)
  }
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', ...options.headers }
  const token = tokenStorage.get()
  if (token) headers.Authorization = `Bearer ${token}`
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'

  let response: Response
  try {
    response = await fetch(`${BASE_URL}${path}${buildQuery(options.query)}`, {
      method: options.method ?? 'GET',
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    })
  } catch {
    throw new ApiError({
      status: 0,
      code: 'SIN_CONEXION',
      message: 'No hay conexión con el servidor.',
    })
  }

  const correlationId = response.headers.get('X-Correlation-Id')
  const body = await readJson(response)

  if (response.ok) {
    return body as T
  }

  const error = isErrorBody(body)
    ? new ApiError({
        status: response.status,
        code: body.error.code,
        message: body.error.message,
        details: (typeof body.error.details === 'object' && body.error.details !== null
          ? body.error.details
          : {}) as Record<string, unknown>,
        correlationId,
        retryAfter: Number(response.headers.get('Retry-After')) || null,
      })
    : new ApiError({
        status: response.status,
        code: response.status === 401 ? 'NO_AUTENTICADO' : 'ERROR_INTERNO',
        message: 'Respuesta inesperada del servidor.',
        correlationId,
      })

  if (!options.skipSessionHandling) {
    if (error.status === 401) {
      tokenStorage.clear()
      sessionHandlers?.onUnauthorized()
    } else if (error.status === 403 && error.code === 'CAMBIO_CLAVE_REQUERIDO') {
      sessionHandlers?.onPasswordChangeRequired()
    }
  }

  throw error
}
