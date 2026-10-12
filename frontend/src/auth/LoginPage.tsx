import { useMutation } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router'
import { ErrorAlert } from '../components/ui'
import { useAuth } from './AuthContext'

export function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  const mutation = useMutation({
    mutationFn: () => login(email.trim(), password),
    onSuccess: (loggedIn) => navigate(loggedIn.must_change_password ? '/cambiar-clave' : '/', { replace: true }),
  })

  if (user) return <Navigate to="/" replace />

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!mutation.isPending) mutation.mutate()
  }

  return (
    <main className="auth-page">
      <form className="card auth-card" onSubmit={handleSubmit}>
        <h1>FARTMAR IPS</h1>
        <p className="muted">Dispensación, inventario por lote y traslados.</p>
        <label>
          Correo
          <input type="email" autoComplete="username" autoFocus required value={email} onChange={(e) => setEmail(e.target.value)} />
        </label>
        <label>
          Contraseña
          <input type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        <ErrorAlert error={mutation.error} />
        <button type="submit" className="primary" disabled={mutation.isPending}>
          {mutation.isPending ? 'Ingresando…' : 'Ingresar'}
        </button>
      </form>
    </main>
  )
}
