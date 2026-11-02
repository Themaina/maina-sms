<?php

namespace Maina888\Sms\Providers;

use Botble\Base\Facades\DashboardMenu;
use Botble\Base\Facades\PanelSectionManager;
use Botble\Base\PanelSections\PanelSectionItem;
use Botble\Base\Supports\DashboardMenuItem;
use Botble\Base\Supports\ServiceProvider;
use Botble\Base\Traits\LoadAndPublishDataTrait;
use Botble\Dashboard\Events\RenderingDashboardWidgets;
use Botble\Dashboard\Supports\DashboardWidgetInstance;
use Botble\Setting\PanelSections\SettingOthersPanelSection;
use Maina888\Sms\Commands\SendScheduledSmsCommand;
use Maina888\Sms\Listeners\SendOrderSmsListener;
use Maina888\Sms\Services\AfricasTalkingClient;
use Maina888\Sms\Services\SmsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

class SmsServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    public function register(): void
    {
        $this->app->singleton(SmsService::class);

        // Not a singleton: settings (API key, environment) can change between sends.
        $this->app->bind(AfricasTalkingClient::class, fn () => new AfricasTalkingClient());
    }

    public function boot(): void
    {
        $this
            ->setNamespace('plugins/sms')
            ->loadHelpers()
            ->loadAndPublishConfigurations(['permissions'])
            ->loadAndPublishTranslations()
            ->loadAndPublishViews()
            ->loadRoutes()
            ->loadMigrations();

        $this->registerTemplates();
        $this->registerOrderListeners();
        $this->registerAdmin();

        if ($this->app->runningInConsole()) {
            $this->commands([SendScheduledSmsCommand::class]);

            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command(SendScheduledSmsCommand::class)
                    ->everyMinute()
                    ->withoutOverlapping();
            });
        }
    }

    protected function registerTemplates(): void
    {
        if (! is_plugin_active('ecommerce')) {
            return;
        }

        add_filter('sms_templates', function (array $templates): array {
            return array_merge(SendOrderSmsListener::templates(), $templates);
        }, 1);
    }

    protected function registerOrderListeners(): void
    {
        if (! is_plugin_active('ecommerce') || ! class_exists(\Botble\Ecommerce\Events\OrderPlacedEvent::class)) {
            return;
        }

        foreach ([
            \Botble\Ecommerce\Events\OrderPlacedEvent::class,
            \Botble\Ecommerce\Events\OrderConfirmedEvent::class,
            \Botble\Ecommerce\Events\OrderPaymentConfirmedEvent::class,
            \Botble\Ecommerce\Events\OrderCompletedEvent::class,
        ] as $event) {
            Event::listen($event, [SendOrderSmsListener::class, 'handle']);
        }
    }

    protected function registerAdmin(): void
    {
        DashboardMenu::default()->beforeRetrieving(function (): void {
            DashboardMenu::make()
                ->registerItem(
                    DashboardMenuItem::make()
                        ->id('cms-plugins-sms')
                        ->priority(440)
                        ->name('plugins/sms::sms.menu')
                        ->icon('ti ti-message-2')
                        ->route('sms.logs.index')
                        ->permissions('sms.logs.index')
                )
                ->registerItem(
                    DashboardMenuItem::make()
                        ->id('cms-plugins-sms-logs')
                        ->parentId('cms-plugins-sms')
                        ->priority(10)
                        ->name('plugins/sms::sms.logs.title')
                        ->icon('ti ti-list')
                        ->route('sms.logs.index')
                        ->permissions('sms.logs.index')
                )
                ->registerItem(
                    DashboardMenuItem::make()
                        ->id('cms-plugins-sms-send')
                        ->parentId('cms-plugins-sms')
                        ->priority(20)
                        ->name('plugins/sms::sms.send.title')
                        ->icon('ti ti-send')
                        ->route('sms.send')
                        ->permissions('sms.send')
                )
                ->registerItem(
                    DashboardMenuItem::make()
                        ->id('cms-plugins-sms-settings')
                        ->parentId('cms-plugins-sms')
                        ->priority(30)
                        ->name('plugins/sms::sms.settings.title')
                        ->icon('ti ti-settings')
                        ->route('sms.settings')
                        ->permissions('sms.settings')
                );
        });

        PanelSectionManager::default()->beforeRendering(function (): void {
            PanelSectionManager::registerItem(
                SettingOthersPanelSection::class,
                fn () => PanelSectionItem::make('sms')
                    ->setTitle(trans('plugins/sms::sms.settings.title'))
                    ->withIcon('ti ti-message-2')
                    ->withDescription(trans('plugins/sms::sms.settings.panel_description'))
                    ->withPriority(150)
                    ->withRoute('sms.settings')
            );
        });

        $this->app['events']->listen(RenderingDashboardWidgets::class, function (): void {
            add_filter(DASHBOARD_FILTER_ADMIN_LIST, function (array $widgets, Collection $widgetSettings): array {
                if (! Auth::guard()->user()?->hasPermission('sms.logs.index')) {
                    return $widgets;
                }

                return (new DashboardWidgetInstance())
                    ->setPermission('sms.logs.index')
                    ->setKey('widget_sms_stats')
                    ->setTitle(trans('plugins/sms::sms.widget.title'))
                    ->setIcon('ti ti-message-2')
                    ->setColor('orange')
                    ->setRoute(route('sms.widgets.stats'))
                    ->setBodyClass('')
                    ->setColumn('col-md-6 col-sm-6')
                    ->init($widgets, $widgetSettings);
            }, 240, 2);
        });
    }
}
