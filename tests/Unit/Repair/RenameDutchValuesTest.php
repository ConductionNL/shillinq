<?php

/**
 * Unit tests for the Dutch-to-English value migration.
 *
 * The step runs against a fake ValueMigrationPort rather than a database
 * double: `createMock(IDBConnection)` cannot be built in this environment,
 * because PHPUnit resolves the interface's parameter types while CONSTRUCTING
 * the double and `Doctrine\DBAL\ParameterType` is absent. The failure is in
 * building the double, so stubbing cannot get past it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\RenameDutchValueDecisions;
use OCA\Shillinq\Repair\RenameDutchValues;
use OCA\Shillinq\Repair\ValueMigrationPort;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Covers the value migration's predicates, its wiring and its l10n contract.
 */
final class RenameDutchValuesTest extends TestCase
{

    /**
     * The predicates under test.
     *
     * @var RenameDutchValueDecisions
     */
    private RenameDutchValueDecisions $decisions;

    /**
     * Build the collaborator.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->decisions = new RenameDutchValueDecisions();

    }//end setUp()

    /**
     * A port that records what it was asked to rewrite.
     *
     * @param array<int, string>               $tables  Shard tables to report.
     * @param array<string, array<int,string>> $columns Columns per table.
     * @param array<int, array<string,string>> $log     Rewrites, by reference.
     *
     * @return ValueMigrationPort
     */
    private function fakePort(array $tables, array $columns, array &$log): ValueMigrationPort
    {
        return new class($tables, $columns, $log) implements ValueMigrationPort {
            /**
             * @param array<int, string>               $tables  Tables.
             * @param array<string, array<int,string>> $columns Columns per table.
             * @param array<int, array<string,string>> $log     Rewrite log.
             */
            public function __construct(
                private array $tables,
                private array $columns,
                private array &$log,
            ) {
            }//end __construct()

            /**
             * @return array<int, string>
             */
            public function shardTables(): array
            {
                return $this->tables;
            }//end shardTables()

            /**
             * @param string $table Table.
             *
             * @return array<int, string>
             */
            public function columnsOf(string $table): array
            {
                return ($this->columns[$table] ?? []);
            }//end columnsOf()

            /**
             * @param string $table  Table.
             * @param string $column Column.
             * @param string $old    Old value.
             * @param string $new    New value.
             *
             * @return int
             */
            public function rewrite(string $table, string $column, string $old, string $new): int
            {
                $this->log[] = [
                    'table'  => $table,
                    'column' => $column,
                    'old'    => $old,
                    'new'    => $new,
                ];
                return 1;
            }//end rewrite()
        };

    }//end fakePort()

    /**
     * The column name must be the one MagicMapper actually materialises.
     *
     * There is no acronym rule, so `premiesSVWerkgever` becomes
     * `premies_svwerkgever`, NOT `premies_sv_werkgever`. Spell it the intuitive
     * way and the UPDATE matches nothing while the step reports success.
     *
     * @return void
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function testColumnForMirrorsMagicMapper(): void
    {
        self::assertSame('threshold_status', $this->decisions->columnFor('thresholdStatus'));
        self::assertSame('premies_svwerkgever', $this->decisions->columnFor('premiesSVWerkgever'));
        self::assertSame('state', $this->decisions->columnFor('state'));
        self::assertSame('event_type', $this->decisions->columnFor('event_type'));

    }//end testColumnForMirrorsMagicMapper()

    /**
     * A property whose column the table lacks is skipped entirely.
     *
     * Shard tables are per-schema, so most carry only a few mapped columns and
     * an UPDATE against a missing one is an error, not a no-op.
     *
     * @return void
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function testPlannedRewritesSkipColumnsTheTableLacks(): void
    {
        $planned = $this->decisions->plannedRewrites(
            valueMap: [
                'state'           => ['vastgesteld' => 'determined'],
                'thresholdStatus' => ['KRITIEK' => 'CRITICAL'],
            ],
            columns: ['id', 'state']
        );

        self::assertSame(
            [
                [
                    'column' => 'state',
                    'old'    => 'vastgesteld',
                    'new'    => 'determined',
                ],
            ],
            $planned
        );

    }//end testPlannedRewritesSkipColumnsTheTableLacks()

    /**
     * A null cell yields an empty string rather than a TypeError.
     *
     * @return void
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function testColumnIsDefensiveAboutNulls(): void
    {
        self::assertSame(
            ['a', ''],
            $this->decisions->column(
                rows: [
                    ['table_name' => 'a'],
                    ['table_name' => null],
                ],
                key: 'table_name'
            )
        );

    }//end testColumnIsDefensiveAboutNulls()

    /**
     * With no shard tables the step reports so and touches nothing.
     *
     * @return void
     *
     * @spec exclude Data migration for the Dutch-to-English vocabulary change.
     */
    public function testNoShardTablesRewritesNothing(): void
    {
        $log  = [];
        $step = new RenameDutchValues($this->fakePort([], [], $log), $this->decisions);

        $output = $this->createMock(IOutput::class);
        $output->expects(self::once())
            ->method('info')
            ->with(self::stringContains('nothing to do'));

        $step->run($output);

        self::assertSame([], $log);

    }//end testNoShardTablesRewritesNothing()

