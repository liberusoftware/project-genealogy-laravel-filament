<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\SessionsDevicesFilament\Pages\AccountSecurity;
use Liberu\Genealogy\Evidence\Filament\Resources\CitationResource\Pages\EditCitation;
use Liberu\Genealogy\Evidence\Filament\Resources\CitationResource\RelationManagers\PersonLinksRelationManager;
use Liberu\Genealogy\Evidence\Models\Citation;
use Liberu\Genealogy\Evidence\Models\Source;
use Liberu\Genealogy\GenealogyCore\Actions\CreateTree as CreateTreeAction;
use Liberu\Genealogy\GenealogyCore\Filament\Resources\TreeResource\Pages\CreateTree;
use Liberu\Genealogy\GenealogyCore\Filament\Resources\TreeResource\Pages\EditTree;
use Liberu\Genealogy\GenealogyCore\Filament\Resources\TreeResource\Pages\ListTrees;
use Liberu\Genealogy\GenealogyCore\Models\Tree;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\People\Actions\CreatePerson as CreatePersonAction;
use Liberu\Genealogy\People\Filament\Resources\PersonResource\Pages\CreatePerson;
use Liberu\Genealogy\People\Filament\Resources\PersonResource\Pages\EditPerson;
use Liberu\Genealogy\People\Filament\Resources\PersonResource\Pages\ListPeople;
use Liberu\Genealogy\People\Filament\Resources\PersonResource\RelationManagers\AssociationsRelationManager;
use Liberu\Genealogy\People\Filament\Resources\PersonResource\RelationManagers\IdentitiesRelationManager;
use Liberu\Genealogy\People\Filament\Resources\PersonResource\RelationManagers\LifeEventsRelationManager;
use Liberu\Genealogy\People\Filament\Resources\PersonResource\RelationManagers\MergeCandidatesRelationManager;
use Liberu\Genealogy\People\Filament\Resources\PersonResource\RelationManagers\NamesRelationManager;
use Liberu\Genealogy\People\Models\Person;
use Liberu\Genealogy\Relationships\Filament\Pages\RelationshipCalculator;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setupAppFilamentContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    app(TeamContext::class)->set($team->id);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    return [$user, $team];
}

it('renders index and create pages for all 32 Genealogy Filament resources registered on the app panel', function (): void {
    [$user] = setupAppFilamentContext();
    $this->actingAs($user);

    $resources = collect(Filament::getPanel('app')->getResources())
        ->filter(fn (string $resource): bool => str_starts_with($resource, 'Liberu\\Genealogy\\'))
        ->values();

    expect($resources->count())->toBe(32);

    foreach ($resources as $resource) {
        $pages = $resource::getPages();
        expect($pages)->toHaveKey('index');

        Livewire::actingAs($user)
            ->test($pages['index']->getPage())
            ->assertOk();

        if (isset($pages['create'])) {
            Livewire::actingAs($user)
                ->test($pages['create']->getPage())
                ->assertOk();
        }
    }
});

