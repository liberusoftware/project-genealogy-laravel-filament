<?php

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\Analytics\Contracts\AnalyticsEvent;
use Liberu\Analytics\Google\Contracts\GoogleTransport;
use Liberu\Analytics\Google\Support\GoogleDestination;
use Liberu\Analytics\Google\Support\GoogleEventMapper;
use Liberu\Analytics\Meta\Contracts\MetaTransport;
use Liberu\Analytics\Meta\Support\MetaCustomerNormalizer;
use Liberu\Analytics\Meta\Support\MetaDestination;
use Liberu\Foundation\Currency\Contracts\ExchangeRateProvider;
use Liberu\Foundation\Currency\Services\CurrencyConverter;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Currency\Services\OpenExchangeRateApiProvider;
use Liberu\Foundation\Currency\ValueObjects\Currency;
use Liberu\Foundation\Currency\ValueObjects\ExchangeRate;
use Liberu\Foundation\DeveloperExperience\Support\EnvironmentDoctor;
use Liberu\Foundation\Identity\Events\IdentityEvent;
use Liberu\Foundation\Identity\Listeners\EmitAuthenticationEvent;
use Liberu\Foundation\JetstreamBridge\Actions\Jetstream\DeleteUser;
use Liberu\Foundation\Localization\MyMemory\TranslationService;
use Liberu\Foundation\Organizations\Actions\Jetstream\DeleteTeam;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Models\Permission;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Liberu\Foundation\RolesPermissions\Policies\RolePolicy;
use Liberu\Foundation\RolesPermissions\Services\AnyTeamRoleLookup;
use Liberu\Foundation\RolesPermissions\Services\RoleConfigurationService;
use Liberu\Foundation\RolesPermissions\Support\RolePermissionCatalog;
use Liberu\Foundation\Tenancy\Http\Middleware\SetTenantContext;
use Liberu\Foundation\Tenancy\Jobs\Middleware\RestoreTenantContext;
use Liberu\Foundation\Tenancy\TenantContext;
use Liberu\Foundation\UsersRoles\Policies\UserPolicy;
use Liberu\Foundation\UsersRoles\Services\UserRoleLookup;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('manages Tenancy TenantContext, SetTenantContext middleware, and RestoreTenantContext job middleware safely', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    TenantContext::clear();
    expect(TenantContext::currentId())->toBeNull()
        ->and(TenantContext::forUser(null))->toBeNull()
        ->and(TenantContext::forUser($user->fresh()))->toBe((int) $team->id);

    TenantContext::set((int) $team->id);
    expect(TenantContext::currentId())->toBe((int) $team->id);
    TenantContext::clear();
    expect(TenantContext::currentId())->toBeNull();

    // SetTenantContext middleware sets team from sanctum user and clears on terminate()
    $this->actingAs($user->fresh(), 'sanctum');
    $middleware = new SetTenantContext();
    $req = Request::create('/api/test', 'GET');
    $seenDuring = null;
    $resp = $middleware->handle($req, function () use (&$seenDuring) {
        $seenDuring = TenantContext::currentId();

        return response('ok');
    });
    expect($seenDuring)->toBe((int) $team->id);
    $middleware->terminate($req, $resp);
    expect(TenantContext::currentId())->toBeNull();

    // RestoreTenantContext job middleware restores tenantId and clears in finally even when job throws
    $job = new class($team->id)
    {
        public function __construct(public ?int $tenantId) {}
    };
    $jobMiddleware = new RestoreTenantContext();
    $seenInJob = null;
    $jobMiddleware->handle($job, function () use (&$seenInJob): string {
        $seenInJob = TenantContext::currentId();

        return 'done';
    });
    expect($seenInJob)->toBe((int) $team->id)
        ->and(TenantContext::currentId())->toBeNull();

    expect(fn () => $jobMiddleware->handle($job, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');
    expect(TenantContext::currentId())->toBeNull();
});

it('maps and delivers Google and Meta analytics events and normalizes customer PII hashes', function (): void {
    $occurredAt = new DateTimeImmutable('2026-05-01T12:00:00+00:00');
    $event = new AnalyticsEvent(
        id: 'evt-123',
        name: 'tree_created',
        sequence: '1',
        occurredAt: $occurredAt,
        source: 'server',
        anonymousId: null,
        userId: '1',
        sessionId: 'sess-1',
        locale: 'en_GB',
        currency: 'GBP',
        consentCategory: 'analytics',
        properties: ['plan' => 'premium'],
    );

    // GoogleEventMapper + GoogleDestination
    $mapper = new GoogleEventMapper();
    $mapped = $mapper->map($event);
    expect($mapped['name'])->toBe('tree_created')
        ->and($mapped['params']['currency'])->toBe('GBP')
        ->and($mapped['params']['plan'])->toBe('premium');

    $tooLong = new AnalyticsEvent(
        id: 'evt-long',
        name: str_repeat('a', 41),
        sequence: '2',
        occurredAt: $occurredAt,
        source: 'server',
        anonymousId: null,
        userId: '1',
        sessionId: 'sess-1',
        locale: 'en_GB',
        currency: 'GBP',
        consentCategory: 'analytics',
    );
    expect(fn () => $mapper->map($tooLong))->toThrow(InvalidArgumentException::class, '40 characters');

    $googleTransport = new class() implements GoogleTransport
    {
        public array $sent = [];

        public function send(array $payload): array
        {
            $this->sent = $payload;

            return ['status' => 'accepted'];
        }
    };
    $googleDest = new GoogleDestination($mapper, $googleTransport);
    expect($googleDest->name())->toBe('google')
        ->and($googleDest->deliver($event))->toBe(['status' => 'accepted'])
        ->and($googleTransport->sent['name'])->toBe('tree_created');

    // MetaCustomerNormalizer + MetaDestination
    $normalizer = new MetaCustomerNormalizer();
    expect($normalizer->email('  Alice@Example.COM '))->toBe(hash('sha256', 'alice@example.com'))
        ->and($normalizer->phone('+44 (0) 7700-900123'))->toBe(hash('sha256', '4407700900123'))
        ->and($normalizer->externalId(' user-42 ', 'salt-secret'))->toBe(hash_hmac('sha256', 'user-42', 'salt-secret'));

    $metaTransport = new class() implements MetaTransport
    {
        public array $sent = [];

        public function send(array $payload): array
        {
            $this->sent = $payload;

            return ['events_received' => 1];
        }
    };
    $metaDest = new MetaDestination($metaTransport);
    expect($metaDest->name())->toBe('meta')
        ->and($metaDest->deliver($event))->toBe(['events_received' => 1])
        ->and($metaTransport->sent['event_id'])->toBe('evt-123');
});

it('translates text via MyMemory TranslationService with caching and graceful error fallback', function (): void {
    Cache::flush();

    Http::fake([
        'api.mymemory.translated.net/*' => Http::sequence()
            ->push(['responseData' => ['translatedText' => 'Arbre généalogique']], 200)
            ->push([], 500),
    ]);

    $service = new TranslationService();
    expect($service->name())->toBe('mymemory')
        ->and($service->isLanguageSupported('fr'))->toBeTrue()
        ->and($service->isLanguageSupported('zz'))->toBeFalse()
        ->and($service->supportedLanguages())->toHaveKeys(['en', 'es', 'fr', 'de'])
        ->and($service->translate('Family tree', 'en', 'en'))->toBe('Family tree');

    // First call hits HTTP & caches; second call returns cached value without hitting HTTP
    expect($service->translate('Family tree', 'fr', 'en'))->toBe('Arbre généalogique')
        ->and($service->translate('Family tree', 'fr', 'en'))->toBe('Arbre généalogique');
    Http::assertSentCount(1);

    // HTTP 500 gracefully falls back to source text
    expect($service->translate('Uncached failure', 'es', 'en'))->toBe('Uncached failure');
});

it('converts currencies and fetches OpenExchangeRateApiProvider rates with caching and failure fallbacks', function (): void {
    Cache::flush();
    $gbp = new Currency('GBP', 2, '£');
    $usd = new Currency('USD', 2, '$');
    $registry = new CurrencyRegistry([
        'GBP' => ['minor_units' => 2, 'symbol' => '£'],
        'USD' => ['minor_units' => 2, 'symbol' => '$'],
    ]);

    // Disabled config returns null without HTTP call
    config()->set('currency.exchange_rates_enabled', false);
    config()->set('currency.exchange_rate_api_url', 'https://open.er-api.com/v6/latest/{base}');
    Http::fake([
        'open.er-api.com/*' => Http::response([
            'result' => 'success',
            'time_last_update_unix' => 1700000000,
            'rates' => ['USD' => 1.25],
        ], 200),
    ]);

    $apiProvider = new OpenExchangeRateApiProvider();
    expect($apiProvider->rate($gbp, $usd, new DateTimeImmutable()))->toBeNull();
    Http::assertNothingSent();

    // Enabled config fetches and caches rate
    config()->set('currency.exchange_rates_enabled', true);
    $rate = $apiProvider->rate($gbp, $usd, new DateTimeImmutable());
    expect($rate)->toBeInstanceOf(ExchangeRate::class)
        ->and($rate->rate)->toBe('1.25');

    // CurrencyConverter: same-currency passthrough, unknown currency null, direct & inverted conversion
    $converter = new CurrencyConverter($registry, $apiProvider);
    expect($converter->convertAmount(100.0, 'GBP', 'GBP'))->toBe(100.0)
        ->and($converter->convertAmount(100.0, 'GBP', 'XXX'))->toBeNull()
        ->and($converter->convertAmount(100.0, 'GBP', 'USD'))->toBe(125.0);

    $invertedProvider = new class() implements ExchangeRateProvider
    {
        public function rate(Currency $base, Currency $quote, DateTimeImmutable $effectiveAt): ?ExchangeRate
        {
            return new ExchangeRate($base, $quote, '2.0', 'test', 'market', $effectiveAt, 6, inverted: true);
        }
    };
    expect((new CurrencyConverter($registry, $invertedProvider))->convertAmount(100.0, 'GBP', 'USD'))->toBe(50.0);
});

it('inspects runtime prerequisites via EnvironmentDoctor and checks cross-team roles via AnyTeamRoleLookup', function (): void {
    $doctor = new EnvironmentDoctor();
    expect($doctor->inspect(['json'], [storage_path()]))->toBe([]);

    $issues = $doctor->inspect(['nonexistent_php_ext_xyz'], [storage_path('nonexistent_dir_xyz_999')]);
    expect($issues)->toHaveCount(2)
        ->and($issues[0])->toContain('Missing PHP extension')
        ->and($issues[1])->toContain('Not writable');

    $user = User::factory()->create();
    $teamA = Team::factory()->create(['user_id' => $user->id]);
    $teamB = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $user->forceFill(['current_team_id' => $teamB->id])->save();

    setPermissionsTeamId($teamA->id);
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web', 'team_id' => $teamA->id]);
    $user->assignRole($adminRole);

    // Even when active Spatie team context is teamB, AnyTeamRoleLookup finds the admin role in teamA
    setPermissionsTeamId($teamB->id);
    $lookup = new AnyTeamRoleLookup();
    expect($lookup->hasRoleInAnyTeam($user, []))->toBeFalse()
        ->and($lookup->hasRoleInAnyTeam($user, 'admin'))->toBeTrue()
        ->and($lookup->hasRoleInAnyTeam($user, ['super_admin', 'editor']))->toBeFalse();
});

it('manages custom team roles via RoleConfigurationService and evaluates RolePolicy and UserPolicy permissions', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $owner->forceFill(['current_team_id' => $team->id])->save();

    $service = new RoleConfigurationService();
    expect($service->canManageTeam($owner, $team))->toBeTrue()
        ->and($service->canManageTeam($outsider, $team))->toBeFalse();

    // Seed a team-allowed permission so RolePermissionCatalog::options(false) includes it
    config()->set('permissions.groups.core', ['contact']);
    config()->set('permissions.actions', ['view', 'create', 'update', 'delete']);
    Permission::firstOrCreate(['name' => 'view_contact', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'create_contact', 'guard_name' => 'web']);

    expect(RolePermissionCatalog::options(false))->toHaveKey('view_contact')
        ->and(RolePermissionCatalog::presets())->toHaveKey('sales')
        ->and(RolePermissionCatalog::preset('sales'))->toContain('view_contact');

    // Unauthorized actor gets 403
    expect(fn () => $service->save($outsider, $team, ['name' => 'Archivist', 'permissions' => ['view_contact']]))
        ->toThrow(HttpException::class);

    // Reserved system role name rejected
    expect(fn () => $service->save($owner, $team, ['name' => 'admin', 'permissions' => ['view_contact']]))
        ->toThrow(ValidationException::class);

    // Note: RoleConfigurationService::audit() writes directly to `audit_logs` without checking
    // Schema::hasTable('audit_logs'); this repository does not ship an `audit_logs` migration
    // (documented as an upstream gap in reports/16-phase6-final-coverage-report.md).
    if (! Schema::hasTable('audit_logs')) {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('action');
            $table->string('description')->nullable();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('changes')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }

    // Create custom team role + verify audit log
    $role = $service->save($owner, $team, ['name' => 'Archivist', 'permissions' => ['view_contact']]);
    expect($role->name)->toBe('Archivist')
        ->and((int) $role->team_id)->toBe((int) $team->id)
        ->and($role->hasPermissionTo('view_contact'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'team.role_configured',
        'team_id' => $team->id,
        'auditable_id' => $role->id,
    ]);

    // Duplicate name rejected (case-insensitive)
    expect(fn () => $service->save($owner, $team, ['name' => 'archivist', 'permissions' => []]))
        ->toThrow(ValidationException::class);

    // Update role permissions
    $updated = $service->save($owner, $team, ['name' => 'Lead Archivist', 'permissions' => ['view_contact', 'create_contact']], $role);
    expect($updated->name)->toBe('Lead Archivist')
        ->and($updated->hasPermissionTo('create_contact'))->toBeTrue();

    // Assigned role cannot be deleted until members are reassigned
    setPermissionsTeamId($team->id);
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);
    $member->assignRole($updated);
    expect(fn () => $service->delete($owner, $team, $updated))
        ->toThrow(ValidationException::class);

    // Unassign and delete cleanly
    $member->removeRole($updated);
    $service->delete($owner, $team, $updated);
    $this->assertDatabaseMissing('roles', ['id' => $updated->id]);

    // RolePolicy and UserPolicy permission checks + UserRoleLookup
    $rolePolicy = new RolePolicy();
    $userPolicy = new UserPolicy();
    $sampleRole = Role::firstOrCreate(['name' => 'reviewer', 'guard_name' => 'web', 'team_id' => $team->id]);
    expect($rolePolicy->viewAny($outsider))->toBeFalse()
        ->and($rolePolicy->view($outsider, $sampleRole))->toBeFalse()
        ->and($rolePolicy->create($outsider))->toBeFalse()
        ->and($rolePolicy->update($outsider, $sampleRole))->toBeFalse()
        ->and($rolePolicy->delete($outsider, $sampleRole))->toBeFalse()
        ->and($rolePolicy->deleteAny($outsider))->toBeFalse()
        ->and($rolePolicy->restore($outsider, $sampleRole))->toBeFalse()
        ->and($rolePolicy->forceDelete($outsider, $sampleRole))->toBeFalse()
        ->and($rolePolicy->forceDeleteAny($outsider))->toBeFalse()
        ->and($rolePolicy->restoreAny($outsider))->toBeFalse()
        ->and($rolePolicy->replicate($outsider, $sampleRole))->toBeFalse()
        ->and($rolePolicy->reorder($outsider))->toBeFalse()
        ->and($userPolicy->viewAny($outsider))->toBeFalse()
        ->and($userPolicy->view($outsider))->toBeFalse()
        ->and($userPolicy->create($outsider))->toBeFalse()
        ->and($userPolicy->update($outsider))->toBeFalse()
        ->and($userPolicy->delete($outsider))->toBeFalse()
        ->and($userPolicy->deleteAny($outsider))->toBeFalse()
        ->and($userPolicy->restore($outsider))->toBeFalse()
        ->and($userPolicy->forceDelete($outsider))->toBeFalse()
        ->and($userPolicy->forceDeleteAny($outsider))->toBeFalse()
        ->and($userPolicy->restoreAny($outsider))->toBeFalse()
        ->and($userPolicy->replicate($outsider))->toBeFalse()
        ->and($userPolicy->reorder($outsider))->toBeFalse();

    $member->assignRole($sampleRole);
    $userRoleLookup = new UserRoleLookup();
    expect($userRoleLookup->hasRoleInAnyTeam($member, []))->toBeFalse()
        ->and($userRoleLookup->hasRoleInAnyTeam($member, 'reviewer'))->toBeTrue()
        ->and($userRoleLookup->hasRoleInAnyTeam($member, ['nonexistent']))->toBeFalse();
});

