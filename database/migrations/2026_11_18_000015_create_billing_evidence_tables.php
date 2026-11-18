<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive only. Existing meter references are NOT customer-account mappings.
        Schema::create('utility_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('account_reference');
            $table->timestamps();
            $table->unique(['provider', 'account_reference']);
        });
        Schema::create('utility_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('meter_id')->unique()->constrained()->restrictOnDelete();
            $table->string('service_reference')->unique();
            $table->string('physical_meter_reference')->nullable();
            $table->string('mapping_reference');
            $table->timestamps();
        });
        Schema::create('billing_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_record_id')->constrained()->restrictOnDelete();
            $table->foreignId('utility_service_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('monthly_record_version');
            $table->unsignedInteger('version');
            $table->string('source_fingerprint', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('bill_date');
            $table->string('origin');
            $table->string('source_document');
            $table->string('document_hash', 64);
            $table->json('source_pages');
            $table->string('revision_reason', 2000);
            foreach (['import_kwh', 'export_kwh', 'renewable_kwh', 'own_use_kwh'] as $field) {
                $table->decimal($field, 15, 3)->nullable();
            }
            foreach (['energy_amount_php', 'export_credit_php', 'other_charges_php', 'applied_credits_php', 'current_charges_php', 'previous_balance_php', 'total_due_php'] as $field) {
                $table->decimal($field, 20, 2)->nullable();
            }
            $table->string('verification_status')->default('draft');
            // Historical identities: deleting an account must not erase authorship
            // or add a new restriction to Fortify's existing deletion behavior.
            $table->unsignedBigInteger('author_id');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['monthly_record_id', 'version']);
        });
        Schema::create('billing_evidence_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_evidence_id')->constrained('billing_evidence')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('parent_position')->nullable();
            $table->boolean('is_subtotal')->default(false);
            $table->string('category');
            $table->string('label');
            $table->string('basis_unit');
            $table->decimal('quantity', 20, 6)->nullable();
            $table->decimal('printed_rate', 20, 8)->nullable();
            $table->decimal('amount_php', 20, 2);
            $table->unsignedInteger('source_page');
            $table->unique(['billing_evidence_id', 'position'], 'bill_component_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_evidence_components');
        Schema::dropIfExists('billing_evidence');
        Schema::dropIfExists('utility_services');
        Schema::dropIfExists('utility_accounts');
    }
};
