<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('kind')->index();
            $table->text('alt')->nullable();
            $table->json('keywords')->nullable();
            $table->text('search_text')->nullable();
            $table->string('status')->default('awaiting_upload')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->string('mime')->nullable();
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('hls_path')->nullable();
            $table->string('tus_id')->nullable()->index();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('media_items', function (Blueprint $table) {
                $table->fullText('search_text');
            });
        }

        if (! Schema::hasTable('videos')) {
            return;
        }

        $rows = DB::table('videos')->orderBy('id')->get();

        foreach ($rows as $row) {
            $search = trim(implode(' ', array_filter([$row->title, $row->slug])));

            DB::table('media_items')->insert([
                'id' => $row->id,
                'ulid' => $row->ulid,
                'title' => $row->title,
                'slug' => $row->slug,
                'kind' => 'video',
                'alt' => null,
                'keywords' => json_encode([]),
                'search_text' => $search === '' ? null : mb_strtolower($search),
                'status' => $row->status,
                'progress' => $row->progress,
                'error_message' => $row->error_message,
                'mime' => null,
                'extension' => null,
                'size' => null,
                'duration_seconds' => $row->duration_seconds,
                'width' => $row->width,
                'height' => $row->height,
                'hls_path' => $row->hls_path,
                'tus_id' => $row->tus_id,
                'uploaded_by' => $row->uploaded_by,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        $max = DB::table('media_items')->max('id');

        if (is_numeric($max) && Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_items AUTO_INCREMENT = '.((int) $max + 1));
        }

        if (Schema::hasTable('media')) {
            DB::table('media')
                ->where('model_type', 'App\\Models\\Video')
                ->update(['model_type' => 'App\\Models\\MediaItem']);
        }

        Schema::drop('videos');
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
