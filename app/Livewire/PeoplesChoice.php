<?php

namespace App\Livewire;

use App\Models\Category;
use App\Services\PeoplesChoiceService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Livewire\Component;

/**
 * Admin control panel for the People's Choice Award: open/close voting, QR code,
 * live tally, reveal on the LED, reset, PDF report.
 */
class PeoplesChoice extends Component
{
    public $selectedCategory = '';
    public $base64pdf;

    public function mount(): void
    {
        abort_unless(auth()->user()?->role === 'admin', 403);
    }

    public function toggleOpen(): void
    {
        if ($slug = $this->slug()) {
            PeoplesChoiceService::setOpen($slug, !PeoplesChoiceService::isOpen($slug));
        }
    }

    public function toggleReveal(): void
    {
        if ($slug = $this->slug()) {
            PeoplesChoiceService::setRevealed($slug, !PeoplesChoiceService::isRevealed($slug));
        }
    }

    public function resetVotes(): void
    {
        if ($slug = $this->slug()) {
            $deleted = PeoplesChoiceService::resetVotes($slug);
            PeoplesChoiceService::setRevealed($slug, false);
            session()->flash('pc_message', $deleted . ' vote(s) deleted.');
        }
    }

    public function generatePdf(): void
    {
        $slug = $this->slug();
        if (!$slug) {
            return;
        }

        $category = Category::where('category', $slug)->first();
        $tally    = PeoplesChoiceService::tally($slug);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $html = view('generated_pdf.peoples-choice', compact('category', 'tally'))->render();

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('folio', 'portrait');
        $dompdf->render();

        $this->base64pdf = base64_encode($dompdf->output());
        $this->dispatch('openPeoplesChoicePdf');
    }

    private function slug(): ?string
    {
        $slug = (string) $this->selectedCategory;
        return $slug !== '' && Category::where('category', $slug)->exists() ? $slug : null;
    }

    public function render()
    {
        $categories = Category::where('is_active', 1)->orderBy('description')->get();
        $slug       = $this->slug();

        $data = compact('categories', 'slug');
        if ($slug) {
            $voteUrl = PeoplesChoiceService::voteUrl($slug);
            $data += [
                'isOpen'     => PeoplesChoiceService::isOpen($slug),
                'isRevealed' => PeoplesChoiceService::isRevealed($slug),
                'tally'      => PeoplesChoiceService::tally($slug),
                'voteUrl'    => $voteUrl,
                'qrSvg'      => PeoplesChoiceService::qrSvg($voteUrl),
                'displayUrl' => route('display_peoples_choice', $slug),
                'posterUrl'  => route('peoples-choice.poster', $slug),
                'qrPngUrl'   => route('peoples-choice.qr', $slug),
            ];
        }

        return view('livewire.peoples-choice', $data);
    }
}
