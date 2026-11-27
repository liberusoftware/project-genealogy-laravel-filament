<?php

use App\Filament\App\Pages\PremiumDashboardPage;
use App\Filament\App\Pages\SubscriptionPage;
use App\Filament\App\Pages\TrialExpiredPage;
use App\Http\Middleware\EnsurePremiumAccess;
use App\Models\ConnectedAccount;
use App\Models\TeamIntegration;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\PanelNavigation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Liberu\Foundation\ApiAccess\Support\TokenPolicy;
use Liberu\Foundation\Files\Support\UploadPolicy;
use Liberu\Foundation\Identity\Socialstream\Policies\ConnectedAccountPolicy;
use Liberu\Foundation\Notifications\Support\NotificationPolicy;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\TwoFactor\Enforcement\TwoFactorPolicy;
use Liberu\Foundation\Webhooks\Support\RetrySchedule;
use Liberu\Foundation\Webhooks\Support\WebhookSigner;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('enforces EnsurePremiumAccess middleware across disabled, active, unpaid, suspended, JSON, web, and allowlisted paths', function (): void {
    $middleware = new EnsurePremiumAccess();
    $pass = fn () => response('passed', 200);

    $unpaid = User::factory()->create(['is_premium' => false, 'premium_started_at' => null, 'trial_ends_at' => null]);
    $active = User::factory()->create(['is_premium' => true, 'premium_started_at' => now()->subDay(), 'trial_ends_at' => now()->addDays(5)]);
    $suspended = User::factory()->create(['is_premium' => false, 'premium_started_at' => now()->subDays(10), 'trial_ends_at' => now()->subDay()]);

    // Disabled config lets unpaid through
    config()->set('premium.enabled', false);
    $this->actingAs($unpaid);
    expect($middleware->handle(Request::create('/app', 'GET'), $pass)->getStatusCode())->toBe(200);

    // Enabled config: active passes, unpaid gets 402 JSON or redirect to subscription
    config()->set('premium.enabled', true);
    $this->actingAs($active);
    expect($middleware->handle(Request::create('/app', 'GET'), $pass)->getStatusCode())->toBe(200);

    $this->actingAs($unpaid);
    $jsonUnpaid = $middleware->handle(Request::create('/api/v1/genealogy/people', 'GET', server: ['HTTP_ACCEPT' => 'application/json']), $pass);
    expect($jsonUnpaid->getStatusCode())->toBe(402)
        ->and($jsonUnpaid->getContent())->toContain('An active Premium subscription is required.');

    $webUnpaid = $middleware->handle(Request::create('/app/people', 'GET'), $pass);
    expect($webUnpaid->getStatusCode())->toBe(302)
        ->and($webUnpaid->headers->get('Location'))->toBe(route('filament.app.pages.subscription'));

    // Suspended user gets 402 with suspended message or redirect to trial-expired
    $this->actingAs($suspended);
    $jsonSuspended = $middleware->handle(Request::create('/api/v1/genealogy/people', 'GET', server: ['HTTP_ACCEPT' => 'application/json']), $pass);
    expect($jsonSuspended->getStatusCode())->toBe(402)
        ->and($jsonSuspended->getContent())->toContain('suspended');

    $webSuspended = $middleware->handle(Request::create('/app/people', 'GET'), $pass);
    expect($webSuspended->getStatusCode())->toBe(302)
        ->and($webSuspended->headers->get('Location'))->toBe(route('filament.app.pages.trial-expired'));

    // Allowlisted GEDCOM export & affiliate paths remain accessible even when suspended
    expect($middleware->handle(Request::create('/api/v1/genealogy/import-export/export', 'GET'), $pass)->getStatusCode())->toBe(200)
        ->and($middleware->handle(Request::create('/affiliate', 'GET'), $pass)->getStatusCode())->toBe(200)
        ->and($middleware->handle(Request::create('/affiliate/dashboard', 'GET'), $pass)->getStatusCode())->toBe(200);
});

it('exercises SubscriptionService and Premium Filament pages across unpaid, trial, and suspended users', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    config()->set('premium.enabled', true);
    config()->set('premium.require_card', false);
    config()->set('premium.trial_days', 7);

    $service = app(SubscriptionService::class);
    expect($service->pricing()['trial_days'])->toBe(7)
        ->and($service->stripeApplicationKey())->toBeString()->not->toBeEmpty();

    // Unpaid user sees SubscriptionPage and starts local trial
    $unpaid = User::factory()->create(['is_premium' => false]);
    $team = Team::factory()->create(['user_id' => $unpaid->id]);
    $unpaid->forceFill(['current_team_id' => $team->id])->save();

    Livewire::actingAs($unpaid)
        ->test(PremiumDashboardPage::class)
        ->assertRedirect(route('filament.app.pages.subscription'));

    Livewire::actingAs($unpaid)
        ->test(SubscriptionPage::class)
        ->assertOk()
        ->call('startTrial')
        ->assertRedirect(route('filament.app.pages.premium-dashboard'));

    expect($unpaid->fresh()->isPremium())->toBeTrue();

    // Active trial user accesses PremiumDashboardPage and can cancel/resume
    Livewire::actingAs($unpaid->fresh())
        ->test(PremiumDashboardPage::class)
        ->assertOk()
        ->call('cancel')
        ->call('resume');
    expect($unpaid->fresh()->premium_cancelled_at)->toBeNull();

    // Active user visiting SubscriptionPage or TrialExpiredPage is redirected to dashboard
    Livewire::actingAs($unpaid->fresh())
        ->test(SubscriptionPage::class)
        ->assertRedirect(route('filament.app.pages.premium-dashboard'));
    Livewire::actingAs($unpaid->fresh())
        ->test(TrialExpiredPage::class)
        ->assertRedirect(route('filament.app.pages.premium-dashboard'));

    // Suspended user sees TrialExpiredPage
    $suspended = User::factory()->create([
        'is_premium' => false,
        'premium_started_at' => now()->subDays(10),
        'trial_ends_at' => now()->subDay(),
    ]);
    Livewire::actingAs($suspended)
        ->test(TrialExpiredPage::class)
        ->assertOk();

    // Card-required guard in SubscriptionService
    config()->set('premium.require_card', true);
    expect(fn () => $service->startLocalTrial($suspended))
        ->toThrow(RuntimeException::class, 'payment card');
});

