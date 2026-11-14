<?php

use App\Http\Middleware\EnsurePremiumAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

it('keeps guests and disabled-premium installations outside the premium gate', function (): void {
    config(['premium.enabled' => true]);
    $middleware = new EnsurePremiumAccess();
    expect($middleware->handle(Request::create('/protected'), fn () => response('allowed'))->getContent())->toBe('allowed');
    $this->actingAs(User::factory()->create());
    config(['premium.enabled' => false]);
    expect($middleware->handle(Request::create('/protected'), fn () => response('allowed'))->getContent())->toBe('allowed');
});

it('requires payment for expired and never-subscribed users with appropriate JSON and browser responses', function (bool $expired): void {
    config(['premium.enabled' => true]);
    $user = User::factory()->create();
    if ($expired) {
        $user->forceFill(['is_premium' => true, 'premium_started_at' => now()->subDays(10), 'trial_ends_at' => now()->subDay()])->save();
    }
    $this->actingAs($user);
    $middleware = new EnsurePremiumAccess();
    $json = Request::create('/protected');
    $json->headers->set('Accept', 'application/json');
    $response = $middleware->handle($json, fn () => response('must not reach handler'));
    expect($response->getStatusCode())->toBe(402)
        ->and(json_decode($response->getContent(), true)['message'])->toContain($expired ? 'suspended' : 'subscription is required');
    $api = $middleware->handle(Request::create('/api/private'), fn () => response('must not reach handler'));
    expect($api->getStatusCode())->toBe(402);
    $browser = $middleware->handle(Request::create('/protected'), fn () => response('must not reach handler'));
    expect($browser->getTargetUrl())->toBe(route($expired ? 'filament.app.pages.trial-expired' : 'filament.app.pages.subscription'));
})->with(['expired trial' => [true], 'no subscription' => [false]]);

it('preserves billing recovery export and affiliate access while premium is suspended', function (string $path, ?string $routeName): void {
    config(['premium.enabled' => true, 'subscription.allowlist_route_patterns' => ['billing.*']]);
    $this->actingAs(User::factory()->create());
    $request = Request::create($path);
    if ($routeName !== null) {
        $route = (new Route('GET', ltrim($path, '/'), fn () => null))->name($routeName);
        $request->setRouteResolver(fn () => $route);
    }
    expect((new EnsurePremiumAccess())->handle($request, fn () => response('available'))->getContent())->toBe('available');
})->with([
    'billing route' => ['/billing', 'billing.recover'],
    'GEDCOM export' => ['/api/v1/genealogy/import-export/export', null],
    'affiliate landing' => ['/affiliate', null],
    'affiliate detail' => ['/affiliate/overview', null],
]);

it('allows an active paid subscription and reports remaining local trial days', function (): void {
    config(['premium.enabled' => true]);
    $user = User::factory()->create();
    $user->subscriptions()->create(['type' => 'premium', 'stripe_id' => 'sub_fixture', 'stripe_status' => 'active', 'stripe_price' => 'price_fixture', 'quantity' => 1]);
    $this->actingAs($user);
    expect($user->isPremium())->toBeTrue()
        ->and((new EnsurePremiumAccess())->handle(Request::create('/protected'), fn () => response('allowed'))->getContent())->toBe('allowed');
    $this->travelTo(now()->startOfSecond());
    $trial = User::factory()->create();
    $trial->forceFill(['is_premium' => true, 'trial_ends_at' => now()->addDays(3)])->save();
    expect($trial->trialDaysRemaining())->toBe(3)->and($user->trialDaysRemaining())->toBe(0);
});
