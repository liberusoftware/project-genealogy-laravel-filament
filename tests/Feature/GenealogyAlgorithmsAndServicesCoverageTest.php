<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Genealogy\Discovery\Queries\DuplicateCandidates;
use Liberu\Genealogy\Dna\Actions\GrantDnaConsent;
use Liberu\Genealogy\Dna\Actions\ImportDnaKit;
use Liberu\Genealogy\Dna\Models\DnaKit;
use Liberu\Genealogy\Dna\Services\AnalyzeDnaMatch;
use Liberu\Genealogy\Dna\Services\CompareDnaKits;
use Liberu\Genealogy\Dna\Services\ParseDnaFile;
use Liberu\Genealogy\Dna\Services\RelationshipEstimator;
use Liberu\Genealogy\Dna\Services\TriangulateDna;
use Liberu\Genealogy\Evidence\Models\Source;
use Liberu\Genealogy\GenealogyCore\TeamContext;
use Liberu\Genealogy\People\Actions\CreatePerson;
use Liberu\Genealogy\Relationships\Actions\CreateRelationship;
use Liberu\Genealogy\Reports\Queries\BuildGenealogyReport;
use Liberu\Genealogy\Research\Actions\CreateResearchEntry;
use Liberu\Genealogy\Research\Actions\CreateResearchProject;
use Liberu\Genealogy\Timeline\Actions\CreateTimelineEvent;

uses(RefreshDatabase::class);

