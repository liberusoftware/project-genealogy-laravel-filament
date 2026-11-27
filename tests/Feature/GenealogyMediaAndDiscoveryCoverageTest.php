<?php

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\Discovery\Contracts\ExternalRecordProvider;
use Liberu\Genealogy\Discovery\Models\DiscoveryMatch;
use Liberu\Genealogy\Discovery\Models\SocialFamilyConnection;
use Liberu\Genealogy\Discovery\Services\ExternalRecordMatcher;
use Liberu\Genealogy\Discovery\Services\SocialFamilyDiscovery;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\Media\Contracts\FaceRecognitionProvider;
use Liberu\Genealogy\Media\Contracts\TranscriptionProvider;
use Liberu\Genealogy\Media\Models\MediaAsset;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\Relationships\Actions\CreateRelationship;

uses(RefreshDatabase::class);

it('uploads media, searches library, links assets, runs fake face detection & transcription providers, and deletes assets', function (): void {
    Storage::fake('local');

    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    app(TeamContext::class)->set($team->id);
    $person = app(CreatePerson::class)->execute(['given_name' => 'Portrait', 'family_name' => 'Subject']);
    app(TeamContext::class)->clear();

    $uploaded = $this->actingAs($user)->postJson('/api/v1/genealogy/media/upload', [
        'file' => UploadedFile::fake()->create('family-photo.jpg', 24, 'image/jpeg'),
        'name' => '1920 Family Portrait',
        'kind' => 'photograph',
        'storage_disk' => 'local',
        'is_public' => true,
    ])->assertCreated();
    $assetId = $uploaded->json('data.id');

    // Library query
    $this->actingAs($user)->getJson('/api/v1/genealogy/media/library?kind=photograph&q=Portrait')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Link media to person
    $this->actingAs($user)->postJson("/api/v1/genealogy/media/{$assetId}/links", [
        'linkable_type' => $person->getMorphClass(),
        'linkable_id' => $person->id,
        'role' => 'primary',
    ])->assertCreated()->assertJsonPath('data.role', 'primary');

    // Bind fake FaceRecognitionProvider & TranscriptionProvider
    app()->instance(FaceRecognitionProvider::class, new class implements FaceRecognitionProvider
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function detect(string $absolutePath): array
        {
            return [[
                'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.3],
                'confidence' => 0.94,
                'face_id' => 'face-1',
            ]];
        }
    });

    app()->instance(TranscriptionProvider::class, new class implements TranscriptionProvider
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function transcribe(string $absolutePath): array
        {
            return [
                'text' => 'Family gathering in Kent, 1920',
                'confidence' => 0.91,
                'language' => 'en',
                'metadata' => ['engine' => 'fake-ocr'],
            ];
        }
    });

    $this->actingAs($user)->postJson("/api/v1/genealogy/media/{$assetId}/analyze-faces")
        ->assertOk()
        ->assertJsonPath('data.available', true);

    $faceTags = $this->actingAs($user)->getJson("/api/v1/genealogy/media/{$assetId}/face-tags")
        ->assertOk()
        ->assertJsonCount(1, 'data');
    $tagId = $faceTags->json('data.0.id');

    $this->actingAs($user)->patchJson("/api/v1/genealogy/media/face-tags/{$tagId}", [
        'status' => 'confirmed',
        'person_id' => $person->id,
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'confirmed')
        ->assertJsonPath('data.attributes.person_id', $person->id);

    $this->actingAs($user)->postJson("/api/v1/genealogy/media/{$assetId}/transcribe")
        ->assertOk()
        ->assertJsonPath('data.available', true);

    $this->actingAs($user)->postJson("/api/v1/genealogy/media/{$assetId}/transcription-corrections", [
        'text' => 'Family gathering in Canterbury, Kent, 1920',
    ])->assertCreated()
        ->assertJsonPath('data.attributes.corrected_text', 'Family gathering in Canterbury, Kent, 1920');

    $this->actingAs($user)->patchJson("/api/v1/genealogy/media/{$assetId}", ['rights_status' => 'public_domain'])
        ->assertOk()->assertJsonPath('data.attributes.rights_status', 'public_domain');

    $this->actingAs($user)->deleteJson("/api/v1/genealogy/media/{$assetId}")->assertNoContent();
    expect(MediaAsset::withoutGlobalScopes()->withTrashed()->find($assetId)?->trashed())->toBeTrue();
});

