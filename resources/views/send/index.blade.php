@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @unless ($enabled)
        <div class="alert alert-warning">
            {{ trans('plugins/sms::sms.send.disabled') }}
            <a href="{{ route('sms.settings') }}">{{ trans('plugins/sms::sms.settings.title') }}</a>
        </div>
    @endunless

    @if ($quietNow)
        <div class="alert alert-info">{{ trans('plugins/sms::sms.send.quiet_now') }}</div>
    @endif

    @php $audience = old('audience', 'single'); @endphp

    <form method="POST" action="{{ route('sms.send.store') }}" class="sms-send-form">
        @csrf

        <x-core::card>
            <x-core::card.header>
                <x-core::card.title>{{ trans('plugins/sms::sms.send.title') }}</x-core::card.title>
            </x-core::card.header>

            <x-core::card.body>
                <div class="mb-3">
                    <label class="form-label required">{{ trans('plugins/sms::sms.send.audience') }}</label>
                    <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column flex-md-row gap-2">
                        @php
                            $audiences = ['single' => [trans('plugins/sms::sms.send.single'), null]];

                            if ($customersCount !== null) {
                                $audiences['customers'] = [trans('plugins/sms::sms.send.customers'), $customersCount];
                            }

                            foreach ($extraAudiences as $key => $extra) {
                                $audiences[$key] = [$extra['label'], $extra['count']];
                            }

                            $audiences['csv'] = [trans('plugins/sms::sms.send.csv'), null];
                        @endphp
                        @foreach ($audiences as $value => [$label, $count])
                            <label class="form-check border rounded px-3 py-2 m-0 flex-fill">
                                <input class="form-check-input ms-0 me-2" type="radio" name="audience" value="{{ $value }}" @checked($audience === $value)>
                                <span class="form-check-label">
                                    {{ $label }}
                                    @if ($count !== null)
                                        <span class="badge bg-secondary-lt ms-1">{{ trans('plugins/sms::sms.send.count', ['count' => number_format($count)]) }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="mb-3" data-sms-audience="single">
                    <label class="form-label required" for="sms-phone">{{ trans('plugins/sms::sms.send.phone') }}</label>
                    <input type="text" class="form-control @error('phone') is-invalid @enderror" id="sms-phone" name="phone" value="{{ old('phone') }}" placeholder="+2547XXXXXXXX" maxlength="32">
                    @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3" data-sms-audience="csv">
                    <label class="form-label required" for="sms-csv">{{ trans('plugins/sms::sms.send.csv_label') }}</label>
                    <textarea class="form-control font-monospace @error('csv') is-invalid @enderror" id="sms-csv" name="csv" rows="6" placeholder="+2547XXXXXXXX,Demo Customer&#10;+2341XXXXXXXXX,Another Customer">{{ old('csv') }}</textarea>
                    <div class="form-hint">{{ trans('plugins/sms::sms.send.csv_helper') }}</div>
                    @error('csv')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label required" for="sms-message">{{ trans('plugins/sms::sms.send.message') }}</label>
                    <textarea class="form-control @error('message') is-invalid @enderror" id="sms-message" name="message" rows="4" maxlength="918" required>{{ old('message') }}</textarea>
                    <div class="d-flex justify-content-between form-hint">
                        <span>{!! trans('plugins/sms::sms.send.message_helper') !!}</span>
                        <span data-sms-counter></span>
                    </div>
                    @error('message')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <label class="form-check">
                    <input type="hidden" name="urgent" value="0">
                    <input class="form-check-input" type="checkbox" name="urgent" value="1" @checked(old('urgent'))>
                    <span class="form-check-label">{{ trans('plugins/sms::sms.send.urgent') }}</span>
                </label>
            </x-core::card.body>

            <div class="card-footer d-flex justify-content-between align-items-center">
                <a href="{{ route('sms.logs.index') }}">{{ trans('plugins/sms::sms.logs.title') }}</a>
                <button type="submit" class="btn btn-primary" data-sms-submit>
                    <x-core::icon name="ti ti-send" />
                    {{ trans('plugins/sms::sms.send.submit') }}
                </button>
            </div>
        </x-core::card>
    </form>

    <script>
        (function () {
            const form = document.querySelector('.sms-send-form');

            if (! form) {
                return;
            }

            const sync = () => {
                const audience = form.querySelector('input[name="audience"]:checked')?.value || 'single';

                form.querySelectorAll('[data-sms-audience]').forEach((element) => {
                    element.style.display = element.dataset.smsAudience === audience ? '' : 'none';
                });
            };

            form.querySelectorAll('input[name="audience"]').forEach((input) => input.addEventListener('change', sync));
            sync();

            const message = form.querySelector('#sms-message');
            const counter = form.querySelector('[data-sms-counter]');
            const gsm = /^[@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&'()*+,\-./0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà^{}\\\[~\]|€]*$/;

            const count = () => {
                const text = message.value;
                const isGsm = gsm.test(text);
                const length = isGsm ? [...text].reduce((total, char) => total + ('^{}\\[~]|€'.includes(char) ? 2 : 1), 0) : [...text].length;
                const single = isGsm ? 160 : 70;
                const multi = isGsm ? 153 : 67;
                const parts = length <= single ? 1 : Math.ceil(length / multi);

                counter.textContent = length + ' · ' + parts + ' SMS' + (isGsm ? '' : ' (Unicode)');
            };

            message.addEventListener('input', count);
            count();

            form.addEventListener('submit', (event) => {
                const audience = form.querySelector('input[name="audience"]:checked')?.value;

                if (audience !== 'single' && ! window.confirm(@json(trans('plugins/sms::sms.send.confirm_bulk')))) {
                    event.preventDefault();

                    return;
                }

                form.querySelector('[data-sms-submit]').disabled = true;
            });
        })();
    </script>
@stop
