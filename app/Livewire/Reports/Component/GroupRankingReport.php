<?php

namespace App\Livewire\Reports\Component;

use App\Models\Category;
use App\Models\RefCriteria;
use App\Models\RefJudge;
use App\Models\RefParticipant;
use Dompdf\Dompdf;
use Dompdf\Options;
use Livewire\Component;

/**
 * Group events (e.g. Storytelling): each member is scored individually,
 * the group's score is the average of its members' final scores, and the
 * highest-scoring member of each group is that group's Best Speaker.
 */
class GroupRankingReport extends Component
{
    public $selectedCategory, $base64pdf;

    public function render()
    {
        $categories = Category::where('is_active', 1)->get();
        return view('livewire.reports.component.group-ranking-report', compact('categories'));
    }

    public function generateReport()
    {
        $this->validate([
            'selectedCategory' => 'required',
        ]);

        $category  = Category::where('category', $this->selectedCategory)->first();
        $judges    = RefJudge::active()->category($this->selectedCategory)->orderByNumber()->get();
        $criterias = RefCriteria::category($this->selectedCategory)->get();
        $members   = RefParticipant::category($this->selectedCategory)
            ->whereNotNull('group_name')
            ->where('group_name', '!=', '')
            ->get();

        $groups = $this->calculateGroups($members, $judges, $category, $criterias);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $htmlContent = view('generated_pdf.group-ranking-summary', compact('category', 'judges', 'groups'))->render();

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($htmlContent);
        $dompdf->setPaper('folio', 'landscape');
        $dompdf->render();

        $canvas      = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font        = $fontMetrics->getFont("Arial", "normal");
        $canvas->page_text(850, 10, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, 10, [0, 0, 0]);

        $this->base64pdf = base64_encode($dompdf->output());
        $this->dispatch('openGroupRankingModal');
    }

    private function calculateGroups($members, $judges, $category, $criterias): array
    {
        $isWeighted = $criterias->whereNotNull('segment')->whereNotNull('segment_weight')->isNotEmpty();
        $segmentedCriterias = $isWeighted ? $criterias->groupBy('segment') : collect();

        // Group names are matched case-insensitively so "Group A" and "group a" land together.
        $byGroup = $members->groupBy(fn($m) => mb_strtolower(trim($m->group_name)));

        $groups = [];
        foreach ($byGroup as $groupMembers) {
            $rows = [];
            foreach ($groupMembers as $member) {
                $judgeScores = [];
                foreach ($judges as $judge) {
                    $judgeScores[$judge->user_id] = $this->judgeScore($member, $judge, $category->category, $segmentedCriterias, $isWeighted);
                }

                $rows[] = [
                    'participant_no' => $member->participant_no,
                    'participant'    => $member->participant,
                    'school'         => $member->school,
                    'judge_scores'   => $judgeScores,
                    'deduction'      => $member->higalaayDeduction($category->category)?->deduction ?? 0,
                    // Same final score the individual Higalaay reports use: judge average, weights, minus deduction.
                    'score'          => round($member->averageHigalaay($category->category), 4),
                ];
            }

            usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);

            $total = array_sum(array_column($rows, 'score'));
            $count = count($rows);
            $top   = $rows[0]['score'] ?? 0;

            // Best speaker = highest-scoring member; ties are all listed.
            $bestSpeakers = $top > 0
                ? array_values(array_filter($rows, fn($r) => $r['score'] == $top))
                : [];
            foreach ($rows as &$row) {
                $row['is_best'] = $top > 0 && $row['score'] == $top;
            }
            unset($row);

            $groups[] = [
                'group_name'    => trim($groupMembers->first()->group_name),
                'members'       => $rows,
                'member_count'  => $count,
                'total'         => round($total, 4),
                'average'       => $count > 0 ? round($total / $count, 4) : 0,
                'best_speakers' => $bestSpeakers,
            ];
        }

        return bong_rank_arranger($groups, 'average', 'ordinal_rank', true, 'DESC');
    }

    /** One judge's score for a member (weighted by segment when the category uses weights). */
    private function judgeScore($member, $judge, $category, $segmentedCriterias, $isWeighted)
    {
        if (!$isWeighted) {
            return $member->getHigalaayScoreByJudge($judge->id, $category);
        }

        $weighted = 0;
        foreach ($segmentedCriterias as $segCriterias) {
            $segWeight = $segCriterias->first()->segment_weight;
            $segMax    = $segCriterias->sum('perfect_score');
            if ($segMax == 0) continue;
            $segRaw = 0;
            foreach ($segCriterias as $criteria) {
                $segRaw += $member->getHigalaayScoreByJudge($judge->id, $category, $criteria);
            }
            $weighted += ($segRaw / $segMax) * $segWeight;
        }
        return round($weighted, 4);
    }
}
