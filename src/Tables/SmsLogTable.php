<?php

namespace Maina888\Sms\Tables;

use Maina888\Sms\Enums\SmsStatusEnum;
use Maina888\Sms\Models\SmsLog;
use Maina888\Sms\Services\SmsService;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\Action;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\CreatedAtBulkChange;
use Botble\Table\BulkChanges\SelectBulkChange;
use Botble\Table\BulkChanges\TextBulkChange;
use Botble\Table\Columns\CreatedAtColumn;
use Botble\Table\Columns\FormattedColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\StatusColumn;
use Botble\Table\HeaderActions\HeaderAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SmsLogTable extends TableAbstract
{
    public function setup(): void
    {
        $templates = collect(app(SmsService::class)->templates())
            ->mapWithKeys(fn (array $template, string $key) => [$key => $template['label']])
            ->all();

        $this
            ->model(SmsLog::class)
            ->addHeaderActions([
                HeaderAction::make('send')
                    ->label(trans('plugins/sms::sms.send.title'))
                    ->icon('ti ti-send')
                    ->color('primary')
                    ->route('sms.send'),
            ])
            ->addColumns([
                IdColumn::make(),
                FormattedColumn::make('to')
                    ->title(trans('plugins/sms::sms.logs.to'))
                    ->alignStart()
                    ->getValueUsing(fn (FormattedColumn $column) => e($column->getItem()->to)),
                FormattedColumn::make('message')
                    ->title(trans('plugins/sms::sms.logs.message'))
                    ->alignStart()
                    ->getValueUsing(fn (FormattedColumn $column) => sprintf(
                        '<span title="%s">%s</span>',
                        e($column->getItem()->message),
                        e(Str::limit($column->getItem()->message, 70))
                    )),
                FormattedColumn::make('template_key')
                    ->title(trans('plugins/sms::sms.logs.template'))
                    ->getValueUsing(fn (FormattedColumn $column) => e($templates[$column->getItem()->template_key] ?? ($column->getItem()->template_key ?: '—'))),
                StatusColumn::make(),
                FormattedColumn::make('cost')
                    ->title(trans('plugins/sms::sms.logs.cost'))
                    ->getValueUsing(fn (FormattedColumn $column) => $column->getItem()->cost !== null
                        ? e(trim(($column->getItem()->currency ?: '') . ' ' . rtrim(rtrim(number_format((float) $column->getItem()->cost, 4), '0'), '.')))
                        : '—'),
                FormattedColumn::make('segments')
                    ->title(trans('plugins/sms::sms.logs.segments')),
                FormattedColumn::make('error')
                    ->title(trans('plugins/sms::sms.logs.error'))
                    ->alignStart()
                    ->getValueUsing(fn (FormattedColumn $column) => $column->getItem()->error
                        ? sprintf('<span class="text-danger" title="%s">%s</span>', e($column->getItem()->error), e(Str::limit($column->getItem()->error, 50)))
                        : (($sendAfter = $column->getItem()->send_after) && $column->getItem()->isStatus(SmsStatusEnum::QUEUED)
                            ? e(trans('plugins/sms::sms.logs.waiting_until', ['time' => $sendAfter->format('Y-m-d H:i')]))
                            : '')),
                FormattedColumn::make('delivered_at')
                    ->title(trans('plugins/sms::sms.logs.delivered_at'))
                    ->getValueUsing(fn (FormattedColumn $column) => $column->getItem()->delivered_at?->format('Y-m-d H:i') ?: ''),
                CreatedAtColumn::make()->dateFormat('Y-m-d H:i'),
            ])
            ->addActions([
                Action::make('resend')
                    ->label(trans('plugins/sms::sms.logs.resend'))
                    ->icon('ti ti-repeat')
                    ->color('primary')
                    ->route('sms.logs.resend')
                    ->action('POST')
                    ->confirmation()
                    ->confirmationModalTitle(trans('plugins/sms::sms.logs.resend_confirm_title'))
                    ->confirmationModalMessage(trans('plugins/sms::sms.logs.resend_confirm_message'))
                    ->confirmationModalButton(trans('plugins/sms::sms.logs.resend')),
                DeleteAction::make()->route('sms.logs.destroy'),
            ])
            ->addBulkAction(DeleteBulkAction::make()->permission('sms.logs.destroy'))
            ->addFilters([
                SelectBulkChange::make()
                    ->name('status')
                    ->title(trans('core/base::tables.status'))
                    ->choices(SmsStatusEnum::labels()),
                SelectBulkChange::make()
                    ->name('template_key')
                    ->title(trans('plugins/sms::sms.logs.template'))
                    ->choices($templates),
                TextBulkChange::make()
                    ->name('to')
                    ->title(trans('plugins/sms::sms.logs.to')),
                CreatedAtBulkChange::make(),
            ])
            ->queryUsing(fn (Builder $query) => $query->select([
                'id',
                'to',
                'message',
                'template_key',
                'status',
                'cost',
                'currency',
                'segments',
                'error',
                'send_after',
                'delivered_at',
                'created_at',
            ]));
    }

    public function getDefaultButtons(): array
    {
        return array_unique(array_merge(['export'], parent::getDefaultButtons()));
    }
}