    /**
     * The step rewrites exactly the mapped values the table has columns for.
     *
     * @return void
     *
     * @spec exclude Data migration for the Dutch-to-English vocabulary change.
     */
    public function testStepRewritesTheMappedValues(): void
    {
        $log  = [];
        $step = new RenameDutchValues(
            $this->fakePort(
                ['oc_openregister_table_7'],
                ['oc_openregister_table_7' => ['id', 'state']],
                $log
            ),
            $this->decisions
        );

        $step->run($this->createMock(IOutput::class));

        self::assertNotSame([], $log, 'the state column is mapped, so something must have been rewritten');
        foreach ($log as $rewrite) {
            self::assertSame('state', $rewrite['column']);
            self::assertNotSame($rewrite['old'], $rewrite['new']);
        }

    }//end testStepRewritesTheMappedValues()

    /**
     * Every mapped value is reachable through a column MagicMapper produces.
     *
     * A map entry whose property never becomes a column is dead weight that
     * reads as coverage — the migration would report success having matched
     * nothing.
     *
     * @return void
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function testEveryMappedPropertyYieldsANonEmptyColumn(): void
    {
        foreach (array_keys(RenameDutchValueDecisions::VALUE_MAP) as $property) {
            self::assertNotSame('', $this->decisions->columnFor((string) $property), (string) $property);
        }

    }//end testEveryMappedPropertyYieldsANonEmptyColumn()

    /**
     * No mapped value translates to itself.
     *
     * An identity entry rewrites nothing and hides a translation that was never
     * made — 56 such entries in an earlier draft only RECASED an already-English
     * token, renaming entity types and schema keys in the process.
     *
     * @return void
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function testNoMappedValueIsAnIdentityOrACaseOnlyChange(): void
    {
        $normalise = static fn (string $value): string => strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');

        foreach (RenameDutchValueDecisions::VALUE_MAP as $property => $values) {
            foreach ($values as $old => $new) {
                self::assertNotSame(
                    $normalise((string) $old),
                    $normalise($new),
                    sprintf('%s: "%s" -> "%s" changes only the casing', $property, (string) $old, $new)
                );
            }
        }

    }//end testNoMappedValueIsAnIdentityOrACaseOnlyChange()

    /**
     * The Dutch term survives in l10n so a Dutch UI still reads correctly.
     *
     * The value is English now, which is right for the data. A Dutch user must
     * still see the Dutch word, and Nextcloud resolves that from the English
     * source string as the KEY.
     *
     * @return void
     *
     * @spec exclude l10n contract of the Dutch-to-English vocabulary migration.
     */
    public function testDutchTermsSurviveInL10n(): void
    {
        $nl = json_decode((string) file_get_contents(__DIR__.'/../../../l10n/nl.json'), true);
        self::assertIsArray($nl, 'l10n/nl.json must parse');

        $translations = ($nl['translations'] ?? []);
        self::assertIsArray($translations);

        foreach ([
            'Determined' => 'Vastgesteld',
            'Province'   => 'Provincie',
            // `uitbetaald` is disbursed, not paid: `Paid` was already taken by
            // `Betaald`, and reusing it would have shown a Dutch reader the
            // wrong word for a state that means the money went out.
            'Disbursed'  => 'Uitbetaald',
        ] as $english => $dutch) {
            self::assertSame($dutch, ($translations[$english] ?? null), $english);
        }

    }//end testDutchTermsSurviveInL10n()
    /**
     * The map holds no case-only entry, and the detector can say so.
     *
     * @return void
     *
     * @spec exclude Self-check on the vocabulary migration's own map.
     */
    public function testMapContainsNoCaseOnlyEntries(): void
    {
        self::assertSame([], $this->decisions->caseOnlyEntries(RenameDutchValueDecisions::VALUE_MAP));

        // Fed a known offender it must speak up, or the empty result above
        // proves nothing about the map.
        self::assertSame(
            ['entityType: ACMReport -> aCMReport'],
            $this->decisions->caseOnlyEntries(['entityType' => ['ACMReport' => 'aCMReport']])
        );
        self::assertSame(
            [],
            $this->decisions->caseOnlyEntries(['state' => ['vastgesteld' => 'determined']])
        );

    }//end testMapContainsNoCaseOnlyEntries()
    /**
     * The tranche-2 commitment vocabulary, as property => old => new.
     *
     * Kept in one place so every tranche-2 test below asserts against the same
     * list rather than each restating it.
     *
     * @return array<string, array<string, string>>
     */
    private function commitmentTrancheTwo(): array
    {
        return [
            'status' => [
                'in_goedkeuring'     => 'in_approval',
                'aangegaan'          => 'committed',
                'deels_geleverd'     => 'partially_delivered',
                'deels_gefactureerd' => 'partially_invoiced',
                'deels_betaald'      => 'partially_paid',
                'afgesloten'         => 'closed',
                'geannuleerd'        => 'cancelled',
            ],
            'kind' => [
                'inkooporder'         => 'purchase_order',
                'arbeidscontract'     => 'employment_contract',
                'subsidiebeschikking' => 'grant_decision',
                'huurovereenkomst'    => 'lease_agreement',
            ],
            'vat_regime' => [
                'verlegd' => 'reverse_charged',
            ],
            'role_required' => [
                'budgethouder' => 'budget_holder',
                'teamleider'   => 'team_lead',
                'directeur'    => 'director',
                'college'      => 'municipal_executive',
            ],
        ];
    }//end commitmentTrancheTwo()

