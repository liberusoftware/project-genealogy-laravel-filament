<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\GenealogyCore\Actions\CreateTree;
use Liberu\Genealogy\GenealogyCore\Actions\DeleteTree;
use Liberu\Genealogy\GenealogyCore\Events\TreeDeleted;
use Liberu\Genealogy\GenealogyCore\Http\Middleware\EstablishTeamContext;
use Liberu\Genealogy\GenealogyCore\Models\Tree;
use Liberu\Genealogy\GenealogyCore\Policies\TeamOwnedPolicy;
use Liberu\Genealogy\GenealogyCore\Policies\TreePolicy;
use Liberu\Genealogy\GenealogyCore\Queries\TreeStatistics;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\Relationships\Models\Relationship;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('soft deletes trees, emits TreeDeleted, and rejects cross-team deletion', function (): void {
    Event::fake([TreeDeleted::class]);

    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $otherTeam = Team::factory()->create(['user_id' => User::factory()->create()->id]);

    app(TeamContext::class)->set($team->id);
    $tree = app(CreateTree::class)->execute(['name' => 'Deletable Tree', 'user_id' => $owner->id]);

    app(TeamContext::class)->set($otherTeam->id);
    expect(fn () => app(DeleteTree::class)->execute($tree))
        ->toThrow(InvalidArgumentException::class, 'active team');

    app(TeamContext::class)->set($team->id);
    app(DeleteTree::class)->execute($tree);

    expect(Tree::withTrashed()->find($tree->id)?->trashed())->toBeTrue();
    Event::assertDispatched(TreeDeleted::class, fn (TreeDeleted $event): bool => $event->tree->is($tree));
});

it('enforces TreePolicy and TeamOwnedPolicy across owners, members, cross-team actors, and guests', function (): void {
    $owner = User::factory()->create();
    $otherMember = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $team->users()->attach($otherMember->id, ['role' => 'member', 'status' => 'active']);
    $otherTeam = Team::factory()->create(['user_id' => $outsider->id]);

    app(TeamContext::class)->set($team->id);
    $privateTree = app(CreateTree::class)->execute(['name' => 'Private', 'is_public' => false, 'user_id' => $owner->id]);
    $publicTree = app(CreateTree::class)->execute(['name' => 'Public', 'is_public' => true, 'user_id' => $owner->id]);

    $treePolicy = new TreePolicy();
    $teamPolicy = new TeamOwnedPolicy();

    // TreePolicy: view allows public for everyone, private only for owner
    expect($treePolicy->view(null, $privateTree))->toBeFalse()
        ->and($treePolicy->view(null, $publicTree))->toBeTrue()
        ->and($treePolicy->view($owner, $privateTree))->toBeTrue()
        ->and($treePolicy->view($otherMember, $privateTree))->toBeFalse()
        ->and($treePolicy->view($otherMember, $publicTree))->toBeTrue()
        ->and($treePolicy->manage($owner, $privateTree))->toBeTrue()
        ->and($treePolicy->manage($otherMember, $privateTree))->toBeFalse()
        ->and($treePolicy->manage(null, $publicTree))->toBeFalse();

    // TeamOwnedPolicy: fail-closed when context absent or mismatched
    expect($teamPolicy->viewAny($owner))->toBeTrue()
        ->and($teamPolicy->create($owner))->toBeTrue()
        ->and($teamPolicy->view($otherMember, $privateTree))->toBeTrue()
        ->and($teamPolicy->update($otherMember, $privateTree))->toBeTrue()
        ->and($teamPolicy->delete($otherMember, $privateTree))->toBeTrue()
        ->and($teamPolicy->view(null, $privateTree))->toBeFalse()
        ->and($teamPolicy->view(null, $publicTree))->toBeTrue()
        ->and($teamPolicy->update(null, $publicTree))->toBeFalse();

    app(TeamContext::class)->set($otherTeam->id);
    expect($teamPolicy->view($outsider, $privateTree))->toBeFalse()
        ->and($teamPolicy->update($outsider, $privateTree))->toBeFalse()
        ->and($teamPolicy->delete($outsider, $privateTree))->toBeFalse()
        ->and($teamPolicy->view($outsider, $publicTree))->toBeTrue();

    app(TeamContext::class)->clear();
    expect($teamPolicy->viewAny($owner))->toBeFalse()
        ->and($teamPolicy->create($owner))->toBeFalse()
        ->and($teamPolicy->update($owner, $privateTree))->toBeFalse();
});

it('computes TreeStatistics safely for null root, isolated root, and cyclic graphs', function (): void {
    $team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    app(TeamContext::class)->set($team->id);

    $stats = app(TreeStatistics::class);

    $emptyTree = app(CreateTree::class)->execute(['name' => 'No Root']);
    expect($stats->for($emptyTree))->toBe([
        'total_people' => 0,
        'total_ancestors' => 0,
        'total_descendants' => 0,
        'total_generations' => 0,
    ]);

    $solo = app(CreatePerson::class)->execute(['given_name' => 'Solo']);
    $soloTree = app(CreateTree::class)->execute(['name' => 'Solo Root', 'root_person_id' => $solo->id]);
    expect($stats->for($soloTree))->toBe([
        'total_people' => 1,
        'total_ancestors' => 0,
        'total_descendants' => 0,
        'total_generations' => 0,
    ]);

    // Malformed imported cycle: A -> B -> A must terminate cleanly
    $a = app(CreatePerson::class)->execute(['given_name' => 'CycleA']);
    $b = app(CreatePerson::class)->execute(['given_name' => 'CycleB']);
    Relationship::query()->create(['team_id' => $team->id, 'person_id' => $a->id, 'related_person_id' => $b->id, 'type' => 'parent', 'confidence' => 50]);
    Relationship::query()->create(['team_id' => $team->id, 'person_id' => $b->id, 'related_person_id' => $a->id, 'type' => 'parent', 'confidence' => 50]);

    $cyclicTree = app(CreateTree::class)->execute(['name' => 'Cyclic', 'root_person_id' => $a->id]);
    $cyclicStats = $stats->for($cyclicTree);

    expect($cyclicStats['total_people'])->toBe(2)
        ->and($cyclicStats['total_ancestors'])->toBe(1)
        ->and($cyclicStats['total_descendants'])->toBe(1);
});

