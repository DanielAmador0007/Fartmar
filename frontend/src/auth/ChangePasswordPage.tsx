import { useMutation } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { ErrorAlert } from '../components/ui'
import { useAuth, useCurrentUser } from './AuthContext'
import { authApi } from './authApi'

const MIN_LENGTH = 12

// Validación local solo para avisar antes de enviar; las reglas completas
// (letras y números, máx. 72 bytes, distinta de la actual) las aplica la API.
function localProblem(password: string, confirmation: string): string | null {
  if (password.length < MIN_LENGTH) return `La nueva contraseña debe tener al menos ${MIN_LENGTH} caracteres.`
  if (password !== confirmation) return 'La confirmación no coincide con la nueva contraseña.'
  return null
}

export function ChangePasswordPage() {
  const user = useCurrentUser()
  const { updateUser, logout } = useAuth()
  const navigate = useNavigate()
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [problem, setProblem] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: () => authApi.changePassword({ current_password: current, password, password_confirmation: confirmation }),
    onSuccess: (updated) => {
      updateUser(updated)
      navigate('/', { replace: true })
    },
  })

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const found = localProblem(password, confirmation)
    setProblem(found)
    if (!found && !mutation.isPending) mutation.mutate()
  }

  return (
    <main className="auth-page">
      <form className="card auth-card" onSubmit={handleSubmit}>
        <h1>Cambiar contraseña</h1>
        {user.must_change_password && (
          <p className="alert alert-warning">Tienes una contraseña temporal. Debes cambiarla antes de continuar.</p>
        )}
        <label>
          Contraseña actual
          <input type="password" autoComplete="current-password" autoFocus required value={current} onChange={(e) => setCurrent(e.target.value)} />
        </label>
        <label>
          Nueva contraseña (mínimo {MIN_LENGTH} caracteres, con letras y números)
          <input type="password" autoComplete="new-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        <label>
          Confirmar nueva contraseña
          <input type="password" autoComplete="new-password" required value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
        </label>
        {problem && (
          <p role="alert" className="alert alert-error">
            {problem}
          </p>
        )}
        <ErrorAlert error={mutation.error} />
        <button type="submit" className="primary" disabled={mutation.isPending}>
          {mutation.isPending ? 'Guardando…' : 'Cambiar contraseña'}
        </button>
        <button type="button" className="link" onClick={() => void logout()}>
          Cerrar sesión
        </button>
      </form>
    </main>
  )
}
