<?php

namespace App\Filament\Pages;

use App\Models\GeneralConfig;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * CronSettingsPage
 *
 * Filament settings page for managing the master cron enable flag and
 * individual per-model enable flags, all persisted to general_config.
 */
class CronSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static \BackedEnum|string|null $navigationIcon = Heroicon::Cog6Tooth;

    protected static ?string $navigationLabel = 'Cron Settings';

    protected static ?string $title = 'Cron & Model Settings';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.cron-settings-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * Load current flag values from general_config on page mount.
     */
    public function mount(): void
    {
        $this->form->fill([
            'cron_enabled' => GeneralConfig::isCronEnabled(),
            'counter_trend_enabled' => GeneralConfig::isModelEnabled('counter_trend'),
            'pre_pump_enabled' => GeneralConfig::isModelEnabled('pre_pump'),
            'momentum_enabled' => GeneralConfig::isModelEnabled('momentum'),
            'daily_safe_momentum_enabled' => GeneralConfig::isModelEnabled('daily_safe_momentum'),
        ]);
    }

    /**
     * Form schema: master switch + per-model toggles.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Global Controls')
                    ->description('Master switch. When disabled, ALL cron jobs and model pipelines will be skipped regardless of per-model flags.')
                    ->schema([
                        Toggle::make('cron_enabled')
                            ->label('Enable all cron jobs')
                            ->helperText('Controls: trading:run-cycle, MarketRegime, CoinUniverse, and all model jobs.')
                            ->onColor('success')
                            ->offColor('danger'),
                    ]),

                Section::make('Per-Model Controls')
                    ->description('Individually pause specific model pipelines. The master cron switch must also be ON for these to take effect.')
                    ->schema([
                        Toggle::make('counter_trend_enabled')
                            ->label('Counter-Trend model (every 15 min)')
                            ->onColor('success'),

                        Toggle::make('pre_pump_enabled')
                            ->label('Pre-Pump model (every 4 hours)')
                            ->onColor('success'),

                        Toggle::make('momentum_enabled')
                            ->label('Momentum model (hourly)')
                            ->onColor('success'),

                        Toggle::make('daily_safe_momentum_enabled')
                            ->label('Daily Safe Momentum model (daily)')
                            ->onColor('success'),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Persist form state to general_config.
     */
    public function save(): void
    {
        $state = $this->form->getState();

        GeneralConfig::set('cron_enabled', $state['cron_enabled'] ? '1' : '0');
        GeneralConfig::set('counter_trend_enabled', $state['counter_trend_enabled'] ? '1' : '0');
        GeneralConfig::set('pre_pump_enabled', $state['pre_pump_enabled'] ? '1' : '0');
        GeneralConfig::set('momentum_enabled', $state['momentum_enabled'] ? '1' : '0');
        GeneralConfig::set('daily_safe_momentum_enabled', $state['daily_safe_momentum_enabled'] ? '1' : '0');

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Settings')
                ->action('save')
                ->color('primary'),
        ];
    }
}
