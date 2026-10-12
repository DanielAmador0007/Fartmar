// Mapa código de error → mensaje en español para personal de farmacia.
// Usa los `details` del backend cuando aportan números concretos
// (disponible vs solicitado, etc.). Si el código es desconocido se usa un
// texto genérico: nunca se muestra JSON crudo ni trazas.
import { ApiError } from './client'

function num(details: Record<string, unknown>, key: string): number | null {
  const value = details[key]
  return typeof value === 'number' ? value : null
}

function str(details: Record<string, unknown>, key: string): string | null {
  const value = details[key]
  return typeof value === 'string' ? value : null
}

function validationMessage(details: Record<string, unknown>): string {
  const fields = details.fields
  if (typeof fields === 'object' && fields !== null) {
    const messages = Object.values(fields as Record<string, unknown>)
      .flatMap((value) => (Array.isArray(value) ? value : [value]))
      .filter((value): value is string => typeof value === 'string')
    if (messages.length > 0) return `Revisa los datos: ${messages.slice(0, 3).join(' ')}`
  }
  return 'Revisa los datos ingresados.'
}

const GENERIC = 'Ocurrió un error inesperado. Intenta de nuevo; si persiste, reporta el código de soporte.'

// Mensaje del servidor (ya en español) si es texto corto de una línea; nunca trazas.
function serverMessage(error: ApiError): string | null {
  const text = error.message.trim()
  return text && text.length <= 300 && !text.includes('\n') ? text : null
}

const PRESCRIPTION_REASONS: Record<string, string> = {
  COMPLETADA: 'La prescripción ya fue entregada en su totalidad.',
  ANULADA: 'La prescripción fue anulada y no se puede dispensar.',
  PACIENTE_NO_CORRESPONDE: 'La prescripción no pertenece al paciente seleccionado.',
  LINEA_NO_CORRESPONDE: 'Algunos medicamentos no hacen parte de la prescripción. Actualiza la pantalla.',
}

// PRESCRIPCION_NO_VIGENTE agrupa varios motivos (details.reason).
function prescriptionNotValid(d: Record<string, unknown>, fallback: string | null): string {
  const reason = str(d, 'reason')
  if (reason === 'VENCIDA') {
    const validUntil = str(d, 'valid_until')
    return validUntil
      ? `La prescripción venció el ${validUntil}. El paciente necesita una nueva fórmula.`
      : 'La prescripción está vencida. El paciente necesita una nueva fórmula.'
  }
  if (reason && PRESCRIPTION_REASONS[reason]) return PRESCRIPTION_REASONS[reason]
  return fallback ?? 'La prescripción no está vigente.'
}

export interface ErrorContext {
  // Qué intentaba hacer el usuario, para el 403: "No tienes permiso para <action>."
  action?: string
}

export function errorMessage(error: unknown, context: ErrorContext = {}): string {
  if (!(error instanceof ApiError)) {
    return 'Ocurrió un error inesperado. Intenta de nuevo.'
  }
  const d = error.details

  switch (error.code) {
    case 'STOCK_INSUFICIENTE': {
      const requested = num(d, 'requested')
      const available = num(d, 'available')
      if (requested !== null && available !== null) {
        return `Stock insuficiente: se solicitaron ${requested} unidades y solo hay ${available} disponibles en lotes no vencidos.`
      }
      return 'Stock insuficiente en la bodega para la cantidad solicitada.'
    }
    case 'LOTE_VENCIDO':
      return 'El lote está vencido: no se puede dispensar ni trasladar.'
    case 'PRESCRIPCION_EXCEDIDA': {
      const requested = num(d, 'requested')
      const remaining = num(d, 'remaining')
      if (requested !== null && remaining !== null) {
        return `La cantidad supera lo prescrito: se pidieron ${requested} y a la prescripción solo le quedan ${remaining} por entregar.`
      }
      return 'La cantidad supera lo que queda por entregar de la prescripción.'
    }
    case 'PRESCRIPCION_NO_VIGENTE':
      return prescriptionNotValid(d, serverMessage(error))
    case 'TRANSICION_INVALIDA':
      return 'Esta acción ya no es válida en el estado actual (otro usuario pudo haberlo cambiado). Actualiza la pantalla.'
    case 'SEGREGACION_FUNCIONES': {
      const reason = str(d, 'reason')
      if (reason === 'ES_RECEPTOR') return 'Quien recibió el traslado no puede resolver su discrepancia: debe hacerlo otro regente.'
      return 'Esta acción la debe hacer un usuario distinto de quien creó o solicitó el registro.'
    }
    case 'IDEMPOTENCIA_CONFLICTO':
      return 'Esta solicitud ya se registró antes con datos diferentes. Revisa los datos y confirma de nuevo.'
    case 'CREDENCIALES_INVALIDAS':
      return 'Correo o contraseña incorrectos.'
    case 'VALIDACION':
      return validationMessage(d)
    case 'NO_AUTENTICADO':
      return 'Tu sesión expiró. Inicia sesión de nuevo.'
    case 'NO_AUTORIZADO':
      return context.action ? `No tienes permiso para ${context.action}.` : 'No tienes permiso para realizar esta acción.'
    case 'NO_ENCONTRADO':
      return 'No se encontró el registro solicitado.'
    case 'DEMASIADAS_SOLICITUDES':
      return error.retryAfter
        ? `Demasiadas solicitudes. Espera ${error.retryAfter} segundos e intenta de nuevo.`
        : 'Demasiadas solicitudes. Espera un momento e intenta de nuevo.'
    case 'CONFLICTO_CONCURRENCIA':
      return 'Otro usuario estaba modificando las mismas existencias. Intenta de nuevo: es seguro reintentar.'
    case 'TRASLADO_INVALIDO':
      // details.reason (MISMA_BODEGA, PRODUCTO_REPETIDO, LOTES_NO_COINCIDEN…): el texto del servidor es específico.
      return serverMessage(error) ?? 'Los datos del traslado no son válidos. Revisa las cantidades y los lotes.'
    case 'AJUSTE_INVALIDO':
      return serverMessage(error) ?? 'El ajuste no es válido. Revisa la bodega, el lote y la cantidad.'
    case 'OPERACION_NO_PERMITIDA':
      return 'No puedes desactivarte ni quitarte el rol de administrador a ti mismo.'
    case 'ASISTENTE_NO_DISPONIBLE':
      // details.provider no se muestra: es un dato técnico, no ayuda al usuario.
      return 'El asistente no está disponible en este momento. Intenta más tarde; el resto del sistema funciona con normalidad.'
    case 'CAMBIO_CLAVE_REQUERIDO':
      return 'Debes cambiar tu contraseña temporal antes de continuar.'
    case 'SIN_CONEXION':
      return 'No hay conexión con el servidor. Revisa la red e intenta de nuevo.'
    case 'ERROR_INTERNO':
      return GENERIC
    default:
      // Código sin texto propio: el mensaje del servidor ya viene en español para el usuario.
      return serverMessage(error) ?? GENERIC
  }
}

export function correlationIdOf(error: unknown): string | null {
  return error instanceof ApiError ? error.correlationId : null
}
