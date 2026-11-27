<?php

use App\Models\User;
use Filament\Events\TenantSet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;
use Liberu\Foundation\Organizations\Actions\Jetstream\AddTeamMember;
use Liberu\Foundation\Organizations\Actions\Jetstream\CreateTeam;
use Liberu\Foundation\Organizations\Actions\Jetstream\DeleteTeam;
use Liberu\Foundation\Organizations\Actions\Jetstream\InviteTeamMember;
use Liberu\Foundation\Organizations\Actions\Jetstream\RemoveTeamMember;
use Liberu\Foundation\Organizations\Actions\Jetstream\UpdateTeamName;
use Liberu\Foundation\Organizations\Contracts\TeamJobNotifier;
use Liberu\Foundation\Organizations\Exceptions\TeamImportException;
use Liberu\Foundation\Organizations\Exceptions\TeamRestoreException;
use Liberu\Foundation\Organizations\Jobs\GenerateTeamBackup;
use Liberu\Foundation\Organizations\Jobs\ImportTeamBackup;
use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
use Liberu\Foundation\Organizations\Listeners\AssignDefaultTeamRole;
use Liberu\Foundation\Organizations\Listeners\CreatePersonalTeam;
use Liberu\Foundation\Organizations\Listeners\SwitchTeam as OrganizationsSwitchTeam;
use Liberu\Foundation\Organizations\Models\Membership;
use Liberu\Foundation\Organizations\Models\Organization;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Models\TeamInvitation;
use Liberu\Foundation\Organizations\Models\TeamOnboarding;
use Liberu\Foundation\Organizations\Models\TeamSubscription;
use Liberu\Foundation\Organizations\Services\ImportTeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamBackupService;
use Liberu\Foundation\Organizations\Services\TeamCloneService;
use Liberu\Foundation\Organizations\Services\TeamManagementService;
use Liberu\Foundation\Organizations\Services\TeamRestoreService;
use Liberu\Foundation\RolesPermissions\Enums\Role;
use Liberu\Foundation\Tenancy\Listeners\SwitchTeam as TenancySwitchTeam;
use Liberu\Genealogy\Collaboration\Models\CollaborationAttribution;
use Liberu\Genealogy\Collaboration\Models\CollaborationWatch;
use Liberu\Genealogy\Collaboration\Models\VirtualEvent;
use Liberu\Genealogy\Collaboration\Models\VirtualEventAttendee;
use Liberu\Genealogy\Discovery\Models\SocialConnectionPrivacy;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\Media\Actions\CorrectMediaTranscription;
use Liberu\Genealogy\Media\Models\MediaAsset;
use Liberu\Genealogy\Media\Models\MediaLink;
use Liberu\Genealogy\Media\Models\MediaTranscriptionCorrection;
use Liberu\Genealogy\Media\Queries\MediaLibrary;
use Liberu\Genealogy\Research\Models\UserChecklist;
use Liberu\Genealogy\Research\Models\UserChecklistItem;

uses(RefreshDatabase::class);

