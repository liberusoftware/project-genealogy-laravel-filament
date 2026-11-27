<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\Relationships\Actions\CreateRelationship;
use Liberu\Genealogy\Relationships\Models\Relationship;
use Liberu\Genealogy\TreeViewer\Actions\CreateTreeView;
use Liberu\Genealogy\TreeViewer\Actions\DeleteTreeView;
use Liberu\Genealogy\TreeViewer\Events\TreeViewDeleted;
use Liberu\Genealogy\TreeViewer\Models\TreeView;
use Liberu\Genealogy\TreeViewer\Queries\TreeGraph;

uses(RefreshDatabase::class);

it('soft deletes tree views, emits TreeViewDeleted, and enforces active team boundaries', function (): void {
    Event::fake([TreeViewDeleted::class]);

    $teamA = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $teamB = Team::factory()->create(['user_id' => User::factory()->create()->id]);

    app(TeamContext::class)->set($teamA->id);
    $view = app(CreateTreeView::class)->execute(['name' => 'Deletable View']);

    app(TeamContext::class)->set($teamB->id);
    expect(fn () => app(DeleteTreeView::class)->execute($view))
        ->toThrow(InvalidArgumentException::class, 'active team');

    app(TeamContext::class)->set($teamA->id);
    app(DeleteTreeView::class)->execute($view);

    expect(TreeView::withTrashed()->find($view->id)?->trashed())->toBeTrue();
    Event::assertDispatched(TreeViewDeleted::class, fn (TreeViewDeleted $event): bool => $event->tree->is($view));
});

it('clamps TreeGraph bounds, handles cycles, respects view modes, and masks living partners and siblings', function (): void {
    $team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    app(TeamContext::class)->set($team->id);

    $parent = app(CreatePerson::class)->execute(['given_name' => 'DeceasedParent', 'death_date' => '1950-01-01']);
    $root = app(CreatePerson::class)->execute(['given_name' => 'DeceasedRoot', 'death_date' => '1980-01-01']);
    $livingPartner = app(CreatePerson::class)->execute(['given_name' => 'LivingPartner']);
    $deceasedPartner = app(CreatePerson::class)->execute(['given_name' => 'DeceasedPartner', 'death_date' => '1990-01-01']);
    $livingSibling = app(CreatePerson::class)->execute(['given_name' => 'LivingSibling']);
    $child = app(CreatePerson::class)->execute(['given_name' => 'DeceasedChild', 'death_date' => '2000-01-01']);

    app(CreateRelationship::class)->execute(['person_id' => $parent->id, 'related_person_id' => $root->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $parent->id, 'related_person_id' => $livingSibling->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $root->id, 'related_person_id' => $child->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $root->id, 'related_person_id' => $livingPartner->id, 'type' => 'partner']);
    app(CreateRelationship::class)->execute(['person_id' => $root->id, 'related_person_id' => $deceasedPartner->id, 'type' => 'partner']);

    $graph = new TreeGraph();

    // Generations=0 returns empty ancestors and descendants; invalid view falls back to chart; maxNodes clamps
    $zero = $graph->for($root, generations: 0, includeLiving: true, view: 'nonexistent', includeSiblings: false, maxNodes: 5);
    expect($zero['view'])->toBe('chart')
        ->and($zero['ancestors'])->toBeEmpty()
        ->and($zero['descendants'])->toBeEmpty()
        ->and($zero['navigation']['generations'])->toBe(0)
        ->and($zero['navigation']['max_nodes'])->toBe(100)
        ->and($zero['navigation']['can_expand'])->toBeTrue();

    // High bounds clamp cleanly to 12 generations and 5000 maxNodes
    $clamped = $graph->for($root, generations: 99, includeLiving: true, view: 'pedigree', includeSiblings: false, maxNodes: 99999);
    expect($clamped['navigation']['generations'])->toBe(12)
        ->and($clamped['navigation']['max_nodes'])->toBe(5000)
        ->and($clamped['navigation']['can_expand'])->toBeFalse()
        ->and($clamped['descendants'])->toBeEmpty()
        ->and($clamped['ancestors'])->toHaveCount(1);

    // Privacy masking: includeLiving=false excludes living partners and masks living siblings
    $private = $graph->for($root, generations: 3, includeLiving: false, view: 'chart', includeSiblings: true);
    expect($private['partners'])->toHaveCount(1)
        ->and($private['partners'][0]['person']['name'])->toBe('DeceasedPartner')
        ->and($private['siblings'])->toHaveCount(1)
        ->and($private['siblings'][0]['name'])->toBe('Living person')
        ->and($private['siblings'][0]['given_name'])->toBeNull();

    // Cyclic parent edge does not loop infinitely
    Relationship::query()->create(['team_id' => $team->id, 'person_id' => $child->id, 'related_person_id' => $parent->id, 'type' => 'parent', 'confidence' => 50]);
    $cyclic = $graph->for($root, generations: 5, includeLiving: true, view: 'chart');
    expect($cyclic['ancestors'])->toHaveCount(2);
});

it('supports tree viewer API CRUD, owner authorization, filtering, and rootless graph 422 response', function (): void {
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $team->users()->attach($teammate->id, ['role' => 'member', 'status' => 'active']);
    $owner->forceFill(['current_team_id' => $team->id])->save();
    $teammate->forceFill(['current_team_id' => $team->id])->save();

    // Guest cannot create tree view
    $this->postJson('/api/v1/genealogy/tree-viewer', ['name' => 'Guest Tree'])->assertUnauthorized();

    $created = $this->actingAs($owner)
        ->postJson('/api/v1/genealogy/tree-viewer', [
            'name' => 'Rootless Tree',
            'status' => 'active',
            'is_public' => false,
        ])
        ->assertCreated();
    $treeId = $created->json('data.id');
    expect($treeId)->toBeString()->not->toBeEmpty();

    // Graph without root_person_id returns 422
    $this->actingAs($owner)->getJson("/api/v1/genealogy/tree-viewer/{$treeId}/graph")
        ->assertStatus(422);

    // Non-owner cannot view private tree (404), update (403), or delete (403)
    $this->actingAs($teammate)->getJson("/api/v1/genealogy/tree-viewer/{$treeId}")->assertNotFound();
    $this->actingAs($teammate)->patchJson("/api/v1/genealogy/tree-viewer/{$treeId}", ['name' => 'Blocked'])->assertForbidden();
    $this->actingAs($teammate)->deleteJson("/api/v1/genealogy/tree-viewer/{$treeId}")->assertForbidden();

    // Owner updates and filters index
    $this->actingAs($owner)->patchJson("/api/v1/genealogy/tree-viewer/{$treeId}", ['name' => 'Updated View', 'status' => 'completed'])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated View');

    $this->actingAs($owner)->getJson('/api/v1/genealogy/tree-viewer?status=completed')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($owner)->deleteJson("/api/v1/genealogy/tree-viewer/{$treeId}")
        ->assertOk();
    expect(TreeView::withoutGlobalScopes()->withTrashed()->find($treeId)?->trashed())->toBeTrue();
});
