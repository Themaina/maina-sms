<?php

namespace Maina888\Sms\Tests\Unit;

use Maina888\Sms\Supports\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Supports/PhoneNumber.php';

class PhoneNumberTest extends TestCase
{
    public static function kenyanNumbers(): array
    {
        return [
            'local 07' => ['0712345678', '+254712345678'],
            'local 07 spaced' => ['0712 345 678', '+254712345678'],
            'local 01' => ['0110345678', '+254110345678'],
            'no trunk zero' => ['712345678', '+254712345678'],
            'no trunk zero 1xx' => ['112345678', '+254112345678'],
            '254 prefix' => ['254712345678', '+254712345678'],
            'e164' => ['+254712345678', '+254712345678'],
            'e164 spaced' => ['+254 712 345 678', '+254712345678'],
            'dashes and brackets' => ['(0712) 345-678', '+254712345678'],
            '00 prefix' => ['00254712345678', '+254712345678'],
            'trunk zero after code' => ['+2540712345678', '+254712345678'],
            'business line' => ['+254 700 000 222', '+254700000222'],
        ];
    }

    #[DataProvider('kenyanNumbers')]
    public function test_normalises_kenyan_mobiles(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalise($input, '254'));
    }

    public static function invalidNumbers(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'letters' => ['call me'],
            'too short' => ['07123'],
            'too long' => ['07123456789'],
            'landline 020' => ['0202345678'],
            'kenyan landline e164' => ['+254202345678'],
            'kenyan too long' => ['+2547123456789'],
            'html' => ['<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_rejects_invalid_numbers(string $input): void
    {
        $this->assertNull(PhoneNumber::normalise($input, '254'));
    }

    public function test_null_is_null(): void
    {
        $this->assertNull(PhoneNumber::normalise(null));
    }

    public function test_keeps_other_countries_written_with_plus(): void
    {
        $this->assertSame('+447911123456', PhoneNumber::normalise('+44 7911 123456'));
        $this->assertSame('+256772123456', PhoneNumber::normalise('00256772123456'));
    }

    public function test_without_a_default_country_only_international_numbers_work(): void
    {
        $this->assertNull(PhoneNumber::normalise('0712345678'));
        $this->assertNull(PhoneNumber::normalise('712345678'));
        $this->assertSame('+254712345678', PhoneNumber::normalise('254712345678'));
        $this->assertSame('+2348031234567', PhoneNumber::normalise('+234 803 123 4567'));
    }

    public function test_uses_the_default_country_code(): void
    {
        $this->assertSame('+256772123456', PhoneNumber::normalise('0772123456', '256'));
        $this->assertSame('+256772123456', PhoneNumber::normalise('0772123456', '+256'));
    }
}
