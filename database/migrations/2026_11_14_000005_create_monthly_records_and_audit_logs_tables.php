<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meter_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->decimal('kwh', 15, 3);
            $table->decimal('billed_demand_kw', 15, 3)->nullable();
            $table->decimal('cost_php', 15, 2)->nullable();
            $table->decimal('power_factor', 5, 4)->nullable();
            $table->text('notes')->nullable();
            $table->string('verification_status')->default('draft')->index();
            $table->string('source');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['meter_id', 'month']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event')->index();
            $table->foreignId('monthly_record_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('monthly_records');
    }
};
