import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import App from './App'

describe('App', () => {
  it('muestra el título de la aplicación', () => {
    render(<App />)

    expect(screen.getByRole('heading', { level: 1, name: 'FARTMAR IPS' })).toBeInTheDocument()
  })
})
