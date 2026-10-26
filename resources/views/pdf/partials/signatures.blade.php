{{--
    $signatures: daftar [label, nama] atau [label, nama, kunci penanda tangan].
    Kunci (director/finance/purchasing/warehouse/production) mengisi nama & jabatan dari Pengaturan
    bila dokumen belum mencatat orangnya.
--}}
<table class="sign">
    @if (! empty($place))
        <tr><td colspan="{{ count($signatures) }}" style="text-align:right; padding-bottom:8px;">{{ $place }}</td></tr>
    @endif
    <tr>
        @foreach ($signatures as $signature)
            @php
                [$label, $name] = $signature;
                $signer = isset($signature[2]) ? ($company['signers'][$signature[2]] ?? null) : null;
                $fromSettings = ! $name && $signer;
                $name = $name ?: $signer['name'] ?? null;
            @endphp
            <td style="width: {{ round(100 / count($signatures), 2) }}%;">
                <div class="muted">{{ $label }}</div>
                <div class="space"></div>
                <div class="line">{{ $name ?: '(................................)' }}</div>
                @if ($fromSettings)<div class="role">{{ $signer['title'] }}</div>@endif
            </td>
        @endforeach
    </tr>
</table>