it('exposes PanelNavigation groups and protects ConnectedAccount and TeamIntegration models', function (): void {
    $appLabels = array_map(fn ($group) => $group->getLabel(), PanelNavigation::app());
    $adminLabels = array_map(fn ($group) => $group->getLabel(), PanelNavigation::admin());

    expect($appLabels)->toContain('Genealogy', 'Research & Evidence', 'DNA & Matching', 'Billing & plan')
        ->and($adminLabels)->toContain('Administration', 'Genealogy', 'Operations', 'Settings');

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);

    $account = new ConnectedAccount([
        'provider' => 'github',
        'provider_id' => 'gh-host-1',
        'name' => 'Owner',
        'email' => 'owner@example.com',
        'token' => 'gho_secret_token',
        'refresh_token' => 'ghr_refresh_secret',
        'enable_family_matching' => 1,
        'cached_profile_data' => ['handle' => 'owner'],
        'last_synced_at' => '2026-01-01 00:00:00',
    ]);
    $account->forceFill(['user_id' => $owner->id])->save();
    $account->refresh();

    expect($account->enable_family_matching)->toBeTrue()
        ->and($account->cached_profile_data)->toBe(['handle' => 'owner'])
        ->and($account->last_synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($account->toArray())->not->toHaveKeys(['token', 'secret', 'refresh_token']);

    $policy = new ConnectedAccountPolicy();
    expect($policy->view($owner, $account))->toBeTrue()
        ->and($policy->view($other, $account))->toBeFalse();

    $integration = TeamIntegration::query()->create([
        'team_id' => $team->id,
        'provider' => 'familysearch',
        'label' => 'FS Sync',
        'credentials' => ['client_secret' => 'top-secret-key-999'],
        'enabled' => true,
    ]);

    $rawCredentials = DB::table('team_integrations')->where('id', $integration->id)->value('credentials');
    expect($rawCredentials)->not->toContain('top-secret-key-999')
        ->and($integration->fresh()->credentials)->toBe(['client_secret' => 'top-secret-key-999'])
        ->and($integration->team->is($team))->toBeTrue();
});

it('serves application health/readiness endpoints and enforces foundation security and operational policies', function (): void {
    $this->get('/up')->assertOk();
    $this->getJson('/ready')->assertOk()->assertJsonStructure(['status', 'checks']);

    // UploadPolicy
    $upload = new UploadPolicy();
    $upload->assert('image/jpeg', 1024, ['image/jpeg', 'image/png'], 10_000);
    expect(fn () => $upload->assert('image/jpeg', 0, ['image/jpeg'], 10_000))->toThrow(InvalidArgumentException::class);
    expect(fn () => $upload->assert('image/jpeg', 20_000, ['image/jpeg'], 10_000))->toThrow(InvalidArgumentException::class);
    expect(fn () => $upload->assert('application/x-php', 500, ['image/jpeg'], 10_000))->toThrow(InvalidArgumentException::class);

    // NotificationPolicy
    $notif = new NotificationPolicy();
    expect($notif->channels(['mail', 'sms'], ['mail', 'database']))->toBe(['mail'])
        ->and($notif->channels(['sms'], ['mail'], operational: true))->toBe(['database'])
        ->and($notif->isQuiet(new DateTimeImmutable('2026-05-01 23:30:00'), '22:00', '07:00'))->toBeTrue()
        ->and($notif->isQuiet(new DateTimeImmutable('2026-05-01 12:00:00'), '22:00', '07:00'))->toBeFalse()
        ->and($notif->isQuiet(new DateTimeImmutable('2026-05-01 12:00:00'), null, null))->toBeFalse();

    // TokenPolicy
    $tokens = new TokenPolicy();
    expect($tokens->scopes(['read', 'read'], ['read', 'write']))->toBe(['read']);
    expect(fn () => $tokens->scopes(['admin'], ['read']))->toThrow(InvalidArgumentException::class);
    $farFuture = new DateTimeImmutable('+365 days');
    expect($tokens->expiresAt($farFuture))->toBeLessThan($farFuture);

    // TwoFactorPolicy
    $twoFactor = new TwoFactorPolicy();
    $user = User::factory()->make();
    config()->set('two-factor.enforce_all', false);
    config()->set('two-factor.required_roles', ['super_admin']);
    expect($twoFactor->requiredFor($user, ['member']))->toBeFalse()
        ->and($twoFactor->requiredFor($user, ['super_admin']))->toBeTrue();
    config()->set('two-factor.enforce_all', true);
    expect($twoFactor->requiredFor($user, []))->toBeTrue();

    // WebhookSigner & RetrySchedule
    $signer = new WebhookSigner();
    $now = time();
    $sig = $signer->sign('{"event":"tree.created"}', 'whsec_test', $now);
    expect($signer->verify('{"event":"tree.created"}', 'whsec_test', $now, $sig))->toBeTrue()
        ->and($signer->verify('{"event":"tampered"}', 'whsec_test', $now, $sig))->toBeFalse();

    $retry = new RetrySchedule();
    expect($retry->seconds(1))->toBe(30)
        ->and($retry->seconds(2))->toBe(60)
        ->and($retry->seconds(20))->toBe(61440);
});