it('emits IdentityEvent via EmitAuthenticationEvent and cleanly deletes users via JetstreamBridge DeleteUser', function (): void {
    Event::fake([IdentityEvent::class]);
    $user = User::factory()->create();
    $listener = new EmitAuthenticationEvent();

    $listener->handle(new Login('web', $user, false));
    $listener->handle(new Failed('web', $user, ['email' => $user->email]));
    $listener->handle(new Logout('web', $user));
    $listener->handle(new stdClass());

    Event::assertDispatched(IdentityEvent::class, 3);
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e): bool => $e->name === 'identity.login.succeeded' && $e->actorId === $user->getAuthIdentifier());
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e): bool => $e->name === 'identity.login.failed');
    Event::assertDispatched(IdentityEvent::class, fn (IdentityEvent $e): bool => $e->name === 'identity.logout.completed');

    // DeleteUser action deletes owned teams, detaches memberships, deletes tokens & connected accounts
    $ownedTeam = Team::factory()->create(['user_id' => $user->id]);
    $otherOwner = User::factory()->create();
    $memberTeam = Team::factory()->create(['user_id' => $otherOwner->id]);
    $memberTeam->users()->attach($user, ['role' => 'editor']);
    $user->createToken('api-test');
    ConnectedAccount::forceCreate([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-delete-test-1',
        'token' => 'secret-token',
    ]);

    $deleter = new DeleteUser(new DeleteTeam());
    expect(fn () => $deleter->delete('not-a-user'))->toThrow(InvalidArgumentException::class);

    $deleter->delete($user->fresh());

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('teams', ['id' => $ownedTeam->id]);
    $this->assertDatabaseMissing('team_user', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('connected_accounts', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
});
