<?php

use App\Filament\App\Pages\PremiumDashboardPage;
use App\Filament\App\Pages\SubscriptionPage;
use App\Filament\App\Pages\TrialExpiredPage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('redirects active users away from subscription and expired-trial pages', function (): void {
    config(['premium.enabled' => true]);
    $user = User::factory()->create();
    $user->forceFill(['is_premium' => true, 'premium_started_at' => now(), 'trial_ends_at' => now()->addDays(3)])->save();
    $this->actingAs($user);
    Livewire::test(SubscriptionPage::class)->assertRedirect(route('filament.app.pages.premium-dashboard'));
    Livewire::test(TrialExpiredPage::class)->assertRedirect(route('filament.app.pages.premium-dashboard'));
    expect(SubscriptionPage::shouldRegisterNavigation())->toBeTrue()->and(PremiumDashboardPage::shouldRegisterNavigation())->toBeTrue()->and(TrialExpiredPage::shouldRegisterNavigation())->toBeFalse();
    config(['premium.enabled' => false]);
    expect(SubscriptionPage::shouldRegisterNavigation())->toBeFalse()->and(PremiumDashboardPage::shouldRegisterNavigation())->toBeFalse();
    Livewire::test(TrialExpiredPage::class)->assertRedirect(route('filament.app.pages.premium-dashboard'));
});

it('routes nonpremium and suspended users from the dashboard to recovery', function (bool $expired): void {
    config(['premium.enabled' => true]);
    $user = User::factory()->create();
    if ($expired) {
        $user->forceFill(['premium_started_at' => now()->subDays(10), 'trial_ends_at' => now()->subDay(), 'is_premium' => true])->save();
    }
    $this->actingAs($user);
    Livewire::test(PremiumDashboardPage::class)->assertRedirect(route($expired ? 'filament.app.pages.trial-expired' : 'filament.app.pages.subscription'));
})->with([true, false]);

it('starts a local trial and handles a card-required refusal', function (): void {
    config(['premium.enabled' => true, 'premium.require_card' => true]);
    $user = User::factory()->create();
    $this->actingAs($user);
    Livewire::test(SubscriptionPage::class)->call('startTrial')->assertNotified('Unable to start trial');
    config(['premium.require_card' => false]);
    Livewire::test(SubscriptionPage::class)->call('startTrial')->assertRedirect(route('filament.app.pages.premium-dashboard'));
    expect($user->fresh()->isPremium())->toBeTrue();
});

it('surfaces a checkout configuration failure without leaving the subscription page', function (): void {
    config(['premium.enabled' => false]);
    $this->actingAs(User::factory()->create());
    Livewire::test(SubscriptionPage::class)->call('subscribe')->assertNotified('Unable to start checkout')->assertNoRedirect();
});

it('redirects monthly and yearly checkout actions to the returned Stripe session', function (bool $yearly): void {
    config(['premium.enabled' => true, 'cashier.secret' => 'sk_test_fixture', 'premium.stripe_prices.month' => 'price_month', 'premium.stripe_prices.year' => 'price_year']);
    $this->actingAs(User::factory()->create());
    $requests = [];
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('request')->andReturnUsing(function (string $method, string $url, array $headers, array $params) use (&$requests): array {
        $path = parse_url($url, PHP_URL_PATH);
        $requests[] = ['path' => $path, 'params' => $params];
        $data = match ($path) {
            '/v1/customers' => ['id' => 'cus_fixture', 'object' => 'customer'],
            '/v1/checkout/sessions' => ['id' => 'cs_fixture', 'object' => 'checkout.session', 'url' => 'https://checkout.example.test/session'],
            default => throw new LogicException('Unexpected Stripe request: '.$path),
        };

        return [json_encode($data, JSON_THROW_ON_ERROR), 200, []];
    });
    $previous = ApiRequestor::httpClient();
    ApiRequestor::setHttpClient($client);
    try {
        Livewire::test(SubscriptionPage::class)->call($yearly ? 'subscribeYearly' : 'subscribe')->assertRedirect('https://checkout.example.test/session');
        $session = collect($requests)->firstWhere('path', '/v1/checkout/sessions')['params'];
        expect($session['line_items'][0]['price'])->toBe($yearly ? 'price_year' : 'price_month')
            ->and($session['success_url'])->toBe(route('filament.app.pages.premium-dashboard'))
            ->and($session['cancel_url'])->toBe(route('filament.app.pages.subscription'));
    } finally {
        ApiRequestor::setHttpClient($previous);
    }
})->with(['monthly' => [false], 'yearly' => [true]]);

it('cancels and resumes a local trial through the status page', function (): void {
    config(['premium.enabled' => true]);
    $user = User::factory()->create();
    $user->forceFill(['is_premium' => true, 'trial_ends_at' => now()->addDays(3)])->save();
    $this->actingAs($user);
    $page = Livewire::test(PremiumDashboardPage::class)->call('cancel')->assertNotified('Subscription cancelled');
    expect($user->fresh()->premium_cancelled_at)->not->toBeNull();
    $page->call('resume')->assertNotified('Subscription resumed');
    expect($user->fresh()->premium_cancelled_at)->toBeNull();
});

it('reports persistence failures for cancellation and resumption', function (): void {
    config(['premium.enabled' => true]);
    $user = User::factory()->create();
    $user->forceFill(['is_premium' => true, 'trial_ends_at' => now()->addDays(3)])->save();
    $this->actingAs($user);
    $page = Livewire::test(PremiumDashboardPage::class);
    DB::unprepared("CREATE TRIGGER reject_premium_update BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'billing storage unavailable'); END");
    try {
        $page->call('cancel')->assertNotified('Unable to cancel subscription');
        $page->call('resume')->assertNotified('Unable to resume subscription');
    } finally {
        DB::unprepared('DROP TRIGGER reject_premium_update');
    }
});
