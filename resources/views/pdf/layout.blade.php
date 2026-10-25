<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 28px 32px 48px 32px; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .kop td { vertical-align: middle; }
        .kop .logo { width: 70px; }
        .kop .logo img { max-width: 64px; max-height: 64px; }
        .kop .name { font-size: 16px; font-weight: bold; color: #111827; }
        .kop .meta { font-size: 9px; color: #4b5563; line-height: 1.4; }
        .kop .doc { text-align: right; }
        .kop .doc .title { font-size: 15px; font-weight: bold; letter-spacing: 1px; color: #312e81; }
        .kop .doc .number { font-size: 11px; font-weight: bold; margin-top: 2px; }
        .rule { border-bottom: 2px solid #312e81; margin: 8px 0 12px; }
        .info td { vertical-align: top; padding: 2px 0; }
        .box { border: 1px solid #d1d5db; padding: 8px; border-radius: 3px; }
        .box .label { font-size: 8px; text-transform: uppercase; color: #6b7280; letter-spacing: .5px; margin-bottom: 3px; }
        .kv td { padding: 1.5px 0; vertical-align: top; }
        .kv td.k { color: #6b7280; width: 38%; }
        .items { margin-top: 12px; }
        .items th { background: #eef2ff; color: #312e81; font-size: 9px; text-transform: uppercase; text-align: left; padding: 6px 5px; border: 1px solid #c7d2fe; }
        .items td { padding: 5px; border: 1px solid #e5e7eb; vertical-align: top; }
        .items tfoot td { font-weight: bold; background: #f9fafb; }
        .r { text-align: right; }
        .c { text-align: center; }
        .muted { color: #6b7280; }
        .small { font-size: 8.5px; }
        .bold { font-weight: bold; }
        .terbilang { margin-top: 6px; padding: 6px 8px; background: #f9fafb; border-left: 3px solid #6366f1; font-style: italic; }
        .notes { margin-top: 10px; }
        .section-title { margin: 14px 0 4px; font-size: 10.5px; font-weight: bold; color: #312e81; }
        .sign { margin-top: 26px; page-break-inside: avoid; }
        .sign td { text-align: center; vertical-align: top; width: 33%; }
        .sign .space { height: 58px; }
        .sign .line { border-top: 1px solid #374151; margin: 0 22px; padding-top: 3px; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8px; color: #9ca3af; }
        .footer td { padding: 0; }
    </style>
</head>
<body>
    <div class="footer">
        <table><tr>
            <td>{{ $company['name'] }} · Dicetak {{ now()->translatedFormat('d M Y H:i') }} oleh {{ auth()->user()?->name }}</td>
            <td class="r">@yield('number')</td>
        </tr></table>
    </div>

    <table class="kop">
        <tr>
            @if ($company['logo_data'])
                <td class="logo"><img src="{{ $company['logo_data'] }}" alt="Logo"></td>
            @endif
            <td>
                <div class="name">{{ $company['name'] }}</div>
                <div class="meta">
                    @if ($company['address']){{ $company['address'] }}<br>@endif
                    @if ($company['phone'])Telp. {{ $company['phone'] }}@endif
                    @if ($company['phone'] && $company['email']) · @endif
                    @if ($company['email']){{ $company['email'] }}@endif
                    @if ($company['npwp'])<br>NPWP {{ $company['npwp'] }}@endif
                </div>
            </td>
            <td class="doc">
                <div class="title">@yield('title')</div>
                <div class="number">@yield('number')</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    @yield('content')
</body>
</html>
