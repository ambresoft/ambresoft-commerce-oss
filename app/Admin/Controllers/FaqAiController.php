<?php

namespace App\Admin\Controllers;

use App\Website\Infrastructure\FaqAiGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FaqAiController
{
    public function generate(Request $request, FaqAiGenerator $generator): JsonResponse
    {
        return response()->json($generator->generate((string) $request->user()->id, $request->only(['owner', 'object_id', 'locale', 'count', 'source_name', 'source_text', 'question'])));
    }

    public function chat(Request $request, FaqAiGenerator $generator): JsonResponse
    {
        return response()->json($generator->chat((string) $request->user()->id, $request->only(['messages', 'current'])));
    }
}
