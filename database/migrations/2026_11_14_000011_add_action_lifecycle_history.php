<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('completed_at')->nullable();
        });
        Schema::create('action_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('action_version');
            $table->json('before');
            $table->json('after');
            $table->timestamps();
            $table->unique(['action_id', 'action_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_updates');
        Schema::table('actions', fn (Blueprint $table) => $table->dropColumn(['version', 'completed_at']));
    }
};
