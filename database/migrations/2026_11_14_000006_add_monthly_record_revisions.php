<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_records', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('monthly_record_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_record_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->json('before');
            $table->json('after');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_record_revisions');
        Schema::table('monthly_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_edited_by');
            $table->dropColumn('version');
        });
    }
};