it('exercises Jetstream team lifecycle actions with authorization and validation rules', function (): void {
    Mail::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    // CreateTeam
    $createAction = new CreateTeam();
    expect(fn () => $createAction->create($owner, ['name' => '']))->toThrow(ValidationException::class);

    $team = $createAction->create($owner, ['name' => 'Tudor Archives']);
    expect($team->name)->toBe('Tudor Archives')
        ->and($team->personal_team)->toBeTrue()
        ->and((int) $owner->fresh()->current_team_id)->toBe((int) $team->id);

    // UpdateTeamName
    $updateAction = new UpdateTeamName();
    expect(fn () => $updateAction->update($outsider, $team, ['name' => 'Hijacked']))->toThrow(AuthorizationException::class);
    $updateAction->update($owner, $team, ['name' => 'Plantagenet Archives']);
    expect($team->fresh()->name)->toBe('Plantagenet Archives');

    // AddTeamMember
    $addAction = new AddTeamMember();
    expect(fn () => $addAction->add($owner, $team, 'missing@example.com', 'editor'))->toThrow(ValidationException::class);
    $addAction->add($owner, $team, $member->email, 'editor');
    expect($team->fresh()->hasUser($member))->toBeTrue();
    expect(fn () => $addAction->add($owner, $team->fresh(), $member->email, 'editor'))->toThrow(ValidationException::class);

    // InviteTeamMember
    $inviteAction = new InviteTeamMember();
    $inviteAction->invite($owner, $team->fresh(), 'guest.researcher@example.com', 'editor');
    Mail::assertSent(TeamInvitationMail::class);
    expect($team->teamInvitations()->where('email', 'guest.researcher@example.com')->exists())->toBeTrue();
    expect(fn () => $inviteAction->invite($owner, $team->fresh(), 'guest.researcher@example.com', 'editor'))->toThrow(ValidationException::class);
    expect(fn () => $inviteAction->invite($owner, $team->fresh(), $member->email, 'editor'))->toThrow(ValidationException::class);

    // RemoveTeamMember
    $removeAction = new RemoveTeamMember();
    expect(fn () => $removeAction->remove($outsider, $team->fresh(), $member))->toThrow(AuthorizationException::class);
    expect(fn () => $removeAction->remove($owner, $team->fresh(), $owner))->toThrow(ValidationException::class);
    $removeAction->remove($owner, $team->fresh(), $member);
    expect($team->fresh()->hasUser($member))->toBeFalse();

    // DeleteTeam
    (new DeleteTeam())->delete($team->fresh());
    $this->assertDatabaseMissing('teams', ['id' => $team->id]);
});

it('manages team membership, switching, roles, and listeners via TeamManagementService', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $service = app(TeamManagementService::class);

    expect($service->defaultMemberRole())->toBeInstanceOf(Role::class);

    $personalTeam = $service->createPersonalTeamForUser($owner);
    expect($personalTeam->personal_team)->toBeTrue()
        ->and((int) $personalTeam->user_id)->toBe((int) $owner->id);

    $service->assignUserToTeam($member, $personalTeam);
    expect($personalTeam->fresh()->hasUser($member))->toBeTrue();

    // switchTeam succeeds for members and throws for non-members
    $service->switchTeam($member, $personalTeam);
    expect((int) $member->fresh()->current_team_id)->toBe((int) $personalTeam->id);
    expect(fn () => $service->switchTeam($outsider, $personalTeam))->toThrow(Exception::class);

    // Listeners: CreatePersonalTeam, AssignDefaultTeamRole, SwitchTeam (organizations + tenancy)
    $freshUser = User::factory()->create();
    (new CreatePersonalTeam($service))->handle(new Registered($freshUser));
    expect($freshUser->fresh()->current_team_id)->not->toBeNull();

    (new AssignDefaultTeamRole($service))->handle(new TeamMemberAdded($personalTeam, $member));

    setPermissionsTeamId(0);
    (new OrganizationsSwitchTeam())->handle(new TenantSet($personalTeam, $owner));
    expect((int) getPermissionsTeamId())->toBe((int) $personalTeam->id);

    setPermissionsTeamId(0);
    (new TenancySwitchTeam())->handle(new TenantSet($personalTeam, $owner));
    expect((int) getPermissionsTeamId())->toBe((int) $personalTeam->id);
});

