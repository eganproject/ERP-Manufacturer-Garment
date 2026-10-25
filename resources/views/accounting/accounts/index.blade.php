@use('App\Enums\AccountType')
<x-layouts.app title="Bagan Akun">
    @can('accounting.manage')
        <x-slot:actions>
            <a href="{{ route('accounts.mapping') }}" class="btn-secondary">Pemetaan Akun Otomatis</a>
            <a href="{{ route('accounts.create') }}" class="btn-primary">+ Tambah Akun</a>
        </x-slot:actions>
    @endcan

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari kode / nama akun...">
                <div class="w-56"><x-form.select name="type" :options="AccountType::options()" :value="request('type')" placeholder="Semua golongan" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th class="w-28">Kode</th><th>Nama Akun</th><th>Golongan</th><th>Saldo Normal</th><th class="text-right">Saldo per {{ tanggal(today()) }}</th><th>Status</th><th class="w-24"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($accounts as $account)
                        @php $depth = $account->parent ? ($account->parent->parent ? 2 : 1) : 0; @endphp
                        <tr @class(['bg-gray-50' => $account->is_header])>
                            <td @class(['font-mono text-sm', 'font-semibold' => $account->is_header])>{{ $account->code }}</td>
                            <td style="padding-left: {{ 1 + $depth * 1.25 }}rem" @class(['font-semibold text-gray-900' => $account->is_header])>
                                @if ($account->is_header)
                                    {{ $account->name }}
                                @else
                                    <a href="{{ route('reports.ledger', ['account_id' => $account->id, 'from' => today()->startOfYear()->toDateString()]) }}" class="text-indigo-600 hover:underline">{{ $account->name }}</a>
                                @endif
                                @if ($account->is_system && ! $account->is_header)<span class="ml-1 text-xs text-gray-400" title="Dipakai jurnal otomatis">• sistem</span>@endif
                            </td>
                            <td><x-badge :color="$account->type->color()">{{ $account->type->label() }}</x-badge></td>
                            <td class="text-sm text-gray-600">{{ ucfirst($account->normal_balance) }}</td>
                            <td @class(['text-right whitespace-nowrap', 'font-semibold' => $account->is_header, 'text-red-600' => ($balances[$account->id] ?? 0) < 0])>{{ rupiah($balances[$account->id] ?? 0, false) }}</td>
                            <td>@unless ($account->is_active)<x-badge color="gray">Nonaktif</x-badge>@endunless</td>
                            <td class="text-right whitespace-nowrap">
                                @can('accounting.manage')
                                    <a href="{{ route('accounts.edit', $account) }}" class="text-sm text-indigo-600 hover:underline">Ubah</a>
                                    @if ($account->is_header)
                                        <a href="{{ route('accounts.create', ['parent_id' => $account->id, 'type' => $account->type->value]) }}" class="ml-2 text-sm text-gray-500 hover:text-indigo-600" title="Tambah sub-akun">+ Sub</a>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
