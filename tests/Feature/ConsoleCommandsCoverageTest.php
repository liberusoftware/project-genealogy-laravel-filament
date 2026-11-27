<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Liberu\Foundation\Organizations\Console\Commands\BackupTeam;
use Liberu\Foundation\Organizations\Console\Commands\CloneTeam;
use Liberu\Foundation\Organizations\Console\Commands\ImportTeam;
use Liberu\Foundation\Organizations\Console\Commands\RestoreTeam;
use Liberu\Foundation\Organizations\Jobs\GenerateTeamBackup;
use Liberu\Foundation\Organizations\Jobs\RestoreTeamBackup;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamBackup;
use Liberu\Foundation\Organizations\Services\TeamBackupService;

uses(RefreshDatabase::class);

it('runs foundation:doctor and module-manager Artisan commands cleanly', function (): void {
    $this->artisan('foundation:doctor')->assertSuccessful();

    $this->artisan('module:list')->assertSuccessful();
    $this->artisan('module:list', ['--json' => true])->assertSuccessful();

    $this->artisan('module:features')->assertSuccessful();
    $this->artisan('module:features', ['query' => 'genealogy'])->assertSuccessful();

    $this->artisan('module:status', ['name' => 'genealogy-core'])->assertSuccessful();
    $this->artisan('module:status', ['name' => 'definitely-nonexistent-module-xyz'])->assertFailed();

    $this->artisan('module:validate')->assertSuccessful();

    // Cache and clear into a temporary path so bootstrap/cache is never dirtied
    $tmpCache = sys_get_temp_dir().DIRECTORY_SEPARATOR.'liberu_modules_test_cache_'.uniqid('', true).'.php';
    config()->set('modules.cache_path', $tmpCache);

    try {
        $this->artisan('module:cache')->assertSuccessful();
        expect(is_file($tmpCache))->toBeTrue();

        $this->artisan('module:clear')->assertSuccessful();
        expect(is_file($tmpCache))->toBeFalse();
    } finally {
        @unlink($tmpCache);
    }
});

it('runs theme-support and search Artisan commands cleanly', function (): void {
    $this->artisan('theme:validate')->assertSuccessful();

    $tmpThemeCache = sys_get_temp_dir().DIRECTORY_SEPARATOR.'liberu_themes_test_cache_'.uniqid('', true).'.php';
    config()->set('theme.cache_path', $tmpThemeCache);

    try {
        $this->artisan('theme:cache')->assertSuccessful();
        expect(is_file($tmpThemeCache))->toBeTrue();

        $this->artisan('theme:clear')->assertSuccessful();
        expect(is_file($tmpThemeCache))->toBeFalse();
    } finally {
        @unlink($tmpThemeCache);
    }

    $this->artisan('search:reindex')->assertSuccessful();
    $this->artisan('search:reindex', ['type' => 'unknown-entity-xyz'])->assertFailed();
});

it('runs organizations-teams backup, restore, import, and clone Artisan commands', function (): void {
    Queue::fake();
    Storage::fake('local');

    // Note: OrganizationsServiceProvider::boot() does not currently call $this->commands([...])
    // for these four commands (documented as a Phase 6 finding in reports/16-phase6-final-coverage-report.md).
    // Register them on the console kernel so their command handlers are thoroughly verified.
    $kernel = app(Kernel::class);
    foreach ([BackupTeam::class, RestoreTeam::class, ImportTeam::class, CloneTeam::class] as $commandClass) {
        $kernel->registerCommand(app($commandClass));
    }

    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id, 'name' => 'CLI Source Team']);

    // team:backup
    $this->artisan('team:backup', ['team' => 999999])->assertFailed();
    $this->artisan('team:backup', ['team' => $team->id])->assertSuccessful();
    Queue::assertPushed(GenerateTeamBackup::class);

    // team:restore
    $backup = TeamBackup::create([
        'team_id' => $team->id,
        'disk' => 'local',
        'status' => 'completed',
        'path' => 'team-backups/sample.zip',
    ]);
    $this->artisan('team:restore', ['backup' => 999999])->assertFailed();
    $this->artisan('team:restore', ['backup' => $backup->id])->assertSuccessful();
    Queue::assertPushed(RestoreTeamBackup::class);

    // team:clone
    $this->artisan('team:clone', ['source' => 999999, '--name' => 'Ghost'])->assertFailed();
    $this->artisan('team:clone', ['source' => $team->id, '--name' => 'Bad Owner', '--owner' => 999999])->assertFailed();
    $this->artisan('team:clone', ['source' => $team->id, '--name' => 'CLI Cloned Team', '--owner' => $owner->id])
        ->assertSuccessful();
    $this->assertDatabaseHas('teams', ['name' => 'CLI Cloned Team', 'user_id' => $owner->id]);

    // team:import
    $this->artisan('team:import', ['path' => sys_get_temp_dir().'/missing-cli-import.zip'])->assertFailed();

    $exported = app(TeamBackupService::class)->backup($team, 'local');
    $localZip = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cli_import_'.uniqid('', true).'.zip';
    file_put_contents($localZip, (string) Storage::disk('local')->get($exported['path']));

    try {
        $this->artisan('team:import', [
            'path' => $localZip,
            '--name' => 'CLI Imported Team',
            '--owner' => $owner->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('teams', ['name' => 'CLI Imported Team', 'user_id' => $owner->id]);
    } finally {
        @unlink($localZip);
    }
});
