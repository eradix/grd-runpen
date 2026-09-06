<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Runner\CodeRunner;
use App\Services\Runner\RunSlots;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Executes a script-mode snippet and streams its output back as it happens.
 *
 * Frontend-mode pens never come here — they run in the visitor's own browser.
 *
 * The response is server-sent events rather than one JSON body so a slow
 * program shows its output while it runs, instead of the page sitting blank
 * until the five-second deadline. Two event types are emitted:
 *
 *   event: output   {"stream":"out"|"err","text":"…"}
 *   event: done     the full RunResult
 *
 * Failures before the stream starts — validation, no free slot — are ordinary
 * JSON responses with a real status code, because they are not part of a run.
 */
class RunController extends Controller
{
    public function __invoke(Request $request, CodeRunner $runner, RunSlots $slots): StreamedResponse
    {
        $validated = $request->validate([
            // Rule::in against the config keys is the security boundary: the
            // image and command are then looked up from config, so no request
            // can influence what is executed, only which of three things is.
            'language' => ['required', 'string', Rule::in(array_keys(config('runner.languages')))],
            'code' => ['required', 'string', 'max:100000'],
            'stdin' => ['nullable', 'string', 'max:10000'],
        ]);

        // Taken before the response starts: a 429 has to be a 429, not an error
        // event inside a 200 stream. Released when the stream ends.
        $lock = $slots->acquire();

        return response()->stream(function () use ($validated, $runner, $lock): void {
            try {
                $result = $runner->run(
                    $validated['language'],
                    $validated['code'],
                    $validated['stdin'] ?? '',
                    fn (string $stream, string $text) => $this->send('output', ['stream' => $stream, 'text' => $text]),
                );

                $this->send('done', $result->toArray());
            } finally {
                $lock->release();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            // Tells nginx not to buffer the response; without it the stream
            // arrives all at once in production and the streaming is pointless.
            'X-Accel-Buffering' => 'no',
            // Deliberately no `Connection: keep-alive`: the stream ends when the
            // run does, and asking to keep the connection open leaves clients
            // that read to EOF (curl, anything not using fetch) waiting for a
            // close that never comes.
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data)."\n\n";

        // Both are needed: ob_flush pushes PHP's buffer out, flush pushes the
        // web server's. Guarded because there is not always a buffer to flush.
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }
}