    /**
     * The register fragment that owns the commitment schemas.
     *
     * @return array<string, mixed>
     */
    private function commitmentFragment(): array
    {
        $path = __DIR__.'/../../../lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json';

        return (array) json_decode((string) file_get_contents($path), true);
    }//end commitmentFragment()

    /**
     * Every tranche-2 rename is in the map, under the right property.
     *
     * Without its entry a rename is schema-only: fresh installs get the English
     * value and every upgraded install keeps the Dutch one, which does not
     * error — the filter simply stops matching.
     *
     * @return void
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function testCommitmentTrancheTwoValuesAreMapped(): void
    {
        foreach ($this->commitmentTrancheTwo() as $property => $values) {
            self::assertArrayHasKey(
                $property,
                RenameDutchValueDecisions::VALUE_MAP,
                sprintf('VALUE_MAP has no "%s" bucket', (string) $property)
            );

            foreach ($values as $old => $new) {
                self::assertSame(
                    $new,
                    (RenameDutchValueDecisions::VALUE_MAP[$property][$old] ?? null),
                    sprintf('%s: "%s" must migrate to "%s"', (string) $property, (string) $old, $new)
                );
            }
        }

    }//end testCommitmentTrancheTwoValuesAreMapped()

    /**
     * The map's replacements are exactly what the schema now declares.
     *
     * The migration writes the `new` value into the shard column; if the enum
     * does not list it, every upgraded row lands out-of-enum. The reverse is
     * just as bad: an `old` value still in the enum means the schema kept a
     * spelling the migration translates away.
     *
     * @return void
     *
     * @spec exclude Contract between the vocabulary migration and the schema.
     */
    public function testCommitmentEnumsMatchTheMigrationTargets(): void
    {
        $schemas = $this->commitmentFragment()['components']['schemas'];
        $enums   = [
            'status'        => $schemas['Commitment']['properties']['status']['enum'],
            'kind'          => $schemas['Commitment']['properties']['kind']['enum'],
            'vat_regime'    => $schemas['Commitment']['properties']['vat_regime']['enum'],
            'role_required' => $schemas['ApprovalStep']['properties']['role_required']['enum'],
        ];

        foreach ($this->commitmentTrancheTwo() as $property => $values) {
            foreach ($values as $old => $new) {
                self::assertContains(
                    $new,
                    $enums[$property],
                    sprintf('%s enum must declare the migrated value "%s"', (string) $property, $new)
                );
                self::assertNotContains(
                    (string) $old,
                    $enums[$property],
                    sprintf('%s enum still declares the Dutch value "%s"', (string) $property, (string) $old)
                );
            }
        }

    }//end testCommitmentEnumsMatchTheMigrationTargets()

