<?php

namespace App\Livewire;

use App\Models\Category;
use App\Services\PeoplesChoiceService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * LED wall page for the People's Choice Award:
 * shows the voting QR while voting is open, and the winner once the admin reveals it.
 */
class PeoplesChoiceLed extends Component
{
    #[Locked]
    public string $slug;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    public function render()
    {
        $isRevealed = PeoplesChoiceService::isRevealed($this->slug);
        $isOpen     = PeoplesChoiceService::isOpen($this->slug);
        $voteUrl    = PeoplesChoiceService::voteUrl($this->slug);

        return view('livewire.peoples-choice-led', [
            'category'   => Category::where('category', $this->slug)->first(),
            'isRevealed' => $isRevealed,
            'isOpen'     => $isOpen,
            'target'     => PeoplesChoiceService::targetLabel(PeoplesChoiceService::mode($this->slug)),
            'winners'    => $isRevealed ? PeoplesChoiceService::tally($this->slug)['winners'] : [],
            'voteUrl'    => $voteUrl,
            'qrSvg'      => !$isRevealed && $isOpen ? PeoplesChoiceService::qrSvg($voteUrl) : null,
        ]);
    }
}