it('exercises discovery search, duplicate scanning, paths, external matcher ranking, and match review over HTTP', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    app(TeamContext::class)->set($team->id);
    $parent = app(CreatePerson::class)->execute(['given_name' => 'William', 'family_name' => 'Shelley', 'birth_date' => '1850-01-01', 'death_date' => '1920-01-01']);
    $child = app(CreatePerson::class)->execute(['given_name' => 'Mary', 'family_name' => 'Shelley', 'birth_date' => '1875-01-01', 'death_date' => '1945-01-01']);
    app(CreatePerson::class)->execute(['given_name' => 'Mary', 'family_name' => 'Shelley', 'birth_date' => '1875-01-01', 'death_date' => '1945-01-01']);
    app(CreateRelationship::class)->execute(['person_id' => $parent->id, 'related_person_id' => $child->id, 'type' => 'parent']);
    app(TeamContext::class)->clear();

    $this->actingAs($user)->getJson('/api/v1/genealogy/discovery/search?q=Shelley')
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->actingAs($user)->getJson('/api/v1/genealogy/discovery/duplicates')
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->actingAs($user)->postJson('/api/v1/genealogy/discovery/duplicates/scan', ['threshold' => 0.5])
        ->assertCreated();

    $this->actingAs($user)->getJson("/api/v1/genealogy/discovery/paths/{$parent->id}/{$child->id}")
        ->assertOk()
        ->assertJsonStructure(['data']);

    // Unconfigured external search returns available=false gracefully
    $this->actingAs($user)->postJson('/api/v1/genealogy/discovery/external-search', [
        'person' => ['first_name' => 'Mary', 'last_name' => 'Shelley', 'birth_year' => 1875],
    ])->assertOk()->assertJsonPath('data.available', false);

    // Configured fake ExternalRecordProvider ranks closer match first
    app()->instance(ExternalRecordProvider::class, new class implements ExternalRecordProvider
    {
        public function key(): string
        {
            return 'fake-archive';
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function search(array $person): array
        {
            return [
                ['first_name' => 'John', 'last_name' => 'Doe', 'birth_year' => 1910, 'birth_place' => 'York'],
                ['first_name' => 'Mary', 'last_name' => 'Shelley', 'birth_year' => 1875, 'birth_place' => 'London'],
            ];
        }
    });

    $this->actingAs($user)->postJson('/api/v1/genealogy/discovery/external-search', [
        'person' => ['first_name' => 'Mary', 'last_name' => 'Shelley', 'birth_year' => 1875, 'birth_place' => 'London'],
    ])->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.provider', 'fake-archive')
        ->assertJsonPath('data.candidates.0.candidate.first_name', 'Mary');

    // Zero-weight guard in ExternalRecordMatcher
    $zeroScored = (new ExternalRecordMatcher())->scoreCandidates(['first_name' => 'Mary'], [['first_name' => 'Mary']], [
        'first_name' => 0.0, 'last_name' => 0.0, 'birth_year' => 0.0, 'birth_place' => 0.0, 'parents' => 0.0,
    ]);
    expect($zeroScored[0]['score'])->toBe(0.0);

    // Match CRUD + review
    $matchId = $this->actingAs($user)->postJson('/api/v1/genealogy/discovery', [
        'name' => 'Possible 1881 Census Match',
        'confidence' => 78,
        'status' => 'draft',
    ])->assertCreated()->json('data.id');

    $this->actingAs($user)->patchJson("/api/v1/genealogy/discovery/{$matchId}", ['confidence' => 88])
        ->assertOk()->assertJsonPath('data.attributes.confidence', 88);
    $this->actingAs($user)->postJson("/api/v1/genealogy/discovery/{$matchId}/review", ['status' => 'completed'])
        ->assertOk()->assertJsonPath('data.attributes.status', 'completed');
    $this->actingAs($user)->deleteJson("/api/v1/genealogy/discovery/{$matchId}")->assertNoContent();
    expect(DiscoveryMatch::withoutGlobalScopes()->withTrashed()->find($matchId)?->trashed())->toBeTrue();
});

it('manages SocialFamilyDiscovery enable, sync cadence, and disable cleanup', function (): void {
    $user = User::factory()->create();
    $account = new ConnectedAccount();
    $account->forceFill([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-social-1',
        'name' => 'Social Tester',
        'email' => 'social@example.com',
        'token' => 'secret-token-value',
        'enable_family_matching' => false,
    ])->save();

    $service = new SocialFamilyDiscovery();

    expect($service->needsSync($account))->toBeFalse()
        ->and($service->sync($account))->toBeFalse();

    expect($service->enable($account))->toBeTrue();
    $account->refresh();

    expect($account->enable_family_matching)->toBeTrue()
        ->and($account->cached_profile_data)->toBeArray()
        ->and($account->last_synced_at)->not->toBeNull()
        ->and($service->needsSync($account))->toBeFalse();

    // After 25 hours, needsSync returns true
    $account->forceFill(['last_synced_at' => now()->subHours(25)])->save();
    expect($service->needsSync($account->fresh()))->toBeTrue();

    SocialFamilyConnection::query()->create([
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'matched_social_id' => 'gh-social-2',
        'matched_name' => 'Relative',
        'matched_email' => 'rel@example.com',
        'relationship_type' => 'potential_relative',
        'confidence_score' => 40,
        'matching_criteria' => ['common_surnames' => ['smith']],
        'status' => 'pending',
    ]);

    expect($service->disable($account))->toBeTrue();
    $account->refresh();

    expect($account->enable_family_matching)->toBeFalse()
        ->and($account->cached_profile_data)->toBeNull()
        ->and($account->last_synced_at)->toBeNull()
        ->and(SocialFamilyConnection::query()->where('connected_account_id', $account->id)->exists())->toBeFalse();
});
