<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\LocalizationLivewire\Livewire\LanguageSwitcher;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\ThemeSupportLivewire\Livewire\ThemeSwitcher;
use Liberu\Genealogy\Collaboration\Livewire\CollaborationSpaceList;
use Liberu\Genealogy\Discovery\Livewire\DiscoveryMatchList;
use Liberu\Genealogy\Discovery\Livewire\DiscoverySearch;
use Liberu\Genealogy\Discovery\Livewire\ExternalDiscoverySearch;
use Liberu\Genealogy\Dna\Livewire\DnaKitList;
use Liberu\Genealogy\Evidence\Livewire\EvidenceEditor;
use Liberu\Genealogy\Evidence\Livewire\EvidenceRecordList;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\ImportExport\Livewire\DataTransferExport;
use Liberu\Genealogy\ImportExport\Livewire\DataTransferImport;
use Liberu\Genealogy\ImportExport\Livewire\DataTransferList;
use Liberu\Genealogy\Media\Livewire\MediaAssetList;
use Liberu\Genealogy\Media\Livewire\MediaAssetUpload;
use Liberu\Genealogy\Media\Livewire\MediaFaceReview;
use Liberu\Genealogy\Media\Livewire\MediaLibraryBrowser;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\People\Livewire\PersonDetails;
use Liberu\Genealogy\People\Livewire\PersonSearch;
use Liberu\Genealogy\Places\Livewire\PlaceEditor;
use Liberu\Genealogy\Places\Livewire\PlaceList;
use Liberu\Genealogy\Relationships\Livewire\RelationshipList;
use Liberu\Genealogy\Reports\Livewire\GenealogyReportList;
use Liberu\Genealogy\Research\Livewire\ResearchEntryEditor;
use Liberu\Genealogy\Research\Livewire\ResearchEntryList;
use Liberu\Genealogy\Research\Livewire\ResearchProjectEditor;
use Liberu\Genealogy\Research\Livewire\ResearchProjectList;
use Liberu\Genealogy\Timeline\Livewire\TimelineEventList;
use Liberu\Platform\BusinessWorkflowReconciliation\Livewire\ReconciliationCaseList;
use Liberu\Platform\ExecutiveInsights\Livewire\InsightSnapshotList;
use Liberu\Platform\PlatformOrchestration\Livewire\PlatformWorkflowList;
use Liberu\Platform\RevenueAndCareOrchestration\Livewire\CarePlanList;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('searches team-scoped people in PersonSearch without leaking other teams', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $otherTeam = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    app(TeamContext::class)->set($otherTeam->id);
    app(CreatePerson::class)->execute(['given_name' => 'Secret', 'family_name' => 'Foreigner']);

    app(TeamContext::class)->set($team->id);
    app(CreatePerson::class)->execute(['given_name' => 'Florence', 'family_name' => 'Nightingale']);

    Livewire::actingAs($user)
        ->test(PersonSearch::class)
        ->assertOk()
        ->set('query', 'Nightingale')
        ->assertSee('Florence')
        ->assertDontSee('Secret');
});

it('renders authenticated Genealogy Livewire components cleanly under active team context', function (string $componentClass): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    app(TeamContext::class)->set($team->id);

    Livewire::actingAs($user)
        ->test($componentClass)
        ->assertOk();
})->with([
    'PlaceList' => [PlaceList::class],
    'PlaceEditor' => [PlaceEditor::class],
    'RelationshipList' => [RelationshipList::class],
    'EvidenceRecordList' => [EvidenceRecordList::class],
    'EvidenceEditor' => [EvidenceEditor::class],
    'MediaAssetList' => [MediaAssetList::class],
    'MediaAssetUpload' => [MediaAssetUpload::class],
    'MediaFaceReview' => [MediaFaceReview::class],
    'MediaLibraryBrowser' => [MediaLibraryBrowser::class],
    'TimelineEventList' => [TimelineEventList::class],
    'ResearchProjectList' => [ResearchProjectList::class],
    'ResearchProjectEditor' => [ResearchProjectEditor::class],
    'ResearchEntryList' => [ResearchEntryList::class],
    'ResearchEntryEditor' => [ResearchEntryEditor::class],
    'GenealogyReportList' => [GenealogyReportList::class],
    'DiscoveryMatchList' => [DiscoveryMatchList::class],
    'DiscoverySearch' => [DiscoverySearch::class],
    'ExternalDiscoverySearch' => [ExternalDiscoverySearch::class],
    'DnaKitList' => [DnaKitList::class],
    'CollaborationSpaceList' => [CollaborationSpaceList::class],
    'DataTransferList' => [DataTransferList::class],
    'DataTransferImport' => [DataTransferImport::class],
    'DataTransferExport' => [DataTransferExport::class],
]);

it('renders hyphenated Livewire aliases for Collaboration and TreeViewer components', function (string $alias): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    app(TeamContext::class)->set($team->id);

    Livewire::actingAs($user)
        ->test($alias)
        ->assertOk();
})->with([
    'genealogy-collaboration-list',
    'genealogy-collaboration-proposal-list',
    'genealogy-collaboration-watch-list',
    'genealogy-tree-viewer-list',
]);

it('renders Liberu platform orchestration Livewire components cleanly', function (string $componentClass): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    Livewire::actingAs($user)
        ->test($componentClass)
        ->assertOk();
})->with([
    'ReconciliationCaseList' => [ReconciliationCaseList::class],
    'InsightSnapshotList' => [InsightSnapshotList::class],
    'PlatformWorkflowList' => [PlatformWorkflowList::class],
    'CarePlanList' => [CarePlanList::class],
]);

it('renders and exercises LanguageSwitcher and ThemeSwitcher with safe open-redirect handling', function (): void {
    config()->set('app.supported_locales', ['en' => 'English', 'fr' => 'Français']);
    $user = User::factory()->create(['locale' => 'en']);

    Livewire::actingAs($user)
        ->test(LanguageSwitcher::class)
        ->assertOk()
        ->call('switchLanguage', 'fr')
        ->assertSet('currentLocale', 'fr')
        ->assertRedirect('/');

    expect($user->fresh()->locale)->toBe('fr');

    // Unsupported locale is ignored safely
    Livewire::actingAs($user)
        ->test(LanguageSwitcher::class)
        ->call('switchLanguage', 'xx-invalid')
        ->assertNoRedirect();

    Livewire::actingAs($user)
        ->test(ThemeSwitcher::class)
        ->assertOk()
        ->call('switchTheme', 'default')
        ->assertDispatched('theme-changed', theme: 'default')
        ->assertRedirect('/');
});
