<?php

namespace App\Http\Controllers\Barometer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Barometer\ExportRequest;
use App\Models\AuditLog;
use App\Models\BarometerReading;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Barometer export (#35): a printable PDF laid out like the paper form,
 * or a CSV, over a date range, optionally leaving columns out (someone may
 * not want to share their thoughts column that week).
 *
 * Built in memory and streamed — never written to disk on the host — with
 * `no-store` so no cache keeps a copy. Every export is audited (format,
 * range, count; never content).
 */
class ExportController extends Controller
{
    /**
     * The export options page: date range (last 30 days by default) and
     * which columns to include.
     */
    public function show(Request $request): InertiaResponse
    {
        $today = $request->user()->today();

        return Inertia::render('barometer/export', [
            'defaults' => [
                'from' => $today->subDays(29)->toDateString(),
                'to' => $today->toDateString(),
            ],
            'columns' => BarometerReading::COLUMNS,
            'omittable' => BarometerReading::OMITTABLE_COLUMNS,
        ]);
    }

    public function download(ExportRequest $request): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? User::DEFAULT_TIMEZONE;
        $format = $request->string('format')->toString();
        $from = $request->from();
        $to = $request->to();
        $omit = $request->omitted();
        $columns = array_diff_key(BarometerReading::COLUMNS, array_flip($omit));

        $readings = $user->barometerReadings()
            ->whereBetween('local_date', [$from->toDateString(), $to->toDateString()])
            // Day first: untimed readings (null occurred_at) sort first within their day.
            ->oldest('local_date')
            ->oldest('occurred_at')
            ->oldest('id')
            ->get();

        AuditLog::record($user, 'barometer.exported', null, [
            'format' => $format,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => $readings->count(),
            'omitted' => $omit,
        ], $request->ip());

        $filename = sprintf('anxiety-log-%s-to-%s.%s', $from->toDateString(), $to->toDateString(), $format);

        $response = $format === 'pdf'
            ? $this->pdf($user, $readings, $columns, $from, $to, $timezone, $filename)
            : $this->csv($readings, $columns, $timezone, $filename);

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @param  Collection<int, BarometerReading>  $readings
     * @param  array<string, string>  $columns
     */
    private function pdf(User $user, Collection $readings, array $columns, CarbonImmutable $from, CarbonImmutable $to, string $timezone, string $filename): Response
    {
        $pdf = Pdf::loadView('barometer.export', [
            'name' => $user->name,
            'from' => $from,
            'to' => $to,
            'timezone' => $timezone,
            'columns' => $columns,
            'readings' => $readings,
        ])
            ->setPaper('a4', 'landscape')
            // User text goes into this HTML: never fetch remote resources or run embedded PHP.
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);

        // CSS can't count total pages in dompdf, so stamp "Page n of m" onto the rendered canvas.
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.47, 0.47, 0.47]);

        return $pdf->download($filename);
    }

    /**
     * @param  Collection<int, BarometerReading>  $readings
     * @param  array<string, string>  $columns
     */
    private function csv(Collection $readings, array $columns, string $timezone, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($readings, $columns, $timezone) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                throw new RuntimeException('Could not open the output stream for the CSV export.');
            }

            // BOM so Excel opens UTF-8 correctly.
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Date', 'Time', ...array_values($columns)], escape: '');

            foreach ($readings as $reading) {
                $page = $reading->toPageArray($timezone);
                $row = [$page['date'], $page['time'] ?? ''];

                foreach (array_keys($columns) as $column) {
                    $row[] = self::csvCell($reading->{$column});
                }

                fputcsv($out, $row, escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Formula-injection guard: a cell starting with = + - @ (or a tab/CR
     * that some spreadsheets strip first) would run as a formula when the
     * file is opened in Excel — a therapist's PC is exactly where this
     * gets opened. A leading apostrophe makes it plain text.
     */
    public static function csvCell(int|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
