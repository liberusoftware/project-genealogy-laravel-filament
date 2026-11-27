<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\Dna\Models\DnaKit;
use Liberu\Genealogy\Evidence\Models\Citation;
use Liberu\Genealogy\Evidence\Models\CitationLink;
use Liberu\Genealogy\Evidence\Models\EvidenceRecord;
use Liberu\Genealogy\Evidence\Models\Source;
use Liberu\Genealogy\GenealogyCore\Actions\CreateTree;
use Liberu\Genealogy\GenealogyCore\Models\Tree;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\People\Actions\MergePersons;
use Liberu\Genealogy\Relationships\Actions\CreateRelationship;
use Liberu\Genealogy\Relationships\Actions\DeleteRelationship;
use Liberu\Genealogy\Relationships\Events\RelationshipDeleted;
use Liberu\Genealogy\Relationships\Models\Relationship;
use Liberu\Genealogy\Relationships\Queries\GraphValidator;
use Liberu\Genealogy\Relationships\Queries\ParentGraph;
use Liberu\Genealogy\Relationships\Queries\RelationshipCalculator;

uses(RefreshDatabase::class);

it('soft deletes relationships, emits RelationshipDeleted, and enforces active team checks', function (): void {
    Event::fake([RelationshipDeleted::class]);

    $teamA = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $teamB = Team::factory()->create(['user_id' => User::factory()->create()->id]);

    app(TeamContext::class)->set($teamA->id);
    $parent = app(CreatePerson::class)->execute(['given_name' => 'Parent']);
    $child = app(CreatePerson::class)->execute(['given_name' => 'Child']);
    $rel = app(CreateRelationship::class)->execute(['person_id' => $parent->id, 'related_person_id' => $child->id, 'type' => 'parent']);

    app(TeamContext::class)->set($teamB->id);
    expect(fn () => app(DeleteRelationship::class)->execute($rel))
        ->toThrow(InvalidArgumentException::class, 'active team');

    app(TeamContext::class)->set($teamA->id);
    app(DeleteRelationship::class)->execute($rel);

    expect(Relationship::withoutGlobalScopes()->find($rel->id))->toBeNull();
    Event::assertDispatched(RelationshipDeleted::class, fn (RelationshipDeleted $event): bool => $event->relationship->is($rel));
});

it('queries ParentGraph ancestors and descendants strictly within the specified team', function (): void {
    $teamA = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $teamB = Team::factory()->create(['user_id' => User::factory()->create()->id]);

    app(TeamContext::class)->set($teamA->id);
    $gp = app(CreatePerson::class)->execute(['given_name' => 'GP']);
    $p = app(CreatePerson::class)->execute(['given_name' => 'P']);
    $c = app(CreatePerson::class)->execute(['given_name' => 'C']);
    app(CreateRelationship::class)->execute(['person_id' => $gp->id, 'related_person_id' => $p->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $p->id, 'related_person_id' => $c->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $gp->id, 'related_person_id' => $c->id, 'type' => 'partner']);

    $graph = new ParentGraph();

    expect($graph->relatedPeople((string) $teamA->id, [(string) $p->id], ancestors: true))->toBe([(string) $gp->id])
        ->and($graph->relatedPeople((string) $teamA->id, [(string) $p->id], ancestors: false))->toBe([(string) $c->id])
        ->and($graph->relatedPeople((string) $teamB->id, [(string) $p->id], ancestors: true))->toBe([]);
});

it('validates relationship graph constraints including self-links, duplicates, bidirectional partners, and multi-hop cycles', function (): void {
    $team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    app(TeamContext::class)->set($team->id);

    $a = app(CreatePerson::class)->execute(['given_name' => 'A']);
    $b = app(CreatePerson::class)->execute(['given_name' => 'B']);
    $c = app(CreatePerson::class)->execute(['given_name' => 'C']);

    $ab = app(CreateRelationship::class)->execute(['person_id' => $a->id, 'related_person_id' => $b->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $b->id, 'related_person_id' => $c->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $a->id, 'related_person_id' => $c->id, 'type' => 'partner']);

    $validator = new GraphValidator();

    expect($validator->validate($a->id, $a->id, 'parent')['valid'])->toBeFalse()
        ->and($validator->validate($a->id, $b->id, 'invalid_type')['valid'])->toBeFalse()
        ->and($validator->validate($a->id, $b->id, 'parent')['valid'])->toBeFalse()
        ->and($validator->validate($a->id, $b->id, 'parent', (string) $ab->id)['valid'])->toBeTrue()
        ->and($validator->validate($c->id, $a->id, 'partner')['valid'])->toBeFalse()
        ->and($validator->validate($c->id, $a->id, 'parent')['valid'])->toBeFalse()
        ->and($validator->validate($c->id, $a->id, 'parent')['reason'])->toContain('cycle')
        ->and($validator->validate($a->id, $c->id, 'parent')['valid'])->toBeTrue();
});