it('generates BuildGenealogyReport across all 4 numbering schemes, graph scopes, sections, and output formats', function (): void {
    $team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    app(TeamContext::class)->set($team->id);

    $gp = app(CreatePerson::class)->execute(['given_name' => 'Arthur', 'family_name' => 'Pendragon', 'birth_date' => '1820-01-01', 'death_date' => '1890-01-01']);
    $parent = app(CreatePerson::class)->execute(['given_name' => 'Eleanor', 'family_name' => 'Pendragon', 'birth_date' => '1850-05-01']);
    $partner = app(CreatePerson::class)->execute(['given_name' => 'Thomas', 'family_name' => 'Smith', 'birth_date' => '1848-03-01']);
    $child1 = app(CreatePerson::class)->execute(['given_name' => 'Clara', 'family_name' => 'Smith', 'birth_date' => '1875-01-01']);
    $child2 = app(CreatePerson::class)->execute(['given_name' => 'Edward <Jr>', 'family_name' => 'Smith', 'birth_date' => '1878-06-01']);
    $grandchild = app(CreatePerson::class)->execute(['given_name' => 'Beatrice', 'family_name' => 'Smith', 'birth_date' => '1902-02-02']);

    app(CreateRelationship::class)->execute(['person_id' => $gp->id, 'related_person_id' => $parent->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $parent->id, 'related_person_id' => $partner->id, 'type' => 'partner']);
    app(CreateRelationship::class)->execute(['person_id' => $parent->id, 'related_person_id' => $child1->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $parent->id, 'related_person_id' => $child2->id, 'type' => 'parent']);
    app(CreateRelationship::class)->execute(['person_id' => $child1->id, 'related_person_id' => $grandchild->id, 'type' => 'parent']);

    Source::query()->create(['team_id' => $team->id, 'name' => '1881 Census']);
    $project = app(CreateResearchProject::class)->execute(['name' => 'Pendragon Study']);
    app(CreateResearchEntry::class)->execute(['research_project_id' => $project->id, 'kind' => 'log', 'title' => 'Checked parish index']);
    app(CreateTimelineEvent::class)->execute(['name' => 'Family Migration', 'subject_person_id' => $parent->id, 'event_date' => '1870-01-01']);

    $builder = new BuildGenealogyReport();

    // Pedigree with Ahnentafel numbering (root=1, parent=2, grandparent=4)
    $ahn = $builder->execute('pedigree', (string) $team->id, ['root_person_id' => $child1->id, 'numbering' => 'ahnentafel']);
    $ahnNumbers = collect($ahn['content']['numbered_people'])->pluck('number', 'given_name')->all();
    expect($ahnNumbers)->toBe(['Clara' => '1', 'Eleanor' => '2', 'Arthur' => '4']);

    // Descendants with Henry numbering (root=1, child1=11, grandchild=111, child2=12)
    $henry = $builder->execute('descendants', (string) $team->id, ['root_person_id' => $parent->id, 'numbering' => 'henry']);
    $henryNumbers = collect($henry['content']['numbered_people'])->pluck('number', 'given_name')->all();
    expect($henryNumbers)->toBe(['Eleanor' => '1', 'Clara' => '11', 'Beatrice' => '111', 'Edward <Jr>' => '12']);

    // d'Aboville numbering (root=1, child1=1.1, grandchild=1.1.1, child2=1.2)
    $daboville = $builder->execute('descendants', (string) $team->id, ['root_person_id' => $parent->id, 'numbering' => 'daboville']);
    $dabNumbers = collect($daboville['content']['numbered_people'])->pluck('number', 'given_name')->all();
    expect($dabNumbers)->toBe(['Eleanor' => '1', 'Clara' => '1.1', 'Beatrice' => '1.1.1', 'Edward <Jr>' => '1.2']);

    // de Villiers numbering (root=a1, child1=b1, grandchild=b1c1, child2=b2)
    $dv = $builder->execute('descendants', (string) $team->id, ['root_person_id' => $parent->id, 'numbering' => 'de_villiers']);
    $dvNumbers = collect($dv['content']['numbered_people'])->pluck('number', 'given_name')->all();
    expect($dvNumbers)->toBe(['Eleanor' => 'a1', 'Clara' => 'b1', 'Beatrice' => 'b1c1', 'Edward <Jr>' => 'b2']);

    // Invalid numbering scheme rejected with ValidationException
    expect(fn () => $builder->execute('pedigree', (string) $team->id, ['root_person_id' => $child1->id, 'numbering' => 'invalid']))
        ->toThrow(ValidationException::class);

    // family_group expands partner into result set
    $familyGroup = $builder->execute('family_group', (string) $team->id, ['root_person_id' => $parent->id]);
    expect(collect($familyGroup['content']['people'])->pluck('id')->all())->toContain((string) $partner->id);

    // Non-graph sections: sources, research, timeline
    expect($builder->execute('sources', (string) $team->id)['content']['sources'])->toHaveCount(1)
        ->and($builder->execute('research', (string) $team->id)['content']['projects'])->toHaveCount(1)
        ->and($builder->execute('research', (string) $team->id)['content']['entries'])->toHaveCount(1)
        ->and($builder->execute('timeline', (string) $team->id)['content']['events'])->toHaveCount(1);

    // Output formats: CSV, GEDCOM, and SVG (with XML escaping for `<Jr>`)
    $csv = $builder->execute('descendants', (string) $team->id, ['root_person_id' => $parent->id, 'format' => 'csv']);
    $gedcom = $builder->execute('descendants', (string) $team->id, ['root_person_id' => $parent->id, 'format' => 'gedcom']);
    $svg = $builder->execute('descendants', (string) $team->id, ['root_person_id' => $parent->id, 'format' => 'svg']);

    expect($csv['content'])->toContain('Eleanor')
        ->and($gedcom['content'])->toContain('0 HEAD', '1 NAME Eleanor Pendragon', '0 TRLR')
        ->and($svg['content'])->toContain('<svg ', 'Edward &lt;Jr&gt;')
        ->and($svg['content'])->not->toContain('Edward <Jr>');
});

