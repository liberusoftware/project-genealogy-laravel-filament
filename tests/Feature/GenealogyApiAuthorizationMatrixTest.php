<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\Collaboration\Actions\CreateCollaborationProposal;
use Liberu\Genealogy\Discovery\Actions\CreateDiscoveryMatch;
use Liberu\Genealogy\Dna\Actions\CreateDnaKit;
use Liberu\Genealogy\Evidence\Actions\CreateEvidenceRecord;
use Liberu\Genealogy\GenealogyCore\Actions\CreateTree;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\ImportExport\Actions\CreateDataTransfer;
use Liberu\Genealogy\Media\Actions\CreateMediaAsset;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\Places\Actions\CreatePlace;
use Liberu\Genealogy\Relationships\Actions\CreateRelationship;
use Liberu\Genealogy\Reports\Actions\CreateGenealogyReport;
use Liberu\Genealogy\Research\Actions\CreateResearchProject;
use Liberu\Genealogy\Timeline\Actions\CreateTimelineEvent;

uses(RefreshDatabase::class);

it('rejects unauthenticated requests with 401 across protected genealogy API endpoints', function (string $method, string $uri): void {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    'people index' => ['GET', '/api/v1/genealogy/people'],
    'people store' => ['POST', '/api/v1/genealogy/people'],
    'relationships index' => ['GET', '/api/v1/genealogy/relationships'],
    'places index' => ['GET', '/api/v1/genealogy/places'],
    'evidence index' => ['GET', '/api/v1/genealogy/evidence'],
    'media index' => ['GET', '/api/v1/genealogy/media'],
    'timeline index' => ['GET', '/api/v1/genealogy/timeline'],
    'research index' => ['GET', '/api/v1/genealogy/research'],
    'reports index' => ['GET', '/api/v1/genealogy/reports'],
    'discovery index' => ['GET', '/api/v1/genealogy/discovery'],
    'dna kits index' => ['GET', '/api/v1/genealogy/dna/kits'],
    'dna matches index' => ['GET', '/api/v1/genealogy/dna/matches'],
    'collaboration index' => ['GET', '/api/v1/genealogy/collaboration'],
    'import-export index' => ['GET', '/api/v1/genealogy/import-export'],
    'genealogy-core store' => ['POST', '/api/v1/genealogy/genealogy-core'],
    'tree-viewer store' => ['POST', '/api/v1/genealogy/tree-viewer'],
]);

it('rejects authenticated users with missing, archived, or non-member teams with 403 across genealogy APIs', function (string $uri): void {
    $noTeamUser = User::factory()->create(['current_team_id' => null]);

    $archivedOwner = User::factory()->create();
    $archivedTeam = Team::factory()->create(['user_id' => $archivedOwner->id, 'status' => 'archived']);
    $archivedOwner->forceFill(['current_team_id' => $archivedTeam->id])->save();

    $foreignTeam = Team::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
    $nonMember = User::factory()->create(['current_team_id' => $foreignTeam->id]);

    $this->actingAs($noTeamUser)->getJson($uri)->assertForbidden();
    $this->actingAs($archivedOwner->fresh())->getJson($uri)->assertForbidden();
    $this->actingAs($nonMember->fresh())->getJson($uri)->assertForbidden();
})->with([
    '/api/v1/genealogy/people',
    '/api/v1/genealogy/relationships',
    '/api/v1/genealogy/places',
    '/api/v1/genealogy/evidence',
    '/api/v1/genealogy/media',
    '/api/v1/genealogy/timeline',
    '/api/v1/genealogy/research',
    '/api/v1/genealogy/reports',
    '/api/v1/genealogy/discovery',
    '/api/v1/genealogy/dna/kits',
    '/api/v1/genealogy/collaboration',
    '/api/v1/genealogy/import-export',
]);

