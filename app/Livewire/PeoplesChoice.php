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

    // Geofence form (voting only allowed near the venue)
    public $geoEnabled = false;
    public $geoLat = '';
    public $geoLng = '';
    public $geoRadius = 150;

    public function mount(): void
    {
        abort_unless(auth()->user()?->role === 'admin', 403);
    }

    public function updatedSelectedCategory(): void
    {
        $this->loadGeofence();
    }

    private function loadGeofence(): void
    {
        $slug = $this->slug();
        $geo  = $slug ? PeoplesChoiceService::geofenceSettings($slug) : ['enabled' => false, 'lat' => null, 'lng' => null, 'radius' => 150];

        $this->geoEnabled = $geo['enabled'];
        $this->geoLat     = $geo['lat'] ?? '';
        $this->geoLng     = $geo['lng'] ?? '';
        $this->geoRadius  = $geo['radius'];
    }

    public function saveGeofence(): void
    {
        $slug = $this->slug();
        if (!$slug) {
            return;
        }

        $error = PeoplesChoiceService::setGeofence(
            $slug,
            (bool) $this->geoEnabled,
            $this->blankToNull($this->geoLat),
            $this->blankToNull($this->geoLng),
            $this->geoRadius
        );

        if ($error) {
            session()->flash('pc_geo_error', $error);
            return;
        }

        $this->loadGeofence();
        session()->flash('pc_geo_message', $this->geoEnabled
            ? 'Voting area saved. Only phones within ' . $this->geoRadius . ' m of the venue can vote.'
            : 'Voting area saved. Location check is OFF — anyone with the link can vote.');
    }

    private function blankToNull($value)
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    public function toggleOpen(): void
    {
        if ($slug = $this->slug()) {
            PeoplesChoiceService::setOpen($slug, !PeoplesChoiceService::isOpen($slug));
        }
    }

    public function setMode($mode): void
    {
        $slug = $this->slug();
        if (!$slug || !is_string($mode)) {
            return;
        }
        if (!PeoplesChoiceService::setMode($slug, $mode)) {
            session()->flash('pc_message', 'Votes already exist for this event. Reset votes first to change what the audience votes for.');
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
            $tally = PeoplesChoiceService::tally($slug);
            $data += [
                'mode'       => $tally['mode'],
                'target'     => PeoplesChoiceService::targetLabel($tally['mode']),
                'hasVotes'   => \App\Models\PublicVote::category($slug)->exists(),
                'isOpen'     => PeoplesChoiceService::isOpen($slug),
                'isRevealed' => PeoplesChoiceService::isRevealed($slug),
                'tally'      => $tally,
                'voteUrl'    => $voteUrl,
                'qrSvg'      => PeoplesChoiceService::qrSvg($voteUrl),
                'displayUrl' => route('display_peoples_choice', $slug),
                'posterUrl'  => route('peoples-choice.poster', $slug),
                'qrPngUrl'   => route('peoples-choice.qr', $slug),
                'geofence'   => $tally['geofence'],
            ];
        }

        return view('livewire.peoples-choice', $data);
    }
}
