<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_votes', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('group_key');
            $table->string('group_name');
            $table->string('device_token', 64);
            $table->string('fingerprint', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            // One vote per device per event, enforced at the database level too.
            $table->unique(['category', 'device_token']);
            $table->index(['category', 'fingerprint']);
            $table->index(['category', 'group_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_votes');
    }
};
