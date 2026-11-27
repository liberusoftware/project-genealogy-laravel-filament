<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\Collaboration\Actions\DeleteCollaborationSpace;
use Liberu\Genealogy\Collaboration\Models\CollaborationDiscussion;
use Liberu\Genealogy\Collaboration\Models\CollaborationProposal;
use Liberu\Genealogy\Collaboration\Models\CollaborationSpace;
use Liberu\Genealogy\Dna\Models\DnaKit;
use Liberu\Genealogy\Dna\Models\DnaSegment;
use Liberu\Genealogy\Dna\Services\SegmentMatcher;
use Liberu\Genealogy\Gamification\Actions\AwardPoints;
use Liberu\Genealogy\Gamification\Models\UserPoint;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\ImportExport\Actions\UndoDataTransfer;
use Liberu\Genealogy\ImportExport\Models\DataTransfer;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\People\Models\Person;

uses(RefreshDatabase::class);

it('enforces SegmentMatcher SNP, cM, mismatch tolerance, and autosomal chromosome boundaries', function (): void {
    $matcher = new SegmentMatcher();

    // Helper to build N SNPs spanning spanMb megabases on a chromosome
    $buildChr = function (int $count, float $spanMb, string $genotype = 'AA'): array {
        $step = (int) floor(($spanMb * 1_000_000) / max(1, $count - 1));
        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $data[1_000_000 + ($i * $step)] = $genotype;
        }

        return $data;
    };

    // 1. Below MIN_SNPS (499 SNPs across 10 Mb) -> no segment
    $tooFewSnps = $buildChr(499, 10.0, 'AG');
    expect($matcher->match(['1' => $tooFewSnps], ['1' => $tooFewSnps])['shared_segments_count'])->toBe(0);

    // 2. Below MIN_CM (500 SNPs across 6.5 Mb) -> no segment
    $tooShortCm = $buildChr(500, 6.5, 'AG');
    expect($matcher->match(['1' => $tooShortCm], ['1' => $tooShortCm])['shared_segments_count'])->toBe(0);

    // 3. Valid segment (520 SNPs across 10 Mb) with 2 consecutive mismatches tolerated (< MISMATCH_TOLERANCE=3)
    $chrA = $buildChr(520, 10.0, 'AA');
    $chrB = $buildChr(520, 10.0, 'AG'); // half-identical ('A')
    $keys = array_keys($chrB);
    $chrB[$keys[250]] = 'TT';
    $chrB[$keys[251]] = 'TT';
    $tolerated = $matcher->match(['1' => $chrA, 'X' => $chrA], ['1' => $chrB, 'X' => $chrB]);
    expect($tolerated['shared_segments_count'])->toBe(1)
        ->and($tolerated['shared_segments'][0]['chromosome'])->toBe('1')
        ->and($tolerated['total_shared_cm'])->toBeGreaterThanOrEqual(7.0);

    // 4. Three consecutive mismatches (=== MISMATCH_TOLERANCE) breaks a 600-SNP run in half (300 each < 500) -> 0 segments
    $chrC = $buildChr(600, 12.0, 'AA');
    $chrD = $buildChr(600, 12.0, 'AG');
    $keysD = array_keys($chrD);
    $chrD[$keysD[300]] = 'TT';
    $chrD[$keysD[301]] = 'TT';
    $chrD[$keysD[302]] = 'TT';
    expect($matcher->match(['2' => $chrC], ['2' => $chrD])['shared_segments_count'])->toBe(0);
});

