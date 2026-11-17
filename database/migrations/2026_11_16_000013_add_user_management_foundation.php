<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('access_version')->default(1);
            $table->timestamp('deactivated_at')->nullable();
        });

        Schema::create('user_module_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // A row records a restriction, never a grant. An absent row leaves
            // the role baseline unchanged; validation/enforcement land separately.
            $table->string('module_key', 64);
            $table->timestamps();
            $table->unique(['user_id', 'module_key']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            // Historical identifier, deliberately not a foreign key: account
            // deletion must neither erase attribution nor delete/block events.
            // A live subject lookup is not historical name/role evidence.
            $table->unsignedBigInteger('subject_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['subject_user_id']);
            $table->dropColumn('subject_user_id');
        });
        Schema::dropIfExists('user_module_restrictions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'is_active', 'version', 'access_version', 'deactivated_at',
        ]));
    }
};
