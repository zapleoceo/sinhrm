<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('emoji', 16)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('kb_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('kb_categories')->nullOnDelete();
            $table->string('title', 200);
            $table->text('body_md');
            // Rendered on save by the Documents MarkdownRenderer: raw HTML escaped, unsafe links dropped.
            $table->text('body_html');
            $table->jsonb('tags');
            // {"type":"all"} | {"type":"branches","ids":[..]} | {"type":"roles","roles":[..]}
            $table->jsonb('audience');
            // draft | published
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'category_id']);
        });

        // Every saved change of title/body keeps the previous text (history, not an undo stack).
        Schema::create('kb_article_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('kb_articles')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->text('body_md');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['article_id', 'version']);
        });

        // "Was this helpful?" — one vote per user and article (changeable).
        Schema::create('kb_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('kb_articles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('helpful');
            $table->timestamps();
            $table->unique(['article_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_votes');
        Schema::dropIfExists('kb_article_versions');
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
    }
};
