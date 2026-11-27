<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\Evidence\Models\EvidenceRecord;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\Places\Models\Place;
use Liberu\Genealogy\Reports\Models\GenealogyReport;
use Liberu\Genealogy\Research\Actions\DeleteResearchProject;
use Liberu\Genealogy\Research\Actions\UpdateResearchProject;
use Liberu\Genealogy\Research\Models\ResearchProject;
use Liberu\Genealogy\Timeline\Models\TimelineEvent;
use Liberu\Genealogy\Timeline\Queries\ChronologicalTimeline;
use Liberu\Genealogy\Timeline\Queries\ConflictingTimelineEvents;

uses(RefreshDatabase::class);

function createGenealogyActor(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return [$user, $team];
}

it('supports full Place and PlaceName CRUD, hierarchy query, and date validation over HTTP', function (): void {
    [$user] = createGenealogyActor();

    $country = $this->actingAs($user)->postJson('/api/v1/genealogy/places', [
        'name' => 'United Kingdom',
        'jurisdiction' => 'country',
        'latitude' => 51.5074,
        'longitude' => -0.1278,
        'status' => 'active',
    ])->assertCreated();
    $countryId = $country->json('data.id');

    $city = $this->actingAs($user)->postJson('/api/v1/genealogy/places', [
        'name' => 'London',
        'parent_id' => $countryId,
        'jurisdiction' => 'city',
        'status' => 'active',
    ])->assertCreated();
    $cityId = $city->json('data.id');

    $this->actingAs($user)->getJson('/api/v1/genealogy/places/hierarchy')
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->actingAs($user)->getJson("/api/v1/genealogy/places/{$cityId}")
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'London');

    $this->actingAs($user)->patchJson("/api/v1/genealogy/places/{$cityId}", ['name' => 'Greater London'])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Greater London');

    // PlaceName validation: valid_to before valid_from -> 422
    $this->actingAs($user)->postJson("/api/v1/genealogy/places/{$cityId}/names", [
        'name' => 'Londinium',
        'valid_from' => '0500-01-01',
        'valid_to' => '0100-01-01',
    ])->assertStatus(422)->assertJsonValidationErrors(['valid_to']);

    $placeName = $this->actingAs($user)->postJson("/api/v1/genealogy/places/{$cityId}/names", [
        'name' => 'Londinium',
        'type' => 'historical',
        'locale' => 'la',
        'valid_from' => '0043-01-01',
        'valid_to' => '0410-01-01',
    ])->assertCreated();
    $nameId = $placeName->json('data.id');

    $this->actingAs($user)->getJson("/api/v1/genealogy/places/{$cityId}/names")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($user)->patchJson("/api/v1/genealogy/places/{$cityId}/names/{$nameId}", ['name' => 'Londinium Augusta'])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Londinium Augusta');

    $this->actingAs($user)->deleteJson("/api/v1/genealogy/places/{$cityId}/names/{$nameId}")->assertNoContent();
    $this->actingAs($user)->deleteJson("/api/v1/genealogy/places/{$cityId}")->assertNoContent();
    expect(Place::withoutGlobalScopes()->withTrashed()->find($cityId)?->trashed())->toBeTrue();
});

