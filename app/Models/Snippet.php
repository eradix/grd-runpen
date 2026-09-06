<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A saved snippet, addressed by its slug at /p/{slug}.
 *
 * Rows are never updated. Editing a shared snippet and saving produces a new
 * row with a new slug, so a link always shows exactly what it showed when it
 * was sent.
 */
class Snippet extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'mode', 'language', 'source', 'stdin', 'html', 'css', 'js', 'tailwind',
    ];

    protected static function booted(): void
    {
        static::creating(function (Snippet $snippet): void {
            $snippet->slug ??= static::newSlug();
        });
    }

    /**
     * Eight lowercase base36 characters — short enough to read out loud, and
     * ~2.8 trillion combinations, so slugs are not worth guessing at.
     */
    public static function newSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(8));
        } while (static::where('slug', $slug)->exists());

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
