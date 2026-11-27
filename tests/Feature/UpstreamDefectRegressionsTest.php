<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\Collaboration\Livewire\CollaborationDiscussionBoard;
use Liberu\Genealogy\Collaboration\Livewire\CollaborationInvitationList;
use Liberu\Genealogy\Collaboration\Livewire\CollaborationProposalEditor;
use Liberu\Genealogy\Collaboration\Livewire\CollaborationProposalList;
use Liberu\Genealogy\Collaboration\Livewire\CollaborationWatchList;
use Liberu\Genealogy\Collaboration\Models\CollaborationSpace;
use Liberu\Genealogy\Dna\Livewire\DnaAnnotationList;
use Liberu\Genealogy\Dna\Livewire\DnaConsentList;
use Liberu\Genealogy\Dna\Livewire\DnaGroupList;
use Liberu\Genealogy\Dna\Livewire\DnaKitComparison;
use Liberu\Genealogy\Dna\Livewire\DnaMatchList;
use Liberu\Genealogy\Dna\Livewire\DnaProviderList;
use Liberu\Genealogy\Dna\Livewire\DnaSegmentList;
use Liberu\Genealogy\Evidence\Livewire\CitationLinks;
use Liberu\Genealogy\Evidence\Livewire\EvidenceEntityEditor;
use Liberu\Genealogy\Evidence\Livewire\EvidenceEntityList;
use Liberu\Genealogy\Evidence\Models\Citation;
use Liberu\Genealogy\Evidence\Models\Source;
use Liberu\Genealogy\GenealogyCore\Livewire\Components\TreeManager;
use Liberu\Genealogy\GenealogyCore\Models\Tree;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\Media\Filament\Resources\MediaAssetResource\Pages\CreateMediaAsset;
use Liberu\Genealogy\Media\Filament\Resources\MediaAssetResource\Pages\EditMediaAsset;
use Liberu\Genealogy\Media\Models\MediaAsset;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\People\Livewire\PersonDetails;
use Liberu\Genealogy\Relationships\Actions\CreateRelationship;
use Liberu\Genealogy\Relationships\Filament\Pages\RelationshipCalculator;
use Liberu\Genealogy\Research\Models\ResearchProject;
use Liberu\Genealogy\Timeline\Actions\CreateTimelineEvent as CreateTimelineEventAction;
use Liberu\Genealogy\Timeline\Filament\Resources\TimelineEventResource\Pages\CreateTimelineEvent;
use Liberu\Genealogy\Timeline\Filament\Resources\TimelineEventResource\Pages\EditTimelineEvent;
use Liberu\Genealogy\Timeline\Models\TimelineEvent;
use Liberu\Genealogy\TreeViewer\Livewire\TreeGraphView;
use Liberu\Genealogy\TreeViewer\Livewire\TreeViewList;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function phase5Actor(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return [$user, $team];
}