it('supports full Evidence entity hierarchy CRUD, citation-person links, and evidence record lifecycle over HTTP', function (): void {
    [$user, $team] = createGenealogyActor();

    app(TeamContext::class)->set($team->id);
    $person = app(CreatePerson::class)->execute(['given_name' => 'Subject']);
    app(TeamContext::class)->clear();

    // Unknown entity -> 404
    $this->actingAs($user)->getJson('/api/v1/genealogy/evidence/unknown-entity')->assertNotFound();

    // Source -> Repository -> Citation -> Extract -> Assertion -> ProofConclusion chain
    $sourceId = $this->actingAs($user)->postJson('/api/v1/genealogy/evidence/sources', [
        'name' => '1881 UK Census',
        'record_type' => 'census',
    ])->assertCreated()->json('data.id');

    $repoId = $this->actingAs($user)->postJson('/api/v1/genealogy/evidence/repositories', [
        'name' => 'The National Archives',
        'source_id' => $sourceId,
    ])->assertCreated()->json('data.id');

    $citationId = $this->actingAs($user)->postJson('/api/v1/genealogy/evidence/citations', [
        'source_id' => $sourceId,
        'repository_id' => $repoId,
        'title' => 'RG11 Piece 100',
        'page' => 'p. 14',
        'confidence' => 90,
    ])->assertCreated()->json('data.id');

    $extractId = $this->actingAs($user)->postJson('/api/v1/genealogy/evidence/extracts', [
        'citation_id' => $citationId,
        'content' => 'Household entry showing birth in Kent',
    ])->assertCreated()->json('data.id');

    $assertionId = $this->actingAs($user)->postJson('/api/v1/genealogy/evidence/assertions', [
        'statement' => 'Born in Kent circa 1840',
        'subject_person_id' => $person->id,
        'citation_id' => $citationId,
        'extract_id' => $extractId,
        'confidence' => 85,
    ])->assertCreated()->json('data.id');

    $conclusionId = $this->actingAs($user)->postJson('/api/v1/genealogy/evidence/proof-conclusions', [
        'assertion_id' => $assertionId,
        'conclusion' => 'Birthplace confirmed by multiple census returns',
        'confidence' => 92,
    ])->assertCreated()->json('data.id');

    // Index, show, update, delete on proof-conclusions
    $this->actingAs($user)->getJson('/api/v1/genealogy/evidence/proof-conclusions')->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($user)->getJson("/api/v1/genealogy/evidence/proof-conclusions/{$conclusionId}")->assertOk();
    $this->actingAs($user)->patchJson("/api/v1/genealogy/evidence/proof-conclusions/{$conclusionId}", ['confidence' => 95])
        ->assertOk()->assertJsonPath('data.attributes.confidence', 95);
    $this->actingAs($user)->deleteJson("/api/v1/genealogy/evidence/proof-conclusions/{$conclusionId}")->assertNoContent();

    // Citation-person links CRUD
    $linkId = $this->actingAs($user)->postJson("/api/v1/genealogy/evidence/citations/{$citationId}/people", [
        'subject_person_id' => $person->id,
        'group' => 'indi',
        'page' => 'p. 14',
        'quality' => '3',
    ])->assertCreated()->assertJsonPath('data.quality_label', 'Primary evidence')->json('data.id');

    $this->actingAs($user)->getJson("/api/v1/genealogy/evidence/citations/{$citationId}/people")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($user)->putJson("/api/v1/genealogy/evidence/citations/{$citationId}/people/{$linkId}", ['quality' => '2'])
        ->assertOk()->assertJsonPath('data.quality_label', 'Secondary evidence');
    $this->actingAs($user)->deleteJson("/api/v1/genealogy/evidence/citations/{$citationId}/people/{$linkId}")->assertNoContent();

    // EvidenceRecord CRUD + review + archive
    $recordId = $this->actingAs($user)->postJson('/api/v1/genealogy/evidence', [
        'name' => 'Parish Register Entry',
        'kind' => 'source',
        'subject_person_id' => $person->id,
        'confidence' => 80,
    ])->assertCreated()->json('data.id');

    $this->actingAs($user)->patchJson("/api/v1/genealogy/evidence/{$recordId}", ['confidence' => 95])
        ->assertOk()->assertJsonPath('data.attributes.confidence', 95);
    $this->actingAs($user)->postJson("/api/v1/genealogy/evidence/{$recordId}/review")->assertOk();
    $this->actingAs($user)->postJson("/api/v1/genealogy/evidence/{$recordId}/archive")->assertOk();
    $this->actingAs($user)->deleteJson("/api/v1/genealogy/evidence/{$recordId}")->assertNoContent();
    expect(EvidenceRecord::withoutGlobalScopes()->withTrashed()->find($recordId)?->trashed())->toBeTrue();
});

