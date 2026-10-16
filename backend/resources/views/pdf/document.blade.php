<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $doc['title'] }}</title>
<style>
    @page { margin: 22mm 14mm 18mm 14mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #1c1d2e; }
    header { position: fixed; top: -16mm; left: 0; right: 0; height: 12mm; border-bottom: 2px solid {{ $letterhead['colour'] }}; }
    header .name { font-size: 12pt; font-weight: bold; }
    header .lines { font-size: 7.5pt; color: #555; }
    header img { height: 11mm; float: right; }
    footer { position: fixed; bottom: -12mm; left: 0; right: 0; height: 8mm; font-size: 7pt; color: #777; border-top: 1px solid #ddd; padding-top: 2mm; }
    h1 { font-size: 15pt; margin: 4mm 0 1mm; color: {{ $letterhead['colour'] }}; }
    .subtitle { color: #555; margin-bottom: 3mm; }
    table.meta, table.summary { margin-bottom: 4mm; border-collapse: collapse; }
    table.meta td { padding: 0.6mm 4mm 0.6mm 0; vertical-align: top; }
    table.meta td.label { color: #555; }
    table.summary td { border: 1px solid #ddd; padding: 2mm 3mm; }
    table.summary .label { color: #555; font-size: 7.5pt; display: block; }
    table.summary .value { font-size: 11pt; font-weight: bold; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: {{ $letterhead['colour'] }}; color: #fff; text-align: left; padding: 1.5mm 2mm; font-size: 8pt; }
    table.data td { padding: 1.2mm 2mm; border-bottom: 1px solid #e6e6ee; }
    table.data tr:nth-child(even) td { background: #f7f7fb; }
    table.data .num { text-align: right; white-space: nowrap; }
    table.data tr.totals td { font-weight: bold; border-top: 1.5px solid #1c1d2e; background: #fff; }
    .notes { margin-top: 4mm; font-size: 7.5pt; color: #555; }
    .empty { padding: 6mm 0; color: #777; }
</style>
</head>
<body>
<header>
    @if ($letterhead['logo'])
        <img src="{{ $letterhead['logo'] }}" alt="">
    @endif
    <div class="name">{{ $letterhead['name'] }}</div>
    <div class="lines">{{ implode(' · ', $letterhead['lines']) }}</div>
</header>
<footer>
    {{ $generated }} · Tessera POS
</footer>

<h1>{{ $doc['title'] }}</h1>
@if ($doc['subtitle'])
    <div class="subtitle">{{ $doc['subtitle'] }}</div>
@endif

@if ($doc['meta'])
    <table class="meta">
        @foreach ($doc['meta'] as [$label, $value])
            <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>
@endif

@if ($doc['summary'])
    <table class="summary">
        <tr>
            @foreach ($doc['summary'] as [$label, $value])
                <td><span class="label">{{ $label }}</span><span class="value">{{ $value }}</span></td>
            @endforeach
        </tr>
    </table>
@endif

@if ($doc['rows'])
    <table class="data">
        <thead>
            <tr>
                @foreach ($doc['columns'] as $column)
                    <th @class(['num' => $column['numeric'] ?? false])>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['rows'] as $row)
                <tr>
                    @foreach ($row as $i => $cell)
                        <td @class(['num' => $doc['columns'][$i]['numeric'] ?? false])>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
            @if ($doc['totals'])
                <tr class="totals">
                    @foreach ($doc['totals'] as $i => $cell)
                        <td @class(['num' => $doc['columns'][$i]['numeric'] ?? false])>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>
@else
    <div class="empty">Nothing in this period.</div>
@endif

@if ($doc['notes'])
    <div class="notes">
        @foreach ($doc['notes'] as $note)
            <div>{{ $note }}</div>
        @endforeach
    </div>
@endif
</body>
</html>