    /**
     * The step plans the tranche-2 rewrites when the table has the columns.
     *
     * testCommitmentTrancheTwoValuesAreMapped proves the entries exist; this
     * proves they survive plannedRewrites() and reach the port, which is where
     * a property whose column name is spelled wrong drops out in silence.
     *
     * @return void
     *
     * @spec exclude Data migration for the Dutch-to-English vocabulary change.
     */
    public function testStepPlansEveryCommitmentTrancheTwoRewrite(): void
    {
        $log  = [];
        $step = new RenameDutchValues(
            $this->fakePort(
                ['oc_openregister_table_9'],
                ['oc_openregister_table_9' => ['id', 'status', 'kind', 'vat_regime', 'role_required']],
                $log
            ),
            $this->decisions
        );

        $step->run($this->createMock(IOutput::class));

        $seen = [];
        foreach ($log as $rewrite) {
            $seen[$rewrite['column'].'|'.$rewrite['old']] = $rewrite['new'];
        }

        foreach ($this->commitmentTrancheTwo() as $property => $values) {
            $column = $this->decisions->columnFor(name: (string) $property);
            foreach ($values as $old => $new) {
                self::assertSame(
                    $new,
                    ($seen[$column.'|'.$old] ?? null),
                    sprintf('the step must rewrite %s.%s to "%s"', $column, (string) $old, $new)
                );
            }
        }

    }//end testStepPlansEveryCommitmentTrancheTwoRewrite()

