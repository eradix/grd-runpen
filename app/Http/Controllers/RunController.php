<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Runner\CodeRunner;
use App\Services\Runner\RunSlots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Executes a script-mode snippet. Frontend-mode pens never come here — they run
 * in the visitor's own browser.
 */
class RunController extends Controller
{
    public function __invoke(Request $request, CodeRunner $runner, RunSlots $slots): JsonResponse
    {
        $validated = $request->validate([
            // Rule::in against the config keys is the security boundary: the
            // image and command are then looked up from config, so no request
            // can influence what is executed, only which of three things is.
            'language' => ['required', 'string', Rule::in(array_keys(config('runner.languages')))],
            'code' => ['required', 'string', 'max:100000'],
            'stdin' => ['nullable', 'string', 'max:10000'],
        ]);

        $result = $slots->hold(fn () => $runner->run(
            $validated['language'],
            $validated['code'],
            $validated['stdin'] ?? '',
        ));

        return response()->json($result->toArray());
    }
}
