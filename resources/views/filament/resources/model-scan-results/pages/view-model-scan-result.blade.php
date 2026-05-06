<x-filament-panels::page>
    @php
        $detailWidgetsData = ['record' => $this->getRecord()];
    @endphp

    {{ $this->infolist }}

    <x-filament-widgets::widgets
        :widgets="[\App\Filament\Resources\ModelScanResults\Widgets\ShortlistedCoinsTableWidget::class]"
        :data="$detailWidgetsData"
        :columns="1"
    />

    <x-filament-widgets::widgets
        :widgets="[\App\Filament\Resources\ModelScanResults\Widgets\FailedCoinsTableWidget::class]"
        :data="$detailWidgetsData"
        :columns="1"
    />
</x-filament-panels::page>
