<?php

namespace MainaSuperintelligence\Sms\Tests\Feature;

use Botble\ACL\Models\User;
use Botble\ACL\Services\ActivateUserService;
use MainaSuperintelligence\Sms\Tests\SmsTestCase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Admin list pages are Botble TableAbstract tables, which load their rows with a DataTables POST
 * to the page's own URL. A GET-only route renders an empty table forever.
 */
class AdminTableAjaxTest extends SmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = new User();
        $admin->forceFill([
            'email' => self::FIXTURE_PREFIX . '-' . Str::lower(Str::random(8)) . '@example.test',
            'username' => str_replace('-', '_', self::FIXTURE_PREFIX) . '_' . Str::lower(Str::random(6)),
            'password' => bcrypt(Str::random(20)),
            'first_name' => 'IT',
            'last_name' => 'Admin',
            'super_user' => 1,
        ])->save();

        app(ActivateUserService::class)->activate($admin);

        $this->actingAs($admin);
        app()->setLocale('en');
    }

    /** @return array<string, array{string}> */
    public static function tables(): array
    {
        return [
            'sms logs' => ['sms.logs.index'],
        ];
    }

    #[DataProvider('tables')]
    public function test_table_rows_load_over_datatables_post(string $routeName): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
            ->post(route($routeName), ['draw' => 1, 'start' => 0, 'length' => 10], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }
}
