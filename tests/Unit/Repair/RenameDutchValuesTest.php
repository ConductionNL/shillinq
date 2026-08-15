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
}//end class
