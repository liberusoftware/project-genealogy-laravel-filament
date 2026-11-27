<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\People\Actions\CreateMergeCandidate;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\People\Actions\CreatePersonAssociation;
use Liberu\Genealogy\People\Actions\CreatePersonIdentity;
use Liberu\Genealogy\People\Actions\CreatePersonLifeEvent;
use Liberu\Genealogy\People\Actions\CreatePersonName;
use Liberu\Genealogy\People\Actions\DeletePerson;
use Liberu\Genealogy\People\Actions\MergePersons;
use Liberu\Genealogy\People\Events\PersonDeleted;
use Liberu\Genealogy\People\Events\PersonMerged;
use Liberu\Genealogy\People\Models\MergeCandidate;
use Liberu\Genealogy\People\Models\Person;
use Liberu\Genealogy\People\Models\PersonAssociation;

uses(RefreshDatabase::class);

it('merges duplicate people with field fallback, deduplication, reverse association repointing, and event dispatch', function (): void {
    Event::fake([PersonMerged::class]);

    $team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    app(TeamContext::class)->set($team->id);

    $primary = app(CreatePerson::class)->execute([
        'given_name' => 'Ada',
        'family_name' => 'Lovelace',
        'birth_date' => '1815-12-10',
        'attributes' => ['occupation' => 'Mathematician'],
        'metadata' => ['source' => 'primary'],
    ]);
    $duplicate = app(CreatePerson::class)->execute([
        'given_name' => 'Augusta',
        'family_name' => 'Byron',
        'sex' => 'F',
        'birth_place' => 'London',
        'death_date' => '1852-11-27',
        'attributes' => ['occupation' => 'Writer', 'title' => 'Countess'],
        'metadata' => ['source' => 'duplicate', 'import_id' => 'I99'],
    ]);
    $third = app(CreatePerson::class)->execute(['given_name' => 'Charles', 'family_name' => 'Babbage']);

    // Duplicate and unique names
    app(CreatePersonName::class)->execute(['person_id' => $primary->id, 'type' => 'birth', 'given_name' => 'Ada', 'family_name' => 'Byron']);
    app(CreatePersonName::class)->execute(['person_id' => $duplicate->id, 'type' => 'birth', 'given_name' => 'Ada', 'family_name' => 'Byron']);
    app(CreatePersonName::class)->execute(['person_id' => $duplicate->id, 'type' => 'married', 'given_name' => 'Ada', 'family_name' => 'King']);

    // Identities (unique constraint enforces one (team_id, type, value) per team)
    app(CreatePersonIdentity::class)->execute(['person_id' => $primary->id, 'type' => 'familysearch', 'value' => 'FS-1']);
    app(CreatePersonIdentity::class)->execute(['person_id' => $duplicate->id, 'type' => 'wikidata', 'value' => 'Q7259']);

    // Duplicate and unique life events
    app(CreatePersonLifeEvent::class)->execute(['person_id' => $primary->id, 'type' => 'residence', 'date' => '1835-01-01', 'place' => 'London']);
    app(CreatePersonLifeEvent::class)->execute(['person_id' => $duplicate->id, 'type' => 'residence', 'date' => '1835-01-01', 'place' => 'London']);
    app(CreatePersonLifeEvent::class)->execute(['person_id' => $duplicate->id, 'type' => 'burial', 'date' => '1852-12-03', 'place' => 'Hucknall']);

    // Associations: one duplicate, one unique, one reverse pointing to duplicate, one self-loop on merge
    app(CreatePersonAssociation::class)->execute(['person_id' => $primary->id, 'associated_person_id' => $third->id, 'relationship' => 'collaborator']);
    app(CreatePersonAssociation::class)->execute(['person_id' => $duplicate->id, 'associated_person_id' => $third->id, 'relationship' => 'collaborator']);
    app(CreatePersonAssociation::class)->execute(['person_id' => $duplicate->id, 'associated_external_id' => 'EXT-1', 'relationship' => 'godparent']);
    app(CreatePersonAssociation::class)->execute(['person_id' => $third->id, 'associated_person_id' => $duplicate->id, 'relationship' => 'mentor']);
    app(CreatePersonAssociation::class)->execute(['person_id' => $primary->id, 'associated_person_id' => $duplicate->id, 'relationship' => 'duplicate_ref']);

    // Pending merge candidate referencing duplicate should be cleaned up
    app(CreateMergeCandidate::class)->execute(['person_id' => $third->id, 'candidate_person_id' => $duplicate->id, 'status' => 'pending', 'score' => 0.7]);

    $merged = app(MergePersons::class)->execute($primary, $duplicate);

    expect($merged->given_name)->toBe('Ada')
        ->and($merged->sex)->toBe('F')
        ->and($merged->birth_place)->toBe('London')
        ->and($merged->death_date?->toDateString())->toBe('1852-11-27')
        ->and($merged->attributes)->toBe(['occupation' => 'Mathematician', 'title' => 'Countess'])
        ->and($merged->metadata)->toBe(['source' => 'primary', 'import_id' => 'I99'])
        ->and($merged->names()->count())->toBe(2)
        ->and($merged->identities()->count())->toBe(2)
        ->and($merged->lifeEvents()->count())->toBe(2)
        ->and($merged->associations()->count())->toBe(2)
        ->and(PersonAssociation::query()->where('person_id', $third->id)->where('associated_person_id', $primary->id)->exists())->toBeTrue()
        ->and(PersonAssociation::query()->where('person_id', $primary->id)->where('associated_person_id', $duplicate->id)->exists())->toBeFalse()
        ->and(MergeCandidate::query()->where('candidate_person_id', $duplicate->id)->exists())->toBeFalse();

    $trashedDuplicate = Person::withTrashed()->find($duplicate->id);
    expect($trashedDuplicate?->trashed())->toBeTrue()
        ->and($trashedDuplicate?->metadata['merged_into'])->toBe((string) $primary->id);

    Event::assertDispatched(PersonMerged::class, fn (PersonMerged $event): bool => $event->primary->is($primary) && $event->duplicateId === (string) $duplicate->id);
});