it('executes TeamBackupService, TeamRestoreService, ImportTeamBackupService, TeamCloneService, and backup jobs', function (): void {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id, 'name' => 'Backup Source Team']);

    // Direct TeamBackupService
    $backupService = app(TeamBackupService::class);
    $result = $backupService->backup($team, 'local');
    expect($result['size'])->toBeGreaterThan(0)
        ->and(Storage::disk('local')->exists($result['path']))->toBeTrue();

    // GenerateTeamBackup job
    $backupRow = TeamBackup::create([
        'team_id' => $team->id,
        'disk' => 'local',
        'status' => 'pending',
        'created_by' => $owner->id,
    ]);
    (new GenerateTeamBackup($backupRow->id))->handle($backupService);
    $backupRow->refresh();
    expect($backupRow->status)->toBe('completed')
        ->and($backupRow->path)->not->toBeNull()
        ->and($backupRow->size_bytes)->toBeGreaterThan(0)
        ->and($backupRow->team->id)->toBe($team->id)
        ->and($backupRow->creator->id)->toBe($owner->id);

    // TeamRestoreService guards: incomplete backup & missing file
    $pendingBackup = TeamBackup::create([
        'team_id' => $team->id,
        'disk' => 'local',
        'status' => 'pending',
    ]);
    $restoreService = app(TeamRestoreService::class);
    expect(fn () => $restoreService->restore($pendingBackup))->toThrow(TeamRestoreException::class);

    $missingBackup = TeamBackup::create([
        'team_id' => $team->id,
        'disk' => 'local',
        'status' => 'completed',
        'path' => 'team-backups/does-not-exist.zip',
    ]);
    expect(fn () => $restoreService->restore($missingBackup))->toThrow(TeamRestoreException::class);

    // Bind a spy TeamJobNotifier (organizations-teams-filament's DB notifier expects a
    // `notifications` table that is not migrated in this app; documented in Phase 6 report).
    $notifierSpy = new class() implements TeamJobNotifier
    {
        /** @var list<array{title: string, body: string, success: bool}> */
        public array $messages = [];

        public function notify(Model $user, string $title, string $body, bool $success): void
        {
            $this->messages[] = ['title' => $title, 'body' => $body, 'success' => $success];
        }
    };
    app()->instance(TeamJobNotifier::class, $notifierSpy);

    // RestoreTeamBackup job against empty team succeeds cleanly
    (new RestoreTeamBackup($backupRow->id, $owner->id))->handle($restoreService);
    expect($notifierSpy->messages)->toHaveCount(1)
        ->and($notifierSpy->messages[0]['title'])->toBe('Restore complete')
        ->and($notifierSpy->messages[0]['success'])->toBeTrue();

    // ImportTeamBackupService guard + ImportTeamBackup job round-trip
    $importService = app(ImportTeamBackupService::class);
    expect(fn () => $importService->import(sys_get_temp_dir().'/nonexistent-backup-xyz.zip', 'Bad', $owner))
        ->toThrow(TeamImportException::class);

    (new ImportTeamBackup('local', (string) $backupRow->path, 'Imported Heritage Team', $owner->id))->handle($importService);
    $this->assertDatabaseHas('teams', [
        'name' => 'Imported Heritage Team',
        'user_id' => $owner->id,
    ]);
    expect($notifierSpy->messages)->toHaveCount(2)
        ->and($notifierSpy->messages[1]['title'])->toBe('Import complete')
        ->and($notifierSpy->messages[1]['success'])->toBeTrue();

    // TeamCloneService
    $cloned = app(TeamCloneService::class)->clone($team, 'Cloned Heritage Team', $owner);
    expect($cloned->name)->toBe('Cloned Heritage Team')
        ->and($cloned->id)->not->toBe($team->id)
        ->and($cloned->personal_team)->toBeFalse();
});

