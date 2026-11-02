<div class="p-3">
    @unless ($enabled)
        <div class="alert alert-warning mb-3">
            {{ trans('plugins/sms::sms.widget.disabled') }}
            <a href="{{ route('sms.settings') }}">{{ trans('plugins/sms::sms.settings.title') }}</a>
        </div>
    @endunless

    <div class="row g-3 text-center">
        <div class="col-6 col-md-3">
            <div class="text-muted small">{{ trans('plugins/sms::sms.widget.sent_today') }}</div>
            <div class="fs-2 fw-bold text-success">{{ number_format($sentToday) }}</div>
        </div>
        <div class="col-6 col-md-3">
            <div class="text-muted small">{{ trans('plugins/sms::sms.widget.failed_today') }}</div>
            <div class="fs-2 fw-bold {{ $failedToday ? 'text-danger' : '' }}">{{ number_format($failedToday) }}</div>
        </div>
        <div class="col-6 col-md-3">
            <div class="text-muted small">{{ trans('plugins/sms::sms.widget.queued') }}</div>
            <div class="fs-2 fw-bold">{{ number_format($queued) }}</div>
        </div>
        <div class="col-6 col-md-3">
            <div class="text-muted small">{{ trans('plugins/sms::sms.widget.balance') }}</div>
            <div class="fs-3 fw-bold">{{ $balance ?: '—' }}</div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3 small">
        <span class="text-muted">
            {{ trans('plugins/sms::sms.widget.cost_today', ['cost' => rtrim(rtrim(number_format($costToday, 4), '0'), '.')]) }}
        </span>
        <span>
            <a href="{{ route('sms.logs.index') }}">{{ trans('plugins/sms::sms.logs.title') }}</a>
            @if (Auth::user()?->hasPermission('sms.send'))
                · <a href="{{ route('sms.send') }}">{{ trans('plugins/sms::sms.send.title') }}</a>
            @endif
        </span>
    </div>
</div>
