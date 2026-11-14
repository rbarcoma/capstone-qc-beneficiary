<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained()->restrictOnDelete();
            $table->string('rule_code', 8);
            $table->string('policy_fingerprint', 64);
            $table->string('source_fingerprint', 64);
            $table->string('title');
            $table->text('message')->nullable();
            $table->json('evidence');
            $table->string('priority')->nullable();
            $table->boolean('contains_cost_data')->default(false);
            $table->string('evaluation_status')->default('requires_policy');
            $table->string('decision')->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['forecast_id', 'rule_code', 'policy_fingerprint'], 'recommendation_source_policy_unique');
        });
        Schema::create('recommendation_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_state');
            $table->string('to_state');
            $table->text('note');
            $table->unsignedInteger('recommendation_version');
            $table->json('source_snapshot');
            $table->timestamps();
        });
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->date('due_date');
            $table->string('status')->default('open');
            $table->text('outcome')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
        Schema::dropIfExists('recommendation_decisions');
        Schema::dropIfExists('recommendations');
    }
};
