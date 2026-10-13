import { useMutation } from '@tanstack/react-query'
import { apiRequest } from '../../api/client'
import type { AssistantAnswer, AssistantQueryRequest, DataEnvelope } from '../../api/types'

// Límites de la pregunta (AssistantQueryRequest en openapi 0.6.0, S-94).
export const QUESTION_MIN = 3
export const QUESTION_MAX = 500

export const assistantApi = {
  ask: (body: AssistantQueryRequest) =>
    apiRequest<DataEnvelope<AssistantAnswer>>('/assistant/query', { method: 'POST', body }).then((r) => r.data),
}

// Es POST pero no cambia datos: se usa mutación porque cada pregunta es una acción
// del usuario (no se cachea ni se repite sola; mutations.retry = false).
export function useAskAssistant() {
  return useMutation({ mutationFn: assistantApi.ask })
}
