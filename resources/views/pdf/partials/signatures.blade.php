{{-- $signatures: array of [label, name] --}}
<table class="sign">
    @if (! empty($place))
        <tr><td colspan="{{ count($signatures) }}" class="r" style="text-align:right; padding-bottom:6px;">{{ $place }}</td></tr>
    @endif
    <tr>
        @foreach ($signatures as [$label, $name])
            <td>
                <div>{{ $label }}</div>
                <div class="space"></div>
                <div class="line">{{ $name ?: '(................................)' }}</div>
            </td>
        @endforeach
    </tr>
</table>
