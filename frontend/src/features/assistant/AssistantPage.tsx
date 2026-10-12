import { useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import type { AssistantAnswer, AssistantOutcome } from '../../api/types'
import { Empty, ErrorAlert } from '../../components/ui'
import { useSubmitGuard } from '../../lib/useSubmitGuard'
import { AssistantResultTable } from './AssistantResultTable'
import { QUESTION_MAX, QUESTION_MIN, useAskAssistant } from './assistantApi'

// Preguntas que el asistente sabe responder con la semilla (ver docs/asistente-ia.md).
const EXAMPLES = [
  '¿Qué lotes de acetaminofén vencen en los próximos 60 días en la farmacia central?',
  '¿Qué productos están bajo el mínimo en Urgencias?',
  '¿Qué traslados están en tránsito?',
  '¿Qué lotes están vencidos?',
]

interface Exchange {
  id: number
  question: string
  answer: AssistantAnswer
}

const OUTCOME_LABEL: Record<Exclude<AssistantOutcome, 'answered'>, { text: string; tone: string }> = {
  refused: { text: 'Consulta rechazada', tone: 'red' },
  out_of_scope: { text: 'Fuera de alcance', tone: 'gray' },
}

// La conversación vive solo en memoria de esta pantalla (S-97): al salir o recargar se pierde.
export function AssistantPage() {
  const [question, setQuestion] = useState('')
  const [history, setHistory] = useState<Exchange[]>([])
  const nextId = useRef(1)
  const textarea = useRef<HTMLTextAreaElement>(null)
  const mutation = useAskAssistant()
  const guard = useSubmitGuard()

  const trimmed = question.trim()
  const tooShort = trimmed.length > 0 && trimmed.length < QUESTION_MIN
  const valid = trimmed.length >= QUESTION_MIN && question.length <= QUESTION_MAX

  function ask(text: string) {
    const clean = text.trim()
    if (clean.length < QUESTION_MIN || clean.length > QUESTION_MAX) return
    void guard(() =>
      mutation.mutateAsync({ question: clean }).then((answer) => {
        const id = nextId.current++
        setHistory((h) => [{ id, question: clean, answer }, ...h])
        setQuestion('')
        textarea.current?.focus()
      }),
    )
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (mutation.isPending) return
    ask(question)
  }

  // Enter envía; Shift+Enter hace salto de línea (y no se interrumpe una composición IME).
  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
      event.preventDefault()
      if (!mutation.isPending) ask(question)
    }
  }

  function askExample(text: string) {
    setQuestion(text)
    ask(text)
  }

  return (
    <>
      <h1>Asistente de inventario</h1>
      <p className="alert alert-warning">El asistente solo consulta inventario; no tiene acceso a datos de pacientes.</p>

      <form className="card" onSubmit={handleSubmit} aria-label="Pregunta al asistente">
        <label>
          Pregunta
          <textarea
            ref={textarea}
            autoFocus
            rows={3}
            maxLength={QUESTION_MAX}
            value={question}
            placeholder="Ej.: ¿Cuánto stock de ibuprofeno hay en Urgencias?"
            aria-describedby="assistant-help"
            onChange={(e) => setQuestion(e.target.value)}
            onKeyDown={handleKeyDown}
          />
        </label>
        <div className="assistant-form-footer">
          <span id="assistant-help" className="muted">
            Enter para enviar · Shift+Enter para nueva línea · {question.length}/{QUESTION_MAX}
          </span>
          <button type="submit" className="primary" disabled={!valid || mutation.isPending}>
            {mutation.isPending ? 'Consultando…' : 'Preguntar'}
          </button>
        </div>
        {tooShort && <p className="field-error">Escribe al menos {QUESTION_MIN} caracteres.</p>}
        <ErrorAlert error={mutation.error} action="usar el asistente" />

        <div className="assistant-examples" aria-label="Preguntas de ejemplo" role="group">
          <span className="muted">Ejemplos:</span>
          {EXAMPLES.map((text) => (
            <button key={text} type="button" className="chip" disabled={mutation.isPending} onClick={() => askExample(text)}>
              {text}
            </button>
          ))}
        </div>
      </form>

      <section aria-label="Conversación">
        {mutation.isPending && (
          <article className="card exchange" aria-busy="true">
            <p className="exchange-question">{mutation.variables.question}</p>
            <p className="muted" role="status">
              Consultando…
            </p>
          </article>
        )}
        {history.length === 0 && !mutation.isPending && <Empty>Aún no has hecho preguntas en esta sesión.</Empty>}
        {history.map((exchange) => (
          <ExchangeCard key={exchange.id} exchange={exchange} />
        ))}
        {history.length > 0 && (
          <button type="button" className="link" onClick={() => setHistory([])}>
            Limpiar conversación
          </button>
        )}
      </section>
    </>
  )
}

function ExchangeCard({ exchange }: { exchange: Exchange }) {
  const { answer } = exchange
  const label = answer.outcome === 'answered' ? null : OUTCOME_LABEL[answer.outcome]

  return (
    <article className={`card exchange outcome-${answer.outcome}`} aria-label={`Respuesta a: ${exchange.question}`}>
      <p className="exchange-question">{exchange.question}</p>
      {label && <span className={`badge badge-${label.tone}`}>{label.text}</span>}
      {/* Texto plano: React lo escapa; los saltos de línea los respeta el CSS (white-space: pre-line). */}
      <p className="assistant-answer">{answer.answer}</p>
      {answer.result && <AssistantResultTable result={answer.result} />}
      <details className="assistant-tools">
        <summary>
          Herramientas usadas ({answer.tools_used.length}) · proveedor: {answer.provider}
        </summary>
        {answer.tools_used.length === 0 ? (
          <p className="muted">No usó herramientas.</p>
        ) : (
          <ul>
            {answer.tools_used.map((tool, index) => (
              <li key={index}>
                <code>{tool.name}</code> {formatArguments(tool.arguments)}{' '}
                {tool.ok ? <span className="badge badge-green">ok</span> : <span className="badge badge-red">rechazada</span>}
              </li>
            ))}
          </ul>
        )}
      </details>
    </article>
  )
}

// "days=60, warehouse_code=FAR-CEN": argumentos tal como los pidió el modelo, en texto.
function formatArguments(args: Record<string, unknown>): string {
  const parts = Object.entries(args ?? {}).map(
    ([key, value]) => `${key}=${typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value)}`,
  )
  return parts.length > 0 ? `(${parts.join(', ')})` : '()'
}