it('parses consumer DNA export formats, normalizes chromosomes and genotypes, and filters malformed lines', function (): void {
    $parser = new ParseDnaFile();

    // 23andMe tab-separated with chromosome aliases, hemizygous base, unsorted genotype, and malformed lines
    $raw23 = implode("\n", [
        '# This data file generated by 23andMe',
        '# rsid	chromosome	position	genotype',
        "rs1\t1\t1000\tGA",
        "rs2\tchr23\t2000\tA",
        "rs3\t24\t3000\tCT",
        "rs4\t26\t4000\tGG",
        "rs5\tM\t5000\tTT",
        "rs6\t99\t6000\tAA",
        "rs7\t1\tnotanumber\tAA",
        "rs8\t1\t7000\t--",
    ]);

    $parsed23 = $parser->parse($raw23);
    expect($parsed23)->toBe([
        '1' => [1000 => 'AG'],
        'X' => [2000 => 'AA'],
        'Y' => [3000 => 'CT'],
        'MT' => [4000 => 'GG', 5000 => 'TT'],
    ]);

    // AncestryDNA 5-column (separate allele1/allele2 columns)
    $rawAncestry = implode("\n", [
        "rsid\tchromosome\tposition\tallele1\tallele2",
        "rs10\t2\t50000\tT\tC",
        "rs11\t2\t60000\t0\t0",
    ]);
    expect($parser->parse($rawAncestry))->toBe(['2' => [50000 => 'CT']]);

    // MyHeritage CSV format
    $rawMyHeritage = "RSID,Chr,Position,Result\n\"rs20\",\"3\",\"70000\",\"TG\"\n";
    expect($parser->parse($rawMyHeritage))->toBe(['3' => [70000 => 'GT']]);
});

it('estimates DNA relationships across all cM bands and triangulates overlapping 3-way segments', function (): void {
    $estimator = new RelationshipEstimator();

    expect($estimator->estimate(4500.0)['predicted_relationship'])->toBe('Identical Twin / Same Person')
        ->and($estimator->estimate(3450.0)['predicted_relationship'])->toBe('Parent/Child')
        ->and($estimator->estimate(2500.0)['predicted_relationship'])->toBe('Full Sibling')
        ->and($estimator->estimate(1500.0)['predicted_relationship'])->toBe('Grandparent/Aunt/Uncle/Half-sibling')
        ->and($estimator->estimate(700.0)['predicted_relationship'])->toBe('1st Cousin')
        ->and($estimator->estimate(250.0)['predicted_relationship'])->toBe('1st Cousin Once Removed / 2nd Cousin')
        ->and($estimator->estimate(100.0)['predicted_relationship'])->toBe('2nd-3rd Cousin')
        ->and($estimator->estimate(30.0)['predicted_relationship'])->toBe('Distant Cousin')
        ->and($estimator->estimate(5.0)['predicted_relationship'])->toBe(RelationshipEstimator::NO_MATCH_LABEL)
        ->and($estimator->estimate(-10.0)['match_quality_score'])->toBe(0.0)
        ->and($estimator->estimate(9999.0)['match_quality_score'])->toBe(100.0)
        ->and(RelationshipEstimator::labels())->toHaveCount(9);

    $triangulator = new TriangulateDna();
    expect(fn () => $triangulator->execute([], -1.0))->toThrow(InvalidArgumentException::class)
        ->and($triangulator->execute([['id' => 'a', 'segments' => []], ['id' => 'b', 'segments' => []]]))->toBe([]);

    $groups = $triangulator->execute([
        ['id' => 'm1', 'segments' => [['chromosome' => '4', 'start' => 10_000_000, 'end' => 45_000_000]]],
        ['id' => 'm2', 'segments' => [['chromosome' => '4', 'start' => 15_000_000, 'end' => 50_000_000]]],
        ['id' => 'm3', 'segments' => [['chromosome' => '4', 'start' => 20_000_000, 'end' => 42_000_000]]],
        ['id' => 'm4', 'segments' => [['chromosome' => '4', 'start' => 60_000_000, 'end' => 90_000_000]]],
    ], 20.0);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['match_ids'])->toBe(['m1', 'm2', 'm3'])
        ->and($groups[0]['chromosome'])->toBe('4')
        ->and($groups[0]['start'])->toBe(20_000_000)
        ->and($groups[0]['end'])->toBe(42_000_000)
        ->and($groups[0]['centimorgans'])->toBe(22.0);
});

