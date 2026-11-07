<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class AuditTrailTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC')]);
    }

    public function test_guest_and_unprivileged_account_cannot_read_or_export_the_audit(): void
    {
        $this->getJson('/api/v1/audit-events')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/audit-events')->assertForbidden();
        $this->getJson('/api/v1/audit-events/export?month=2026-11')->assertForbidden();
        $this->assertSame(3, AuditEvent::where('result', 'REFUSED')->count());
    }

    public function test_read_and_export_are_separate_server_permissions_and_their_use_is_traced(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('AUDIT_READ', fn (): bool => true);
        $this->getJson('/api/v1/audit-events')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/audit-events/export?month=2026-11')->assertForbidden();
        $this->assertSame(1, AuditEvent::where('action', 'audit.read')->where('result', 'SUCCESS')->count());
        Gate::define('AUDIT_READ', fn (): bool => false);
        Gate::define('AUDIT_EXPORT', fn (): bool => true);
        $this->getJson('/api/v1/audit-events')->assertForbidden();
        $response = $this->get('/api/v1/audit-events/export?month=2026-11')->assertOk();
        unlink($response->baseResponse->getFile()->getPathname());
        $this->assertSame(1, AuditEvent::where('action', 'audit.export')->where('result', 'SUCCESS')->count());
    }

    public function test_role_snapshot_and_allowed_changes_never_store_passwords_tokens_or_body(): void
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create(['code' => 'AUDITOR']);
        $actor->roles()->attach($role->id, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
        $event = $this->event(['actor_id' => $actor->id, 'changes' => [
            'before' => ['status' => 'PENDING', 'password' => 'SECRET-PASSWORD', 0 => 'SECRET-UNTYPED'],
            'after' => ['status' => 'RETRY', 'access_token' => 'SECRET-TOKEN', 'headers' => ['cookie' => 'SECRET-COOKIE']],
            'raw_body' => 'SECRET-BODY', 0 => 'SECRET-LIST',
        ]]);
        $this->assertSame(['AUDITOR'], $event->role_context);
        $this->assertSame(['before' => ['status' => 'PENDING'], 'after' => ['status' => 'RETRY']], $event->changes);
        $actor->roles()->detach();
        $this->assertSame(['AUDITOR'], $event->fresh()->role_context);
        $this->assertStringNotContainsString('SECRET-', $event->fresh()->toJson());
    }

    public function test_role_audit_preserves_complete_permission_lists_and_rejects_untyped_secrets(): void
    {
        $codes = array_map(fn (int $number): string => 'PERMISSION_'.$number, range(1, 1000));
        $event = $this->event(['changes' => [
            'before' => ['code' => 'WAREHOUSE', 'label' => 'Warehouse', 'permissions' => []],
            'after' => ['permissions' => $codes],
        ]]);
        $this->assertSame(['code' => 'WAREHOUSE', 'label' => 'Warehouse', 'permissions' => []], $event->changes['before']);
        $this->assertSame($codes, $event->changes['after']['permissions']);

        $unsafe = $this->event(['changes' => ['after' => ['permissions' => [
            'ROLE_MANAGE', 'PERMISSION_lowercase_1', ['password' => 'SECRET-PASSWORD'], 'secret-token', 42,
        ]]]]);
        $this->assertSame(['ROLE_MANAGE', 'PERMISSION_lowercase_1'], $unsafe->changes['after']['permissions']);
        $this->assertStringNotContainsString('SECRET-', $unsafe->toJson());
    }

    public static function mutations(): array
    {
        return ['update query' => ['update'], 'delete query' => ['delete']];
    }

    #[DataProvider('mutations')]
    public function test_storage_refuses_modifying_audit_through_query_builder(string $operation): void
    {
        $event = $this->event();
        try {
            $query = DB::table('audit_events')->where('id', $event->id);
            if ($operation === 'update') {
                $query->update(['result' => 'FAILED']);
            } else {
                $query->delete();
            }
            $this->fail('Stored audit must be immutable.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Audit events are immutable', $exception->getMessage());
        }
        $this->assertDatabaseHas('audit_events', ['id' => $event->id, 'result' => 'SUCCESS']);
    }

    public function test_filters_are_combined_and_month_boundary_is_exclusive(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        Gate::define('AUDIT_READ', fn (): bool => true);
        $target = $this->event(['actor_id' => $actor->id, 'occurred_at' => '2026-11-01 00:00:00', 'action' => 'product.created', 'object_id' => '42']);
        $this->event(['actor_id' => $actor->id, 'occurred_at' => '2026-12-01 00:00:00', 'action' => 'product.created', 'object_id' => '42']);
        $this->event(['actor_id' => $actor->id, 'occurred_at' => '2026-11-12 00:00:00', 'action' => 'product.created', 'object_id' => '43']);
        config(['audit.timezone' => 'UTC']);
        $this->getJson('/api/v1/audit-events?month=2026-11&actor_id='.$actor->id.'&action=product.created&object_type=product&object_id=42&result=SUCCESS')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/v1/audit-events?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/audit-events?action='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_month_export_is_real_excel_contains_every_filtered_row_and_no_formulas(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('AUDIT_EXPORT', fn (): bool => true);
        config(['audit.timezone' => 'UTC']);
        $this->event(['object_id' => '=HYPERLINK("https://invalid.test")', 'occurred_at' => '2026-11-30 23:59:59']);
        $this->event(['object_id' => 'NEXT-MONTH', 'occurred_at' => '2026-12-01 00:00:00']);
        $response = $this->get('/api/v1/audit-events/export?month=2026-11')->assertOk()
            ->assertHeader('X-Audit-Export-Count', '1');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('HYPERLINK', $sheet);
        $this->assertStringContainsString('t="inlineStr"', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringNotContainsString('NEXT-MONTH', $sheet);
        $this->assertSame(2, substr_count($sheet, '<row>'));
        $zip->close();
        unlink($path);
        $this->assertSame('workbook_prepared', AuditEvent::where('action', 'audit.export')->sole()->reason);
    }

    public function test_critical_product_write_rolls_back_when_success_audit_cannot_be_written(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('PRODUCT_CREATE', fn (): bool => true);
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->result === 'SUCCESS') {
                throw new RuntimeException('SECRET-storage-marker');
            }
        });
        $this->postJson('/api/v1/products', ['sku' => 'AUDIT-ATOMIC', 'designation' => 'Pièce', 'original_reference' => '001'])
            ->assertStatus(500);
        $this->assertDatabaseMissing('products', ['sku' => 'AUDIT-ATOMIC']);
        $event = AuditEvent::where('result', 'FAILED')->sole();
        $this->assertStringNotContainsString('SECRET-storage-marker', $event->toJson());
    }

    public function test_cli_audited_transaction_retains_failure_after_rollback(): void
    {
        try {
            app(AuditWriter::class)->transaction(function (): void {
                Product::factory()->create(['sku' => 'CLI-ROLLBACK']);
                $this->event();
                throw new RuntimeException('SECRET-command');
            }, ['actor_id' => null, 'action' => 'product.import', 'object_type' => 'product']);
            $this->fail('The operation must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SECRET-command', $exception->getMessage());
        }
        $this->assertDatabaseMissing('products', ['sku' => 'CLI-ROLLBACK']);
        $this->assertSame(1, AuditEvent::where('action', 'product.import')->where('result', 'FAILED')->count());
        $this->assertSame(0, AuditEvent::where('result', 'SUCCESS')->count());
    }

    public function test_audit_page_and_public_reference_search_use_server_generated_events(): void
    {
        $this->withoutVite();
        $this->getJson('/api/v1/catalogue/products?reference=SAFE-REFERENCE')->assertOk();
        $event = AuditEvent::where('action', 'catalogue.search')->sole();
        $this->assertSame('visitor', $event->actor_kind);
        $this->assertNotNull($event->request_id);
        $this->actingAs(User::factory()->create());
        Gate::define('AUDIT_READ', fn (): bool => true);
        $this->get('/gestion/audit')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('audit-console')->assertSee('export Excel mensuel');
    }

    public function test_application_roles_cannot_create_update_or_delete_journal_entries(): void
    {
        $event = $this->event();
        $this->actingAs(User::factory()->create());
        Gate::before(fn (): bool => true);
        $this->postJson('/api/v1/audit-events', ['result' => 'SUCCESS', 'actor_id' => 999])->assertStatus(405);
        $this->patchJson('/api/v1/audit-events/'.$event->id, ['result' => 'FAILED'])->assertNotFound();
        $this->deleteJson('/api/v1/audit-events/'.$event->id)->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['id' => $event->id, 'result' => 'SUCCESS']);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_date_interval_rejects_missing_timezone_and_reversed_bounds(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('AUDIT_READ', fn (): bool => true);
        $this->getJson('/api/v1/audit-events?from=2026-11-01T00:00:00')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/v1/audit-events?from=2026-11-02T00:00:00Z&to=2026-11-01T00:00:00Z')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/audit-events?to=2026-11-01T00:00:00Z')->assertOk();
    }

    public function test_month_boundary_converts_the_configured_timezone_to_utc(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('AUDIT_READ', fn (): bool => true);
        config(['audit.timezone' => 'Europe/Paris']);
        $this->event(['occurred_at' => '2026-10-31 22:59:59']);
        $included = $this->event(['occurred_at' => '2026-10-31 23:00:00']);
        $this->event(['occurred_at' => '2026-11-30 23:00:00']);
        $this->getJson('/api/v1/audit-events?month=2026-11')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $included->id);
    }

    public function test_browser_login_redirect_is_recorded_as_refused_instead_of_a_successful_audit_read(): void
    {
        $this->get('/gestion/audit')->assertRedirect(route('login'));
        $event = AuditEvent::where('action', 'audit.page_view')->sole();
        $this->assertSame('REFUSED', $event->result);
        $this->assertSame('http_401', $event->reason);
        $this->assertSame('visitor', $event->actor_kind);
    }

    public function test_export_storage_failure_does_not_publish_a_partial_workbook(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('occupied', 'Keep this file');
        config(['audit.export_directory' => 'occupied']);
        $this->actingAs(User::factory()->create());
        Gate::define('AUDIT_EXPORT', fn (): bool => true);
        $this->getJson('/api/v1/audit-events/export?month=2026-11')->assertStatus(500);
        $this->assertSame('Keep this file', Storage::disk('local')->get('occupied'));
        $this->assertSame(1, AuditEvent::where('action', 'audit.export')->where('result', 'FAILED')->count());
        $this->assertSame(0, AuditEvent::where('action', 'audit.export')->where('result', 'SUCCESS')->count());
    }

    public function test_export_is_not_released_if_its_own_audit_fails(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        Gate::define('AUDIT_EXPORT', fn (): bool => true);
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'audit.export' && $event->result === 'SUCCESS') {
                throw new RuntimeException('Simulated export audit outage');
            }
        });
        $this->getJson('/api/v1/audit-events/export?month=2026-11')->assertStatus(500);
        $this->assertSame([], Storage::disk('local')->allFiles(config('audit.export_directory')));
        $this->assertSame(0, AuditEvent::where('action', 'audit.export')->where('result', 'SUCCESS')->count());
    }

    public function test_unauthenticated_automated_operation_is_a_refusal_after_rollback(): void
    {
        try {
            app(AuditWriter::class)->transaction(function (): void {
                Product::factory()->create(['sku' => 'AUTH-ROLLBACK']);
                throw new AuthenticationException('SECRET-authentication');
            }, ['actor_id' => null, 'action' => 'product.import', 'object_type' => 'product']);
            $this->fail('The unauthenticated operation must fail.');
        } catch (AuthenticationException) {
            $this->assertDatabaseMissing('products', ['sku' => 'AUTH-ROLLBACK']);
        }
        $event = AuditEvent::where('action', 'product.import')->sole();
        $this->assertSame('REFUSED', $event->result);
        $this->assertSame('system', $event->actor_kind);
        $this->assertStringNotContainsString('SECRET-', $event->toJson());
    }

    public function test_export_head_checks_permissions_without_creating_an_excel_file(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        Gate::define('AUDIT_EXPORT', fn (): bool => true);
        $this->head('/api/v1/audit-events/export?month=2026-11')->assertOk();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(1, AuditEvent::where('action', 'audit.export_metadata')->where('result', 'SUCCESS')->count());
        $this->assertSame(0, AuditEvent::where('action', 'audit.export')->count());
    }

    public function test_cart_and_order_head_are_audited_as_reads_without_business_changes(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($profile->user);
        $this->head('/api/v1/me/cart')->assertOk();
        $this->head('/api/v1/me/orders')->assertOk();
        $this->assertSame(1, AuditEvent::where('action', 'cart.view')->where('result', 'SUCCESS')->count());
        $this->assertSame(1, AuditEvent::where('action', 'order.view')->where('result', 'SUCCESS')->count());
        $this->assertSame(0, AuditEvent::whereIn('action', ['cart.change', 'order.submit'])->count());
        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_pagination_does_not_repeat_events_when_each_read_adds_an_audit_event(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('AUDIT_READ', fn (): bool => true);
        $this->freezeTime();
        for ($index = 0; $index < 22; $index++) {
            $this->event(['occurred_at' => now('UTC')->subSeconds($index)]);
        }
        $first = $this->getJson('/api/v1/audit-events?per_page=20')->assertOk()->assertJsonCount(20, 'data')->json();
        $this->assertNotNull($first['meta']['next_cursor']);
        $this->assertSame(1, AuditEvent::where('action', 'audit.read')->count());
        $second = $this->getJson('/api/v1/audit-events?'.http_build_query(['per_page' => 20, 'cursor' => $first['meta']['next_cursor']]))
            ->assertOk()->assertJsonCount(2, 'data')->json();
        $this->assertSame([], array_values(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id'))));
        $this->assertNull($second['meta']['next_cursor']);
        $back = $this->getJson('/api/v1/audit-events?'.http_build_query(['per_page' => 20, 'cursor' => $second['meta']['previous_cursor']]))
            ->assertOk()->assertJsonCount(20, 'data')->json();
        $this->assertSame(array_column($first['data'], 'id'), array_column($back['data'], 'id'));
        $this->getJson('/api/v1/audit-events?cursor=invalid')->assertUnprocessable()->assertJsonValidationErrors('cursor');
        foreach (['tomorrow', '', '2026-02-30 00:00:00'] as $timestamp) {
            $invalid = new Cursor(['occurred_at' => $timestamp, 'id' => $first['data'][0]['id']]);
            $this->getJson('/api/v1/audit-events?'.http_build_query(['cursor' => $invalid->encode()]))
                ->assertUnprocessable()->assertJsonValidationErrors('cursor');
        }
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): AuditEvent
    {
        return AuditEvent::create(array_replace([
            'actor_id' => null, 'request_id' => (string) Str::uuid(), 'action' => 'product.created',
            'object_type' => 'product', 'object_id' => '1', 'result' => 'SUCCESS', 'occurred_at' => now('UTC'),
        ], $overrides));
    }
}
