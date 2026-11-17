<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('notice_key', 64);
            $table->timestamp('read_at');
            $table->unique(['user_id', 'notice_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_receipts');
    }
};
