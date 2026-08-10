<?php

/**
 * Unit tests for the MigrateCommitmentDomainToEnglish repair step.
 *
 * The point of these tests is NOT that the step runs without exploding. It is
 * that the step can be SHOWN TO HAVE BEEN NECESSARY: for every single renamed
 * property, a value stored under the Dutch name is proven UNREADABLE under the
 * English name before the step runs, and proven READABLE under the English name
 * after it. A migration test that only checks the post-state cannot tell a
 * working migration apart from a fixture that was already migrated.
 *
 * `testEveryRenamedPropertyIsUnreadableBeforeAndReadableAfter` is that proof,
 * once per (schema, target property, legacy source key) triple — 100+ of them,
 * generated from the map rather than hand-picked, so a property added to the
 * rename map without a migration path fails here rather than in production.
 *
 * The expected maps below are transcribed independently from the register
 * fragments' own diff (`lib/Settings/register.d/…` at development vs this
 * branch), NOT imported from the class, so a change to the class's maps is
 * caught as drift instead of silently redefining what "correct" means.
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

use OCA\Shillinq\Repair\MigrateCommitmentDomainToEnglish;
use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MigrateCommitmentDomainToEnglish.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class MigrateCommitmentDomainToEnglishTest extends TestCase
{

    /**
     * Renamed schema slugs, transcribed from the register-fragment diff.
     *
     * @var array<string,string>
     */
    private const EXPECTED_SCHEMA_RENAMES = [
        'Verplichting'          => 'Commitment',
        'Verplichtingsregel'    => 'CommitmentLine',
        'Verplichtingsmutatie'  => 'CommitmentMovement',
        'Mandaat'               => 'Mandate',
        'Goedkeuringsstap'      => 'ApprovalStep',
        'TenderNedAanbesteding' => 'TenderNedProcurement',
        'OpdrachtUitvoering'    => 'OrderFulfilment',
    ];

    /**
     * Schemas whose slug did not change but whose properties did.
     *
     * @var array<int,string>
     */
    private const EXPECTED_IN_PLACE_SCHEMAS = [
        'Budget',
        'Requisition',
    ];

    /**
     * Property migration map, transcribed from the register-fragment diff.
     *
     * @var array<string,array<string,array<int,string>>>
     */
    private const EXPECTED_PROPERTY_SOURCES = [
        'Commitment'           => [
            'commitmentNumber'         => ['verplichtingsnummer', 'verplichtingNummer', 'verplichting_nummer'],
            'sourceReference'          => ['bronReferentie', 'bron_referentie'],
            'source'                   => ['bron'],
            'commitmentType'           => ['soort'],
            'commitmentDate'           => ['aangaandatum'],
            'termStart'                => ['looptijd_van', 'looptijdStart', 'looptijd_start'],
            'termEnd'                  => ['looptijd_tot', 'looptijdEind', 'looptijd_eind'],
            'counterparty'             => ['tegenpartij'],
            'totalAmountExclVat'       => ['totaalbedrag_excl_btw'],
            'totalAmountInclVat'       => ['totaalbedrag_incl_btw'],
            'amount'                   => ['bedrag'],
            'currency'                 => ['valuta'],
            'vatRegime'                => ['btw_regime'],
            'mandateApplied'           => ['mandaat_toegepast'],
            'overrideReason'           => ['override_reden'],
            'internalReference'        => ['interne_kenmerk'],
            'lawfulnessChecks'         => ['rechtmatigheidstoetsen'],
            'documents'                => ['documenten'],
            'description'              => ['omschrijving'],
            'costCentre'               => ['kostenplaats'],
            'glAccount'                => ['grootboekrekening'],
            'awardedSupplierCocNumber' => ['gegundeLeverancierKvk', 'gegunde_leverancier_kvk'],
            'milestones'               => ['mijlpalen'],
        ],
        'CommitmentLine'       => [
            'commitment'           => ['verplichting'],
            'lineNumber'           => ['regelnummer'],
            'description'          => ['omschrijving'],
            'fiscalYear'           => ['boekjaar'],
            'amountExclVat'        => ['bedrag_excl_btw'],
            'amountInclVat'        => ['bedrag_incl_btw'],
            'glAccount'            => ['grootboekrekening'],
            'costCentre'           => ['kostenplaats'],
            'programme'            => ['programma'],
            'vatCode'              => ['btw_code'],
            'expectedDeliveryDate' => ['verwacht_geleverd_op'],
            'deliveredAmount'      => ['geleverd_bedrag'],
            'invoicedAmount'       => ['gefactureerd_bedrag'],
            'paidAmount'           => ['betaald_bedrag'],
            'remainingCommitted'   => ['restant_verplicht'],
            'closed'               => ['afgesloten'],
        ],
        'CommitmentMovement'   => [
            'commitment'     => ['verplichting'],
            'commitmentLine' => ['verplichtingsregel'],
            'date'           => ['datum'],
            'movementType'   => ['soort'],
            'amount'         => ['bedrag'],
            'currency'       => ['valuta'],
            'notes'          => ['toelichting'],
            'relatedInvoice' => ['gerelateerde_factuur'],
            'relatedPayment' => ['gerelateerde_betaling'],
            'journalEntry'   => ['journaalpost'],
            'user'           => ['gebruiker'],
        ],
        'Mandate'              => [
            'mandateCode'                  => ['mandaatcode'],
            'name'                         => ['naam'],
            'maximumAmount'                => ['maximumbedrag'],
            'commitmentType'               => ['soort_verplichting'],
            'isOverride'                   => ['is_override'],
            'secondSignatureRequiredAbove' => ['vereist_tweede_handtekening_boven'],
        ],
        'ApprovalStep'         => [
            'commitment' => ['verplichting'],
            'stepNumber' => ['stapnummer'],
        ],
        'TenderNedProcurement' => [
            'procurementId'        => ['aanbestedingId', 'aanbesteding_id'],
            'tenderTitle'          => ['titel'],
            'tenderDescription'    => ['beschrijving'],
            'contractingAuthority' => ['aanbestedendeDienst', 'aanbestedende_dienst'],
            'awardDate'            => ['gunningsDatum', 'gunnings_datum'],
            'contractValue'        => ['contractWaarde', 'contract_waarde'],
            'termStart'            => ['looptijdStart', 'looptijd_start'],
            'termEnd'              => ['looptijdEind', 'looptijd_eind'],
            'awardedSupplier'      => ['gegundeLeverancier', 'gegunde_leverancier'],
            'assignmentType'       => ['opdrachttype'],
            'commitmentId'         => ['verplichtingId', 'verplichting_id'],
        ],
        'OrderFulfilment'      => [
            'commitmentId' => ['verplichtingId', 'verplichting_id'],
            'milestoneId'  => ['mijlpaalId', 'mijlpaal_id'],
            'deliveryDate' => ['opleveringsDatum', 'opleverings_datum'],
            'deliveryType' => ['opleveringsType', 'opleverings_type'],
            'approved'     => ['goedgekeurd'],
            'approver'     => ['goedkeurder'],
            'evidence'     => ['bewijsstukken'],
        ],
        'Budget'               => [
            'programmeCode'          => ['programmaCode', 'programma_code'],
            'costCentre'             => ['kostenplaats'],
            'fiscalYear'             => ['boekjaar'],
            'description'            => ['omschrijving'],
            'authorisedAmount'       => ['geautoriseerd_bedrag'],
            'realisedAmount'         => ['gerealiseerd_bedrag'],
            'outstandingCommitments' => ['openstaande_verplichtingen'],
        ],
        'Requisition'          => [
            'programme'          => ['programma'],
            'fiscalYear'         => ['boekjaar'],
            'commitmentType'     => ['soort'],
            'totalAmountExclVat' => ['totaalbedrag_excl_btw'],
        ],
    ];

    /**
     * The commitment-type vocabulary, shared by three schemas.
     *
     * @var array<string,string>
     */
    private const COMMITMENT_TYPE_VALUES = [
        'inkooporder'         => 'purchaseOrder',
        'raamovereenkomst'    => 'frameworkAgreement',
        'arbeidscontract'     => 'employmentContract',
        'subsidiebeschikking' => 'grantDecision',
        'huurovereenkomst'    => 'rentalAgreement',
        'leasing'             => 'lease',
        'overig'              => 'other',
    ];

    /**
     * Enum value migration map, transcribed from the register-fragment diff.
     *
     * @var array<string,array<string,array<string,string>>>
     */
    private const EXPECTED_VALUE_RENAMES = [
        'Commitment'           => [
            'commitmentType' => self::COMMITMENT_TYPE_VALUES,
            'vatRegime'      => [
                'standaard'   => 'standard',
                'verlegd'     => 'reverseCharge',
                'vrijgesteld' => 'exempt',
            ],
            'status'         => [
                'concept'            => 'draft',
                'in_goedkeuring'     => 'pendingApproval',
                'aangegaan'          => 'committed',
                'deels_geleverd'     => 'partiallyDelivered',
                'deels_gefactureerd' => 'partiallyInvoiced',
                'deels_betaald'      => 'partiallyPaid',
                'afgesloten'         => 'closed',
                'geannuleerd'        => 'cancelled',
            ],
            'source'         => [
                'inkooporder' => 'purchaseOrder',
            ],
        ],
        'CommitmentMovement'   => [
            'movementType' => [
                'aangegaan'           => 'committed',
                'verhoogd'            => 'increased',
                'verlaagd'            => 'decreased',
                'prestatie_ontvangen' => 'performanceReceived',
                'gefactureerd'        => 'invoiced',
                'betaald'             => 'paid',
                'afgesloten'          => 'closed',
                'geannuleerd'         => 'cancelled',
                'teruggevorderd'      => 'reclaimed',
            ],
        ],
        'Mandate'              => [
            'commitmentType' => self::COMMITMENT_TYPE_VALUES,
        ],
        'TenderNedProcurement' => [
            'assignmentType' => [
                'levering-in-fases'          => 'phasedDelivery',
                'dienstverlening-doorlopend' => 'ongoingService',
            ],
            'status'         => [
                'aangekondigd'  => 'announced',
                'gegund'        => 'awarded',
                'in-uitvoering' => 'inProgress',
                'afgerond'      => 'completed',
                'beeindigd'     => 'terminated',
            ],
        ],
        'OrderFulfilment'      => [
            'deliveryType' => [
                'deeloplevering'          => 'partialDelivery',
                'eindoplevering'          => 'finalDelivery',
                'tussentijdse-rapportage' => 'interimReport',
            ],
        ],
        'Requisition'          => [
            'commitmentType' => self::COMMITMENT_TYPE_VALUES,
        ],
    ];

    /**
     * Settings service mock.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * Container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Fake ObjectService captured per-test.
     *
     * @var object
     */
    private object $fake;

    /**
     * Warnings emitted by the repair-step output.
     *
     * @var array<int,string>
     */
    private array $warnings = [];

    /**
     * Setup test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->warnings        = [];

        $this->settingsService->method('getRegisterSlug')->willReturn('shillinq');

    }//end setUp()

    /**
     * The step name is human-readable and names the migration.
     *
     * @return void
     */
    public function testNameIsHumanReadable(): void
    {
        $step = $this->makeStep([]);

        self::assertStringContainsString('commitment', strtolower($step->getName()));

    }//end testNameIsHumanReadable()

    /**
     * The class's schema-rename map matches the register-fragment diff.
     *
     * @return void
     */
    public function testSchemaRenameMapMatchesTheRegisterDiff(): void
    {
        self::assertSame(
            self::EXPECTED_SCHEMA_RENAMES,
            MigrateCommitmentDomainToEnglish::SCHEMA_RENAMES
        );
        self::assertSame(
            self::EXPECTED_IN_PLACE_SCHEMAS,
            MigrateCommitmentDomainToEnglish::IN_PLACE_SCHEMAS
        );

    }//end testSchemaRenameMapMatchesTheRegisterDiff()

    /**
     * The class's property map matches the register-fragment diff.
     *
     * @return void
     */
    public function testPropertyMapMatchesTheRegisterDiff(): void
    {
        self::assertSame(
            self::EXPECTED_PROPERTY_SOURCES,
            MigrateCommitmentDomainToEnglish::PROPERTY_SOURCES
        );

    }//end testPropertyMapMatchesTheRegisterDiff()

    /**
     * The class's enum-value map matches the register-fragment diff.
     *
     * @return void
     */
    public function testValueMapMatchesTheRegisterDiff(): void
    {
        self::assertSame(
            self::EXPECTED_VALUE_RENAMES,
            MigrateCommitmentDomainToEnglish::VALUE_RENAMES
        );

    }//end testValueMapMatchesTheRegisterDiff()

    /**
     * Every renamed schema has a property map, and every mapped schema is
     * either a rename target or an explicit in-place schema. A schema in one
     * list and not the other is a migration that silently does nothing.
     *
     * @return void
     */
    public function testEveryMigratedSchemaIsReachable(): void
    {
        $reachable = array_merge(
            array_values(MigrateCommitmentDomainToEnglish::SCHEMA_RENAMES),
            MigrateCommitmentDomainToEnglish::IN_PLACE_SCHEMAS
        );

        foreach (array_keys(MigrateCommitmentDomainToEnglish::PROPERTY_SOURCES) as $schema) {
            self::assertContains(
                $schema,
                $reachable,
                $schema.' has a property map but is never read by run()'
            );
        }

        foreach (MigrateCommitmentDomainToEnglish::SCHEMA_RENAMES as $old => $new) {
            self::assertArrayHasKey(
                $new,
                MigrateCommitmentDomainToEnglish::PROPERTY_SOURCES,
                $old.' is renamed to '.$new.' but has no property map'
            );
        }

        foreach (array_keys(MigrateCommitmentDomainToEnglish::VALUE_RENAMES) as $schema) {
            self::assertArrayHasKey(
                $schema,
                MigrateCommitmentDomainToEnglish::PROPERTY_SOURCES,
                $schema.' has an enum map but no property map'
            );
        }

    }//end testEveryMigratedSchemaIsReachable()

    /**
     * No legacy source key is also a target property on the same schema.
     *
     * If one were, the "already migrated, do not overwrite" guard would read
     * the legacy value as an English one and the rename would be a no-op that
     * looks like a success.
     *
     * @return void
     */
    public function testNoSourceKeyIsAlsoATargetOnTheSameSchema(): void
    {
        foreach (MigrateCommitmentDomainToEnglish::PROPERTY_SOURCES as $schema => $map) {
            $targets = array_keys($map);
            foreach ($map as $target => $sources) {
                foreach ($sources as $source) {
                    self::assertNotContains(
                        $source,
                        $targets,
                        $schema.': legacy key "'.$source.'" (for '.$target.') is also a target property'
                    );
                }
            }
        }

    }//end testNoSourceKeyIsAlsoATargetOnTheSameSchema()

    /**
     * THE BOTH-DIRECTIONS PROOF, per schema / per property / per legacy
     * spelling.
     *
     * A value stored under the Dutch name is asserted UNREADABLE under the
     * English name before the step runs (so the migration is shown to have
     * been necessary at all), and READABLE under the English name after it.
     *
     * @param string $schema The target schema slug.
     * @param string $target The target (English) property.
     * @param string $source The legacy (Dutch) source key.
     *
     * @return void
     *
     * @dataProvider renamedPropertyProvider
     */
    public function testEveryRenamedPropertyIsUnreadableBeforeAndReadableAfter(
        string $schema,
        string $target,
        string $source
    ): void {
        $sentinel = 'sentinel-'.$source;
        $stored   = [
            'id'    => 'obj-1',
            $source => $sentinel,
        ];

        // BEFORE: the app reads the English name and finds nothing. This is
        // the control — it fails if the fixture was seeded under the English
        // name, or if source and target are the same key.
        self::assertNull(
            ($stored[$target] ?? null),
            $schema.'.'.$target.' must NOT be readable while the value is stored as '.$source
        );
        self::assertSame($sentinel, $stored[$source]);

        $step = $this->makeStep([$schema => [$stored]]);
        $step->run($this->makeOutput());

        // AFTER: exactly one save, under the target schema, and the value now
        // reads under the English name.
        $saves = $this->savesForSchema($schema);
        self::assertCount(
            1,
            $saves,
            $schema.'.'.$target.' was not migrated from '.$source.' (no save)'
        );
        self::assertSame(
            $sentinel,
            ($saves[0]['object'][$target] ?? null),
            $schema.'.'.$target.' did not pick up the value stored under '.$source
        );
        self::assertArrayNotHasKey(
            $source,
            $saves[0]['object'],
            $schema.': the legacy key '.$source.' is still in the written payload'
        );

    }//end testEveryRenamedPropertyIsUnreadableBeforeAndReadableAfter()

    /**
     * Provides every (schema, target property, legacy source key) triple from
     * the test's own transcription of the rename map.
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function renamedPropertyProvider(): array
    {
        $cases = [];
        foreach (self::EXPECTED_PROPERTY_SOURCES as $schema => $map) {
            foreach ($map as $target => $sources) {
                foreach ($sources as $source) {
                    $cases[$schema.'.'.$source.' -> '.$target] = [$schema, $target, $source];
                }
            }
        }

        return $cases;

    }//end renamedPropertyProvider()

    /**
     * THE BOTH-DIRECTIONS PROOF for enum values.
     *
     * A stored Dutch enum value is asserted NOT to equal the English one
     * before the step runs, and to equal it after.
     *
     * @param string $schema   The target schema slug.
     * @param string $property The target property.
     * @param string $oldValue The stored Dutch value.
     * @param string $newValue The expected English value.
     *
     * @return void
     *
     * @dataProvider renamedValueProvider
     */
    public function testEveryRenamedEnumValueIsWrongBeforeAndRightAfter(
        string $schema,
        string $property,
        string $oldValue,
        string $newValue
    ): void {
        $stored = [
            'id'      => 'obj-1',
            $property => $oldValue,
        ];

        // BEFORE: the stored value is not the English one.
        self::assertNotSame(
            $newValue,
            $stored[$property],
            $schema.'.'.$property.': fixture already holds the English value'
        );

        $step = $this->makeStep([$schema => [$stored]]);
        $step->run($this->makeOutput());

        $saves = $this->savesForSchema($schema);
        self::assertCount(1, $saves, $schema.'.'.$property.'='.$oldValue.' was not migrated');
        self::assertSame(
            $newValue,
            ($saves[0]['object'][$property] ?? null),
            $schema.'.'.$property.': '.$oldValue.' did not become '.$newValue
        );

    }//end testEveryRenamedEnumValueIsWrongBeforeAndRightAfter()

    /**
     * Provides every (schema, property, old value, new value) enum quadruple.
     *
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    public static function renamedValueProvider(): array
    {
        $cases = [];
        foreach (self::EXPECTED_VALUE_RENAMES as $schema => $properties) {
            foreach ($properties as $property => $valueMap) {
                foreach ($valueMap as $old => $new) {
                    $cases[$schema.'.'.$property.': '.$old.' -> '.$new] = [$schema, $property, $old, $new];
                }
            }
        }

        return $cases;

    }//end renamedValueProvider()

    /**
     * A value that is already English is left exactly as it is.
     *
     * `Commitment.status` additionally carries `active` / `archived` from the
     * TenderNed fragment; mapping them would corrupt every TenderNed-sourced
     * commitment.
     *
     * @return void
     */
    public function testAlreadyEnglishEnumValuesArePassedThrough(): void
    {
        $step = $this->makeStep(
            [
                'Commitment' => [
                    [
                        'id'               => 'c-1',
                        'commitmentNumber' => 'PO-1',
                        'status'           => 'active',
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        self::assertSame([], $this->fake->saves, 'a fully-migrated object must not be re-saved');

    }//end testAlreadyEnglishEnumValuesArePassedThrough()

    /**
     * An object that was never written under either name produces no save.
     *
     * @return void
     */
    public function testObjectNeverWrittenUnderEitherNameIsNotTouched(): void
    {
        $step = $this->makeStep(
            [
                'Commitment' => [
                    [
                        'id'               => 'c-1',
                        'commitmentNumber' => 'PO-1',
                        'administrationId' => 'adm-1',
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        self::assertSame([], $this->fake->saves);

    }//end testObjectNeverWrittenUnderEitherNameIsNotTouched()

    /**
     * Running the step twice migrates once. The second run must produce no
     * save at all — including for the legacy column an already-migrated object
     * keeps reading back from storage.
     *
     * @return void
     */
    public function testSecondRunIsANoOp(): void
    {
        $stored = [
            'id'                  => 'c-1',
            'verplichtingsnummer' => 'PO-2026-1',
            'soort'               => 'inkooporder',
        ];

        $step = $this->makeStep(['Commitment' => [$stored]]);
        $step->run($this->makeOutput());
        self::assertCount(1, $this->fake->saves, 'first run must migrate');

        $migrated = $this->fake->saves[0]['object'];

        // Second run sees the migrated object — but storage still hands back
        // the retained legacy column, exactly as OpenRegister does.
        $secondPass = array_merge($migrated, ['verplichtingsnummer' => 'PO-2026-1']);
        $step2      = $this->makeStep(['Commitment' => [$secondPass]]);
        $step2->run($this->makeOutput());

        self::assertSame([], $this->fake->saves, 'second run must not re-save anything');

    }//end testSecondRunIsANoOp()

    /**
     * An object carrying BOTH a populated English property and a legacy one is
     * never clobbered — the English value wins and no save happens for it.
     *
     * @return void
     */
    public function testAlreadyMigratedTargetIsNeverOverwritten(): void
    {
        $step = $this->makeStep(
            [
                'Commitment' => [
                    [
                        'id'                  => 'c-1',
                        'commitmentNumber'    => 'PO-NEW',
                        'verplichtingsnummer' => 'PO-STALE',
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        self::assertSame([], $this->fake->saves, 'a populated English value must not trigger a rewrite');

    }//end testAlreadyMigratedTargetIsNeverOverwritten()

    /**
     * THE SPLIT BRAIN. `verplichtingsnummer` (declared by the owning fragment,
     * read by BudgetBlocker) beats `verplichtingNummer` (written only by
     * TenderNedAwardDetectedListener), and the losing value is reported by
     * uuid rather than dropped in silence.
     *
     * @return void
     */
    public function testSplitBrainPrefersTheDeclaredSpellingAndReportsTheLoser(): void
    {
        $step = $this->makeStep(
            [
                'Commitment' => [
                    [
                        'id'                  => 'c-1',
                        'verplichtingsnummer' => 'PO-DECLARED',
                        'verplichtingNummer'  => 'PO-TENDERNED',
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        $saves = $this->savesForSchema('Commitment');
        self::assertCount(1, $saves);
        self::assertSame('PO-DECLARED', $saves[0]['object']['commitmentNumber']);

        $conflict = $this->warningsContaining('conflicting legacy values');
        self::assertCount(1, $conflict, 'a disagreeing split brain must be reported');
        self::assertStringContainsString('c-1', $conflict[0]);
        self::assertStringContainsString('PO-DECLARED', $conflict[0]);
        self::assertStringContainsString('PO-TENDERNED', $conflict[0]);

    }//end testSplitBrainPrefersTheDeclaredSpellingAndReportsTheLoser()

    /**
     * Two legacy spellings holding the SAME value is not a conflict and must
     * not produce a warning — otherwise the real conflicts drown.
     *
     * @return void
     */
    public function testSplitBrainWithAgreeingValuesIsNotReported(): void
    {
        $step = $this->makeStep(
            [
                'Commitment' => [
                    [
                        'id'                  => 'c-1',
                        'verplichtingsnummer' => 'PO-SAME',
                        'verplichtingNummer'  => 'PO-SAME',
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        self::assertSame('PO-SAME', $this->savesForSchema('Commitment')[0]['object']['commitmentNumber']);
        self::assertSame([], $this->warningsContaining('conflicting legacy values'));

    }//end testSplitBrainWithAgreeingValuesIsNotReported()

    /**
     * The second-choice spelling is still migrated when the declared one is
     * absent — the fallback is not decoration.
     *
     * @return void
     */
    public function testFallbackSpellingIsUsedWhenTheDeclaredOneIsAbsent(): void
    {
        $step = $this->makeStep(
            [
                'Commitment' => [
                    [
                        'id'                 => 'c-1',
                        'verplichtingNummer' => 'PO-ONLY-TENDERNED',
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        self::assertSame(
            'PO-ONLY-TENDERNED',
            $this->savesForSchema('Commitment')[0]['object']['commitmentNumber']
        );
        self::assertSame([], $this->warningsContaining('conflicting legacy values'));

    }//end testFallbackSpellingIsUsedWhenTheDeclaredOneIsAbsent()

    /**
     * An object stored under the OLD schema slug is re-pointed to the new one,
     * keeping its uuid — the whole domain joins on that uuid.
     *
     * @return void
     */
    public function testObjectUnderTheOldSlugMovesToTheNewSchemaKeepingItsUuid(): void
    {
        $step = $this->makeStep(
            [
                'Verplichting' => [
                    [
                        'id'                  => 'c-42',
                        'verplichtingsnummer' => 'PO-42',
                        'soort'               => 'raamovereenkomst',
                        'status'              => 'aangegaan',
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        $saves = $this->savesForSchema('Commitment');
        self::assertCount(1, $saves, 'the stranded Verplichting object was not moved');
        self::assertSame('c-42', $saves[0]['object']['id'], 'the uuid must survive the move');
        self::assertSame('PO-42', $saves[0]['object']['commitmentNumber']);
        self::assertSame('frameworkAgreement', $saves[0]['object']['commitmentType']);
        self::assertSame('committed', $saves[0]['object']['status']);
        self::assertSame([], $this->savesForSchema('Verplichting'), 'nothing may be written back to the old slug');

    }//end testObjectUnderTheOldSlugMovesToTheNewSchemaKeepingItsUuid()

    /**
     * A stranded object whose uuid ALREADY resolves under the target schema is
     * skipped whole. Without this gate a second run would overwrite whatever
     * an operator did to the migrated object with the stale source row.
     *
     * @return void
     */
    public function testSlugMoveSkipsAnObjectThatAlreadyExistsUnderTheTarget(): void
    {
        $step = $this->makeStep(
            [
                'Verplichting' => [
                    [
                        'id'                  => 'c-42',
                        'verplichtingsnummer' => 'PO-STALE',
                    ],
                ],
                'Commitment'   => [
                    [
                        'id'               => 'c-42',
                        'commitmentNumber' => 'PO-EDITED-SINCE',
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        self::assertSame([], $this->fake->saves, 'an already-migrated uuid must not be rewritten');

    }//end testSlugMoveSkipsAnObjectThatAlreadyExistsUnderTheTarget()

    /**
     * The class's natural-key map matches the identities the domain actually
     * has. A key added here without the schema having it is a silently
     * suppressed migration; one removed is a silent duplicate.
     *
     * @return void
     */
    public function testNaturalKeyMapIsAsDeclared(): void
    {
        self::assertSame(
            [
                'Commitment'           => ['administrationId', 'commitmentNumber'],
                'CommitmentLine'       => ['administrationId', 'commitment', 'lineNumber'],
                'Mandate'              => ['administrationId', 'mandateCode'],
                'ApprovalStep'         => ['administrationId', 'commitment', 'stepNumber'],
                'TenderNedProcurement' => ['administrationId', 'procurementId'],
            ],
            MigrateCommitmentDomainToEnglish::NATURAL_KEYS
        );

    }//end testNaturalKeyMapIsAsDeclared()

    /**
     * THE SEED COLLISION. `InitializeSettings` runs BEFORE this step and
     * reseeds the mandate templates against a freshly-created (empty) `Mandate`
     * schema, so every template is created. Migrating the instance's legacy
     * `Mandaat` rows on top of that must NOT produce two mandates per code —
     * two matching mandates is not cosmetic for a signing-authority lookup.
     *
     * @return void
     */
    public function testSlugMoveDoesNotDuplicateARowTheSeedsAlreadyRecreated(): void
    {
        $step = $this->makeStep(
            [
                'Mandaat' => [
                    [
                        'id'               => 'legacy-m-1',
                        'administrationId' => 'adm-1',
                        'mandaatcode'      => 'MND-DIR',
                        'maximumbedrag'    => 25000000,
                    ],
                ],
                'Mandate' => [
                    [
                        'id'               => 'seeded-m-1',
                        'administrationId' => 'adm-1',
                        'mandateCode'      => 'MND-DIR',
                        'maximumAmount'    => 25000000,
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        self::assertSame([], $this->fake->saves, 'the legacy Mandaat must not be migrated on top of the seed');

    }//end testSlugMoveDoesNotDuplicateARowTheSeedsAlreadyRecreated()

    /**
     * A legacy row whose business identity is NOT already present still
     * migrates — the dedup gate must not swallow real data.
     *
     * @return void
     */
    public function testSlugMoveStillMigratesARowWithADifferentIdentity(): void
    {
        $step = $this->makeStep(
            [
                'Mandaat' => [
                    [
                        'id'               => 'legacy-m-2',
                        'administrationId' => 'adm-1',
                        'mandaatcode'      => 'MND-OPERATOR-CUSTOM',
                    ],
                ],
                'Mandate' => [
                    [
                        'id'               => 'seeded-m-1',
                        'administrationId' => 'adm-1',
                        'mandateCode'      => 'MND-DIR',
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        $saves = $this->savesForSchema('Mandate');
        self::assertCount(1, $saves, 'an operator-created mandate must still migrate');
        self::assertSame('legacy-m-2', $saves[0]['object']['id']);
        self::assertSame('MND-OPERATOR-CUSTOM', $saves[0]['object']['mandateCode']);

    }//end testSlugMoveStillMigratesARowWithADifferentIdentity()

    /**
     * An INCOMPLETE natural key must not be treated as an identity.
     *
     * The fixture is the shape that actually bites: a target row and a legacy
     * row that share the parts they DO have (`administrationId`) and are both
     * missing the discriminating one (`mandateCode`). Filling a missing part
     * with a placeholder rather than abandoning the key makes those two look
     * like the same business object, and the legacy row is silently dropped
     * from the migration — data loss dressed as deduplication.
     *
     * @return void
     */
    public function testIncompleteNaturalKeysDoNotSuppressMigration(): void
    {
        $step = $this->makeStep(
            [
                'Mandaat' => [
                    ['id' => 'legacy-a', 'administrationId' => 'adm-1', 'maximumbedrag' => 1000],
                    ['id' => 'legacy-b', 'administrationId' => 'adm-1', 'maximumbedrag' => 2000],
                ],
                'Mandate' => [
                    ['id' => 'seeded-x', 'administrationId' => 'adm-1', 'maximumAmount' => 9999],
                ],
            ]
        );
        $step->run($this->makeOutput());

        $saves = $this->savesForSchema('Mandate');
        self::assertCount(2, $saves, 'a row with no business identity must still migrate');
        self::assertSame(
            ['legacy-a', 'legacy-b'],
            array_map(
                static function (array $save): string {
                    return (string) $save['object']['id'];
                },
                $saves
            )
        );

    }//end testIncompleteNaturalKeysDoNotSuppressMigration()

    /**
     * Zero and false are real values and must migrate. Treating them as
     * "absent" is how a migration quietly loses a zero amount or an
     * unapproved flag.
     *
     * @return void
     */
    public function testZeroAndFalseValuesAreMigrated(): void
    {
        $step = $this->makeStep(
            [
                'CommitmentLine' => [
                    [
                        'id'              => 'l-1',
                        'geleverd_bedrag' => 0,
                        'afgesloten'      => false,
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        $saved = $this->savesForSchema('CommitmentLine')[0]['object'];
        self::assertSame(0, $saved['deliveredAmount']);
        self::assertFalse($saved['closed']);

    }//end testZeroAndFalseValuesAreMigrated()

    /**
     * `soort` maps to a DIFFERENT English property per schema. A single global
     * mapping would silently write `commitmentType` onto every movement.
     *
     * @return void
     */
    public function testSoortMapsPerSchema(): void
    {
        $step = $this->makeStep(
            [
                'Commitment'         => [['id' => 'c-1', 'soort' => 'leasing']],
                'CommitmentMovement' => [['id' => 'm-1', 'soort' => 'teruggevorderd']],
            ]
        );
        $step->run($this->makeOutput());

        self::assertSame('lease', $this->savesForSchema('Commitment')[0]['object']['commitmentType']);
        self::assertArrayNotHasKey('movementType', $this->savesForSchema('Commitment')[0]['object']);

        $movement = $this->savesForSchema('CommitmentMovement')[0]['object'];
        self::assertSame('reclaimed', $movement['movementType']);
        self::assertArrayNotHasKey('commitmentType', $movement);

    }//end testSoortMapsPerSchema()

    /**
     * Budget's formula inputs migrate. `vrije_ruimte` is derived from these
     * three, so a half-migrated Budget makes every budget check read a zero
     * ceiling through BudgetBlocker's `??`.
     *
     * @return void
     */
    public function testBudgetFormulaInputsMigrate(): void
    {
        $step = $this->makeStep(
            [
                'Budget' => [
                    [
                        'id'                         => 'b-1',
                        'geautoriseerd_bedrag'       => 500000,
                        'gerealiseerd_bedrag'        => 120000,
                        'openstaande_verplichtingen' => 80000,
                        'boekjaar'                   => 2026,
                    ],
                ],
            ]
        );
        $step->run($this->makeOutput());

        $saved = $this->savesForSchema('Budget')[0]['object'];
        self::assertSame(500000, $saved['authorisedAmount']);
        self::assertSame(120000, $saved['realisedAmount']);
        self::assertSame(80000, $saved['outstandingCommitments']);
        self::assertSame(2026, $saved['fiscalYear']);

    }//end testBudgetFormulaInputsMigrate()

    /**
     * An object that cannot satisfy the target schema is reported by uuid and
     * skipped — the rest of the migration continues. The fix is never a
     * weakened `required` list.
     *
     * @return void
     */
    public function testAFailingObjectIsReportedAndDoesNotStopTheRest(): void
    {
        $step = $this->makeStep(
            [
                'Commitment' => [
                    ['id' => 'c-bad', 'verplichtingsnummer' => 'PO-BAD'],
                    ['id' => 'c-ok', 'verplichtingsnummer' => 'PO-OK'],
                ],
            ],
            failIds: ['c-bad']
        );
        $step->run($this->makeOutput());

        $saves = $this->savesForSchema('Commitment');
        self::assertCount(1, $saves);
        self::assertSame('PO-OK', $saves[0]['object']['commitmentNumber']);
        self::assertCount(1, $this->warningsContaining('c-bad'));

    }//end testAFailingObjectIsReportedAndDoesNotStopTheRest()

    /**
     * A missing source schema is normal (fresh install, already migrated) and
     * must not warn or throw.
     *
     * @return void
     */
    public function testMissingSourceSchemaIsNotAFailure(): void
    {
        $step = $this->makeStep([], failFindAllSchemas: ['Verplichting']);

        $step->run($this->makeOutput());

        self::assertSame([], $this->fake->saves);
        self::assertSame([], $this->warningsContaining('Verplichting'));

    }//end testMissingSourceSchemaIsNotAFailure()

    /**
     * An OpenRegister ObjectEntity row (getObject() payload) migrates exactly
     * as a plain array row does — a blind `(array)` cast would read nothing.
     *
     * @return void
     */
    public function testEntityRowsAreMigrated(): void
    {
        $entity = new class {
            /**
             * Return the schema payload, as OpenRegister's ObjectEntity does.
             *
             * @return array<string,mixed> The payload.
             */
            public function getObject(): array
            {
                return ['id' => 'c-9', 'verplichtingsnummer' => 'PO-9'];

            }//end getObject()
        };

        $step = $this->makeStep(['Commitment' => [$entity]]);
        $step->run($this->makeOutput());

        self::assertSame('PO-9', $this->savesForSchema('Commitment')[0]['object']['commitmentNumber']);

    }//end testEntityRowsAreMigrated()

    /**
     * A row with no persisted identifier is skipped, never saved — saving it
     * would CREATE a duplicate instead of updating.
     *
     * @return void
     */
    public function testRowWithoutAnIdentifierIsSkipped(): void
    {
        $step = $this->makeStep(['Commitment' => [['verplichtingsnummer' => 'PO-NO-ID']]]);
        $step->run($this->makeOutput());

        self::assertSame([], $this->fake->saves);
        self::assertCount(1, $this->warningsContaining('unidentifiable'));

    }//end testRowWithoutAnIdentifierIsSkipped()

    /**
     * A container that cannot resolve OpenRegister degrades to a warning — a
     * repair step must never brick an upgrade.
     *
     * @return void
     */
    public function testMissingObjectServiceIsFailSoft(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')->willThrowException(new \RuntimeException('no OR'));

        $step = new MigrateCommitmentDomainToEnglish(
            $this->settingsService,
            $this->logger,
            $this->container
        );

        $step->run($this->makeOutput());

        self::assertNotSame([], $this->warningsContaining('migration failed'));

    }//end testMissingObjectServiceIsFailSoft()

    /**
     * All saveObject calls recorded for one schema.
     *
     * @param string $schema The schema slug.
     *
     * @return array<int,array{object:array<string,mixed>,schema:string}> The recorded saves.
     */
    private function savesForSchema(string $schema): array
    {
        return array_values(
            array_filter(
                $this->fake->saves,
                static function (array $save) use ($schema): bool {
                    return $save['schema'] === $schema;
                }
            )
        );

    }//end savesForSchema()

    /**
     * All emitted warnings containing a needle.
     *
     * @param string $needle The substring to look for.
     *
     * @return array<int,string> The matching warnings.
     */
    private function warningsContaining(string $needle): array
    {
        return array_values(
            array_filter(
                $this->warnings,
                static function (string $warning) use ($needle): bool {
                    return str_contains($warning, $needle);
                }
            )
        );

    }//end warningsContaining()

    /**
     * An IOutput whose warnings are captured for assertions.
     *
     * @return IOutput The output mock.
     */
    private function makeOutput(): IOutput
    {
        $output = $this->createMock(IOutput::class);
        $output->method('warning')->willReturnCallback(
            function (string $message): void {
                $this->warnings[] = $message;
            }
        );

        return $output;

    }//end makeOutput()

    /**
     * Build the repair step around a fake ObjectService.
     *
     * @param array<string,array<int,mixed>> $rowsBySchema       Fixture rows keyed by schema slug.
     * @param array<int,string>              $failIds            Object ids whose saveObject() throws.
     * @param array<int,string>              $failFindAllSchemas Schemas whose findAll() throws.
     *
     * @return MigrateCommitmentDomainToEnglish The wired step.
     */
    private function makeStep(
        array $rowsBySchema,
        array $failIds=[],
        array $failFindAllSchemas=[]
    ): MigrateCommitmentDomainToEnglish {
        $this->fake = new class($rowsBySchema, $failIds, $failFindAllSchemas) {

            /**
             * Fixture rows keyed by schema.
             *
             * @var array<string,array<int,mixed>>
             */
            public array $rowsBySchema;

            /**
             * Object ids whose saveObject() throws.
             *
             * @var array<int,string>
             */
            public array $failIds;

            /**
             * Schemas whose findAll() throws.
             *
             * @var array<int,string>
             */
            public array $failFindAllSchemas;

            /**
             * The schema currently selected by setSchema().
             *
             * @var string
             */
            public string $schema = '';

            /**
             * Recorded saveObject calls.
             *
             * @var array<int,array{object:array<string,mixed>,register:string,schema:string}>
             */
            public array $saves = [];

            /**
             * Build the fake.
             *
             * @param array<string,array<int,mixed>> $rowsBySchema       Fixture rows keyed by schema.
             * @param array<int,string>              $failIds            Ids whose save fails.
             * @param array<int,string>              $failFindAllSchemas Schemas whose findAll fails.
             */
            public function __construct(
                array $rowsBySchema,
                array $failIds,
                array $failFindAllSchemas
            ) {
                $this->rowsBySchema       = $rowsBySchema;
                $this->failIds            = $failIds;
                $this->failFindAllSchemas = $failFindAllSchemas;

            }//end __construct()

            /**
             * Fluent register setter (no-op).
             *
             * @param string $register The register slug.
             *
             * @return self This fake.
             */
            public function setRegister(string $register): self
            {
                return $this;

            }//end setRegister()

            /**
             * Fluent schema setter.
             *
             * @param string $schema The schema slug.
             *
             * @return self This fake.
             */
            public function setSchema(string $schema): self
            {
                $this->schema = $schema;

                return $this;

            }//end setSchema()

            /**
             * Return the fixture rows for the selected schema.
             *
             * @param array<string,mixed> $config The find configuration.
             *
             * @return array<int,mixed> The rows.
             *
             * @throws \RuntimeException When the schema is configured to fail.
             */
            public function findAll(array $config=[]): array
            {
                if (in_array($this->schema, $this->failFindAllSchemas, true) === true) {
                    throw new \RuntimeException('schema '.$this->schema.' does not exist');
                }

                if (((int) ($config['offset'] ?? 0)) > 0) {
                    return [];
                }

                return ($this->rowsBySchema[$this->schema] ?? []);

            }//end findAll()

            /**
             * Record a save.
             *
             * @param array<string,mixed> $object        The payload.
             * @param string              $register      The register slug.
             * @param string              $schema        The schema slug.
             * @param bool                $_rbac         RBAC toggle (ignored).
             * @param bool                $_multitenancy Multitenancy toggle (ignored).
             * @param object|null         $currentUser   The acting user (ignored).
             *
             * @return array<string,mixed> The saved payload.
             *
             * @throws \RuntimeException When the object id is configured to fail.
             */
            public function saveObject(
                array $object,
                string $register,
                string $schema,
                bool $_rbac=true,
                bool $_multitenancy=true,
                ?object $currentUser=null
            ): array {
                $id = (string) ($object['id'] ?? '');
                if (in_array($id, $this->failIds, true) === true) {
                    throw new \RuntimeException('required property missing');
                }

                $this->saves[] = [
                    'object'   => $object,
                    'register' => $register,
                    'schema'   => $schema,
                ];

                return $object;

            }//end saveObject()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id): object {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $this->fake;
                }

                throw new \RuntimeException('not available in unit context: '.$id);
            }
        );

        return new MigrateCommitmentDomainToEnglish(
            $this->settingsService,
            $this->logger,
            $container
        );

    }//end makeStep()
}//end class