it('supports TreeResource Filament list isolation, form validation, create, and edit workflows', function (): void {
    [$user, $team] = setupAppFilamentContext();

    $otherTeam = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    app(TeamContext::class)->set($otherTeam->id);
    $foreignTree = app(CreateTreeAction::class)->execute(['name' => 'Foreign Team Tree']);

    app(TeamContext::class)->set($team->id);
    $localTree = app(CreateTreeAction::class)->execute(['name' => 'Local Team Tree', 'user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::actingAs($user)
        ->test(ListTrees::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$localTree])
        ->assertCanNotSeeTableRecords([$foreignTree]);

    // Validation requires name
    Livewire::actingAs($user)
        ->test(CreateTree::class)
        ->fillForm(['name' => '', 'status' => 'active'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    Livewire::actingAs($user)
        ->test(CreateTree::class)
        ->fillForm([
            'name' => 'Filament Created Tree',
            'identifier' => 'fil-created',
            'status' => 'active',
            'is_public' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Tree::query()->where('identifier', 'fil-created')->firstOrFail();

    Livewire::actingAs($user)
        ->test(EditTree::class, ['record' => (string) $created->id])
        ->fillForm(['name' => 'Filament Renamed Tree'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($created->fresh()->name)->toBe('Filament Renamed Tree');
});

it('supports PersonResource Filament CRUD, table search, team isolation, and all 5 RelationManagers', function (): void {
    [$user, $team] = setupAppFilamentContext();

    $otherTeam = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    app(TeamContext::class)->set($otherTeam->id);
    $foreignPerson = app(CreatePersonAction::class)->execute(['given_name' => 'Foreign', 'family_name' => 'Secret']);

    app(TeamContext::class)->set($team->id);
    $localPerson = app(CreatePersonAction::class)->execute([
        'given_name' => 'Rosalind',
        'family_name' => 'Franklin',
        'display_name' => 'Rosalind Franklin',
        'birth_place' => 'Notting Hill',
    ]);

    $this->actingAs($user);

    Livewire::actingAs($user)
        ->test(ListPeople::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$localPerson])
        ->assertCanNotSeeTableRecords([$foreignPerson])
        ->searchTable('Notting Hill')
        ->assertCanSeeTableRecords([$localPerson]);

    // Date validation rejects death_date before birth_date
    Livewire::actingAs($user)
        ->test(CreatePerson::class)
        ->fillForm([
            'given_name' => 'InvalidDates',
            'birth_date' => '1950-01-01',
            'death_date' => '1940-01-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['death_date']);

    Livewire::actingAs($user)
        ->test(CreatePerson::class)
        ->fillForm([
            'given_name' => 'Marie',
            'family_name' => 'Curie',
            'sex' => 'F',
            'birth_date' => '1867-11-07',
            'death_date' => '1934-07-04',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Person::query()->where('family_name', 'Curie')->firstOrFail();

    Livewire::actingAs($user)
        ->test(EditPerson::class, ['record' => (string) $created->id])
        ->fillForm(['birth_place' => 'Warsaw'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($created->fresh()->birth_place)->toBe('Warsaw');

    foreach ([
        NamesRelationManager::class,
        IdentitiesRelationManager::class,
        LifeEventsRelationManager::class,
        AssociationsRelationManager::class,
        MergeCandidatesRelationManager::class,
    ] as $relationManager) {
        Livewire::actingAs($user)
            ->test($relationManager, ['ownerRecord' => $created, 'pageClass' => EditPerson::class])
            ->assertOk();
    }
});

it('renders CitationResource PersonLinksRelationManager cleanly', function (): void {
    [$user, $team] = setupAppFilamentContext();
    $this->actingAs($user);

    $source = Source::query()->create(['team_id' => $team->id, 'name' => 'Parish Book']);
    $citation = Citation::query()->create(['team_id' => $team->id, 'source_id' => $source->id, 'title' => 'Baptism Entry']);

    Livewire::actingAs($user)
        ->test(PersonLinksRelationManager::class, ['ownerRecord' => $citation, 'pageClass' => EditCitation::class])
        ->assertOk();
});

it('registers RelationshipCalculator Filament page metadata on both panels', function (): void {
    // Note: Rendering RelationshipCalculator directly fails upstream because `genealogy-relationships-filament` view hint path is unregistered (documented in Report 08).
    expect(Filament::getPanel('app')->getPages())->toContain(RelationshipCalculator::class)
        ->and(Filament::getPanel('admin')->getPages())->toContain(RelationshipCalculator::class)
        ->and(RelationshipCalculator::getNavigationLabel())->toBe('Relationship Calculator');
});

it('gates and renders the AccountSecurity Filament page for authenticated users only', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    expect(AccountSecurity::canAccess())->toBeFalse();

    [$user] = setupAppFilamentContext();
    $this->actingAs($user);

    expect(AccountSecurity::canAccess())->toBeTrue();

    Livewire::actingAs($user)
        ->test(AccountSecurity::class)
        ->assertOk();
});