it('supports DNA kit revocation, match segment update/delete, annotations CRUD, and cross-team validation', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $otherTeam = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    app(TeamContext::class)->set($otherTeam->id);
    $foreignKit = DnaKit::query()->create(['team_id' => $otherTeam->id, 'name' => 'Foreign Kit', 'status' => 'active']);
    app(TeamContext::class)->set($team->id);
    $person = app(CreatePerson::class)->execute(['given_name' => 'Tester']);
    app(TeamContext::class)->clear();

    $kitId = $this->actingAs($user)->postJson('/api/v1/genealogy/dna/kits', [
        'name' => 'Primary Kit',
        'provider' => 'ancestry',
        'person_id' => $person->id,
    ])->assertCreated()->json('data.id');

    $this->actingAs($user)->postJson("/api/v1/genealogy/dna/kits/{$kitId}/consent", [
        'scope' => 'matching',
        'policy_version' => 'v1',
    ])->assertCreated();

    $this->actingAs($user)->getJson("/api/v1/genealogy/dna/kits/{$kitId}/consents")
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($user)->postJson("/api/v1/genealogy/dna/kits/{$kitId}/revoke", [
        'reason' => 'Participant withdrew consent',
    ])->assertOk()
        ->assertJsonPath('data.attributes.consent_status', 'revoked')
        ->assertJsonPath('data.attributes.revocation_reason', 'Participant withdrew consent');

    $matchId = $this->actingAs($user)->postJson('/api/v1/genealogy/dna/matches', [
        'kit_id' => $kitId,
        'external_id' => 'MATCH-100',
        'display_name' => 'Cousin Match',
        'total_cm' => 45.2,
    ])->assertCreated()->json('data.id');

    $segmentId = $this->actingAs($user)->postJson("/api/v1/genealogy/dna/matches/{$matchId}/segments", [
        'chromosome' => 7,
        'start_position' => 1_000_000,
        'end_position' => 15_000_000,
        'centimorgans' => 14.0,
        'snps' => 800,
    ])->assertCreated()->json('data.id');

    $this->actingAs($user)->patchJson("/api/v1/genealogy/dna/segments/{$segmentId}", [
        'centimorgans' => 15.5,
        'side' => 'maternal',
    ])->assertOk()->assertJsonPath('data.attributes.side', 'maternal');

    $this->actingAs($user)->deleteJson("/api/v1/genealogy/dna/segments/{$segmentId}")->assertNoContent();
    expect(DnaSegment::withoutGlobalScopes()->find($segmentId))->toBeNull();

    // Notes: cross-team kit rejected with 422; valid kit note created & deleted
    $this->actingAs($user)->postJson('/api/v1/genealogy/dna/notes', [
        'noteable_type' => DnaKit::class,
        'noteable_id' => $foreignKit->id,
        'body' => 'Cross-team attempt',
    ])->assertStatus(422);

    $noteId = $this->actingAs($user)->postJson('/api/v1/genealogy/dna/notes', [
        'noteable_type' => DnaKit::class,
        'noteable_id' => $kitId,
        'body' => 'Verified maternal kit',
    ])->assertCreated()->json('data.id');
    $this->actingAs($user)->getJson('/api/v1/genealogy/dna/notes')->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($user)->deleteJson("/api/v1/genealogy/dna/notes/{$noteId}")->assertNoContent();

    // DNA relationship annotation CRUD
    $dnaRelId = $this->actingAs($user)->postJson('/api/v1/genealogy/dna/relationships', [
        'match_id' => $matchId,
        'person_id' => $person->id,
        'relationship_type' => '2nd cousin',
        'confidence' => 82,
    ])->assertCreated()->json('data.id');
    $this->actingAs($user)->getJson('/api/v1/genealogy/dna/relationships')->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($user)->deleteJson("/api/v1/genealogy/dna/relationships/{$dnaRelId}")->assertNoContent();
});