it('exercises Organization, TeamSubscription, TeamOnboarding, TeamInvitation, and Membership models', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);

    $org = new Organization([
        'name' => 'Liberu Genealogical Society',
        'slug' => 'liberu-genealogical-society',
        'owner_id' => $owner->id,
        'status' => 'active',
        'archived_at' => now(),
    ]);
    expect($org->name)->toBe('Liberu Genealogical Society')
        ->and($org->archived_at)->toBeInstanceOf(Carbon::class)
        ->and($org->teams())->toBeInstanceOf(HasMany::class);

    $activeSub = new TeamSubscription([
        'team_id' => $team->id,
        'stripe_status' => 'active',
        'trial_ends_at' => now()->addDays(7),
        'ends_at' => now()->addDays(30),
    ]);
    expect($activeSub->onTrial())->toBeTrue()
        ->and($activeSub->hasExpired())->toBeFalse()
        ->and($activeSub->isActive())->toBeTrue()
        ->and($activeSub->cancelAt())->toBeTrue();

    $expiredSub = new TeamSubscription([
        'team_id' => $team->id,
        'stripe_status' => 'active',
        'ends_at' => now()->subDay(),
    ]);
    expect($expiredSub->hasExpired())->toBeTrue()
        ->and($expiredSub->isActive())->toBeFalse()
        ->and($expiredSub->cancelAt())->toBeFalse();

    $onboarding = new TeamOnboarding([
        'team_id' => $team->id,
        'completed_at' => now(),
    ]);
    expect($onboarding->completed_at)->toBeInstanceOf(Carbon::class);

    $invitation = $team->teamInvitations()->create([
        'email' => 'invitee@example.com',
        'role' => 'editor',
        'token_hash' => hash('sha256', 'secret-token'),
        'expires_at' => now()->addDays(3),
    ]);
    expect($invitation)->toBeInstanceOf(TeamInvitation::class)
        ->and($invitation->team->id)->toBe($team->id)
        ->and($invitation->toArray())->not->toHaveKey('token_hash');

    $membership = new Membership();
    expect($membership->incrementing)->toBeTrue();
});