it('prevents cross-team IDOR reads, updates, and deletes across every genealogy API resource', function (): void {
    $victim = User::factory()->create();
    $victimTeam = Team::factory()->create(['user_id' => $victim->id]);
    $victim->forceFill(['current_team_id' => $victimTeam->id])->save();

    $attacker = User::factory()->create();
    $attackerTeam = Team::factory()->create(['user_id' => $attacker->id]);
    $attacker->forceFill(['current_team_id' => $attackerTeam->id])->save();

    app(TeamContext::class)->set($victimTeam->id);
    $p1 = app(CreatePerson::class)->execute(['given_name' => 'Victim1']);
    $p2 = app(CreatePerson::class)->execute(['given_name' => 'Victim2']);
    $tree = app(CreateTree::class)->execute(['name' => 'Secret Tree', 'is_public' => false, 'user_id' => $victim->id]);
    $rel = app(CreateRelationship::class)->execute(['person_id' => $p1->id, 'related_person_id' => $p2->id, 'type' => 'parent']);
    $place = app(CreatePlace::class)->execute(['name' => 'Secret Place']);
    $evidence = app(CreateEvidenceRecord::class)->execute(['name' => 'Secret Evidence', 'kind' => 'source']);
    $media = app(CreateMediaAsset::class)->execute(['name' => 'Secret Photo', 'kind' => 'photograph']);
    $timeline = app(CreateTimelineEvent::class)->execute(['name' => 'Secret Event']);
    $project = app(CreateResearchProject::class)->execute(['name' => 'Secret Research']);
    $report = app(CreateGenealogyReport::class)->execute(['name' => 'Secret Report', 'type' => 'pedigree']);
    $match = app(CreateDiscoveryMatch::class)->execute(['name' => 'Secret Match']);
    $kit = app(CreateDnaKit::class)->execute(['name' => 'Secret Kit']);
    $proposal = app(CreateCollaborationProposal::class)->execute(['title' => 'Secret Proposal', 'proposed_changes' => ['a' => 1]]);
    $transfer = app(CreateDataTransfer::class)->execute(['name' => 'Secret Export', 'format' => 'gedcom', 'direction' => 'export']);
    app(TeamContext::class)->clear();

    $endpoints = [
        "/api/v1/genealogy/genealogy-core/{$tree->id}" => ['PATCH', ['name' => 'Stolen']],
        "/api/v1/genealogy/people/{$p1->id}" => ['PATCH', ['given_name' => 'Stolen']],
        "/api/v1/genealogy/relationships/{$rel->id}" => ['PATCH', ['confidence' => 10]],
        "/api/v1/genealogy/places/{$place->id}" => ['PATCH', ['name' => 'Stolen']],
        "/api/v1/genealogy/evidence/{$evidence->id}" => ['PATCH', ['name' => 'Stolen']],
        "/api/v1/genealogy/media/{$media->id}" => ['PATCH', ['name' => 'Stolen']],
        "/api/v1/genealogy/timeline/{$timeline->id}" => ['PATCH', ['name' => 'Stolen']],
        "/api/v1/genealogy/reports/{$report->id}" => ['PATCH', ['name' => 'Stolen']],
        "/api/v1/genealogy/discovery/{$match->id}" => ['PATCH', ['name' => 'Stolen']],
        "/api/v1/genealogy/dna/kits/{$kit->id}" => ['PATCH', ['name' => 'Stolen']],
        "/api/v1/genealogy/collaboration/proposals/{$proposal->id}" => ['PATCH', ['title' => 'Stolen']],
        "/api/v1/genealogy/import-export/{$transfer->id}" => ['PATCH', ['name' => 'Stolen']],
    ];

    foreach ($endpoints as $uri => [$updateMethod, $payload]) {
        $this->actingAs($attacker)->getJson($uri)->assertNotFound();
        $this->actingAs($attacker)->json($updateMethod, $uri, $payload)->assertNotFound();
        $this->actingAs($attacker)->deleteJson($uri)->assertNotFound();
    }

    // Cross-team research entries under victim project must also 404
    $this->actingAs($attacker)->getJson("/api/v1/genealogy/research/{$project->id}/entries")->assertNotFound();
});

it('returns 404 for nonexistent UUIDs across genealogy API show endpoints', function (string $prefix): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $missingUuid = '01950000-0000-7000-8000-000000000000';
    $this->actingAs($user)->getJson("{$prefix}/{$missingUuid}")->assertNotFound();
})->with([
    '/api/v1/genealogy/genealogy-core',
    '/api/v1/genealogy/people',
    '/api/v1/genealogy/relationships',
    '/api/v1/genealogy/places',
    '/api/v1/genealogy/evidence',
    '/api/v1/genealogy/media',
    '/api/v1/genealogy/timeline',
    '/api/v1/genealogy/reports',
    '/api/v1/genealogy/discovery',
    '/api/v1/genealogy/dna/kits',
    '/api/v1/genealogy/import-export',
    '/api/v1/genealogy/tree-viewer',
]);