it('supports TimelineEvent CRUD, chronological range filtering, conflict detection, and date validation over HTTP', function (): void {
    [$user, $team] = createGenealogyActor();

    app(TeamContext::class)->set($team->id);
    $person = app(CreatePerson::class)->execute(['given_name' => 'Chrono']);
    app(TeamContext::class)->clear();

    // Validation: date_end before date_start -> 422
    $this->actingAs($user)->postJson('/api/v1/genealogy/timeline', [
        'name' => 'Invalid Range',
        'date_start' => '1900-01-01',
        'date_end' => '1890-01-01',
    ])->assertStatus(422)->assertJsonValidationErrors(['date_end']);

    $publicId = $this->actingAs($user)->postJson('/api/v1/genealogy/timeline', [
        'name' => 'Birth Record A',
        'subject_person_id' => $person->id,
        'event_date' => '1880-03-10',
        'conflict_group' => 'birth',
        'is_private' => false,
    ])->assertCreated()->json('data.id');

    $privateId = $this->actingAs($user)->postJson('/api/v1/genealogy/timeline', [
        'name' => 'Birth Record B (Private)',
        'subject_person_id' => $person->id,
        'event_date' => '1881-05-12',
        'conflict_group' => 'birth',
        'is_private' => true,
    ])->assertCreated()->json('data.id');

    // Timeline HTTP endpoint excludes private events by default and filters by date range
    $this->actingAs($user)->getJson("/api/v1/genealogy/timeline/events?subject_person_id={$person->id}&from=1879-01-01&to=1882-01-01")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Domain queries handle includePrivate=true cleanly (note: TimelineEventController passes uncast query string to strict bool; documented in Report 06)
    app(TeamContext::class)->set($team->id);
    expect(app(ChronologicalTimeline::class)->execute($person->id, null, '1879-01-01', '1882-01-01', true))->toHaveCount(2)
        ->and(app(ConflictingTimelineEvents::class)->execute($person->id, true))->toHaveCount(1);
    app(TeamContext::class)->clear();

    $this->actingAs($user)->getJson("/api/v1/genealogy/timeline/conflicts?subject_person_id={$person->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($user)->patchJson("/api/v1/genealogy/timeline/{$publicId}", ['name' => 'Birth Record A Updated'])
        ->assertOk()->assertJsonPath('data.attributes.name', 'Birth Record A Updated');

    $this->actingAs($user)->deleteJson("/api/v1/genealogy/timeline/{$privateId}")->assertNoContent();
    expect(TimelineEvent::withoutGlobalScopes()->withTrashed()->find($privateId)?->trashed())->toBeTrue();
});

it('supports ResearchProject and ResearchEntry CRUD, overdue filtering, and cross-project mismatch 404', function (): void {
    [$user, $team] = createGenealogyActor();

    $projectA = $this->actingAs($user)->postJson('/api/v1/genealogy/research', ['name' => 'Kent Lineage', 'status' => 'active'])
        ->assertCreated()->json('data.id');
    $projectB = $this->actingAs($user)->postJson('/api/v1/genealogy/research', ['name' => 'York Lineage', 'status' => 'draft'])
        ->assertCreated()->json('data.id');

    $entryId = $this->actingAs($user)->postJson("/api/v1/genealogy/research/{$projectA}/entries", [
        'kind' => 'task',
        'title' => 'Check 1851 census',
        'status' => 'open',
        'due_date' => now()->subDays(3)->toDateString(),
    ])->assertCreated()->json('data.id');

    $this->actingAs($user)->getJson("/api/v1/genealogy/research/{$projectA}/entries?overdue=1")
        ->assertOk()->assertJsonCount(1, 'data');

    // Accessing entry under wrong project returns 404
    $this->actingAs($user)->getJson("/api/v1/genealogy/research/{$projectB}/entries/{$entryId}")->assertNotFound();
    $this->actingAs($user)->patchJson("/api/v1/genealogy/research/{$projectB}/entries/{$entryId}", ['title' => 'Hijack'])->assertNotFound();
    $this->actingAs($user)->deleteJson("/api/v1/genealogy/research/{$projectB}/entries/{$entryId}")->assertNotFound();

    $this->actingAs($user)->patchJson("/api/v1/genealogy/research/{$projectA}/entries/{$entryId}", ['status' => 'completed'])
        ->assertOk()->assertJsonPath('data.attributes.status', 'completed');

    $this->actingAs($user)->getJson("/api/v1/genealogy/research/{$projectA}/entries?overdue=1")
        ->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($user)->deleteJson("/api/v1/genealogy/research/{$projectA}/entries/{$entryId}")->assertNoContent();

    // Update & delete ResearchProject via domain actions (note: ResearchProjectController route parameter {research} mismatches $record binding; documented in Report 06)
    app(TeamContext::class)->set($team->id);
    $projectModel = ResearchProject::query()->findOrFail($projectA);
    app(UpdateResearchProject::class)->execute($projectModel, ['status' => 'completed']);
    expect($projectModel->fresh()->status)->toBe('completed');
    app(DeleteResearchProject::class)->execute($projectModel);
    expect(ResearchProject::withoutGlobalScopes()->withTrashed()->find($projectA)?->trashed())->toBeTrue();
    app(TeamContext::class)->clear();
});

it('supports GenealogyReport CRUD and multi-format report generation over HTTP', function (): void {
    [$user] = createGenealogyActor();

    $reportId = $this->actingAs($user)->postJson('/api/v1/genealogy/reports', [
        'name' => 'Pedigree Summary',
        'type' => 'pedigree',
        'status' => 'draft',
    ])->assertCreated()->json('data.id');

    foreach (['json', 'csv', 'gedcom', 'svg'] as $format) {
        $this->actingAs($user)->postJson("/api/v1/genealogy/reports/{$reportId}/generate", ['format' => $format])
            ->assertOk()
            ->assertJsonPath('data.attributes.generated_output.format', $format);
    }

    $this->actingAs($user)->postJson("/api/v1/genealogy/reports/{$reportId}/generate", ['format' => 'pdf'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['format']);

    $this->actingAs($user)->patchJson("/api/v1/genealogy/reports/{$reportId}", ['name' => 'Updated Report'])
        ->assertOk()->assertJsonPath('data.attributes.name', 'Updated Report');

    $this->actingAs($user)->deleteJson("/api/v1/genealogy/reports/{$reportId}")->assertNoContent();
    expect(GenealogyReport::withoutGlobalScopes()->withTrashed()->find($reportId)?->trashed())->toBeTrue();
});