it('enforces CompareDnaKits consent, file, same-kit, and team guards and compares consented kits end-to-end', function (): void {
    Storage::fake('private');

    $teamA = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    $teamB = Team::factory()->create(['user_id' => User::factory()->create()->id]);

    // Build a valid 23andMe raw payload with 550 SNPs spanning 12 Mb on Chr 1
    $header = "# This data file generated by 23andMe\n# rsid\tchromosome\tposition\tgenotype\n";
    $rows = '';
    for ($i = 0; $i < 550; $i++) {
        $pos = 1_000_000 + ($i * 22_000);
        $rows .= "rs{$i}\t1\t{$pos}\tAG\n";
    }
    $dnaContent = $header.$rows;

    app(TeamContext::class)->set($teamA->id);
    $kitA = app(ImportDnaKit::class)->execute($dnaContent, ['name' => 'Kit A', 'provider' => '23andme']);
    $kitB = app(ImportDnaKit::class)->execute($dnaContent, ['name' => 'Kit B', 'provider' => '23andme']);
    $filelessKit = DnaKit::query()->create(['team_id' => $teamA->id, 'name' => 'No File', 'consent_status' => 'granted', 'status' => 'active']);

    app(TeamContext::class)->set($teamB->id);
    $foreignKit = DnaKit::query()->create(['team_id' => $teamB->id, 'name' => 'Foreign', 'consent_status' => 'granted', 'status' => 'active']);

    app(TeamContext::class)->set($teamA->id);
    $compare = app(CompareDnaKits::class);

    // Guards: cross-team, same-kit, unconsented, missing file
    expect(fn () => $compare->execute($kitA, $foreignKit))->toThrow(InvalidArgumentException::class, 'active team')
        ->and(fn () => $compare->execute($kitA, $kitA))->toThrow(InvalidArgumentException::class, 'Two different')
        ->and(fn () => $compare->execute($kitA, $kitB))->toThrow(InvalidArgumentException::class, 'active consent');

    app(GrantDnaConsent::class)->execute($kitA, 'matching');
    app(GrantDnaConsent::class)->execute($kitB, 'matching');

    expect(fn () => $compare->execute($kitA->fresh(), $filelessKit))->toThrow(InvalidArgumentException::class, 'imported raw file');

    $result = $compare->execute($kitA->fresh(), $kitB->fresh());
    expect($result['comparison_performed'])->toBeTrue()
        ->and($result['shared_segments_count'])->toBe(1)
        ->and($result['total_shared_cm'])->toBeGreaterThanOrEqual(12.0)
        ->and($result['predicted_relationship'])->toBeString();

    // Direct AnalyzeDnaMatch check
    $analyzed = app(AnalyzeDnaMatch::class)->analyze([], []);
    expect($analyzed['shared_segments_count'])->toBe(0)
        ->and($analyzed['predicted_relationship'])->toBe(RelationshipEstimator::NO_MATCH_LABEL);
});

it('scores DuplicateCandidates accurately with normalization, minimum threshold, and limit bounds', function (): void {
    $team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
    app(TeamContext::class)->set($team->id);

    app(CreatePerson::class)->execute(['given_name' => '  John ', 'family_name' => 'Smith', 'birth_date' => '1850-03-15']);
    app(CreatePerson::class)->execute(['given_name' => 'john', 'family_name' => 'SMITH', 'birth_date' => '1850-03-15']);
    app(CreatePerson::class)->execute(['given_name' => 'John', 'family_name' => 'Smith', 'birth_date' => '1899-01-01']);
    app(CreatePerson::class)->execute(['given_name' => 'Unique', 'family_name' => 'Person', 'birth_date' => '1920-01-01']);

    $query = new DuplicateCandidates();

    $strict = $query->execute(limit: 10, minimumConfidence: 100);
    expect($strict)->toHaveCount(1)
        ->and($strict[0]['confidence'])->toBe(100)
        ->and($strict[0]['reasons'])->toBe(['matching_family_name', 'matching_given_name', 'matching_birth_date']);

    $all = $query->execute(limit: 10, minimumConfidence: 70);
    expect($all)->toHaveCount(3);

    $limited = $query->execute(limit: 1, minimumConfidence: 70);
    expect($limited)->toHaveCount(1);
});
