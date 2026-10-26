<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>@yield('title') @yield('number')</title>
    <style>
        /* Selaras dengan sistem desain aplikasi: netral (zinc) + satu aksen biru tinta. */
        @page { margin: 34px 36px 58px 36px; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; line-height: 1.4; color: #27272a; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .accent-bar { position: fixed; top: -34px; left: -36px; right: -36px; height: 5px; background: #3159d8; }

        .kop td { vertical-align: top; }
        .kop .logo { width: 62px; padding-right: 10px; }
        .kop .logo img { max-width: 56px; max-height: 56px; }
        .kop .name { font-size: 15px; font-weight: bold; color: #18181b; }
        .kop .meta { font-size: 8.5px; color: #71717a; line-height: 1.5; margin-top: 2px; }
        .kop .doc { text-align: right; width: 38%; }
        .kop .doc .title { font-size: 9px; font-weight: bold; letter-spacing: 1.5px; color: #3159d8; text-transform: uppercase; }
        .kop .doc .number { font-size: 14px; font-weight: bold; color: #18181b; margin-top: 3px; }
        .rule { border-bottom: 1px solid #e4e4e7; margin: 12px 0 14px; }

        .info td { vertical-align: top; padding: 0; }
        .box { border: 1px solid #e4e4e7; background: #fafafa; padding: 8px 10px; border-radius: 6px; }
        .box .label, .label { font-size: 7.5px; font-weight: bold; text-transform: uppercase; color: #a1a1aa; letter-spacing: .8px; margin-bottom: 3px; }
        .kv td { padding: 1.5px 0; vertical-align: top; }
        .kv td.k { color: #71717a; width: 40%; }

        .items { margin-top: 14px; }
        .items th { color: #71717a; font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .6px; text-align: left; padding: 6px 6px; border-bottom: 1.5px solid #d4d4d8; background: #f4f4f5; }
        .items th.r { text-align: right; }
        .items th.c { text-align: center; }
        .items td { padding: 6px 6px; border-bottom: 1px solid #f0f0f2; vertical-align: top; }
        .items tbody tr:nth-child(even) td { background: #fcfcfd; }
        .items tfoot td { font-weight: bold; border-bottom: none; padding: 4px 6px; }
        .items tfoot tr:first-child td { border-top: 1.5px solid #d4d4d8; padding-top: 7px; }
        .items tfoot tr:last-child td { color: #18181b; }

        .r { text-align: right; }
        .c { text-align: center; }
        .muted { color: #71717a; }
        .small { font-size: 8px; }
        .bold { font-weight: bold; }
        .accent { color: #3159d8; }
        .terbilang { margin-top: 8px; padding: 7px 10px; background: #eef2fd; border-radius: 6px; color: #2a47b5; font-style: italic; }
        .notes { margin-top: 10px; }
        .terms { margin-top: 12px; padding: 8px 10px; border: 1px solid #e4e4e7; border-radius: 6px; font-size: 8.5px; color: #52525b; }
        .section-title { margin: 16px 0 4px; font-size: 8px; font-weight: bold; color: #3159d8; text-transform: uppercase; letter-spacing: .8px; }
        .stamp { text-align: center; font-weight: bold; font-size: 11px; letter-spacing: 3px; padding: 5px; margin-bottom: 10px; border-radius: 6px; }
        .stamp-red { color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; }
        .stamp-green { color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0; }
        .stamp-amber { color: #b45309; background: #fffbeb; border: 1px solid #fde68a; }

        .sign { margin-top: 28px; page-break-inside: avoid; }
        .sign td { text-align: center; vertical-align: top; }
        .sign .space { height: 54px; }
        .sign .line { border-top: 1px solid #a1a1aa; margin: 0 18px; padding-top: 4px; font-weight: bold; color: #18181b; }
        .sign .role { color: #71717a; font-size: 8px; }

        .footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 7.5px; color: #a1a1aa; border-top: 1px solid #e4e4e7; padding-top: 5px; }
        .footer td { padding: 0; vertical-align: top; }
        .footer .note { color: #52525b; font-size: 8px; padding-bottom: 2px; }
        .pagenum:before { content: counter(page); }
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="footer">
        @if ($company['footer'])
            <div class="note">{{ $company['footer'] }}</div>
        @endif
        <table><tr>
            <td>{{ $company['name'] }} · Dicetak {{ now()->translatedFormat('d M Y H:i') }}@if (auth()->user()) oleh {{ auth()->user()->name }}@endif</td>
            <td class="r">@yield('number') · Hal. <span class="pagenum"></span></td>
        </tr></table>
    </div>

    @include('pdf.partials.header', ['title' => trim($__env->yieldContent('title')), 'number' => trim($__env->yieldContent('number'))])

    @yield('content')
</body>
</html>
