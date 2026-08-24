<x-filament-widgets::widget>
    <x-filament::section heading="Top clics — 30 derniers jours">
        @php $rows = $this->getRows(); @endphp

        @if (empty($rows))
            <p class="text-sm text-gray-500 dark:text-gray-400">Aucun clic enregistré sur cette période.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <th class="py-2">Entité</th>
                        <th class="py-2">Type</th>
                        <th class="py-2 text-right">Clics</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="py-2 text-gray-900 dark:text-white">{{ $row['label'] }}</td>
                            <td class="py-2 text-gray-500 dark:text-gray-400">{{ $row['type_label'] }}</td>
                            <td class="py-2 text-right font-semibold text-gray-900 dark:text-white">{{ $row['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
