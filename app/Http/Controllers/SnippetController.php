<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Snippet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Saving a snippet always creates a new one — see the Snippet model for why.
 */
class SnippetController extends Controller
{
    /**
     * The most recently shared snippets.
     *
     * Everything saved here is shared by definition — a link exists for it — so
     * there is no visibility flag to respect. The preview is a first line
     * rather than the whole source: this is a list to recognise something in,
     * not to read it from.
     */
    public function index(): Response
    {
        $snippets = Snippet::query()
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Snippet $snippet): array => [
                'slug' => $snippet->slug,
                'mode' => $snippet->mode,
                'language' => $snippet->language,
                'label' => $snippet->mode === 'script'
                    ? (string) config("runner.languages.{$snippet->language}.label", $snippet->language)
                    : 'Frontend',
                'preview' => $this->preview($snippet),
                'createdAt' => $snippet->created_at->diffForHumans(),
            ]);

        return Inertia::render('recent', ['snippets' => $snippets]);
    }

    /** The first non-empty line of whatever the snippet actually contains. */
    private function preview(Snippet $snippet): string
    {
        $source = $snippet->mode === 'script' ? (string) $snippet->source : (string) $snippet->html;

        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '<?php')) {
                return mb_strimwidth($line, 0, 90, '…');
            }
        }

        return '(empty)';
    }

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
