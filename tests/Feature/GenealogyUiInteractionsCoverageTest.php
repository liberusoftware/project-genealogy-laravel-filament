<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\Collaboration\Filament\Resources\CollaborationSpaceResource\Pages\CreateCollaborationSpace;
use Liberu\Genealogy\Collaboration\Filament\Resources\CollaborationSpaceResource\Pages\EditCollaborationSpace;
use Liberu\Genealogy\Collaboration\Livewire\CollaborationDiscussionBoard;
use Liberu\Genealogy\Collaboration\Models\CollaborationDiscussion;
use Liberu\Genealogy\Collaboration\Models\CollaborationSpace;
use Liberu\Genealogy\Dna\Filament\Resources\DnaKitResource\Pages\CreateDnaKit;
use Liberu\Genealogy\Dna\Filament\Resources\DnaKitResource\Pages\EditDnaKit;
use Liberu\Genealogy\Dna\Models\DnaKit;
use Liberu\Genealogy\Evidence\Livewire\EvidenceEditor;
use Liberu\Genealogy\Evidence\Models\EvidenceRecord;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\Places\Filament\Resources\PlaceResource\Pages\CreatePlace;
use Liberu\Genealogy\Places\Filament\Resources\PlaceResource\Pages\EditPlace;
use Liberu\Genealogy\Places\Livewire\PlaceEditor;
use Liberu\Genealogy\Places\Models\Place;
use Liberu\Genealogy\Reports\Filament\Resources\GenealogyReportResource\Pages\CreateGenealogyReport;
use Liberu\Genealogy\Reports\Filament\Resources\GenealogyReportResource\Pages\EditGenealogyReport;
use Liberu\Genealogy\Reports\Models\GenealogyReport;
use Liberu\Genealogy\Research\Filament\Resources\ResearchProjectResource\Pages\CreateResearchProject;
use Liberu\Genealogy\Research\Filament\Resources\ResearchProjectResource\Pages\EditResearchProject;
use Liberu\Genealogy\Research\Livewire\ResearchProjectEditor;
use Liberu\Genealogy\Research\Models\ResearchProject;
use Liberu\Platform\BusinessWorkflowReconciliation\Filament\Resources\ReconciliationCaseResource;
use Liberu\Platform\ExecutiveInsights\Filament\Resources\InsightSnapshotResource;
use Liberu\Platform\PlatformOrchestration\Filament\Resources\PlatformWorkflowResource;
use Liberu\Platform\RevenueAndCareOrchestration\Filament\Resources\CarePlanResource;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function phase6UiActor(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    app(TeamContext::class)->set($team->id);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    return [$user, $team];
}

it('creates and validates places with historical names through PlaceEditor Livewire component', function (): void {
    [$user] = phase6UiActor();

    // Validation rejects out-of-range latitude and missing name
    Livewire::actingAs($user)
        ->test(PlaceEditor::class)
        ->set('name', '')
        ->set('latitude', '120')
        ->call('save')
        ->assertHasErrors(['name', 'latitude']);

    Livewire::actingAs($user)
        ->test(PlaceEditor::class)
        ->set('name', 'York')
        ->set('jurisdiction', 'city')
        ->set('latitude', '53.9591')
        ->set('longitude', '-1.0815')
        ->set('historicalName', 'Eboracum')
        ->set('status', 'active')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('place-created');

    $place = Place::query()->where('name', 'York')->firstOrFail();
    expect($place->names()->where('name', 'Eboracum')->exists())->toBeTrue();
});

