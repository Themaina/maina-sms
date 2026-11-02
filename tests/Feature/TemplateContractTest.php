<?php

namespace Maina888\Sms\Tests\Feature;

use Maina888\Sms\Services\SmsService;
use Maina888\Sms\Supports\SmsText;
use Maina888\Sms\Tests\SmsTestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Contract for other plugins: the `sms_templates` registry, the built-in order templates and the
 * public SmsService signature.
 */
class TemplateContractTest extends SmsTestCase
{
    public const ECOMMERCE_KEYS = ['order_placed', 'order_confirmed', 'order_payment_received', 'order_completed'];

    public function test_built_in_ecommerce_templates_are_registered(): void
    {
        if (! is_plugin_active('ecommerce')) {
            $this->markTestSkipped('ecommerce is not active.');
        }

        $templates = app(SmsService::class)->templates();

        foreach (self::ECOMMERCE_KEYS as $key) {
            $this->assertArrayHasKey($key, $templates);
            $this->assertTrue($templates[$key]['urgent'], "{$key} should be urgent");
        }
    }

    public function test_built_in_templates_render_completely(): void
    {
        if (! is_plugin_active('ecommerce')) {
            $this->markTestSkipped('ecommerce is not active.');
        }

        $templates = app(SmsService::class)->templates();

        foreach (self::ECOMMERCE_KEYS as $key) {
            $this->assertRendersCompletely($key, $templates[$key]);
        }
    }

    public function test_other_plugins_can_register_templates(): void
    {
        add_filter('sms_templates', function (array $templates): array {
            $templates['it_plugintests_shipped'] = [
                'label' => 'Order shipped',
                'group' => 'Shipping',
                'default' => 'Hi {{first_name}}, order {{order_code}} is on its way. {{site_name}}',
                'variables' => ['first_name' => 'First name', 'order_code' => 'Order code'],
            ];

            return $templates;
        }, 30);

        $templates = app(SmsService::class)->templates();

        $this->assertArrayHasKey('it_plugintests_shipped', $templates);
        $this->assertFalse($templates['it_plugintests_shipped']['urgent']);
        $this->assertRendersCompletely('it_plugintests_shipped', $templates['it_plugintests_shipped']);
    }

    public function test_registry_ignores_malformed_entries(): void
    {
        add_filter('sms_templates', function (array $templates): array {
            $templates['Bad Key!'] = ['default' => 'x'];
            $templates['it_plugintests_list'] = 'not an array';
            $templates[] = ['key' => 'it_plugintests_numeric', 'default' => 'Hi {{name}}', 'variables' => ['name']];

            return $templates;
        }, 999);

        $templates = app(SmsService::class)->templates();

        $this->assertArrayNotHasKey('Bad Key!', $templates);
        $this->assertArrayNotHasKey('it_plugintests_list', $templates);
        $this->assertArrayHasKey('it_plugintests_numeric', $templates);
        $this->assertSame(['name' => 'Name'], $templates['it_plugintests_numeric']['variables']);
    }

    /**
     * Other plugins call send(string|array $to, string $message, array $context = [], bool $urgent = false).
     */
    public function test_public_send_signature(): void
    {
        $method = new ReflectionMethod(SmsService::class, 'send');
        $parameters = $method->getParameters();

        $this->assertSame(['to', 'message', 'context', 'urgent'], array_map(fn ($parameter) => $parameter->getName(), $parameters));

        $toType = $parameters[0]->getType();
        $this->assertInstanceOf(ReflectionUnionType::class, $toType);
        $this->assertEqualsCanonicalizing(['string', 'array'], array_map(fn (ReflectionNamedType $type) => $type->getName(), $toType->getTypes()));
        $this->assertSame('string', (string) $parameters[1]->getType());
        $this->assertSame('array', (string) $parameters[2]->getType());
        $this->assertSame([], $parameters[2]->getDefaultValue());
        $this->assertSame('bool', (string) $parameters[3]->getType());
        $this->assertFalse($parameters[3]->getDefaultValue());

        foreach (['renderTemplate', 'normalisePhone', 'balance', 'sendTemplate'] as $name) {
            $this->assertTrue(method_exists(SmsService::class, $name), "SmsService::{$name}() missing");
        }

        $this->assertTrue(function_exists('sms_send'));
    }

    protected function assertRendersCompletely(string $key, array $template): void
    {
        // Check the registered default, not an admin override saved in Settings > SMS.
        setting()->set('sms_template_' . $key, '');

        $vars = [];

        foreach (array_keys($template['variables']) as $name) {
            $vars[$name] = 'v_' . $name;
        }

        $placeholders = SmsText::placeholders($template['default']);
        $declared = array_merge(array_keys($template['variables']), array_keys(app(SmsService::class)->globalVariables()));

        $this->assertSame([], array_values(array_diff($placeholders, $declared)), "{$key} uses undeclared placeholders");

        $text = app(SmsService::class)->renderTemplate($key, $vars);

        $this->assertStringNotContainsString('{{', $text, "{$key} left a placeholder");
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $text, "{$key} has a single-brace placeholder");

        foreach ($placeholders as $name) {
            if (isset($vars[$name])) {
                $this->assertStringContainsString($vars[$name], $text, "{$key} did not render {$name}");
            }
        }
    }
}
