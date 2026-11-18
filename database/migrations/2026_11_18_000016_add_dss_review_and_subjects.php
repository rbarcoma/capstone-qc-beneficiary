<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dss_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('subject_key', 64);
            $table->unsignedInteger('version');
            $table->unsignedInteger('state_version')->default(1);
            $table->json('payload');
            $table->json('source_snapshot');
            $table->string('scope_hash', 64);
            $table->string('content_hash', 64);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('author_id');
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->string('approval_reference', 2000)->nullable();
            $table->string('approved_hash', 64)->nullable();
            $table->json('source_confirmation')->nullable();
            $table->timestamps();
            $table->unique(['building_id', 'kind', 'subject_key', 'version'], 'dss_configuration_version_unique');
        });
        Schema::create('dss_configuration_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dss_configuration_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('actor_id');
            $table->unsignedInteger('state_version');
            $table->string('event');
            $table->text('reference');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['dss_configuration_id', 'state_version'], 'dss_event_version_unique');
        });
        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('subject_key', 64)->default('building');
            $table->json('subject_snapshot')->nullable();
            $table->unique(['forecast_id', 'rule_code', 'policy_fingerprint', 'subject_key'], 'recommendation_subject_policy_unique');
        });
        // InnoDB uses the leading forecast_id index for its FK: install the
        // replacement before removing the original supporting index.
        Schema::table('recommendations', fn (Blueprint $table) => $table->dropUnique('recommendation_source_policy_unique'));
    }

    public function down(): void
    {
        // Refuse a lossy rollback rather than collapse distinct decisions/actions.
        if (DB::table('recommendations')->where('subject_key', '!=', 'building')->exists()) {
            throw new RuntimeException('Per-subject recommendation history must be retained; this rollback cannot collapse it.');
        }
        Schema::table('recommendations', function (Blueprint $table) {
            $table->unique(['forecast_id', 'rule_code', 'policy_fingerprint'], 'recommendation_source_policy_unique');
        });
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropUnique('recommendation_subject_policy_unique');
            $table->dropColumn(['subject_key', 'subject_snapshot']);
        });
        Schema::dropIfExists('dss_configuration_events');
        Schema::dropIfExists('dss_configurations');
    }
};
