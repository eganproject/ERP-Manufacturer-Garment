@props(['status'])

<x-badge :color="$status->color()">{{ $status->label() }}</x-badge>