it('creates evidence records, research projects, and collaboration discussions interactively via Livewire components', function (): void {
    [$user] = phase6UiActor();

    // EvidenceEditor validation + creation
    Livewire::actingAs($user)
        ->test(EvidenceEditor::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name'])
        ->set('name', '1861 Parish Burial')
        ->set('kind', 'citation')
        ->set('confidence', 85)
        ->set('status', 'active')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('evidence-record-created');
    expect(EvidenceRecord::query()->where('name', '1861 Parish Burial')->exists())->toBeTrue();

    // ResearchProjectEditor create + update
    Livewire::actingAs($user)
        ->test(ResearchProjectEditor::class)
        ->set('name', 'Cornwall Tin Miners')
        ->set('status', 'active')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('research-project-created');
    $project = ResearchProject::query()->where('name', 'Cornwall Tin Miners')->firstOrFail();

    Livewire::actingAs($user)
        ->test(ResearchProjectEditor::class, ['projectId' => (string) $project->id])
        ->assertSet('name', 'Cornwall Tin Miners')
        ->set('name', 'Cornwall Mining Families')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('research-project-updated');
    expect($project->fresh()->name)->toBe('Cornwall Mining Families');

    // CollaborationDiscussionBoard post + render
    Livewire::actingAs($user)
        ->test(CollaborationDiscussionBoard::class)
        ->set('body', '')
        ->call('post')
        ->assertHasErrors(['body'])
        ->set('body', 'Confirmed 1861 parish register entry.')
        ->call('post')
        ->assertHasNoErrors()
        ->assertDispatched('collaboration-discussion-created')
        ->assertSee('Confirmed 1861 parish register entry.');
    expect(CollaborationDiscussion::query()->where('body', 'Confirmed 1861 parish register entry.')->exists())->toBeTrue();
});

it('exercises Create and Edit Filament pages across Place, ResearchProject, Report, DnaKit, and CollaborationSpace resources', function (): void {
    [$user] = phase6UiActor();
    $this->actingAs($user);

    // PlaceResource Create & Edit
    Livewire::actingAs($user)
        ->test(CreatePlace::class)
        ->fillForm(['name' => 'Bristol', 'status' => 'active'])
        ->call('create')
        ->assertHasNoFormErrors();
    $place = Place::query()->where('name', 'Bristol')->firstOrFail();
    Livewire::actingAs($user)
        ->test(EditPlace::class, ['record' => (string) $place->id])
        ->fillForm(['jurisdiction' => 'county'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($place->fresh()->jurisdiction)->toBe('county');

    // ResearchProjectResource Create & Edit
    Livewire::actingAs($user)
        ->test(CreateResearchProject::class)
        ->fillForm(['name' => 'Somerset Wills', 'status' => 'active'])
        ->call('create')
        ->assertHasNoFormErrors();
    $project = ResearchProject::query()->where('name', 'Somerset Wills')->firstOrFail();
    Livewire::actingAs($user)
        ->test(EditResearchProject::class, ['record' => (string) $project->id])
        ->fillForm(['status' => 'completed'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($project->fresh()->status)->toBe('completed');

    // GenealogyReportResource Create & Edit
    Livewire::actingAs($user)
        ->test(CreateGenealogyReport::class)
        ->fillForm(['name' => 'Bristol Pedigree', 'type' => 'pedigree', 'status' => 'draft'])
        ->call('create')
        ->assertHasNoFormErrors();
    $report = GenealogyReport::query()->where('name', 'Bristol Pedigree')->firstOrFail();
    Livewire::actingAs($user)
        ->test(EditGenealogyReport::class, ['record' => (string) $report->id])
        ->fillForm(['status' => 'active'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($report->fresh()->status)->toBe('active');

    // DnaKitResource Create & Edit
    Livewire::actingAs($user)
        ->test(CreateDnaKit::class)
        ->fillForm(['name' => 'Maternal Kit', 'provider' => 'ancestry', 'consent_status' => 'pending', 'status' => 'draft'])
        ->call('create')
        ->assertHasNoFormErrors();
    $kit = DnaKit::query()->where('name', 'Maternal Kit')->firstOrFail();
    Livewire::actingAs($user)
        ->test(EditDnaKit::class, ['record' => (string) $kit->id])
        ->fillForm(['status' => 'active'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($kit->fresh()->status)->toBe('active');

    // CollaborationSpaceResource Create & Edit
    Livewire::actingAs($user)
        ->test(CreateCollaborationSpace::class)
        ->fillForm(['name' => 'West Country Group', 'status' => 'active'])
        ->call('create')
        ->assertHasNoFormErrors();
    $space = CollaborationSpace::query()->where('name', 'West Country Group')->firstOrFail();
    Livewire::actingAs($user)
        ->test(EditCollaborationSpace::class, ['record' => (string) $space->id])
        ->fillForm(['status' => 'completed'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($space->fresh()->status)->toBe('completed');
});

it('exposes valid Filament Resource definitions, pages, and models for all four Liberu platform orchestration packages', function (string $resourceClass): void {
    expect(class_exists($resourceClass))->toBeTrue()
        ->and($resourceClass::getModel())->toBeString()->not->toBeEmpty()
        ->and($resourceClass::getPages())->toHaveKeys(['index', 'create', 'edit']);
})->with([
    'ReconciliationCaseResource' => [ReconciliationCaseResource::class],
    'InsightSnapshotResource' => [InsightSnapshotResource::class],
    'PlatformWorkflowResource' => [PlatformWorkflowResource::class],
    'CarePlanResource' => [CarePlanResource::class],
]);
