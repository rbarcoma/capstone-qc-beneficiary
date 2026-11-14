<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `meters` per docs/DATABASE_DESIGN.md §2.1 (proposal §9.1): each meter /
     * Meralco account belongs to one building. `meralco_account_ref` is
     * confidential institutional data (RA 10173 / proposal §12.1): it is
     * stored here but only ever exposed masked in the UI, never sent raw to
     * the frontend.
     */
    public function up(): void
    {
        Schema::create('meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->string('meralco_account_ref')->unique();
            $table->string('tariff_class')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meters');
    }
};
