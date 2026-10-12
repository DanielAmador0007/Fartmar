// Simulador de fetch para pruebas (sin red real).
// Cada ruta es "MÉTODO /ruta" (sin /api/v1 ni query) y devuelve
// { status, body, headers } o una función que la calcula.
import { vi } from 'vitest'

export interface MockReply {
  status?: number
  body?: unknown
  headers?: Record<string, string>
}

export interface RecordedCall {
  method: string
  path: string
  query: URLSearchParams
  headers: Record<string, string>
  body: unknown
}

type Handler = MockReply | ((call: RecordedCall) => MockReply | Promise<MockReply>)

export function jsonResponse({ status = 200, body, headers = {} }: MockReply): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', 'X-Correlation-Id': 'corr-test-123', ...headers },
  })
}

export function apiError(status: number, code: string, message = 'mensaje del servidor', details: Record<string, unknown> = {}): MockReply {
  return { status, body: { error: { code, message, details } } }
}

export function mockApi(routes: Record<string, Handler>) {
  const calls: RecordedCall[] = []
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input), 'http://localhost')
    const path = url.pathname.replace(/^\/api\/v1/, '')
    const method = init?.method ?? 'GET'
    const call: RecordedCall = {
      method,
      path,
      query: url.searchParams,
      headers: (init?.headers ?? {}) as Record<string, string>,
      body: init?.body ? JSON.parse(String(init.body)) : undefined,
    }
    calls.push(call)
    const handler = routes[`${method} ${path}`]
    if (!handler) {
      return jsonResponse({ status: 404, body: { error: { code: 'NO_ENCONTRADO', message: `sin mock: ${method} ${path}`, details: {} } } })
    }
    const reply = typeof handler === 'function' ? await handler(call) : handler
    return jsonResponse(reply)
  })
  vi.stubGlobal('fetch', fetchMock)
  return { calls, fetchMock, callsTo: (method: string, path: string) => calls.filter((c) => c.method === method && c.path === path) }
}
