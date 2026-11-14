<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->foreignId('dataset_id')->constrained()->restrictOnDelete();
            $table->string('issuance_key', 64)->unique();
            $table->string('dataset_fingerprint', 64);
            $table->string('engine_signature', 64);
            $table->string('model_version', 64);
            $table->string('model_name');
            $table->date('training_end_month');
            $table->date('target_month')->index();
            $table->decimal('predicted_kwh', 18, 3);
            $table->decimal('baseline_kwh', 18, 3);
            $table->decimal('lower_kwh', 18, 3);
            $table->decimal('upper_kwh', 18, 3);
            $table->json('worker_result');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
        Schema::create('forecast_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->decimal('actual_kwh', 18, 3);
            $table->decimal('predicted_kwh', 18, 3);
            $table->decimal('baseline_kwh', 18, 3);
            $table->date('train_end_month');
            $table->unsignedInteger('training_rows');
            $table->timestamps();
            $table->unique(['forecast_id', 'month']);
        });
        Schema::create('forecast_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('valid')->default(false);
            $table->string('reason');
            $table->decimal('actual_kwh', 18, 3)->nullable();
            $table->double('absolute_error')->nullable();
            $table->double('squared_error')->nullable();
            $table->double('percentage_error')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->string('state_hash', 64);
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('forecast_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropConstrainedForeignId('forecast_id'));
        Schema::dropIfExists('forecast_errors');
        Schema::dropIfExists('forecast_results');
        Schema::dropIfExists('forecasts');
    }
};
