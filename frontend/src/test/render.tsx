import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import type { ReactElement } from 'react'
import { MemoryRouter } from 'react-router'
import { tokenStorage } from '../api/tokenStorage'
import type { Role, User } from '../api/types'
import App from '../App'
import { AuthProvider } from '../auth/AuthProvider'
import { createQueryClient } from '../queryClient'

export function makeUser(role: Role, overrides: Partial<User> = {}): User {
  return {
    id: 10,
    name: `Usuario ${role}`,
    email: `${role}@fartmar.test`,
    role,
    is_active: true,
    must_change_password: false,
    ...overrides,
  }
}

// Ruta de /auth/me para mockApi.
export function meRoute(user: User) {
  return { 'GET /auth/me': { body: { data: user } } }
}

// Renderiza la aplicación completa en una ruta. Con `loggedIn` deja un token
// guardado para que AuthProvider consulte /auth/me (hay que simularlo).
export function renderApp(route: string, { loggedIn = true }: { loggedIn?: boolean } = {}) {
  if (loggedIn) tokenStorage.set('tok-test')
  return renderWithProviders(<App />, route)
}

export function renderWithProviders(ui: ReactElement, route = '/') {
  const queryClient = createQueryClient()
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[route]}>
        <AuthProvider>{ui}</AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