it('calculates direct, collateral, cousin, removed, unknown-sex, unrelated, and cyclic relationships accurately', function (): void {
    $team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    app(TeamContext::class)->set($team->id);

    $make = fn (string $name, ?string $sex = null) => app(CreatePerson::class)->execute(['given_name' => $name, 'sex' => $sex]);
    $link = fn ($parent, $child) => app(CreateRelationship::class)->execute(['person_id' => $parent->id, 'related_person_id' => $child->id, 'type' => 'parent']);

    $ggp = $make('GGP', 'M');
    $gp1 = $make('GP1', 'F');
    $gp2 = $make('GP2', 'M');
    $p1 = $make('P1', 'M');
    $p2 = $make('P2', 'F');
    $p3 = $make('P3', null);
    $c1 = $make('C1', 'F');
    $c2 = $make('C2', 'M');
    $gc1 = $make('GC1', 'M');
    $unrelated = $make('Unrelated');

    $link($ggp, $gp1);
    $link($ggp, $gp2);
    $link($gp1, $p1);
    $link($gp1, $p3);
    $link($gp2, $p2);
    $link($p1, $c1);
    $link($p2, $c2);
    $link($c1, $gc1);

    $calc = new RelationshipCalculator();

    expect($calc->between($ggp->id, $ggp->id)['relationship'])->toBe('self')
        ->and($calc->between($ggp->id, $gp1->id)['relationship'])->toBe('parent')
        ->and($calc->between($gp1->id, $ggp->id)['relationship'])->toBe('child')
        ->and($calc->between($ggp->id, $p1->id)['relationship'])->toBe('grandparent')
        ->and($calc->between($ggp->id, $c1->id)['relationship'])->toBe('great-grandparent')
        ->and($calc->between($c1->id, $ggp->id)['relationship'])->toBe('great-grandchild')
        ->and($calc->between($gp1->id, $gp2->id)['relationship'])->toBe('sibling')
        ->and($calc->between($gp2->id, $p1->id)['relationship'])->toBe('uncle')
        ->and($calc->between($gp1->id, $p2->id)['relationship'])->toBe('aunt')
        ->and($calc->between($p3->id, $c1->id)['relationship'])->toBe('aunt/uncle')
        ->and($calc->between($c1->id, $p3->id)['relationship'])->toBe('niece')
        ->and($calc->between($gp2->id, $c1->id)['relationship'])->toBe('grand-uncle')
        ->and($calc->between($gp1->id, $gc1->id)['relationship'])->toBe('great-grandparent')
        ->and($calc->between($p1->id, $p2->id)['relationship'])->toBe('1st cousin')
        ->and($calc->between($p1->id, $c2->id)['relationship'])->toBe('1st cousin once removed')
        ->and($calc->between($p2->id, $gc1->id)['relationship'])->toBe('1st cousin twice removed')
        ->and($calc->between($c1->id, $c2->id)['relationship'])->toBe('2nd cousin')
        ->and($calc->between($c1->id, $unrelated->id)['relationship'])->toBe('no traceable relationship');

    // Cyclic imported data must not infinite loop
    $cyc1 = $make('Cyc1');
    $cyc2 = $make('Cyc2');
    Relationship::query()->create(['team_id' => $team->id, 'person_id' => $cyc1->id, 'related_person_id' => $cyc2->id, 'type' => 'parent', 'confidence' => 50]);
    Relationship::query()->create(['team_id' => $team->id, 'person_id' => $cyc2->id, 'related_person_id' => $cyc1->id, 'type' => 'parent', 'confidence' => 50]);
    expect($calc->between($cyc1->id, $cyc2->id)['relationship'])->toBe('parent');
});

