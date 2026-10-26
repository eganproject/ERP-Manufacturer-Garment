@if ($company['bank_info'])
    <div class="box" @isset($style) style="{{ $style }}" @endisset>
        <div class="label">Pembayaran ditransfer ke</div>
        <div>{!! nl2br(e($company['bank_info'])) !!}</div>
    </div>
@endif
