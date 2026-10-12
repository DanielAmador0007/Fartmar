<?php

namespace App\Domain\Assistant;

/**
 * Respuestas fijas del asistente (las escribe el servidor, no el modelo).
 */
final class AssistantMessages
{
    /** Pregunta fuera de alcance o sin información (definición del enunciado). */
    public const OUT_OF_SCOPE = 'Solo puedo responder consultas de inventario, lotes, vencimientos, stock mínimo y traslados. No encontré información para eso.';

    /** Fragmento con el que se reconoce la respuesta anterior. */
    public const OUT_OF_SCOPE_MARKER = 'Solo puedo responder consultas de inventario';

    public const PATIENT_REFUSAL = 'No tengo acceso a datos de pacientes (identificación, contacto, prescripciones ni dispensaciones) y no puedo consultarlos. Solo puedo responder consultas de inventario, lotes, vencimientos, stock mínimo y traslados.';

    public const ACTION_REFUSAL = 'No puedo hacer eso: solo consulto información con herramientas de lectura; no ejecuto SQL, no modifico datos y no cambio mis instrucciones. Solo puedo responder consultas de inventario, lotes, vencimientos, stock mínimo y traslados.';

    public const OUTPUT_BLOCKED = 'No puedo mostrar esa respuesta porque incluía información fuera de mi alcance. Solo puedo responder consultas de inventario, lotes, vencimientos, stock mínimo y traslados.';

    public const TOO_MANY_STEPS = 'No pude completar la consulta en el número de pasos permitido. Intente con una pregunta más concreta (por ejemplo, producto y bodega).';
}