it('establishes and cleans up team context and rejects missing, archived, or non-member teams', function (): void {
    $middleware = new EstablishTeamContext();
    $context = app(TeamContext::class);

    // Guest clears stale singleton context
    $context->set('stale-team-id');
    $guestRequest = Request::create('/test', 'GET');
    $guestRequest->setUserResolver(fn () => null);
    $seenDuringGuest = 'not-run';
    $middleware->handle($guestRequest, function () use ($context, &$seenDuringGuest) {
        $seenDuringGuest = $context->current();

        return response('ok');
    });
    expect($seenDuringGuest)->toBeNull()
        ->and($context->current())->toBeNull();

    // User without current team -> 403
    $noTeamUser = User::factory()->create(['current_team_id' => null]);
    $noTeamRequest = Request::create('/test', 'GET');
    $noTeamRequest->setUserResolver(fn () => $noTeamUser);
    expect(fn () => $middleware->handle($noTeamRequest, fn () => response('ok')))
        ->toThrow(HttpException::class);

    // User with archived team -> 403
    $archivedOwner = User::factory()->create();
    $archivedTeam = Team::factory()->create(['user_id' => $archivedOwner->id, 'status' => 'archived']);
    $archivedOwner->forceFill(['current_team_id' => $archivedTeam->id])->save();
    $archivedRequest = Request::create('/test', 'GET');
    $archivedRequest->setUserResolver(fn () => $archivedOwner->fresh());
    expect(fn () => $middleware->handle($archivedRequest, fn () => response('ok')))
        ->toThrow(HttpException::class);

    // User pointing at team they do not belong to -> 403
    $foreignOwner = User::factory()->create();
    $foreignTeam = Team::factory()->create(['user_id' => $foreignOwner->id, 'status' => 'active']);
    $intruder = User::factory()->create(['current_team_id' => $foreignTeam->id]);
    $intruderRequest = Request::create('/test', 'GET');
    $intruderRequest->setUserResolver(fn () => $intruder->fresh());
    expect(fn () => $middleware->handle($intruderRequest, fn () => response('ok')))
        ->toThrow(HttpException::class);

    // Valid active member sets context during request and clears in finally
    $member = User::factory()->create();
    $activeTeam = Team::factory()->create(['user_id' => $member->id, 'status' => 'active']);
    $member->forceFill(['current_team_id' => $activeTeam->id])->save();
    $validRequest = Request::create('/test', 'GET');
    $validRequest->setUserResolver(fn () => $member->fresh());
    $seenDuringValid = null;
    $middleware->handle($validRequest, function () use ($context, &$seenDuringValid) {
        $seenDuringValid = $context->current();

        return response('ok');
    });
    expect($seenDuringValid)->toBe((string) $activeTeam->id)
        ->and($context->current())->toBeNull();
});

it('enforces owner authorization across tree CRUD, visibility, and delete API endpoints', function (): void {
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $team->users()->attach($teammate->id, ['role' => 'member', 'status' => 'active']);
    $owner->forceFill(['current_team_id' => $team->id])->save();
    $teammate->forceFill(['current_team_id' => $team->id])->save();

    $created = $this->actingAs($owner)
        ->postJson('/api/v1/genealogy/genealogy-core', [
            'name' => 'Owner Tree',
            'status' => 'active',
            'identifier' => 'owner-tree',
            'is_public' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Owner Tree');

    $treeId = $created->json('data.id');

    // Non-owner teammate cannot view private tree (404), update (403), change visibility (403), or delete (403)
    $this->actingAs($teammate)->getJson("/api/v1/genealogy/genealogy-core/{$treeId}")->assertNotFound();
    $this->actingAs($teammate)->patchJson("/api/v1/genealogy/genealogy-core/{$treeId}", ['name' => 'Hijacked'])->assertForbidden();
    $this->actingAs($teammate)->patchJson("/api/v1/genealogy/genealogy-core/{$treeId}/visibility", ['is_public' => true])->assertForbidden();
    $this->actingAs($teammate)->deleteJson("/api/v1/genealogy/genealogy-core/{$treeId}")->assertForbidden();

    // Owner can show, update, toggle visibility, and delete
    $this->actingAs($owner)->getJson("/api/v1/genealogy/genealogy-core/{$treeId}")->assertOk();
    $this->actingAs($owner)->patchJson("/api/v1/genealogy/genealogy-core/{$treeId}", ['name' => 'Renamed Tree'])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Renamed Tree');
    $this->actingAs($owner)->patchJson("/api/v1/genealogy/genealogy-core/{$treeId}/visibility", ['is_public' => true])
        ->assertOk()
        ->assertJsonPath('data.attributes.is_public', true);
    $this->actingAs($teammate)->getJson("/api/v1/genealogy/genealogy-core/{$treeId}")->assertOk();

    $this->actingAs($owner)->deleteJson("/api/v1/genealogy/genealogy-core/{$treeId}")->assertOk();
    expect(Tree::withTrashed()->find($treeId)?->trashed())->toBeTrue();
});
