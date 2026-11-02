<?php

namespace Maina888\Sms\Forms;

use Botble\Base\Forms\FieldOptions\CheckboxFieldOption;
use Botble\Base\Forms\FieldOptions\HtmlFieldOption;
use Botble\Base\Forms\FieldOptions\RadioFieldOption;
use Botble\Base\Forms\FieldOptions\TextareaFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\HtmlField;
use Botble\Base\Forms\Fields\OnOffCheckboxField;
use Botble\Base\Forms\Fields\PasswordField;
use Botble\Base\Forms\Fields\RadioField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Setting\Forms\SettingForm;
use Maina888\Sms\Http\Requests\SmsSettingRequest;
use Maina888\Sms\Services\SmsService;
use Maina888\Sms\Supports\SmsSettings;

class SmsSettingForm extends SettingForm
{
    public function setup(): void
    {
        parent::setup();

        $sms = app(SmsService::class);

        $this
            ->setSectionTitle(trans('plugins/sms::sms.settings.title'))
            ->setSectionDescription(trans('plugins/sms::sms.settings.description'))
            ->setValidatorClass(SmsSettingRequest::class)
            ->add(
                'sms_tools',
                HtmlField::class,
                HtmlFieldOption::make()->view('plugins/sms::settings.tools', [
                    'deliveryUrl' => SmsSettings::deliveryReportUrl(),
                    'hasApiKey' => SmsSettings::hasApiKey(),
                ])
            )
            ->add(
                'sms_enabled',
                OnOffCheckboxField::class,
                CheckboxFieldOption::make()
                    ->label(trans('plugins/sms::sms.settings.enabled'))
                    ->value(SmsSettings::enabled())
            )
            ->add(
                'sms_environment',
                RadioField::class,
                RadioFieldOption::make()
                    ->label(trans('plugins/sms::sms.settings.environment'))
                    ->choices([
                        'sandbox' => trans('plugins/sms::sms.settings.sandbox'),
                        'live' => trans('plugins/sms::sms.settings.live'),
                    ])
                    ->selected(SmsSettings::environment())
                    ->helperText(trans('plugins/sms::sms.settings.environment_helper'))
            )
            ->add(
                'sms_username',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/sms::sms.settings.username'))
                    ->value(setting('sms_username'))
                    ->placeholder('myshop')
                    ->helperText(trans('plugins/sms::sms.settings.username_helper'))
                    ->maxLength(120)
            )
            ->add(
                'sms_api_key',
                PasswordField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/sms::sms.settings.api_key'))
                    ->value('')
                    ->placeholder(SmsSettings::hasApiKey()
                        ? trans('plugins/sms::sms.settings.api_key_saved')
                        : trans('plugins/sms::sms.settings.api_key_placeholder'))
                    ->helperText(trans('plugins/sms::sms.settings.api_key_helper'))
                    ->maxLength(255)
            )
            ->add(
                'sms_sender_id',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/sms::sms.settings.sender_id'))
                    ->value(setting('sms_sender_id'))
                    ->placeholder('MYSHOP')
                    ->helperText(trans('plugins/sms::sms.settings.sender_id_helper'))
                    ->maxLength(20)
            )
            ->add(
                'sms_default_country_code',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/sms::sms.settings.default_country_code'))
                    ->value(setting('sms_default_country_code'))
                    ->placeholder(SmsSettings::defaultCountryCode() ?: '254')
                    ->helperText(trans('plugins/sms::sms.settings.default_country_code_helper'))
                    ->maxLength(4)
            )
            ->add(
                'sms_quiet_hours_enabled',
                OnOffCheckboxField::class,
                CheckboxFieldOption::make()
                    ->label(trans('plugins/sms::sms.settings.quiet_hours'))
                    ->helperText(trans('plugins/sms::sms.settings.quiet_hours_helper'))
                    ->value($quiet = (bool) setting('sms_quiet_hours_enabled', true))
            )
            ->addOpenCollapsible('sms_quiet_hours_enabled', '1', $quiet ? '1' : '0')
            ->add(
                'sms_quiet_hours_start',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/sms::sms.settings.quiet_hours_start'))
                    ->value(setting('sms_quiet_hours_start', '21:00'))
                    ->placeholder('21:00')
                    ->maxLength(5)
            )
            ->add(
                'sms_quiet_hours_end',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/sms::sms.settings.quiet_hours_end'))
                    ->value(setting('sms_quiet_hours_end', '07:00'))
                    ->placeholder('07:00')
                    ->maxLength(5)
            )
            ->add(
                'sms_timezone',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/sms::sms.settings.timezone'))
                    ->value(setting('sms_timezone'))
                    ->placeholder(SmsSettings::timezone())
                    ->helperText(trans('plugins/sms::sms.settings.timezone_helper'))
                    ->maxLength(64)
            )
            ->addCloseCollapsible('sms_quiet_hours_enabled', '1');

        $currentGroup = null;

        foreach ($sms->templates() as $key => $template) {
            if ($template['group'] !== $currentGroup) {
                $currentGroup = $template['group'];

                $this->add(
                    'sms_templates_heading_' . md5($currentGroup),
                    HtmlField::class,
                    HtmlFieldOption::make()->content(
                        '<h4 class="mt-4 mb-2">' . e(trans('plugins/sms::sms.settings.templates_group', ['group' => $currentGroup])) . '</h4>'
                    )
                );
            }

            $variables = collect($template['variables'])
                ->map(fn ($description, $name) => '<code>{{' . e($name) . '}}</code> ' . e($description))
                ->implode(', ');

            $this
                ->add(
                    'sms_template_' . $key . '_enabled',
                    OnOffCheckboxField::class,
                    CheckboxFieldOption::make()
                        ->label(trans('plugins/sms::sms.settings.template_enabled', ['label' => $template['label']]))
                        ->value($enabled = $sms->templateEnabled($key))
                )
                ->addOpenCollapsible('sms_template_' . $key . '_enabled', '1', $enabled ? '1' : '0')
                ->add(
                    'sms_template_' . $key,
                    TextareaField::class,
                    TextareaFieldOption::make()
                        ->label(trans('plugins/sms::sms.settings.template_text', ['label' => $template['label']]))
                        ->value($sms->templateText($key))
                        ->rows(3)
                        ->helperText(
                            trans('plugins/sms::sms.settings.template_variables') . ' ' . $variables
                            . ($template['urgent'] ? '' : ' ' . trans('plugins/sms::sms.settings.template_waits_quiet_hours'))
                        )
                )
                ->addCloseCollapsible('sms_template_' . $key . '_enabled', '1');
        }
    }
}
