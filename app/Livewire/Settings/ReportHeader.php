<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class ReportHeader extends Component
{
    use WithFileUploads;

    private const DIR = 'report-header';

    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg'];

    /** setting key => [component property, label]. Upload property is "{prop}Upload". */
    private const SLOTS = [
        'report_logo_left_1'  => ['prop' => 'logoLeft1',  'label' => 'Left Logo 1'],
        'report_logo_left_2'  => ['prop' => 'logoLeft2',  'label' => 'Left Logo 2'],
        'report_logo_right'   => ['prop' => 'logoRight1', 'label' => 'Right Logo 1'],
        'report_logo_right_2' => ['prop' => 'logoRight2', 'label' => 'Right Logo 2'],
        'report_watermark'    => ['prop' => 'watermark',  'label' => 'Watermark'],
        'report_footer'       => ['prop' => 'footer',     'label' => 'Footer Image'],
    ];

    // Text fields
    public $headerTitle;
    public $headerVenue;
    public $headerTime;
    public $headerVenueAlt;
    public $headerTimeAlt;

    // Image upload fields (new uploads)
    public $logoLeft1Upload;
    public $logoLeft2Upload;
    public $logoRight1Upload;
    public $logoRight2Upload;
    public $watermarkUpload;
    public $footerUpload;

    // Current saved filenames
    public $logoLeft1;
    public $logoLeft2;
    public $logoRight1;
    public $logoRight2;
    public $watermark;
    public $footer;

    // Setting key of the slot whose "choose from library" picker is open
    public ?string $pickerFor = null;

    public function mount()
    {
        $this->headerTitle    = Setting::get('report_header_title', '');
        $this->headerVenue    = Setting::get('report_header_venue', '');
        $this->headerTime     = Setting::get('report_header_time', '');
        $this->headerVenueAlt = Setting::get('report_header_venue_alt', '');
        $this->headerTimeAlt  = Setting::get('report_header_time_alt', '');

        foreach (self::SLOTS as $settingKey => $slot) {
            $this->{$slot['prop']} = Setting::get($settingKey);
        }
    }

    public function render()
    {
        return view('livewire.settings.report-header', [
            'slots' => self::SLOTS,
        ]);
    }

    /**
     * All images previously uploaded to the report-header folder, newest first.
     */
    #[Computed]
    public function libraryImages(): array
    {
        $disk = Storage::disk('public');

        return collect($disk->files(self::DIR))
            ->filter(fn ($path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true))
            ->map(fn ($path) => [
                'name'     => basename($path),
                'modified' => $disk->lastModified($path),
            ])
            ->sortByDesc('modified')
            ->values()
            ->all();
    }

    public function saveText()
    {
        $this->validate([
            'headerTitle'    => 'nullable|string|max:255',
            'headerVenue'    => 'nullable|string|max:255',
            'headerTime'     => 'nullable|string|max:100',
            'headerVenueAlt' => 'nullable|string|max:255',
            'headerTimeAlt'  => 'nullable|string|max:100',
        ]);

        Setting::set('report_header_title',     $this->headerTitle,    'Report header title prefix');
        Setting::set('report_header_venue',     $this->headerVenue,    'Report header default venue');
        Setting::set('report_header_time',      $this->headerTime,     'Report header default time');
        Setting::set('report_header_venue_alt', $this->headerVenueAlt, 'Report header alternate venue (e.g. bangga-sa-daygon)');
        Setting::set('report_header_time_alt',  $this->headerTimeAlt,  'Report header alternate time');

        session()->flash('textSaved', 'Text settings saved.');
    }

    public function saveImages()
    {
        $rules = [];
        foreach (self::SLOTS as $slot) {
            $rules[$slot['prop'] . 'Upload'] = 'nullable|image|max:2048';
        }
        $this->validate($rules);

        foreach (self::SLOTS as $settingKey => $slot) {
            $uploadProp = $slot['prop'] . 'Upload';
            if ($this->$uploadProp) {
                $path = $this->$uploadProp->store(self::DIR, 'public');
                Setting::set($settingKey, basename($path));
                $this->{$slot['prop']} = basename($path);
                $this->$uploadProp = null;
            }
        }

        unset($this->libraryImages);
        session()->flash('imagesSaved', 'Images saved.');
    }

    /**
     * Unassign the image from the slot. The file stays in the library so it can be reused.
     */
    public function removeImage(string $settingKey)
    {
        if (! isset(self::SLOTS[$settingKey])) {
            return;
        }

        Setting::set($settingKey, null);
        $this->{self::SLOTS[$settingKey]['prop']} = null;
    }

    public function openPicker(string $settingKey)
    {
        if (isset(self::SLOTS[$settingKey])) {
            unset($this->libraryImages);
            $this->pickerFor = $settingKey;
        }
    }

    public function closePicker()
    {
        $this->pickerFor = null;
    }

    /**
     * Assign an already-uploaded library image to the slot whose picker is open.
     */
    public function selectExisting(string $filename)
    {
        $settingKey = $this->pickerFor;
        $filename   = $this->resolveLibraryFile($filename);

        if (! $settingKey || ! isset(self::SLOTS[$settingKey]) || ! $filename) {
            return;
        }

        $prop = self::SLOTS[$settingKey]['prop'];
        Setting::set($settingKey, $filename);
        $this->$prop = $filename;
        $this->{$prop . 'Upload'} = null; // discard any pending upload for this slot

        $this->pickerFor = null;
        session()->flash('imagesSaved', self::SLOTS[$settingKey]['label'] . ' updated from library.');
    }

    /**
     * Permanently delete a file from the library, unassigning it from any slot that uses it.
     */
    public function deleteFromLibrary(string $filename)
    {
        $filename = $this->resolveLibraryFile($filename);
        if (! $filename) {
            return;
        }

        foreach (self::SLOTS as $settingKey => $slot) {
            if ($this->{$slot['prop']} === $filename) {
                Setting::set($settingKey, null);
                $this->{$slot['prop']} = null;
            }
        }

        Storage::disk('public')->delete(self::DIR . '/' . $filename);
        unset($this->libraryImages);
    }

    /**
     * Only accept plain filenames that actually exist in the library folder.
     */
    private function resolveLibraryFile(string $filename): ?string
    {
        $filename = basename($filename);

        return collect($this->libraryImages)->contains('name', $filename) ? $filename : null;
    }
}
