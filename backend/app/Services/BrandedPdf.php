<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Models\User;
use Modules\Organisation\Models\Business;
use Modules\Settings\Services\SettingsService;

/**
 * A4 documents (statements, reports) with the business's letterhead: logo, name, contact details,
 * KRA PIN and its primary colour (Settings → Branding). Everything is embedded, so the PDF never
 * fetches anything over the network.
 */
class BrandedPdf
{
    /** Wider tables turn the page to landscape. */
    private const LANDSCAPE_FROM_COLUMNS = 7;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array{
     *     title: string,
     *     subtitle?: string|null,
     *     meta?: list<array{0: string, 1: string}>,
     *     summary?: list<array{0: string, 1: string}>,
     *     columns: list<array{label: string, numeric?: bool}>,
     *     rows: list<list<string>>,
     *     totals?: list<string>|null,
     *     notes?: list<string>,
     * }  $document
     */
    public function download(array $document, string $filename, User $user): Response
    {
        $landscape = count($document['columns']) > self::LANDSCAPE_FROM_COLUMNS;

        $pdf = Pdf::loadView('pdf.document', [
            'doc' => $document + ['subtitle' => null, 'meta' => [], 'summary' => [], 'totals' => null, 'notes' => []],
            'letterhead' => $this->letterhead(),
            'generated' => 'Generated '.CarbonImmutable::now(config('app.timezone'))->format('j M Y H:i').' by '.$user->name,
        ])
            ->setPaper('a4', $landscape ? 'landscape' : 'portrait')
            ->setOption('enable_font_subsetting', true);

        // "Page x of y" is drawn after layout: dompdf does not support CSS counter(pages).
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 38, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.47, 0.47, 0.47]);

        return $pdf->download($filename);
    }

    /**
     * A customer or supplier statement (CustomerAccountService / SupplierAccountService::statement)
     * as document columns, rows and totals. Positive amounts raise the balance.
     *
     * @param  array{from: string, to: string, openingCents: int, closingCents: int, lines: list<array{date: string, dueDate: string|null, reference: string, description: string, amountCents: int, balanceCents: int}>}  $statement
     * @return array{columns: list<array{label: string, numeric?: bool}>, rows: list<list<string>>, totals: list<string>}
     */
    public static function statementTable(array $statement, string $raisesLabel, string $lowersLabel): array
    {
        $tz = config('app.timezone');
        $date = fn (?string $d) => $d ? CarbonImmutable::parse($d)->setTimezone($tz)->format('j M Y') : '';
        $lines = $statement['lines'];

        return [
            'columns' => [
                ['label' => 'Date'], ['label' => 'Reference'], ['label' => 'Details'], ['label' => 'Due'],
                ['label' => $raisesLabel, 'numeric' => true], ['label' => $lowersLabel, 'numeric' => true], ['label' => 'Balance', 'numeric' => true],
            ],
            'rows' => [
                [$date($statement['from']), '', 'Opening balance', '', '', '', self::money($statement['openingCents'])],
                ...array_map(fn (array $l) => [
                    $date($l['date']), $l['reference'], $l['description'], $date($l['dueDate']),
                    $l['amountCents'] > 0 ? self::money($l['amountCents']) : '',
                    $l['amountCents'] < 0 ? self::money(-$l['amountCents']) : '',
                    self::money($l['balanceCents']),
                ], $lines),
            ],
            'totals' => [
                'Closing balance', '', '', '',
                self::money(array_sum(array_filter(array_column($lines, 'amountCents'), fn ($a) => $a > 0))),
                self::money(-array_sum(array_filter(array_column($lines, 'amountCents'), fn ($a) => $a < 0))),
                self::money($statement['closingCents']),
            ],
        ];
    }

    /** "1,234.56" from cents. */
    public static function money(int|float|null $cents): string
    {
        return $cents === null ? '' : number_format(((int) $cents) / 100, 2);
    }

    /** @return array{name: string, lines: list<string>, logo: string|null, colour: string} */
    private function letterhead(): array
    {
        $business = Business::query()->first();
        $name = $this->settings->get('branding.display_name') ?: ($business?->name ?? config('app.name'));

        return [
            'name' => $name,
            'lines' => array_values(array_filter([
                $business?->name !== $name ? $business?->name : null,
                $business?->address,
                implode(' · ', array_filter([$business?->phone, $business?->email])) ?: null,
                $business?->kra_pin ? "KRA PIN {$business->kra_pin}" : null,
            ])),
            'logo' => $this->logo(),
            // Goes into the stylesheet, so only a plain hex colour is accepted.
            'colour' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $this->settings->get('branding.primary_color')) ? (string) $this->settings->get('branding.primary_color') : '#5B3FA3',
        ];
    }

    /** The app logo as a data URI (PNG or JPEG; dompdf cannot embed every SVG reliably). */
    private function logo(): ?string
    {
        $path = $this->settings->get('branding.app_logo');
        $disk = Storage::disk('local');
        if (! is_string($path) || ! $disk->exists($path)) {
            return null;
        }
        $mime = $disk->mimeType($path);

        return in_array($mime, ['image/png', 'image/jpeg'], true) ? 'data:'.$mime.';base64,'.base64_encode($disk->get($path)) : null;
    }
}