it('exercises collaboration invitations revoke, memberships role update, discussions CRUD, watches, proposals, and attributions over HTTP', function (): void {
    $owner = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'collab-invitee@example.com']);
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $owner->forceFill(['current_team_id' => $team->id])->save();

    $spaceId = $this->actingAs($owner)->postJson('/api/v1/genealogy/collaboration', [
        'name' => 'Kent Research Space',
        'status' => 'active',
    ])->assertCreated()->json('data.id');

    // Invite & revoke
    $revokableInvite = $this->actingAs($owner)->postJson('/api/v1/genealogy/collaboration/invitations', [
        'space_id' => $spaceId,
        'email' => 'revoked@example.com',
        'role' => 'contributor',
    ])->assertCreated()->json('data.id');

    $this->actingAs($owner)->postJson("/api/v1/genealogy/collaboration/invitations/{$revokableInvite}/revoke")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'revoked');

    // Invite & accept -> membership role update
    $acceptedInvite = $this->actingAs($owner)->postJson('/api/v1/genealogy/collaboration/invitations', [
        'space_id' => $spaceId,
        'email' => $invitee->email,
        'role' => 'viewer',
    ])->assertCreated()->json('data.id');

    $membershipId = $this->actingAs($invitee)
        ->postJson("/api/v1/genealogy/collaboration/invitations/{$acceptedInvite}/accept")
        ->assertOk()
        ->json('data.id');

    $this->actingAs($owner)->patchJson("/api/v1/genealogy/collaboration/memberships/{$membershipId}", ['role' => 'reviewer'])
        ->assertOk()
        ->assertJsonPath('data.attributes.role', 'reviewer');

    // Discussions CRUD
    $discId = $this->actingAs($owner)->postJson('/api/v1/genealogy/collaboration/discussions', [
        'space_id' => $spaceId,
        'body' => 'Check parish records from 1841',
    ])->assertCreated()->json('data.id');

    $this->actingAs($owner)->patchJson("/api/v1/genealogy/collaboration/discussions/{$discId}", ['status' => 'resolved'])
        ->assertOk()->assertJsonPath('data.attributes.status', 'resolved');
    $this->actingAs($owner)->deleteJson("/api/v1/genealogy/collaboration/discussions/{$discId}")->assertNoContent();
    expect(CollaborationDiscussion::withoutGlobalScopes()->withTrashed()->find($discId)?->trashed())->toBeTrue();

    // Watch toggle on & off
    $this->actingAs($owner)->postJson('/api/v1/genealogy/collaboration/watches/toggle', [
        'watchable_type' => 'genealogy_space',
        'watchable_id' => $spaceId,
    ])->assertOk()->assertJsonPath('data.attributes.watchable_id', $spaceId);
    $this->actingAs($owner)->getJson('/api/v1/genealogy/collaboration/watches')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($owner)->postJson('/api/v1/genealogy/collaboration/watches/toggle', [
        'watchable_type' => 'genealogy_space',
        'watchable_id' => $spaceId,
    ])->assertOk()->assertJsonPath('data', null);

    // Proposals CRUD + review + attributions index
    $proposalId = $this->actingAs($owner)->postJson('/api/v1/genealogy/collaboration/proposals', [
        'space_id' => $spaceId,
        'title' => 'Add 1841 birth record',
        'proposed_changes' => ['birth_year' => 1841],
    ])->assertCreated()->json('data.id');

    $this->actingAs($owner)->postJson("/api/v1/genealogy/collaboration/proposals/{$proposalId}/review", ['status' => 'approved'])
        ->assertOk()->assertJsonPath('data.attributes.status', 'approved');
    $this->actingAs($owner)->deleteJson("/api/v1/genealogy/collaboration/proposals/{$proposalId}")->assertNoContent();
    expect(CollaborationProposal::withoutGlobalScopes()->withTrashed()->find($proposalId)?->trashed())->toBeTrue();

    $this->actingAs($owner)->getJson('/api/v1/genealogy/collaboration/attributions')->assertOk()->assertJsonStructure(['data', 'meta']);

    // Delete space via domain action (note: CollaborationSpaceController route param {collaboration} mismatches $record binding; documented in Report 06)
    app(TeamContext::class)->set($team->id);
    $space = CollaborationSpace::query()->findOrFail($spaceId);
    app(DeleteCollaborationSpace::class)->execute($space);
    expect(CollaborationSpace::withoutGlobalScopes()->withTrashed()->find($spaceId)?->trashed())->toBeTrue();
    app(TeamContext::class)->clear();
});

