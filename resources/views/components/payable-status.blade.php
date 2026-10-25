@props(['doc'])

@php $s = $doc->displayStatus(); @endphp
<x-badge :color="$s['color']">{{ $s['label'] }}</x-badge>
