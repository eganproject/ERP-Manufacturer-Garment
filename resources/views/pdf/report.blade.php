@extends('pdf.layout')

@section('title', strtoupper($table->title))
@section('number', $table->subtitle)

@section('content')
    @if ($table->summary)
        <table class="kv" style="width: auto; margin-bottom: 6px;">
            @foreach ($table->summary as [$label, $value])
                <tr><td class="k" style="width: 150px;">{{ $label }}</td><td class="bold">{{ $value }}</td></tr>
            @endforeach
        </table>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th class="c" style="width: 22px;">#</th>
                @foreach ($table->columns as $key => [$label, $type])
                    <th @if ($table->isNumeric($key)) style="text-align: right;" @endif>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($table->rows as $i => $row)
                <tr>
                    <td class="c muted">{{ $i + 1 }}</td>
                    @foreach ($table->columns as $key => $column)
                        <td @class(['r' => $table->isNumeric($key)])>{{ $table->format($key, $row[$key] ?? null) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($table->columns) + 1 }}" class="c muted">Tidak ada data pada periode ini.</td></tr>
            @endforelse
        </tbody>
        @if ($table->totals !== null && $table->rows->isNotEmpty())
            <tfoot>
                <tr>
                    <td></td>
                    @foreach (array_keys($table->columns) as $i => $key)
                        <td @class(['r' => $table->isNumeric($key)])>{{ $i === 0 ? 'TOTAL' : (array_key_exists($key, $table->totals) && $table->totals[$key] !== null ? $table->format($key, $table->totals[$key]) : '') }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
@endsection
