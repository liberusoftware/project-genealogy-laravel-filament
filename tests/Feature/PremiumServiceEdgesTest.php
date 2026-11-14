<?php

use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionBuilder;
use Stripe\Checkout\Session;
use Stripe\Price;
use Stripe\Product;
use Stripe\Service\PriceService;
use Stripe\Service\ProductService;
use Stripe\StripeClient;

it('provisions one product for both intervals and reuses persisted prices', function (bool $configuredName): void {
    config(['premium.stripe_application_key' => 'genealogy-test', 'premium.prices.month' => 249, 'premium.prices.year' => 2499, 'app.name' => 'Genealogy', 'premium.stripe_product_name' => $configuredName ? 'Genealogy Premium' : null, 'premium.stripe_prices.month' => null, 'premium.stripe_prices.year' => null]);
    $products = Mockery::mock(ProductService::class);
    $prices = Mockery::mock(PriceService::class);
    $client = Mockery::mock(StripeClient::class)->makePartial();
    $client->shouldReceive('getService')->with('products')->andReturn($products);
    $client->shouldReceive('getService')->with('prices')->andReturn($prices);
    $products->shouldReceive('create')->once()->withArgs(fn (array $data) => $data['name'] === 'Genealogy Premium' && $data['metadata']['liberu_application'] === 'genealogy-test')->andReturn(Product::constructFrom(['id' => 'prod_fixture']));
    $prices->shouldReceive('create')->twice()->andReturnUsing(function (array $data): Price {
        expect($data['product'])->toBe('prod_fixture')->and($data['currency'])->toBe('gbp')
            ->and($data['metadata']['liberu_interval'])->toBe($data['recurring']['interval'])
            ->and($data['unit_amount'])->toBe($data['recurring']['interval'] === 'month' ? 249 : 2499);

        return Price::constructFrom(['id' => 'price_'.$data['recurring']['interval']]);
    });
    app()->bind(StripeClient::class, fn () => $client);
    $service = app(SubscriptionService::class);
    expect($service->stripePriceId('month'))->toBe('price_month')
        ->and($service->stripePriceId('year'))->toBe('price_year')
        ->and($service->stripePriceId('month'))->toBe('price_month');
    expect(DB::table('premium_stripe_prices')->count())->toBe(2);
})->with(['configured product name' => [true], 'application product name' => [false]]);

it('rejects disabled billing unsupported intervals and invalid prices before reaching Stripe', function (): void {
    $user = User::factory()->create();
    $service = app(SubscriptionService::class);
    config(['premium.enabled' => false]);
    expect(fn () => $service->createCheckout($user))->toThrow(RuntimeException::class, 'Premium billing is disabled.')
        ->and(fn () => $service->stripePriceId('week'))->toThrow(RuntimeException::class, 'Unsupported Premium billing interval.');
    config(['premium.stripe_prices.month' => null, 'premium.prices.month' => 0]);
    expect(fn () => $service->stripePriceId('month'))->toThrow(RuntimeException::class, 'Premium Stripe pricing configuration is invalid.');
    config(['premium.enabled' => true, 'premium.require_card' => true]);
    expect(fn () => $service->startLocalTrial($user))->toThrow(RuntimeException::class);
});

it('uses a stable application namespace and bounds configured trial duration', function (): void {
    config(['premium.stripe_application_key' => null, 'app.name' => 'Genealogy', 'app.url' => 'https://genealogy.example.test', 'premium.trial_days' => -1]);
    $service = app(SubscriptionService::class);
    expect($service->stripeApplicationKey())->toBe(substr(hash('sha256', 'Genealogy|https://genealogy.example.test'), 0, 32))
        ->and($service->trialDays())->toBe(0);
    config(['premium.stripe_application_key' => ' '.str_repeat('a', 150).' ']);
    expect($service->stripeApplicationKey())->toBe(str_repeat('a', 128));
});

it('passes the selected price trial and return URLs to the checkout builder', function (string $interval, string $expected, int $trial): void {
    config(['premium.enabled' => true, 'premium.trial_days' => $trial, 'premium.stripe_prices.month' => 'price_month', 'premium.stripe_prices.year' => 'price_year']);
    $user = Mockery::mock(User::class)->makePartial();
    $builder = Mockery::mock(SubscriptionBuilder::class);
    $checkout = new Checkout(null, Session::constructFrom(['id' => 'cs_fixture', 'url' => 'https://checkout.example.test/session']));
    $user->shouldReceive('newSubscription')->once()->with('premium', $expected)->andReturn($builder);
    if ($trial > 0) {
        $builder->shouldReceive('trialDays')->once()->with($trial)->andReturnSelf();
    } else {
        $builder->shouldNotReceive('trialDays');
    }
    $builder->shouldReceive('checkout')->once()->with(['success_url' => route('filament.app.pages.premium-dashboard'), 'cancel_url' => route('filament.app.pages.subscription')])->andReturn($checkout);
    expect(app(SubscriptionService::class)->createCheckout($user, $interval))->toBe($checkout);
})->with(['year' => ['year', 'price_year', 7], 'invalid interval fallback' => ['week', 'price_month', 0]]);

it('cancels an active subscription and resumes only during its grace period', function (): void {
    $stored = User::factory()->create();
    $user = Mockery::mock(User::class)->makePartial();
    $user->setTable($stored->getTable());
    $user->setRawAttributes($stored->getAttributes(), true);
    $user->exists = true;
    $subscription = Mockery::mock(Subscription::class);
    $user->shouldReceive('subscription')->with('premium')->andReturn($subscription);
    $subscription->shouldReceive('canceled')->once()->andReturnFalse();
    $subscription->shouldReceive('cancel')->once()->andReturnSelf();
    $subscription->shouldReceive('onGracePeriod')->once()->andReturnTrue();
    $subscription->shouldReceive('resume')->once()->andReturnSelf();
    $service = app(SubscriptionService::class);
    $service->cancel($user);
    expect($stored->fresh()->premium_cancelled_at)->not->toBeNull();
    $service->resume($user);
    expect($stored->fresh()->premium_cancelled_at)->toBeNull()->and($stored->fresh()->is_premium)->toBeTrue();
});

it('leaves an already ended subscription unchanged on a resume request', function (): void {
    $stored = User::factory()->create();
    $stored->forceFill(['premium_cancelled_at' => now(), 'is_premium' => false])->save();
    $user = Mockery::mock(User::class)->makePartial();
    $user->setTable($stored->getTable());
    $user->setRawAttributes($stored->getAttributes(), true);
    $user->exists = true;
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('onGracePeriod')->once()->andReturnFalse();
    $subscription->shouldNotReceive('resume');
    $user->shouldReceive('subscription')->with('premium')->andReturn($subscription);
    app(SubscriptionService::class)->resume($user);
    expect($stored->fresh()->premium_cancelled_at)->not->toBeNull()->and($stored->fresh()->is_premium)->toBeFalse();
});
