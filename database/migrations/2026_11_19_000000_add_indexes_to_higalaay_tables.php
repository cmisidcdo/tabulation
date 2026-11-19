<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index the columns the scoring page, reports and judge progress filter on.
     */
    public function up(): void
    {
        Schema::table('higalaays', function (Blueprint $table) {
            // Cell lookup in saveScore + page preload (category, participant IN (...))
            $table->index(['category', 'participant_id', 'criteria_id', 'judge_id'], 'higalaays_cell_index');
            // Judge progress counts
            $table->index(['category', 'judge_id'], 'higalaays_category_judge_index');
        });

        Schema::table('higalaay_deductions', function (Blueprint $table) {
            $table->index(['category', 'participant_id'], 'higalaay_deductions_category_participant_index');
        });
    }

    public function down(): void
    {
        Schema::table('higalaays', function (Blueprint $table) {
            $table->dropIndex('higalaays_cell_index');
            $table->dropIndex('higalaays_category_judge_index');
        });

        Schema::table('higalaay_deductions', function (Blueprint $table) {
            $table->dropIndex('higalaay_deductions_category_participant_index');
        });
    }
};
