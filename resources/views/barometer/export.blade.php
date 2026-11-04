{{--
    Barometer PDF export (#35), rendered by dompdf: A4 landscape, laid out
    like the paper anxiety-tracking form. Titled plainly ("Anxiety log") so
    it reads as the user's log, not our branding. Remote resources and PHP are
    disabled in ExportController; all user text goes through {{ }}. Page
    numbers are stamped on by ExportController after rendering. The footer
    credit is plain text — no link, no tracking parameters.

    $summary (optional, #37) is Stats::toPageArray(): numbers only, shown on
    a page of its own before the readings. It describes, never interprets,
    and leaves second ratings out when that column is left out.
--}}
@php
    $textColumns = array_values(array_diff(array_keys($columns), ['rating_before', 'rating_after']));
    $ratingWidth = 7;
    $whenWidth = 10;
    $ratingCount = count($columns) - count($textColumns);
    $textWidth = count($textColumns) > 0 ? (100 - $whenWidth - $ratingWidth * $ratingCount) / count($textColumns) : 0;
    $summary ??= null;
    $showSecond = array_key_exists('rating_after', $columns);
    $sideBySide = $summary !== null && count($summary['weeks']) <= 14;
    $one = fn (?float $value) => $value === null ? '–' : number_format($value, 1);
    $change = fn (float $value) => match (true) {
        round($value, 1) < 0 => 'down '.number_format(abs($value), 1),
        round($value, 1) > 0 => 'up '.number_format($value, 1),
        default => 'no change',
    };
    // Heatmap shading by count: the header navy, mixed with white.
    $maxCell = $summary === null ? 0 : max(1, ...array_map(fn ($row) => max(array_column($row['cells'], 'count')), $summary['heatmap']));
    $shade = function (int $count) use ($maxCell) {
        $t = $count === 0 ? 0 : 0.15 + 0.85 * $count / $maxCell;
        $mix = fn (int $c) => (int) round(255 + ($c - 255) * $t);

        return sprintf('background: rgb(%d, %d, %d); color: %s;', $mix(0x1F), $mix(0x2D), $mix(0x3D), $t > 0.7 ? '#fff' : '#1a1a1a');
    };
@endphp
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <title>Anxiety log — {{ $name }}</title>
    <style>
        @page { margin: 16mm 12mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1a1a1a; line-height: 1.35; }
        h1 { font-size: 16pt; margin: 0 0 2pt; }
        .meta { color: #555; margin: 0 0 10pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background: #1f2d3d; color: #fff; text-align: left; font-weight: bold; padding: 5pt 4pt; border: 0.5pt solid #1f2d3d; }
        td { padding: 5pt 4pt; border: 0.5pt solid #9aa5b1; vertical-align: top; white-space: pre-line; word-wrap: break-word; }
        th.rating, td.rating { text-align: center; }
        td.rating { font-size: 12pt; font-weight: bold; }
        th small { display: block; font-weight: normal; font-size: 7pt; }
        td.when .date { font-weight: bold; }
        td.when .time { color: #555; }
        .empty { color: #555; font-style: italic; text-align: center; padding: 20pt; }
        .summary { page-break-after: always; }
        .summary h2 { font-size: 11pt; margin: 14pt 0 4pt; }
        .summary p { margin: 0 0 4pt; }
        .summary .headline { font-size: 12pt; }
        .summary .note { color: #555; font-size: 8pt; }
        .summary table { width: auto; table-layout: auto; }
        .summary td, .summary th { padding: 2.5pt 6pt; white-space: nowrap; }
        .summary table.columns { width: 100%; }
        .summary td.column { border: none; padding: 0 18pt 0 0; vertical-align: top; white-space: normal; }
        .summary td.cell { text-align: center; min-width: 52pt; }
        .summary td.cell small { display: block; font-size: 7pt; }
        footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #777; }
    </style>
</head>
<body>
    <footer>
        {{ $name }} · Anxiety log · {{ $from->format('j M Y') }} – {{ $to->format('j M Y') }} · Made with Offing · offing.uk
    </footer>

    @if ($summary !== null)
        <section class="summary">
            <h1>Anxiety log — summary</h1>
            <p class="meta">
                {{ $name }} · {{ $from->format('j F Y') }} to {{ $to->format('j F Y') }}
                · Ratings 0 (calm) to 10 (most anxious) · These numbers describe the log; they don't diagnose anything.
            </p>

            @if ($summary['count'] === 0)
                <p>No readings in this period.</p>
            @else
                <p class="headline">
                    {{ $summary['count'] }} {{ Str::plural('reading', $summary['count']) }}, first rating average {{ $one($summary['first']['average']) }}
                    (lowest {{ $summary['first']['lowest'] }}, highest {{ $summary['first']['highest'] }}).
                </p>
                @if ($showSecond)
                    @if ($summary['second'] === null)
                        <p>No second ratings in this period.</p>
                    @else
                        <p class="headline">
                            With a second rating ({{ $summary['second']['paired'] }} of {{ $summary['count'] }}): average {{ $one($summary['second']['before']) }},
                            then {{ $one($summary['second']['after']) }} — {{ $change($summary['second']['change']) }} after what I did.
                        </p>
                    @endif
                @endif

                {{-- Side by side while the weeks fit beside the grid; a long range stacks so the weeks can run onto more pages. --}}
                @if ($sideBySide)<table class="columns"><tr><td class="column">@endif
                <h2>When readings happened</h2>
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            @foreach ($summary['timesOfDay'] as $time)
                                <th>{{ $time['label'] }}<small>{{ $time['hours'] }}</small></th>
                            @endforeach
                            <th>All</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['heatmap'] as $row)
                            <tr>
                                <th>{{ $row['label'] }}</th>
                                @foreach ($row['cells'] as $cell)
                                    <td class="cell" style="{{ $shade($cell['count']) }}">{{ $cell['count'] }}@if ($cell['average'] !== null)<small>avg {{ $one($cell['average']) }}</small>@endif</td>
                                @endforeach
                                <td class="cell">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="note">
                    Number of readings, with the average first rating, in each reading's local time.
                    @if ($summary['untimed'] > 0)
                        {{ $summary['untimed'] }} {{ Str::plural('reading', $summary['untimed']) }} without a time {{ $summary['untimed'] === 1 ? 'is' : 'are' }} counted under All only.
                    @endif
                </p>
                @if ($sideBySide)</td><td class="column">@endif
                <h2>By week</h2>
                <table>
                    <thead>
                        <tr><th>Week starting</th><th>Readings</th><th>Average first rating</th>@if ($showSecond)<th>Average change</th>@endif</tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['weeks'] as $week)
                            <tr>
                                <td>{{ \Carbon\CarbonImmutable::parse($week['start'])->format('D j M Y') }}</td>
                                <td>{{ $week['count'] }}</td>
                                <td>{{ $one($week['average']) }}</td>
                                @if ($showSecond)
                                    <td>{{ $week['change'] === null ? '–' : $change($week['change']).' ('.$week['paired'].')' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($sideBySide)</td></tr></table>@endif
            @endif
        </section>
    @endif

    <h1>Anxiety log</h1>
    <p class="meta">
        {{ $name }} · {{ $from->format('j F Y') }} to {{ $to->format('j F Y') }}
        · {{ $readings->count() }} {{ Str::plural('reading', $readings->count()) }}
        · Ratings 0 (calm) to 10 (most anxious) · Times in {{ $timezone }}
    </p>

    <table>
        <thead>
            <tr>
                <th style="width: {{ $whenWidth }}%">Day / time</th>
                @foreach ($columns as $key => $label)
                    @if ($key === 'rating_before' || $key === 'rating_after')
                        <th class="rating" style="width: {{ $ratingWidth }}%">{{ $label }}<small>0–10</small></th>
                    @else
                        <th style="width: {{ $textWidth }}%">{{ $label }}</th>
                    @endif
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($readings as $reading)
                @php($when = $reading->toPageArray($timezone))
                <tr>
                    <td class="when"><span class="date">{{ \Carbon\CarbonImmutable::parse($when['date'])->format('D j M') }}</span>
<span class="time">{{ $when['time'] ?? 'Time not recorded' }}</span></td>
                    @foreach (array_keys($columns) as $key)
                        <td @class(['rating' => $key === 'rating_before' || $key === 'rating_after'])>{{ $reading->{$key} }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ count($columns) + 1 }}">No readings in this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
