<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snippets are immutable: saving mints a new row and a new slug, the way 3v4l
 * and pastebin work. That is why there is no owner column, no edit key and no
 * updated_at — there is nothing to authorize, because nothing is ever changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snippets', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            // 'script' runs server-side in a container; 'frontend' is rendered
            // in the visitor's own browser and never reaches the runner.
            $table->string('mode');

            // Script mode.
            $table->string('language')->nullable();
            $table->text('source')->nullable();
            $table->text('stdin')->nullable();

            // Frontend mode.
            $table->text('html')->nullable();
            $table->text('css')->nullable();
            $table->text('js')->nullable();
            $table->string('tailwind')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snippets');
    }
};
