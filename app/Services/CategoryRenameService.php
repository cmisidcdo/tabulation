<?php

namespace App\Services;

use App\Models\RefJudge;
use App\Models\RefParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A category's slug (categories.category) is the only link to its data: it is stored as a
 * plain string — or as an element of a JSON array — across many tables and settings keys.
 * Renaming a slug must therefore move every reference with it, or the data is orphaned.
 */
class CategoryRenameService
{
    /** Tables holding the slug in a JSON array column (a record can belong to several categories). */
    private const JSON_ARRAY_MODELS = [
        'ref_participants' => RefParticipant::class,
        'ref_judges' => RefJudge::class,
    ];

    /** table => column holding the slug as a plain string. */
    private const STRING_COLUMNS = [
        'ref_criterias' => 'category',
        'higalaays' => 'category',
        'higalaay_deductions' => 'category',
        'ref_deductions' => 'category',
        'led_management' => 'category',
        'public_votes' => 'category',
        'technical_categories' => 'competition_category',
        'technical_scores' => 'competition_category',
        'technical_deductions' => 'competition_category',
    ];

    /** Settings stored per category as "<prefix><slug>". */
    private const SETTING_PREFIXES = [
        'scoring_locked_',
        'peoples_choice_open_',
        'peoples_choice_revealed_',
        'peoples_choice_mode_',
        'peoples_choice_geo_',
    ];

    /**
     * Move every reference from $from to $to. Wrap in the caller's transaction or runs its own.
     *
     * @return array<string,int> rows changed per table (only non-zero entries)
     */
    public static function rename(string $from, string $to): array
    {
        if ($from === $to) {
            return [];
        }

        return DB::transaction(function () use ($from, $to) {
            $changed = [];

            foreach (self::JSON_ARRAY_MODELS as $table => $model) {
                if (!Schema::hasTable($table)) continue;
                $count = 0;
                foreach ($model::whereJsonContains('category', $from)->get() as $record) {
                    $categories = array_map(fn($c) => $c === $from ? $to : $c, (array) $record->category);
                    $record->category = array_values(array_unique($categories));
                    $record->save();
                    $count++;
                }
                $changed[$table] = $count;
            }

            foreach (self::STRING_COLUMNS as $table => $column) {
                if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) continue;
                $changed[$table] = DB::table($table)->where($column, $from)->update([$column => $to]);
            }

            if (Schema::hasTable('settings')) {
                $count = 0;
                foreach (self::SETTING_PREFIXES as $prefix) {
                    if (!DB::table('settings')->where('name', $prefix . $from)->exists()) continue;
                    // A leftover setting for the new slug (e.g. from a deleted category) must not win
                    DB::table('settings')->where('name', $prefix . $to)->delete();
                    $count += DB::table('settings')->where('name', $prefix . $from)->update(['name' => $prefix . $to]);
                }
                $changed['settings'] = $count;
            }

            return array_filter($changed);
        });
    }
}
