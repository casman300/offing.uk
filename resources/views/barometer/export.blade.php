{{--
    Barometer PDF export (#35), rendered by dompdf: A4 landscape, laid out
    like the paper anxiety-tracking form. Titled plainly ("Anxiety log") so
    it reads as the user's log, not our branding. Remote resources and PHP are
    disabled in ExportController; all user text goes through {{ }}. Page
    numbers are stamped on by ExportController after rendering. The footer
    credit is plain text — no link, no tracking parameters.
--}}
@php
    $textColumns = array_values(array_diff(array_keys($columns), ['rating_before', 'rating_after']));
    $ratingWidth = 7;
    $whenWidth = 10;
    $ratingCount = count($columns) - count($textColumns);
    $textWidth = count($textColumns) > 0 ? (100 - $whenWidth - $ratingWidth * $ratingCount) / count($textColumns) : 0;
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
        footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #777; }
    </style>
</head>
<body>
    <footer>
        {{ $name }} · Anxiety log · {{ $from->format('j M Y') }} – {{ $to->format('j M Y') }} · Made with Offing · offing.uk
    </footer>

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
