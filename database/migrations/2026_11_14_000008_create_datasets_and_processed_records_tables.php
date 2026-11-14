<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('pipeline_version');
            $table->date('cutoff_month');
            $table->string('fingerprint', 64);
            $table->json('input_manifest');
            $table->json('issues');
            $table->unsignedInteger('rows_count');
            $table->unsignedInteger('weather_complete_count');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['building_id', 'version']);
            $table->unique(['building_id', 'fingerprint']);
        });
        Schema::create('processed_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->decimal('kwh', 18, 3);
            $table->decimal('cost_php', 18, 2)->nullable();
            $table->json('source_snapshot');
            $table->foreignId('weather_record_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('weather_snapshot')->nullable();
            $table->boolean('weather_complete');
            $table->json('features');
            $table->json('metrics');
            $table->timestamps();
            $table->unique(['dataset_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_records');
        Schema::dropIfExists('datasets');
    }
};