it('rejects self-merge and cross-team merge and enforces team checks on DeletePerson', function (): void {
    Event::fake([PersonDeleted::class]);

    $teamA = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $teamB = Team::factory()->create(['user_id' => User::factory()->create()->id]);

    app(TeamContext::class)->set($teamA->id);
    $personA = app(CreatePerson::class)->execute(['given_name' => 'Alpha']);

    app(TeamContext::class)->set($teamB->id);
    $personB = app(CreatePerson::class)->execute(['given_name' => 'Beta']);

    app(TeamContext::class)->set($teamA->id);
    expect(fn () => app(MergePersons::class)->execute($personA, $personA))
        ->toThrow(InvalidArgumentException::class, 'merged with itself');
    expect(fn () => app(MergePersons::class)->execute($personA, $personB))
        ->toThrow(InvalidArgumentException::class, 'active team');
    expect(fn () => app(DeletePerson::class)->execute($personB))
        ->toThrow(InvalidArgumentException::class, 'active team');

    app(DeletePerson::class)->execute($personA);
    expect(Person::withTrashed()->find($personA->id)?->trashed())->toBeTrue();
    Event::assertDispatched(PersonDeleted::class, fn (PersonDeleted $event): bool => $event->person->is($personA));
});

it('supports full PersonController CRUD, filtering, includes, attributes, life status, and associations over HTTP', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    // Validation failure: death_date before birth_date and invalid sex
    $this->actingAs($user)
        ->postJson('/api/v1/genealogy/people', [
            'given_name' => 'Invalid',
            'sex' => 'Z',
            'birth_date' => '1950-05-01',
            'death_date' => '1940-01-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sex', 'death_date']);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/genealogy/people', [
            'given_name' => 'Grace',
            'family_name' => 'Hopper',
            'sex' => 'F',
            'birth_date' => '1906-12-09',
            'death_date' => '1992-01-01',
            'is_public' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.life_status', 'deceased');

    $personId = $created->json('data.id');

    $living = $this->actingAs($user)
        ->postJson('/api/v1/genealogy/people', [
            'given_name' => 'Living',
            'family_name' => 'Relative',
            'is_public' => false,
        ])
        ->assertCreated();
    $livingId = $living->json('data.id');

    // Show & Update
    $this->actingAs($user)->getJson("/api/v1/genealogy/people/{$personId}")
        ->assertOk()
        ->assertJsonPath('data.attributes.family_name', 'Hopper');

    $this->actingAs($user)->patchJson("/api/v1/genealogy/people/{$personId}", ['birth_place' => 'New York'])
        ->assertOk()
        ->assertJsonPath('data.attributes.birth_place', 'New York');

    // Attributes update & remove
    $this->actingAs($user)->putJson("/api/v1/genealogy/people/{$personId}/attributes", [
        'attributes' => ['rank' => 'Rear Admiral', 'nickname' => 'Amazing Grace'],
    ])->assertOk()->assertJsonPath('data.attributes.attributes.rank', 'Rear Admiral');

    $this->actingAs($user)->deleteJson("/api/v1/genealogy/people/{$personId}/attributes/nickname")
        ->assertOk()
        ->assertJsonMissingPath('data.attributes.attributes.nickname');

    // Life status update
    $this->actingAs($user)->patchJson("/api/v1/genealogy/people/{$livingId}/life-status", [
        'status' => 'deceased',
        'death_date' => '2024-06-01',
    ])->assertOk()->assertJsonPath('data.attributes.life_status', 'deceased');

    // Associations CRUD + cross-person mismatch 404
    $assoc = $this->actingAs($user)->postJson("/api/v1/genealogy/people/{$personId}/associations", [
        'associated_person_id' => $livingId,
        'relationship' => 'mentor',
    ])->assertCreated();
    $assocId = $assoc->json('data.id');

    $this->actingAs($user)->putJson("/api/v1/genealogy/people/{$livingId}/associations/{$assocId}", ['relationship' => 'tampered'])
        ->assertNotFound();
    $this->actingAs($user)->putJson("/api/v1/genealogy/people/{$personId}/associations/{$assocId}", ['relationship' => 'colleague'])
        ->assertOk()
        ->assertJsonPath('data.attributes.relationship', 'colleague');

    // Index filtering + includes
    $this->actingAs($user)->getJson('/api/v1/genealogy/people?search=Hopper&public_only=1&include_living=0&include=associations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.relationships.associations.0.attributes.relationship', 'colleague');

    $this->actingAs($user)->deleteJson("/api/v1/genealogy/people/{$personId}/associations/{$assocId}")
        ->assertNoContent();

    // Destroy person
    $this->actingAs($user)->deleteJson("/api/v1/genealogy/people/{$livingId}")
        ->assertNoContent();
    expect(Person::withoutGlobalScopes()->withTrashed()->find($livingId)?->trashed())->toBeTrue();
});