// =========================================================================
// Defect A: Timeline API strict boolean query parameter parsing
// =========================================================================
it('parses boolean include_private query parameters cleanly across Timeline API index, events, and conflicts', function (): void {
    [$user, $team] = phase5Actor();

    app(TeamContext::class)->set($team->id);
    $person = app(CreatePerson::class)->execute(['given_name' => 'TimelineSubject']);
    app(CreateTimelineEventAction::class)->execute([
        'name' => 'Public Birth',
        'subject_person_id' => $person->id,
        'event_date' => '1880-01-01',
        'conflict_group' => 'birth',
        'is_private' => false,
    ]);
    app(CreateTimelineEventAction::class)->execute([
        'name' => 'Private Birth',
        'subject_person_id' => $person->id,
        'event_date' => '1881-01-01',
        'conflict_group' => 'birth',
        'is_private' => true,
    ]);
    app(TeamContext::class)->clear();

    // Truthy values: 1 and true
    foreach (['1', 'true'] as $truthy) {
        $this->actingAs($user)->getJson("/api/v1/genealogy/timeline?include_private={$truthy}")
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->actingAs($user)->getJson("/api/v1/genealogy/timeline/events?subject_person_id={$person->id}&include_private={$truthy}")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($user)->getJson("/api/v1/genealogy/timeline/conflicts?subject_person_id={$person->id}&include_private={$truthy}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // Falsy values: 0 and false
    foreach (['0', 'false'] as $falsy) {
        $this->actingAs($user)->getJson("/api/v1/genealogy/timeline?include_private={$falsy}")
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->getJson("/api/v1/genealogy/timeline/events?subject_person_id={$person->id}&include_private={$falsy}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson("/api/v1/genealogy/timeline/conflicts?subject_person_id={$person->id}&include_private={$falsy}")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // Invalid boolean query values rejected with 422
    $this->actingAs($user)->getJson('/api/v1/genealogy/timeline?include_private=banana')
        ->assertStatus(422)->assertJsonValidationErrors(['include_private']);
    $this->actingAs($user)->getJson("/api/v1/genealogy/timeline/events?subject_person_id={$person->id}&include_private=yes")
        ->assertStatus(422)->assertJsonValidationErrors(['include_private']);
    $this->actingAs($user)->getJson("/api/v1/genealogy/timeline/conflicts?subject_person_id={$person->id}&include_private=2")
        ->assertStatus(422)->assertJsonValidationErrors(['include_private']);
});

// =========================================================================
// Defect B: Collaboration API implicit model binding ({collaboration} -> $record)
// =========================================================================
it('binds CollaborationSpace records cleanly on show, update, delete, missing 404, and cross-team isolation', function (): void {
    [$owner] = phase5Actor();
    [$outsider] = phase5Actor();

    $spaceId = $this->actingAs($owner)->postJson('/api/v1/genealogy/collaboration', [
        'name' => 'Sussex Parish Research',
        'status' => 'active',
    ])->assertCreated()->json('data.id');

    $this->actingAs($owner)->getJson("/api/v1/genealogy/collaboration/{$spaceId}")
        ->assertOk()
        ->assertJsonPath('data.id', $spaceId)
        ->assertJsonPath('data.attributes.name', 'Sussex Parish Research');

    // Cross-team isolation -> 404
    $this->actingAs($outsider)->getJson("/api/v1/genealogy/collaboration/{$spaceId}")->assertNotFound();
    $this->actingAs($outsider)->patchJson("/api/v1/genealogy/collaboration/{$spaceId}", ['name' => 'Hijack'])->assertNotFound();
    $this->actingAs($outsider)->deleteJson("/api/v1/genealogy/collaboration/{$spaceId}")->assertNotFound();

    // Nonexistent UUID -> 404
    $this->actingAs($owner)->getJson('/api/v1/genealogy/collaboration/01950000-0000-7000-8000-000000000000')->assertNotFound();

    // Update and delete succeed
    $this->actingAs($owner)->patchJson("/api/v1/genealogy/collaboration/{$spaceId}", ['name' => 'Sussex Renamed', 'status' => 'completed'])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Sussex Renamed')
        ->assertJsonPath('data.attributes.status', 'completed');

    $this->actingAs($owner)->deleteJson("/api/v1/genealogy/collaboration/{$spaceId}")->assertNoContent();
    expect(CollaborationSpace::withoutGlobalScopes()->withTrashed()->find($spaceId)?->trashed())->toBeTrue();
});

// =========================================================================
// Defect C: Research API implicit model binding ({research} -> $record)
// =========================================================================
it('binds ResearchProject records cleanly on show, update, delete, missing 404, and cross-team isolation', function (): void {
    [$owner] = phase5Actor();
    [$outsider] = phase5Actor();

    $projectId = $this->actingAs($owner)->postJson('/api/v1/genealogy/research', [
        'name' => 'Devon Probate Project',
        'status' => 'active',
    ])->assertCreated()->json('data.id');

    $this->actingAs($owner)->getJson("/api/v1/genealogy/research/{$projectId}")
        ->assertOk()
        ->assertJsonPath('data.id', $projectId)
        ->assertJsonPath('data.attributes.name', 'Devon Probate Project');

    // Cross-team isolation -> 404
    $this->actingAs($outsider)->getJson("/api/v1/genealogy/research/{$projectId}")->assertNotFound();
    $this->actingAs($outsider)->patchJson("/api/v1/genealogy/research/{$projectId}", ['name' => 'Hijack'])->assertNotFound();
    $this->actingAs($outsider)->deleteJson("/api/v1/genealogy/research/{$projectId}")->assertNotFound();

    // Nonexistent UUID -> 404
    $this->actingAs($owner)->getJson('/api/v1/genealogy/research/01950000-0000-7000-8000-000000000000')->assertNotFound();

    // Update and delete succeed
    $this->actingAs($owner)->patchJson("/api/v1/genealogy/research/{$projectId}", ['name' => 'Devon Probate Completed', 'status' => 'completed'])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Devon Probate Completed')
        ->assertJsonPath('data.attributes.status', 'completed');

    $this->actingAs($owner)->deleteJson("/api/v1/genealogy/research/{$projectId}")->assertNoContent();
    expect(ResearchProject::withoutGlobalScopes()->withTrashed()->find($projectId)?->trashed())->toBeTrue();
});

// =========================================================================
// Defect D: Liberu Platform API Rule::enum(LifecycleStatus::class) validation
// =========================================================================
it('validates LifecycleStatus enum values on POST and PATCH across all four Liberu platform APIs', function (string $prefix): void {
    [$user] = phase5Actor();

    // Invalid status on POST -> 422
    $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'invalid-'.md5($prefix))
        ->postJson($prefix, ['name' => 'Bad Status', 'status' => 'not-a-valid-status'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    // Valid status on POST -> 201
    $created = $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'valid-'.md5($prefix))
        ->postJson($prefix, ['name' => 'Valid Draft', 'status' => 'draft'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');
    $id = $created->json('data.id');

    // Invalid status on PATCH -> 422
    $this->actingAs($user)
        ->patchJson("{$prefix}/{$id}", ['status' => 'bogus'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    // Null status on PATCH -> 422 (string + enum rule does not permit null)
    $this->actingAs($user)
        ->patchJson("{$prefix}/{$id}", ['status' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    // Valid transition draft -> active on PATCH -> 200
    $this->actingAs($user)
        ->patchJson("{$prefix}/{$id}", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
})->with([
    'business-workflow-reconciliation' => ['/api/v1/liberu/business-workflow-reconciliation'],
    'executive-insights' => ['/api/v1/liberu/executive-insights'],
    'platform-orchestration' => ['/api/v1/liberu/platform-orchestration'],
    'revenue-and-care-orchestration' => ['/api/v1/liberu/revenue-and-care-orchestration'],
]);

// =========================================================================
// Defect E: Filament Form Component Imports (MediaAssetResource & TimelineEventResource)
// =========================================================================
it('renders and submits MediaAssetResource and TimelineEventResource Create and Edit Filament forms', function (): void {
    [$user, $team] = phase5Actor();
    app(TeamContext::class)->set($team->id);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $this->actingAs($user);

    // MediaAsset Create & Edit
    Livewire::actingAs($user)
        ->test(CreateMediaAsset::class)
        ->assertOk()
        ->fillForm([
            'name' => '1911 Census Scan',
            'kind' => 'document',
            'status' => 'active',
            'transcription_status' => 'not_started',
            'is_public' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $media = MediaAsset::query()->where('name', '1911 Census Scan')->firstOrFail();

    Livewire::actingAs($user)
        ->test(EditMediaAsset::class, ['record' => (string) $media->id])
        ->assertOk()
        ->fillForm(['transcription' => 'Transcribed line 1'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($media->fresh()->transcription)->toBe('Transcribed line 1');

    // TimelineEvent Create & Edit
    Livewire::actingAs($user)
        ->test(CreateTimelineEvent::class)
        ->assertOk()
        ->fillForm([
            'name' => '1911 Census Enumeration',
            'kind' => TimelineEvent::KINDS[0],
            'status' => TimelineEvent::STATUSES[0],
            'date_precision' => TimelineEvent::DATE_PRECISIONS[0],
            'event_date' => '1911-04-02',
            'is_private' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = TimelineEvent::query()->where('name', '1911 Census Enumeration')->firstOrFail();

    Livewire::actingAs($user)
        ->test(EditTimelineEvent::class, ['record' => (string) $event->id])
        ->assertOk()
        ->fillForm(['description' => 'Updated historical note'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($event->fresh()->description)->toBe('Updated historical note');
});

// =========================================================================
// Defect F: RelationshipCalculator Filament Page view namespace registration
// =========================================================================
it('renders and calculates relationships on the RelationshipCalculator Filament page', function (): void {
    [$user, $team] = phase5Actor();
    app(TeamContext::class)->set($team->id);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $this->actingAs($user);

    $parent = app(CreatePerson::class)->execute(['given_name' => 'CalcParent']);
    $child = app(CreatePerson::class)->execute(['given_name' => 'CalcChild']);
    app(CreateRelationship::class)->execute(['person_id' => $parent->id, 'related_person_id' => $child->id, 'type' => 'parent']);

    Livewire::actingAs($user)
        ->test(RelationshipCalculator::class)
        ->assertOk()
        ->set('firstPersonId', (string) $parent->id)
        ->set('secondPersonId', (string) $parent->id)
        ->call('calculate')
        ->assertHasErrors(['secondPersonId'])
        ->set('secondPersonId', (string) $child->id)
        ->call('calculate')
        ->assertHasNoErrors()
        ->assertSet('result.relationship', 'parent')
        ->assertSee('Relationship: parent');
});

// =========================================================================
// Defect G: Livewire 4 module component aliases across all 6 packages (17 components)
// =========================================================================
it('resolves and exercises TreeManager and PersonDetails Livewire components by class and canonical alias', function (): void {
    [$user, $team] = phase5Actor();
    app(TeamContext::class)->set($team->id);

    Livewire::actingAs($user)
        ->test(TreeManager::class)
        ->assertOk()
        ->set('name', 'Alias Repaired Tree')
        ->set('identifier', 'alias-repaired')
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('tree-created')
        ->assertSee('Alias Repaired Tree');

    Livewire::actingAs($user)
        ->test('genealogy-core-tree-manager')
        ->assertOk()
        ->assertSee('Alias Repaired Tree');

    $tree = Tree::query()->where('identifier', 'alias-repaired')->firstOrFail();
    Livewire::actingAs($user)
        ->test(TreeManager::class)
        ->call('delete', (string) $tree->id)
        ->assertDispatched('tree-deleted');

    $person = app(CreatePerson::class)->execute(['given_name' => 'Emmeline', 'family_name' => 'Pankhurst']);

    Livewire::actingAs($user)
        ->test(PersonDetails::class, ['personId' => (string) $person->id])
        ->assertOk()
        ->assertSee('Emmeline');

    Livewire::actingAs($user)
        ->test('genealogy-people-details', ['personId' => (string) $person->id])
        ->assertOk()
        ->assertSee('Emmeline');
});

it('resolves CitationLinks Livewire component by class and flat alias with a real citation', function (): void {
    [$user, $team] = phase5Actor();
    app(TeamContext::class)->set($team->id);

    $source = Source::query()->create(['team_id' => $team->id, 'name' => 'Parish Register']);
    $citation = Citation::query()->create(['team_id' => $team->id, 'source_id' => $source->id, 'title' => '1891 Entry']);

    Livewire::actingAs($user)->test(CitationLinks::class, ['citationId' => (string) $citation->id])->assertOk();
    Livewire::actingAs($user)->test('genealogy-evidence-citation-links', ['citationId' => (string) $citation->id])->assertOk();
});

it('resolves all previously broken Genealogy Livewire components by both class name and flat alias in Livewire 4', function (string $class, string $alias): void {
    [$user, $team] = phase5Actor();
    app(TeamContext::class)->set($team->id);

    Livewire::actingAs($user)->test($class)->assertOk();
    Livewire::actingAs($user)->test($alias)->assertOk();
})->with([
    'EvidenceEntityList' => [EvidenceEntityList::class, 'genealogy-evidence-entity-list'],
    'EvidenceEntityEditor' => [EvidenceEntityEditor::class, 'genealogy-evidence-entity-editor'],
    'DnaKitComparison' => [DnaKitComparison::class, 'genealogy-dna-kit-comparison'],
    'DnaMatchList' => [DnaMatchList::class, 'genealogy-dna-match-list'],
    'DnaGroupList' => [DnaGroupList::class, 'genealogy-dna-group-list'],
    'DnaAnnotationList' => [DnaAnnotationList::class, 'genealogy-dna-annotation-list'],
    'DnaSegmentList' => [DnaSegmentList::class, 'genealogy-dna-segment-list'],
    'DnaConsentList' => [DnaConsentList::class, 'genealogy-dna-consent-list'],
    'DnaProviderList' => [DnaProviderList::class, 'genealogy-dna-provider-list'],
    'CollaborationProposalList' => [CollaborationProposalList::class, 'genealogy-collaboration-proposal-list'],
    'CollaborationProposalEditor' => [CollaborationProposalEditor::class, 'genealogy-collaboration-proposal-editor'],
    'CollaborationInvitationList' => [CollaborationInvitationList::class, 'genealogy-collaboration-invitation-list'],
    'CollaborationDiscussionBoard' => [CollaborationDiscussionBoard::class, 'genealogy-collaboration-discussion-board'],
    'CollaborationWatchList' => [CollaborationWatchList::class, 'genealogy-collaboration-watch-list'],
    'TreeViewList' => [TreeViewList::class, 'genealogy-tree-viewer-list'],
    'TreeGraphView' => [TreeGraphView::class, 'genealogy-tree-viewer-graph'],
]);

// =========================================================================
// Defect H: TreeViewApiController::store() consistent top-level `data` wrapper
// =========================================================================
it('returns a consistent top-level data wrapper across TreeViewApiController store, show, and update', function (): void {
    [$owner, $team] = phase5Actor();
    $teammate = User::factory()->create();
    $team->users()->attach($teammate->id, ['role' => 'member', 'status' => 'active']);
    $teammate->forceFill(['current_team_id' => $team->id])->save();

    // Validation failure -> 422
    $this->actingAs($owner)->postJson('/api/v1/genealogy/tree-viewer', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    // Store returns 201 with top-level `data` envelope
    $stored = $this->actingAs($owner)->postJson('/api/v1/genealogy/tree-viewer', [
        'name' => 'Consistent Envelope Tree',
        'status' => 'active',
        'is_public' => false,
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'genealogy-tree-viewer')
        ->assertJsonPath('data.attributes.name', 'Consistent Envelope Tree')
        ->assertJsonPath('data.attributes.status', 'active')
        ->assertJsonMissingPath('id');

    $id = $stored->json('data.id');
    expect($id)->toBeString()->not->toBeEmpty();

    // Show & Update return identical top-level `data` envelope structure
    $this->actingAs($owner)->getJson("/api/v1/genealogy/tree-viewer/{$id}")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.type', 'genealogy-tree-viewer')
        ->assertJsonPath('data.attributes.name', 'Consistent Envelope Tree');

    $this->actingAs($owner)->patchJson("/api/v1/genealogy/tree-viewer/{$id}", ['name' => 'Consistent Updated'])
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.attributes.name', 'Consistent Updated');

    // Non-owner authorization preserved
    $this->actingAs($teammate)->patchJson("/api/v1/genealogy/tree-viewer/{$id}", ['name' => 'Blocked'])->assertForbidden();
});
