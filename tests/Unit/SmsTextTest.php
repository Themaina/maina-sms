<?php

namespace MainaSuperintelligence\Sms\Tests\Unit;

use MainaSuperintelligence\Sms\Supports\SmsText;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Supports/SmsText.php';

class SmsTextTest extends TestCase
{
    public function test_renders_placeholders_with_and_without_spaces(): void
    {
        $this->assertSame(
            'Hi Jane, your Monthly plan ends on 2026-11-01.',
            SmsText::render('Hi {{name}}, your {{ plan }} plan ends on {{ends_at}}.', [
                'name' => 'Jane',
                'plan' => 'Monthly',
                'ends_at' => '2026-11-01',
            ])
        );
    }

    public function test_unknown_placeholders_render_empty_and_tidy_spacing(): void
    {
        $this->assertSame('Hi, welcome back.', SmsText::render('Hi {{first_name}}, welcome back.', []));
        $this->assertSame('Order #1 ready', SmsText::render('Order {{code}} {{missing}} ready', ['code' => '#1']));
    }

    public function test_non_scalar_values_render_empty(): void
    {
        $this->assertSame('x', SmsText::render('x{{a}}', ['a' => ['nested']]));
        $this->assertSame('Paid: yes', SmsText::render('Paid: {{paid}}', ['paid' => true]));
    }

    public function test_lists_placeholders(): void
    {
        $this->assertSame(['name', 'plan'], SmsText::placeholders('{{name}} {{ plan }} {{name}}'));
    }

    public function test_counts_gsm_segments(): void
    {
        $this->assertSame(1, SmsText::segments(str_repeat('a', 160)));
        $this->assertSame(2, SmsText::segments(str_repeat('a', 161)));
        $this->assertSame(2, SmsText::segments(str_repeat('a', 306)));
        $this->assertSame(3, SmsText::segments(str_repeat('a', 307)));
        // Extension characters count double.
        $this->assertSame(2, SmsText::segments(str_repeat('€', 81)));
    }

    public function test_counts_unicode_segments(): void
    {
        $this->assertTrue(SmsText::isGsm7('Hello @ 5pm'));
        $this->assertFalse(SmsText::isGsm7('Asante 🙏'));
        $this->assertSame(1, SmsText::segments(str_repeat('ü', 160)));
        $this->assertSame(1, SmsText::segments('🙏' . str_repeat('a', 69)));
        $this->assertSame(2, SmsText::segments('🙏' . str_repeat('a', 70)));
    }
}
