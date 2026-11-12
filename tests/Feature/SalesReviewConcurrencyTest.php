<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\QuoteNotificationSender;
use App\Services\QuotePaymentTerms;
use App\Services\SalesOrderReview;
use App\Services\SalesQuoteDrafts;
use App\Services\SalesQuotes;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class SalesReviewConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires the dedicated MySQL test database and pcntl in Docker.');
        }
    }

    private function actor(): int
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::firstOrCreate(['code' => 'ORDER_REVIEW_MANAGE'], ['module' => 'sales', 'label' => 'Review']);
        $role->permissions()->attach($permission);
        $actor->roles()->attach($role, ['assigned_by' => $actor->id, 'assigned_at' => now()]);

        return $actor->id;
    }

    private function order(): int
    {
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);

        return DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::ulid(),
            'status' => 'SOUMISE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}',
            'lines' => '[]', 'catalogue_subtotal' => '10.00', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_simultaneous_claim_has_one_owner_and_one_transition(): void
    {
        $id = $this->order();
        $first = $this->actor();
        $second = $this->actor();
        $results = $this->parallel($id, [
            ['actor' => $first, 'method' => 'claim', 'values' => []],
            ['actor' => $second, 'method' => 'claim', 'values' => []],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('sales_order_reviews', 1);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'status' => 'EN_VERIFICATION', 'version' => 2]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sales.order_claimed')->count());
    }

    public function test_simultaneous_call_replay_records_once(): void
    {
        $id = $this->order();
        $actor = $this->actor();
        app(SalesOrderReview::class)->claim(User::findOrFail($actor), $id, 1);
        $values = ['expected_version' => 2, 'request_key' => (string) Str::uuid(), 'outcome' => 'JOINED',
            'happened_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s'), 'note' => 'Concurrent test'];
        $task = ['actor' => $actor, 'method' => 'recordCall', 'values' => $values];
        $results = $this->parallel($id, [$task, $task]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $replays = array_column(array_column($results, 'data'), 'replayed');
        sort($replays);
        $this->assertSame([false, true], $replays);
        $this->assertDatabaseCount('sales_order_calls', 1);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'version' => 3]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sales.call_recorded')->count());
    }

    public function test_competing_revisions_reject_stale_version_and_preserve_original(): void
    {
        $id = $this->order();
        $actor = $this->actor();
        app(SalesOrderReview::class)->claim(User::findOrFail($actor), $id, 1);
        $values = ['expected_version' => 2, 'request_key' => (string) Str::uuid(), 'kind' => 'contact',
            'changes' => ['name' => 'Test', 'email' => 'test@example.test', 'phone' => '0000000000'], 'reason' => 'Test'];
        $results = $this->parallel($id, [
            ['actor' => $actor, 'method' => 'proposeRevision', 'values' => $values],
            ['actor' => $actor, 'method' => 'proposeRevision', 'values' => array_replace($values, ['request_key' => (string) Str::uuid()])],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('sales_order_revisions', 1);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'version' => 3]);
        $this->assertSame('{}', DB::table('customer_orders')->where('id', $id)->value('contact'));
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_concurrent_quote_replay_creates_one_immutable_revision(): void
    {
        [$id, $actor, $values] = $this->quoteScenario();
        $task = ['actor' => $actor, 'method' => 'quote', 'values' => $values];
        $results = $this->parallel($id, [$task, $task]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $replays = array_column(array_column($results, 'data'), 'replayed');
        sort($replays);
        $this->assertSame([false, true], $replays);
        $this->assertDatabaseCount('sales_quote_drafts', 1);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'version' => 3, 'status' => 'EN_VERIFICATION']);
    }

    public function test_competing_quote_creations_reject_stale_version_without_duplicate_revision(): void
    {
        [$id, $actor, $values] = $this->quoteScenario();
        $results = $this->parallel($id, [
            ['actor' => $actor, 'method' => 'quote', 'values' => $values],
            ['actor' => $actor, 'method' => 'quote', 'values' => array_replace($values, ['request_key' => (string) Str::uuid()])],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('sales_quote_drafts', 1);
        $this->assertDatabaseHas('sales_quote_drafts', ['order_id' => $id, 'revision' => 1]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sales.quote_draft_created')->count());
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_concurrent_quote_issuance_replays_one_pdf(): void
    {
        [$id, $actor, $input] = $this->issuanceScenario();
        $task = ['actor' => $actor, 'method' => 'issueQuote', 'values' => $input];
        $results = $this->parallel($id, [$task, $task]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $replays = array_column(array_column($results, 'data'), 'replayed');
        sort($replays);
        $this->assertSame([false, true], $replays);
        $this->assertDatabaseCount('sales_quotes', 1);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'status' => 'ATTENTE_ACCORD', 'version' => 4]);
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_competing_quote_decisions_keep_one_attributed_outcome(): void
    {
        [$id, $actor, $input] = $this->issuanceScenario();
        $quote = app(SalesQuotes::class)->issue(User::findOrFail($actor), $id, $input)['quote_id'];
        $values = ['quote_id' => $quote, 'expected_version' => 4, 'request_key' => (string) Str::uuid(), 'outcome' => 'ACCEPTED',
            'contact_method' => 'PHONE', 'contact_name' => 'Test', 'contacted_at' => now('UTC')->format('Y-m-d H:i:s')];
        $results = $this->parallel($id, [
            ['actor' => $actor, 'method' => 'decideQuote', 'values' => $values],
            ['actor' => $actor, 'method' => 'decideQuote', 'values' => array_replace($values, ['request_key' => (string) Str::uuid(), 'outcome' => 'REFUSED'])],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('sales_quote_decisions', 1);
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_concurrent_quote_notification_records_one_transport_acceptance(): void
    {
        [$id, $actor, $input] = $this->issuanceScenario();
        config(['quotes.notifications_enabled' => true]);
        $quote = app(SalesQuotes::class)->issue(User::findOrFail($actor), $id, $input)['quote_id'];
        $intent = DB::table('sales_quote_notifications')->where('quote_id', $quote)->first();
        $task = ['actor' => $actor, 'method' => 'notifyQuote', 'values' => ['intent_id' => $intent->id]];
        $results = $this->parallel($id, [$task, $task]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $sent = array_column(array_column($results, 'data'), 'sent');
        sort($sent);
        $this->assertSame([false, true], $sent);
        $this->assertDatabaseHas('sales_quote_notifications', ['id' => $intent->id, 'status' => 'SENT', 'attempts' => 1]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sales.quote_notification')->where('result', 'SUCCESS')->count());
    }

    /** @return array{int, int, array<string, mixed>} */
    private function issuanceScenario(): array
    {
        [$id, $actor, $values] = $this->quoteScenario();
        $draft = app(SalesQuoteDrafts::class)->prepare(User::findOrFail($actor), $id, $values, true)['draft_id'];
        $snapshot = json_decode(DB::table('sales_quote_drafts')->where('id', $draft)->value('snapshot'), true);
        $customerId = (int) DB::table('customer_orders')->where('id', $id)->value('user_id');
        config(['receivables.enabled' => true]);
        DB::table('customer_finance_accounts')->insert(['customer_id' => $customerId, 'version' => 1, 'blocked' => false]);
        DB::table('customer_payment_terms')->insert(['id' => (string) Str::uuid(), 'customer_id' => $customerId, 'version' => 1,
            'mode' => 'cash', 'label' => 'Conditions fictives', 'recorded_by' => $actor, 'reason' => 'Fixture', 'created_at' => now('UTC')]);
        $snapshot = array_replace($snapshot, DB::transaction(fn (): array => app(QuotePaymentTerms::class)->capture($customerId, now('UTC')->addWeek()->toDateString())));
        $snapshot['fees'] = '0.00';
        $snapshot['contact'] = ['name' => 'Client fictif'];
        $snapshot['billing_address'] = ['address' => 'Rabat'];
        DB::table('sales_quote_drafts')->where('id', $draft)->update(['snapshot' => json_encode($snapshot)]);
        config(['quotes.issuance_enabled' => true, 'quotes.policy_version' => 'TEST-ONLY', 'quotes.number_prefix' => 'DEV-TEST-',
            'quotes.seller' => ['name' => 'Fictif', 'address' => 'Test'], 'quotes.tax_note' => 'Test TTC']);
        $record = DB::table('sales_quote_drafts')->where('id', $draft)->first();

        return [$id, $actor, ['draft_id' => $draft, 'expected_version' => 3, 'request_key' => (string) Str::uuid(),
            'issuance_token' => app(SalesQuotes::class)->readiness($record)['token']]];
    }

    /** @return array{int, int, array<string, mixed>} */
    private function quoteScenario(): array
    {
        config(['pricing.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC')]);
        DB::table('pricing_settings')->where('id', 1)->update(['currency' => 'MAD', 'tax_basis' => 'TTC']);
        $id = $this->order();
        $actor = $this->actor();
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        DB::table('customer_orders')->where('id', $id)->update(['lines' => json_encode([
            ['id' => (string) Str::uuid(), 'product_id' => $product->id, 'quantity' => 2, 'units_per_package' => 1],
        ])]);
        app(SalesOrderReview::class)->claim(User::findOrFail($actor), $id, 1);
        $values = ['expected_version' => 2, 'valid_until' => now('UTC')->addWeek()->toDateString()];
        $preview = app(SalesQuoteDrafts::class)->prepare(User::findOrFail($actor), $id, $values);

        return [$id, $actor, $values + ['request_key' => (string) Str::uuid(), 'preview_token' => $preview['preview_token']]];
    }

    /** @param list<array{actor: int, method: string, values: array<string, mixed>}> $tasks
     * @return list<array<string, mixed>>
     */
    private function parallel(int $id, array $tasks): array
    {
        DB::disconnect();
        $children = [];
        foreach ($tasks as $task) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $this->assertNotFalse($pair);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($pair[0]);
                fread($pair[1], 1);
                try {
                    $actor = User::findOrFail($task['actor']);
                    if ($task['method'] === 'notifyQuote') {
                        $data = ['sent' => app(QuoteNotificationSender::class)->send($task['values']['intent_id'])];
                    } elseif ($task['method'] === 'issueQuote') {
                        $data = app(SalesQuotes::class)->issue($actor, $id, $task['values']);
                    } elseif ($task['method'] === 'decideQuote') {
                        $data = app(SalesQuotes::class)->decide($actor, $id, $task['values']['quote_id'], $task['values']);
                    } elseif ($task['method'] === 'quote') {
                        $data = app(SalesQuoteDrafts::class)->prepare($actor, $id, $task['values'], true);
                    } else {
                        $service = app(SalesOrderReview::class);
                        $data = $task['method'] === 'claim' ? $service->claim($actor, $id, 1) : $service->{$task['method']}($actor, $id, $task['values']);
                    }
                    $result = ['status' => 200, 'data' => $data];
                } catch (Throwable $exception) {
                    $result = ['status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500, 'error' => $exception->getMessage()];
                }
                fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR));
                fclose($pair[1]);
                DB::disconnect();
                exit(0);
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 30);
            $children[] = ['pid' => $pid, 'socket' => $pair[0]];
        }
        foreach ($children as $child) {
            fwrite($child['socket'], '1');
        }
        $results = [];
        foreach ($children as $child) {
            $results[] = json_decode(stream_get_contents($child['socket']), true, 512, JSON_THROW_ON_ERROR);
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        return $results;
    }
}
