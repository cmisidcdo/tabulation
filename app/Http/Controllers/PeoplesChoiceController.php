<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\PeoplesChoiceService;

class PeoplesChoiceController extends Controller
{
    /** Public voting page reached via the QR code (no login). */
    public function vote($slug)
    {
        $category = Category::where('category', $slug)->where('is_active', 1)->firstOrFail();
        return view('public-vote', compact('category'));
    }

    /** Public LED page: QR while voting, winner once revealed. */
    public function led($slug)
    {
        $category = Category::where('category', $slug)->where('is_active', 1)->firstOrFail();
        return view('led_display.peoples_choice', compact('category'));
    }

    /** Admin: printable QR poster. */
    public function poster($slug)
    {
        $this->authorizeAdmin();
        $category = Category::where('category', $slug)->firstOrFail();
        $voteUrl  = PeoplesChoiceService::voteUrl($slug);
        $qr       = PeoplesChoiceService::qrPngDataUri($voteUrl, 16);
        return view('peoples-choice-poster', compact('category', 'voteUrl', 'qr'));
    }

    /** Admin: download the QR code as a PNG file. */
    public function qrPng($slug)
    {
        $this->authorizeAdmin();
        Category::where('category', $slug)->firstOrFail();

        $dataUri = PeoplesChoiceService::qrPngDataUri(PeoplesChoiceService::voteUrl($slug), 16);
        $png     = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1));

        return response($png, 200, [
            'Content-Type'        => 'image/png',
            'Content-Disposition' => 'attachment; filename="peoples-choice-' . $slug . '-qr.png"',
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->role === 'admin', 403);
    }
}
