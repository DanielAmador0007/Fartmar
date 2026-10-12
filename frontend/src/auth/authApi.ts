import { apiRequest } from '../api/client'
import type { DataEnvelope, LoginResponse, User } from '../api/types'

export const authApi = {
  login: (email: string, password: string) =>
    apiRequest<LoginResponse>('/auth/login', {
      method: 'POST',
      body: { email, password, device_name: 'spa' },
      skipSessionHandling: true,
    }),
  me: () => apiRequest<DataEnvelope<User>>('/auth/me').then((r) => r.data),
  logout: () => apiRequest<null>('/auth/logout', { method: 'POST', skipSessionHandling: true }),
  changePassword: (body: { current_password: string; password: string; password_confirmation: string }) =>
    apiRequest<DataEnvelope<User>>('/auth/password', { method: 'POST', body }).then((r) => r.data),
}
