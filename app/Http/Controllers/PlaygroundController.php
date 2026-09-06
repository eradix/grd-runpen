<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Snippet;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the single page the whole app consists of, either empty or seeded
 * from a saved snippet.
 */
class PlaygroundController extends Controller
{
    public function __invoke(?Snippet $snippet = null): Response
    {
        return Inertia::render('playground', [
            'languages' => $this->languages(),
            'snippet' => $snippet?->only([
                'slug', 'mode', 'language', 'source', 'stdin', 'html', 'css', 'js', 'tailwind',
            ]),
        ]);
    }

    /**
     * The language dropdown is built from the same allowlist the runner uses,
     * so the UI can never offer something the backend would refuse.
     *
     * @return list<array{key: string, label: string, version: string, editor: string, sample: string}>
     */
    private function languages(): array
    {
        $languages = [];

        // config() is untyped, so the shape the React page relies on is built
        // explicitly here rather than passed through as-is.
        foreach ((array) config('runner.languages') as $key => $config) {
            $languages[] = [
                'key' => (string) $key,
                'label' => (string) $config['label'],
                'version' => (string) $config['version'],
                'editor' => (string) $config['editor'],
                'sample' => (string) $config['sample'],
            ];
        }

        return $languages;
    }
}