it('supports full CRUD for all person supporting records and rejects unknown supporting types', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $p1 = $this->actingAs($user)->postJson('/api/v1/genealogy/people', ['given_name' => 'Alan', 'family_name' => 'Turing'])->json('data.id');
    $p2 = $this->actingAs($user)->postJson('/api/v1/genealogy/people', ['given_name' => 'A.', 'family_name' => 'Turing'])->json('data.id');

    $cases = [
        'names' => [['type' => 'alias', 'given_name' => 'Alan M.', 'family_name' => 'Turing'], ['family_name' => 'Turing-Updated']],
        'identities' => [['type' => 'wikidata', 'value' => 'Q7251'], ['label' => 'Verified Wikidata']],
        'life-events' => [['type' => 'graduation', 'date' => '1934-06-01', 'place' => 'Cambridge'], ['place' => 'King\'s College']],
        'merge-candidates' => [['candidate_person_id' => $p2, 'score' => 0.85, 'reason' => 'Name match'], ['reason' => 'Confirmed match']],
    ];

    foreach ($cases as $supporting => [$createPayload, $updatePayload]) {
        $stored = $this->actingAs($user)
            ->postJson("/api/v1/genealogy/people/{$p1}/{$supporting}", $createPayload)
            ->assertCreated();
        $id = $stored->json('data.id');

        $this->actingAs($user)->getJson("/api/v1/genealogy/people/{$p1}/{$supporting}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($user)->putJson("/api/v1/genealogy/people/{$p1}/{$supporting}/{$id}", $updatePayload)
            ->assertOk();

        $this->actingAs($user)->deleteJson("/api/v1/genealogy/people/{$p1}/{$supporting}/{$id}")
            ->assertNoContent();
    }

    $this->actingAs($user)->getJson("/api/v1/genealogy/people/{$p1}/unknown-type")->assertNotFound();
});
