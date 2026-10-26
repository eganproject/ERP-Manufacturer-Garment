{{-- Kop dokumen: logo + profil perusahaan di kiri, jenis & nomor dokumen di kanan. $title, $number --}}
<table class="kop">
    <tr>
        @if ($company['logo_data'])
            <td class="logo"><img src="{{ $company['logo_data'] }}" alt="Logo"></td>
        @endif
        <td>
            <div class="name">{{ $company['name'] }}</div>
            <div class="meta">
                @if ($company['address']){!! nl2br(e($company['address'])) !!}<br>@endif
                {!! collect([
                    $company['phone'] ? 'Telp. '.e($company['phone']) : null,
                    $company['email'] ? e($company['email']) : null,
                    $company['website'] ? e($company['website']) : null,
                ])->filter()->implode(' &nbsp;·&nbsp; ') !!}
                @if ($company['npwp'])<br>NPWP {{ $company['npwp'] }}@endif
            </div>
        </td>
        <td class="doc">
            <div class="title">{{ $title }}</div>
            <div class="number">{{ $number }}</div>
        </td>
    </tr>
</table>
<div class="rule"></div>
