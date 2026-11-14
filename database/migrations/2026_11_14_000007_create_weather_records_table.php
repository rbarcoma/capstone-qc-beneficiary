<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_records', function (Blueprint $table) {
            $table->id();
            $table->string('location_key', 64);
            $table->string('source');
            $table->date('month');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 10, 6);
            $table->json('weather_values');
            $table->json('units');
            $table->json('quality');
            $table->boolean('is_complete')->index();
            $table->json('raw_values');
            $table->json('source_metadata');
            $table->string('content_hash', 64);
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['location_key', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_records');
    }
};
