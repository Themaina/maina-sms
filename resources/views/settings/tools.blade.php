<div class="sms-settings-tools mb-4" data-test-url="{{ route('sms.settings.test') }}" data-balance-url="{{ route('sms.settings.balance') }}">
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="border rounded p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <strong>{{ trans('plugins/sms::sms.settings.balance') }}</strong>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-sms-balance>
                        {{ trans('plugins/sms::sms.settings.check_balance') }}
                    </button>
                </div>
                <div class="fs-2 fw-bold" data-sms-balance-value>
                    {{ $hasApiKey ? '…' : trans('plugins/sms::sms.settings.balance_needs_key') }}
                </div>
                <div class="text-muted small mt-2">{{ trans('plugins/sms::sms.settings.balance_helper') }}</div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="border rounded p-3 h-100">
                <strong class="d-block mb-2">{{ trans('plugins/sms::sms.settings.test_send') }}</strong>
                <div class="input-group mb-2">
                    <input type="text" class="form-control" data-sms-test-phone placeholder="+2547XXXXXXXX" maxlength="32" autocomplete="off">
                    <button type="button" class="btn btn-primary" data-sms-test>
                        {{ trans('plugins/sms::sms.settings.send_test') }}
                    </button>
                </div>
                <input type="text" class="form-control mb-2" data-sms-test-message maxlength="918" placeholder="{{ trans('plugins/sms::sms.settings.test_message_placeholder') }}">
                <div class="small" data-sms-test-result></div>
                <div class="text-muted small">{{ trans('plugins/sms::sms.settings.test_helper') }}</div>
            </div>
        </div>
        <div class="col-12">
            <div class="border rounded p-3">
                <strong class="d-block mb-1">{{ trans('plugins/sms::sms.settings.delivery_url') }}</strong>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" value="{{ $deliveryUrl }}" readonly data-sms-delivery-url>
                    <button type="button" class="btn btn-outline-secondary" data-sms-copy>{{ trans('plugins/sms::sms.settings.copy') }}</button>
                </div>
                <div class="text-muted small mt-1">{{ trans('plugins/sms::sms.settings.delivery_url_helper') }}</div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const root = document.querySelector('.sms-settings-tools');

        if (! root || root.dataset.bound) {
            return;
        }

        root.dataset.bound = '1';

        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const request = (url, method, body) => fetch(url, {
            method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined,
        }).then(async (response) => {
            const json = await response.json().catch(() => ({}));

            if (! response.ok && ! json.message) {
                json.error = true;
                json.message = json.errors ? Object.values(json.errors).flat().join(' ') : ('HTTP ' + response.status);
            }

            if (response.status === 422) {
                json.error = true;
            }

            return json;
        });

        const notify = (json) => {
            if (window.Botble) {
                json.error ? Botble.showError(json.message) : Botble.showSuccess(json.message);
            }
        };

        const balanceValue = root.querySelector('[data-sms-balance-value]');

        const loadBalance = () => {
            balanceValue.textContent = '…';

            request(root.dataset.balanceUrl, 'GET').then((json) => {
                balanceValue.textContent = json.data && json.data.balance ? json.data.balance : (json.message || '—');
            }).catch(() => {
                balanceValue.textContent = '—';
            });
        };

        root.querySelector('[data-sms-balance]').addEventListener('click', loadBalance);

        @if ($hasApiKey)
            loadBalance();
        @endif

        root.querySelector('[data-sms-test]').addEventListener('click', (event) => {
            const button = event.currentTarget;
            const result = root.querySelector('[data-sms-test-result]');
            const phone = root.querySelector('[data-sms-test-phone]').value.trim();
            const message = root.querySelector('[data-sms-test-message]').value.trim();

            if (! phone) {
                root.querySelector('[data-sms-test-phone]').focus();

                return;
            }

            button.disabled = true;
            result.className = 'small text-muted';
            result.textContent = '…';

            request(root.dataset.testUrl, 'POST', { phone, message }).then((json) => {
                result.className = 'small mb-1 ' + (json.error ? 'text-danger' : 'text-success');
                result.textContent = json.message || '';
                notify(json);
            }).finally(() => {
                button.disabled = false;
            });
        });

        root.querySelector('[data-sms-copy]').addEventListener('click', () => {
            const input = root.querySelector('[data-sms-delivery-url]');

            input.select();

            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value);
            } else {
                document.execCommand('copy');
            }

            if (window.Botble) {
                Botble.showSuccess(@json(trans('plugins/sms::sms.settings.copied')));
            }
        });
    })();
</script>
