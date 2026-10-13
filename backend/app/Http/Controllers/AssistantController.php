<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\AssistantService;
use App\Http\Requests\AssistantQueryRequest;
use App\Http\Resources\AssistantAnswerResource;
use App\Models\User;

/**
 * Asistente IA de consulta de inventario. El Form Request valida y
 * autoriza; AssistantService hace todo lo demás.
 */
class AssistantController extends Controller
{
    public function query(AssistantQueryRequest $request, AssistantService $assistant): AssistantAnswerResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AssistantAnswerResource($assistant->ask($user, $request->question()));
    }
}