it('exercises VirtualEvent, VirtualEventAttendee, CollaborationAttribution, CollaborationWatch, MediaLink, MediaTranscriptionCorrection, UserChecklist, and SocialConnectionPrivacy models', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $this->actingAs($user);
    app(TeamContext::class)->set($team->id);

    $event = VirtualEvent::create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'title' => 'Annual Clan Reunion',
        'status' => 'published',
        'start_time' => now()->addMinutes(5),
        'end_time' => now()->addHours(2),
        'max_attendees' => 1,
    ]);

    expect($event->canJoin())->toBeTrue()
        ->and($event->isAtCapacity())->toBeFalse()
        ->and(VirtualEvent::published()->upcoming()->whereKey($event->id)->exists())->toBeTrue();

    $attendee = VirtualEventAttendee::create([
        'virtual_event_id' => $event->id,
        'user_id' => $user->id,
        'guest_name' => 'Cousin Clara',
        'guest_email' => 'clara@example.com',
    ]);
    expect($attendee->invitation_token)->toHaveLength(32)
        ->and($attendee->virtualEvent->id)->toBe($event->id);

    $attendee->maybe('Checking travel');
    expect($attendee->fresh()->rsvp_status)->toBe('maybe');

    $attendee->decline('Flight delayed');
    expect($attendee->fresh()->rsvp_status)->toBe('declined');

    $attendee->accept('Joining online');
    expect($attendee->fresh()->rsvp_status)->toBe('accepted')
        ->and($event->fresh()->isAtCapacity())->toBeTrue()
        ->and(VirtualEventAttendee::accepted()->whereKey($attendee->id)->exists())->toBeTrue();

    $attendee->markAsAttended(['duration_minutes' => 45, 'client' => 'web']);
    expect($attendee->fresh()->attended)->toBeTrue()
        ->and(VirtualEventAttendee::attended()->whereKey($attendee->id)->exists())->toBeTrue();

    // CollaborationAttribution & CollaborationWatch
    $attr = CollaborationAttribution::create([
        'team_id' => $team->id,
        'actor_id' => $user->id,
        'attributable_type' => VirtualEvent::class,
        'attributable_id' => $event->id,
        'action' => 'created',
        'metadata' => ['source' => 'ui'],
        'created_at' => now(),
    ]);
    expect($attr->metadata)->toBe(['source' => 'ui']);

    $watch = CollaborationWatch::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'watchable_type' => VirtualEvent::class,
        'watchable_id' => $event->id,
    ]);
    expect($watch->exists)->toBeTrue();

    // MediaLink, MediaTranscriptionCorrection, CorrectMediaTranscription, & MediaLibrary
    $asset = MediaAsset::create([
        'team_id' => $team->id,
        'name' => '1891 Census Page',
        'kind' => 'document',
        'storage_disk' => 'local',
        'storage_path' => 'media/census-1891.jpg',
        'mime_type' => 'image/jpeg',
        'byte_size' => 2048,
        'transcription' => 'Willm Smyth',
        'transcription_status' => 'pending',
        'rights_status' => 'public_domain',
        'is_public' => true,
        'status' => 'active',
    ]);

    $link = MediaLink::create([
        'team_id' => $team->id,
        'media_asset_id' => $asset->id,
        'linkable_type' => VirtualEvent::class,
        'linkable_id' => $event->id,
        'role' => 'exhibit',
        'metadata' => ['page' => 4],
    ]);
    expect($link->metadata)->toBe(['page' => 4]);

    $corrector = new CorrectMediaTranscription();
    expect(fn () => $corrector->execute($asset, '   '))->toThrow(InvalidArgumentException::class);

    $correction = $corrector->execute($asset, 'William Smith', (string) $user->id);
    expect($correction)->toBeInstanceOf(MediaTranscriptionCorrection::class)
        ->and($correction->asset->id)->toBe($asset->id)
        ->and($correction->original_text)->toBe('Willm Smyth')
        ->and($correction->corrected_text)->toBe('William Smith')
        ->and($asset->fresh()->transcription)->toBe('William Smith')
        ->and($asset->fresh()->transcription_status)->toBe('completed');

    $library = (new MediaLibrary())->execute(kind: 'document', term: 'William Smith', publicOnly: true, limit: 10);
    expect($library)->toHaveCount(1)
        ->and($library[0]['name'])->toBe('1891 Census Page')
        ->and($library[0]['is_public'])->toBeTrue();

    // UserChecklist & UserChecklistItem lifecycle
    $checklist = UserChecklist::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'name' => '1881 Census Verification',
        'status' => 'not_started',
        'priority' => 'high',
        'due_date' => now()->subDay(),
    ]);
    expect($checklist->completion_percentage)->toBe(0.0)
        ->and($checklist->is_overdue)->toBeTrue()
        ->and(UserChecklist::active()->overdue()->whereKey($checklist->id)->exists())->toBeTrue();

    $item1 = UserChecklistItem::create([
        'checklist_id' => $checklist->id,
        'title' => 'Locate parish register',
        'sort_order' => 1,
        'is_completed' => false,
    ]);
    $item2 = UserChecklistItem::create([
        'checklist_id' => $checklist->id,
        'title' => 'Transcribe entry',
        'sort_order' => 2,
        'is_completed' => false,
    ]);

    $item1->markAsCompleted(15);
    expect($checklist->fresh()->status)->toBe('in_progress')
        ->and($checklist->fresh()->completion_percentage)->toBe(50.0)
        ->and(UserChecklistItem::completed()->whereKey($item1->id)->exists())->toBeTrue();

    $item2->markAsCompleted(20);
    expect($checklist->fresh()->status)->toBe('completed')
        ->and($checklist->fresh()->completion_percentage)->toBe(100.0)
        ->and($checklist->fresh()->is_overdue)->toBeFalse();

    $item2->markAsIncomplete();
    expect($checklist->fresh()->status)->toBe('in_progress')
        ->and($checklist->fresh()->completion_percentage)->toBe(50.0);

    // SocialConnectionPrivacy
    $privacy = SocialConnectionPrivacy::create([
        'user_id' => $user->id,
        'allow_family_discovery' => true,
        'show_profile_to_matches' => false,
        'share_tree_with_matches' => true,
        'allow_contact_from_matches' => false,
        'blocked_users' => [999],
    ]);
    expect($privacy->user->id)->toBe($user->id)
        ->and($privacy->allow_family_discovery)->toBeTrue()
        ->and($privacy->blocked_users)->toBe([999]);

    app(TeamContext::class)->clear();
});