    /**
     * A second run rewrites nothing, because no replacement is another key.
     *
     * `rewrite()` is `UPDATE t SET c = ? WHERE c = ?`, so a row already holding
     * the English value cannot match a Dutch key — the step is idempotent by
     * construction. The one way to break that is a chained entry (a => b
     * alongside b => c) within the same bucket, which would keep moving the
     * same row on every upgrade. Assert no bucket contains one.
     *
     * @return void
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function testNoBucketChainsOneReplacementIntoAnotherKey(): void
    {
        foreach (RenameDutchValueDecisions::VALUE_MAP as $property => $values) {
            foreach ($values as $old => $new) {
                self::assertArrayNotHasKey(
                    $new,
                    $values,
                    sprintf(
                        '%s: "%s" -> "%s" chains into "%s" -> "%s"; the step would keep moving the row',
                        (string) $property,
                        (string) $old,
                        $new,
                        $new,
                        (string) ($values[$new] ?? '')
                    )
                );
            }
        }

    }//end testNoBucketChainsOneReplacementIntoAnotherKey()

    /**
     * ApprovalStep.status stays Dutch, and stays out of the map.
     *
     * `in_behandeling` and `afgewezen` are ALSO live in the rechtmatigheid and
     * titel-9 fragments, which still declare the Dutch enum. Because VALUE_MAP
     * is keyed by property name and applies register-wide, adding either one
     * here would rewrite their stored rows too and desync schema from data —
     * silently, since an out-of-enum stored value does not error.
     *
     * @return void
     *
     * @spec exclude Deliberate scope boundary of the vocabulary migration.
     */
    public function testApprovalStepStatusIsLeftWhollyDutch(): void
    {
        $schemas = $this->commitmentFragment()['components']['schemas'];
        $enum    = $schemas['ApprovalStep']['properties']['status']['enum'];

        self::assertContains('in_behandeling', $enum, 'the enum must stay Dutch, not be half-renamed');
        self::assertContains('afgewezen', $enum);
        self::assertNotEmpty(
            ($schemas['ApprovalStep']['properties']['status']['_note'] ?? ''),
            'the deliberate exception must be recorded next to the enum it applies to'
        );

        foreach (['wachtend', 'in_behandeling', 'afgewezen', 'teruggezonden'] as $dutch) {
            self::assertArrayNotHasKey(
                $dutch,
                RenameDutchValueDecisions::VALUE_MAP['status'],
                sprintf('"%s" would also rewrite the rechtmatigheid/titel-9 status columns', $dutch)
            );
        }

        // The collision is real, not hypothetical: prove the other fragments
        // still declare these values, so the day they stop this test tells us.
        $rechtmatigheid = (array) json_decode(
            (string) file_get_contents(
                __DIR__.'/../../../lib/Settings/register.d/bookkeeping-rechtmatigheidsverantwoording.json'
            ),
            true
        );
        self::assertContains(
            'in_behandeling',
            $rechtmatigheid['components']['schemas']['Rechtmatigheidstoets']['properties']['status']['enum']
        );

    }//end testApprovalStepStatusIsLeftWhollyDutch()

    /**
     * Mandate.kind_commitment speaks the same vocabulary as Commitment.kind.
     *
     * MandateEnforcer::mandateApplies() matches the commitment's `kind` against
     * this array with in_array(strict). Let the two enums drift and every
     * mandate stops applying — which reads as "mandate insufficient", so the
     * commitment routes to approval rather than raising anything.
     *
     * @return void
     *
     * @spec exclude Contract between the mandate matcher and the kind enum.
     */
    public function testMandateKindCommitmentTracksTheCommitmentKindEnum(): void
    {
        $schemas = $this->commitmentFragment()['components']['schemas'];

        self::assertSame(
            $schemas['Commitment']['properties']['kind']['enum'],
            $schemas['Mandate']['properties']['kind_commitment']['items']['enum'],
            'Mandate.kind_commitment must enumerate exactly Commitment.kind'
        );

        // The seeded mandates must speak it too, or a fresh install ships
        // mandates that match no commitment.
        $allowed = $schemas['Commitment']['properties']['kind']['enum'];
        foreach (($this->commitmentFragment()['objects'] ?? []) as $object) {
            if (($object['@self']['schema'] ?? '') !== 'Mandate') {
                continue;
            }

            foreach (($object['kind_commitment'] ?? []) as $kind) {
                self::assertContains($kind, $allowed, sprintf('seeded mandate carries unknown kind "%s"', (string) $kind));
            }
        }

    }//end testMandateKindCommitmentTracksTheCommitmentKindEnum()

    /**
     * The shard-table LIKE pattern keeps its underscores escaped.
     *
     * Unescaped, `_` is LIKE's single-character wildcard, so the migration
     * would match tables it has no business rewriting — silently, because a
     * wider match yields more UPDATEs rather than an error.
     *
     * @return void
     *
     * @spec exclude Predicate of the Dutch-to-English vocabulary migration.
     */
    public function testShardTablePatternEscapesItsUnderscores(): void
    {
        $pattern = $this->decisions->shardTablePattern();

        self::assertStringContainsString("openregister\\_table\\_", $pattern);
        self::assertStringNotContainsString('openregister_table_', $pattern);

    }//end testShardTablePatternEscapesItsUnderscores()
}//end class