it('exposes relationship validate, calculate, and full CRUD endpoints over HTTP', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    app(TeamContext::class)->set($team->id);
    $parent = app(CreatePerson::class)->execute(['given_name' => 'Parent']);
    $child = app(CreatePerson::class)->execute(['given_name' => 'Child']);
    app(TeamContext::class)->clear();

    $this->actingAs($user)->postJson('/api/v1/genealogy/relationships/validate', [
        'person_id' => $parent->id,
        'related_person_id' => $child->id,
        'type' => 'parent',
    ])->assertOk()->assertJsonPath('data.valid', true);

    $created = $this->actingAs($user)->postJson('/api/v1/genealogy/relationships', [
        'person_id' => $parent->id,
        'related_person_id' => $child->id,
        'type' => 'parent',
        'confidence' => 90,
    ])->assertCreated();
    $relId = $created->json('data.id');

    // Reverse parent would create cycle
    $this->actingAs($user)->postJson('/api/v1/genealogy/relationships/validate', [
        'person_id' => $child->id,
        'related_person_id' => $parent->id,
        'type' => 'parent',
    ])->assertOk()->assertJsonPath('data.valid', false);

    // Self-relationship rejected by validation
    $this->actingAs($user)->postJson('/api/v1/genealogy/relationships', [
        'person_id' => $parent->id,
        'related_person_id' => $parent->id,
        'type' => 'parent',
    ])->assertStatus(422)->assertJsonValidationErrors(['person_id']);

    $this->actingAs($user)->getJson("/api/v1/genealogy/relationships/{$relId}")
        ->assertOk()
        ->assertJsonPath('data.attributes.confidence', 90);

    $this->actingAs($user)->patchJson("/api/v1/genealogy/relationships/{$relId}", ['confidence' => 100])
        ->assertOk()
        ->assertJsonPath('data.attributes.confidence', 100);

    $this->actingAs($user)->deleteJson("/api/v1/genealogy/relationships/{$relId}")
        ->assertNoContent();
    expect(Relationship::withoutGlobalScopes()->find($relId))->toBeNull();
});

it('reconciles relationships, DNA kits, evidence records, citation links, and tree roots on person merge', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    app(TeamContext::class)->set($team->id);

    $primary = app(CreatePerson::class)->execute(['given_name' => 'Primary']);
    $duplicate = app(CreatePerson::class)->execute(['given_name' => 'Duplicate']);
    $child = app(CreatePerson::class)->execute(['given_name' => 'Child']);
    $otherParent = app(CreatePerson::class)->execute(['given_name' => 'OtherParent']);

    // Relationships: one to repoint, one duplicate to delete, one self-loop to delete
    $toRepoint = app(CreateRelationship::class)->execute(['person_id' => $otherParent->id, 'related_person_id' => $duplicate->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $primary->id, 'related_person_id' => $child->id, 'type' => 'parent']);
    $duplicateEdge = app(CreateRelationship::class)->execute(['person_id' => $duplicate->id, 'related_person_id' => $child->id, 'type' => 'parent']);
    $selfLoopEdge = app(CreateRelationship::class)->execute(['person_id' => $primary->id, 'related_person_id' => $duplicate->id, 'type' => 'partner']);

    // DNA kit pointing to duplicate
    $kit = DnaKit::query()->create(['team_id' => $team->id, 'name' => 'Dup Kit', 'person_id' => $duplicate->id, 'status' => 'active']);

    // EvidenceRecord + CitationLink pointing to duplicate
    $evidence = EvidenceRecord::query()->create([
        'team_id' => $team->id,
        'name' => 'Census Record',
        'kind' => 'source',
        'subject_person_id' => $duplicate->id,
        'status' => 'active',
        'confidence' => 80,
    ]);
    $source = Source::query()->create(['team_id' => $team->id, 'name' => 'National Archives']);
    $citation = Citation::query()->create(['team_id' => $team->id, 'source_id' => $source->id, 'title' => '1900 Census']);
    $citationLink = CitationLink::query()->create([
        'team_id' => $team->id,
        'citation_id' => $citation->id,
        'subject_person_id' => $duplicate->id,
        'group' => 'indi',
    ]);

    // Tree rooted at duplicate
    $tree = app(CreateTree::class)->execute(['name' => 'Duplicate Root Tree', 'root_person_id' => $duplicate->id, 'user_id' => $user->id]);

    // Execute real merge so all registered ReconcilePersonMerge listeners run
    app(MergePersons::class)->execute($primary, $duplicate);

    expect($toRepoint->fresh()->related_person_id)->toBe((string) $primary->id)
        ->and(Relationship::withoutGlobalScopes()->find($duplicateEdge->id))->toBeNull()
        ->and(Relationship::withoutGlobalScopes()->find($selfLoopEdge->id))->toBeNull()
        ->and($kit->fresh()->person_id)->toBe((string) $primary->id)
        ->and($evidence->fresh()->subject_person_id)->toBe((string) $primary->id)
        ->and($citationLink->fresh()->subject_person_id)->toBe((string) $primary->id)
        ->and(Tree::query()->find($tree->id)?->root_person_id)->toBe((string) $primary->id);
});