it('previews, imports, undoes GEDCOM transfers, exports Gramps XML, and rejects expired undo windows', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $gedcom = implode("\n", [
        '0 HEAD',
        '1 SOUR TEST',
        '1 GEDC',
        '2 VERS 5.5.1',
        '0 @I1@ INDI',
        '1 NAME Clara /Barton/',
        '1 SEX F',
        '1 BIRT',
        '2 DATE 25 DEC 1821',
        '0 TRLR',
    ]);

    $this->actingAs($user)->postJson('/api/v1/genealogy/import-export/preview', ['content' => $gedcom])
        ->assertOk()
        ->assertJsonPath('data.format', 'gedcom')
        ->assertJsonPath('data.people', 1);

    $imported = $this->actingAs($user)->postJson('/api/v1/genealogy/import-export/import', [
        'name' => 'Clara Import',
        'content' => $gedcom,
    ])->assertCreated();
    $transferId = $imported->json('transfer.id');

    expect(Person::withoutGlobalScopes()->where('team_id', $team->id)->where('given_name', 'Clara')->exists())->toBeTrue();

    // Gramps XML export
    $exportResponse = $this->actingAs($user)->get('/api/v1/genealogy/import-export/export?format=gramps-xml');
    $exportResponse->assertOk();
    expect($exportResponse->streamedContent())->toContain('Clara');

    // Undo rolls back imported person
    $this->actingAs($user)->postJson("/api/v1/genealogy/import-export/{$transferId}/undo")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'rolled_back');

    expect(Person::withoutGlobalScopes()->where('team_id', $team->id)->where('given_name', 'Clara')->exists())->toBeFalse();

    // Expired undo window rejected directly by UndoDataTransfer
    app(TeamContext::class)->set($team->id);
    $expired = DataTransfer::query()->create([
        'team_id' => $team->id,
        'name' => 'Expired Import',
        'format' => 'gedcom',
        'direction' => 'import',
        'status' => 'completed',
        'metadata' => ['undo' => ['expires_at' => now()->subMinute()->toISOString(), 'created_people' => []]],
    ]);
    expect(fn () => app(UndoDataTransfer::class)->execute($expired))
        ->toThrow(InvalidArgumentException::class, 'expired');
    app(TeamContext::class)->clear();

    // Delete data transfer
    $this->actingAs($user)->deleteJson("/api/v1/genealogy/import-export/{$transferId}")->assertNoContent();
    expect(DataTransfer::withoutGlobalScopes()->withTrashed()->find($transferId)?->trashed())->toBeTrue();
});

it('validates AwardPoints inputs and stores polymorphic related models', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    app(TeamContext::class)->set($team->id);
    $person = app(CreatePerson::class)->execute(['given_name' => 'Milestone']);

    $award = new AwardPoints();

    expect(fn () => $award->execute($user, 'person_created', 0))
        ->toThrow(InvalidArgumentException::class, 'greater than zero');
    expect(fn () => $award->execute($user, 'person_created', -10))
        ->toThrow(InvalidArgumentException::class, 'greater than zero');
    expect(fn () => $award->execute($user, '   ', 25))
        ->toThrow(InvalidArgumentException::class, 'activity type');

    $point = $award->execute($user, '  person_verified  ', 50, 'Verified person record', ['source' => 'census'], $person);

    expect($point)->toBeInstanceOf(UserPoint::class)
        ->and($point->activity_type)->toBe('person_verified')
        ->and($point->points)->toBe(50)
        ->and((string) $point->related_id)->toBe((string) $person->id)
        ->and($point->related_type)->toBe($person->getMorphClass());
});
