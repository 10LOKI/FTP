<?php

namespace Tests\Feature;

use App\Models\ApprovalNotification;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerAccountJourneyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.customer_portal_url' => 'https://portal.example.com']);
    }

    public function test_registration_approval_mail_login_and_owned_account_journey(): void
    {
        $registration = $this->postJson('/api/v1/customer-registrations', $this->registration())->assertCreated();
        $id = $registration->json('data.customerId');
        $this->postJson('/api/v1/customer-registrations', $this->registration())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('customer_profiles', 1);
        $this->assertDatabaseCount('users', 1);
        $this->postJson('/api/v1/login', ['identifier' => 'customer@example.com', 'password' => 'customer-password'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_PENDING');
        $this->assertGuest('web');

        $this->signInReviewer();
        $this->getJson('/api/v1/customer-registrations')->assertOk()->assertJsonPath('data.0.customerId', $id);
        $this->postJson('/api/v1/customers/'.$id.'/approval-decisions', [
            'decision' => 'APPROUVE', 'version' => 1, 'reason' => 'Private approval reason',
        ])->assertOk()->assertJsonPath('data.customerCode', 'CLI-000001');
        $this->carrySession($this->postJson('/api/v1/logout')->assertNoContent());

        $this->artisan('customers:send-approval-notifications')->assertSuccessful();
        $notification = ApprovalNotification::sole();
        $this->assertSame('SENT', $notification->status);
        $message = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertStringContainsString('CLI-000001', $message->getTextBody());
        $this->assertStringNotContainsString('customer-password', $message->toString());

        $profile = CustomerProfile::findOrFail($id);
        $profile->internal_notes = 'Private staff note';
        $profile->save();
        $login = $this->postJson('/api/v1/login', ['identifier' => 'CLI-000001', 'password' => 'customer-password'])->assertOk();
        $this->carrySession($login);
        $account = $this->withHeader('Origin', 'http://localhost:8090')->getJson('/api/v1/customer-account')->assertOk();
        $account->assertExactJson(['data' => [
            'customerId' => $id,
            'customerCode' => 'CLI-000001',
            'type' => 'PARTICULIER',
            'approval' => 'APPROUVE',
            'name' => 'Example Customer',
            'email' => 'customer@example.com',
            'phone' => '+212 600 000 000',
            'address' => '10 Example Street',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'company_name' => null,
            'responsible_name' => null,
        ]]);
        $this->assertSame($account->json(), $login->json());
        $this->assertStringNotContainsString('Private staff note', $account->getContent());
        $this->assertStringNotContainsString('Private approval reason', $account->getContent());
        $this->assertStringNotContainsString($profile->user->password, $account->getContent());
        $this->assertStringNotContainsString('customer-password', AuditEvent::all()->toJson());
        $this->getJson('/api/v1/user')->assertExactJson(['data' => [
            'id' => $profile->user_id, 'name' => 'Example Customer', 'email' => 'customer@example.com',
        ]]);

        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'customer_code' => 'OTHER-002']);
        $this->getJson('/api/v1/customer-account?customerId='.$other->id)->assertUnprocessable();
        $this->getJson('/api/v1/customer-account/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/customer-registrations/'.$other->id)->assertForbidden();
        $this->getJson('/api/v1/customer-registrations')->assertForbidden();
        $this->getJson('/api/v1/customer-account/export')->assertNotFound();
    }

    public function test_refused_registration_cannot_open_a_session_or_read_an_account(): void
    {
        $id = $this->postJson('/api/v1/customer-registrations', $this->registration())->assertCreated()->json('data.customerId');
        $this->signInReviewer();
        $this->postJson('/api/v1/customers/'.$id.'/approval-decisions', [
            'decision' => 'REFUSE', 'version' => 1, 'reason' => 'Private refusal reason',
        ])->assertOk();
        $this->carrySession($this->postJson('/api/v1/logout')->assertNoContent());
        $this->postJson('/api/v1/login', ['identifier' => 'customer@example.com', 'password' => 'customer-password'])
            ->assertForbidden()->assertExactJson(['code' => 'ACCOUNT_REFUSED', 'message' => 'Votre demande de compte a été refusée.']);
        $this->assertGuest('web');
        $this->withHeader('Origin', 'http://localhost:8090')->getJson('/api/v1/customer-account')->assertUnauthorized();
        $this->assertDatabaseCount('approval_notifications', 0);
    }

    public function test_approval_is_rechecked_on_existing_sessions_and_business_routes(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'customer_code' => 'CLI-000001']);
        $product = Product::factory()->create();
        $this->actingAs($profile->user, 'web');
        Gate::define('PRODUCT_UPDATE', fn (User $user): bool => true);
        Gate::define('CUSTOMER_APPROVE', fn (User $user): bool => true);
        $this->getJson('/api/v1/customer-account')->assertOk();

        $profile->approval = 'EN_ATTENTE';
        $profile->save();
        $this->getJson('/api/v1/customer-account')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_PENDING');
        $this->getJson('/api/v1/user')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_PENDING');
        $this->getJson('/api/v1/products/'.$product->id)->assertForbidden()->assertJsonPath('code', 'ACCOUNT_PENDING');
        $this->getJson('/api/v1/customer-registrations')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_PENDING');

        $profile->approval = 'REFUSE';
        $profile->save();
        $this->getJson('/api/v1/customer-account')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_REFUSED');
        $this->postJson('/api/v1/logout')->assertNoContent();
    }

    public function test_staff_without_customer_profile_cannot_select_someone_elses_account(): void
    {
        CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/v1/customer-account')->assertNotFound();
    }

    private function signInReviewer(): void
    {
        $reviewer = User::factory()->create();
        $this->actingAs($reviewer, 'web');
        Gate::define('CUSTOMER_APPROVE', fn (User $user): bool => $user->id === $reviewer->id);
    }

    private function carrySession(TestResponse $response): void
    {
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
        Auth::forgetGuards();
    }

    /** @return array<string, string> */
    private function registration(): array
    {
        return [
            'type' => 'PARTICULIER', 'name' => 'Example Customer', 'phone' => '+212 600 000 000',
            'email' => 'customer@example.com', 'password' => 'customer-password',
            'address' => '10 Example Street', 'city' => 'Casablanca', 'country' => 'Morocco',
        ];
    }
}
