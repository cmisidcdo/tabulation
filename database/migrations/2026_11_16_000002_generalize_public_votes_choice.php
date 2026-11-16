<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People's Choice can now vote for a group OR an individual participant,
 * so the "group_*" columns become a generic "choice_*" and each vote records its mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_votes', function (Blueprint $table) {
            $table->renameColumn('group_key', 'choice_key');
            $table->renameColumn('group_name', 'choice_name');
        });

        Schema::table('public_votes', function (Blueprint $table) {
            $table->string('mode', 20)->default('group')->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('public_votes', function (Blueprint $table) {
            $table->dropColumn('mode');
        });

        Schema::table('public_votes', function (Blueprint $table) {
            $table->renameColumn('choice_key', 'group_key');
            $table->renameColumn('choice_name', 'group_name');
        });
    }
};
