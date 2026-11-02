# Changelog

## 1.1.0
- First public release on the Botble Marketplace as **SMS via Africa's Talking**, free under the MIT License.
- Vendor namespace is now `MainaSuperintelligence\Sms` and the plugin id is `maina888/sms`.
- Works on any Botble shop: a project-specific recipient group is replaced by an `sms_audiences` filter that other plugins can use to add their own groups.
- The default country code is no longer fixed to Kenya: it is a setting, falls back to the store country when Africa's Talking serves it, and otherwise requires international numbers.
- Quiet hours use the site timezone unless you set one.
- Neutral placeholders and examples; delivery-report route works on Laravel 12 and 13.
- Unit tests run with only the project's vendor directory; feature tests are a separate suite.

## 1.0.0
- Africa's Talking sending with encrypted API key, Sandbox/Live switch and account balance.
- Order placed / confirmed / payment received / completed messages with editable templates.
- Quiet hours, delivery reports, SMS logs with resend, bulk sends and a dashboard widget.
- `sms_send()`, `sms_send_template()`, `sms_templates` filter and SmsSent / SmsDelivered / SmsFailed events.
