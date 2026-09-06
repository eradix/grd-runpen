<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Snippet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saving a snippet always creates a new one — see the Snippet model for why.
 */
class SnippetController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['script', 'frontend'])],
            'language' => ['nullable', Rule::in(array_keys(config('runner.languages')))],
            'source' => ['nullable', 'string', 'max:100000'],
            'stdin' => ['nullable', 'string', 'max:10000'],
            'html' => ['nullable', 'string', 'max:100000'],
            'css' => ['nullable', 'string', 'max:100000'],
            'js' => ['nullable', 'string', 'max:100000'],
            'tailwind' => ['nullable', Rule::in(['3', '4'])],
        ]);

        $snippet = Snippet::create($validated);

        return response()->json([
            'slug' => $snippet->slug,
            'url' => route('snippet.show', $snippet),
        ], 201);
    }
}
