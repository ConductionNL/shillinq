<?php

/**
 * Shillinq MigrateCommitmentDomainToEnglish Repair Step
 *
 * Data migration for the Dutch -> English rename of the commitment domain
 * (`docs/commitment-domain-english-rename-map.md`, shillinq#485).
 *
 * WHY THIS EXISTS. A property rename is a DATA MIGRATION, not a text edit.
 * OpenRegister materialises a schema's declared properties as real columns
 * (MagicMapper::buildTableColumnsFromSchema) and reads/writes only what the
 * schema declares. The moment `Verplichting.verplichtingsnummer` stops being
 * declared, every stored value under that name becomes invisible to the app:
 * no error, no warning, CI fully green, and the number is simply gone from
 * every screen. Renaming the SCHEMA is worse still — OpenRegister's importer
 * resolves schemas by slug (`ImportHandler::findByApplicationAndSlug`), so a
 * changed slug does not rename the schema, it CREATES A NEW ONE and leaves
 * every existing object stranded under the old one, unreachable by an app
 * that now only ever asks for `Commitment`.
 *
 * The rename itself (PR #495) shipped no such migration. This step is it.
 *
 * WHAT IT DOES. Two passes, both driven off the same declarative maps:
 *
 *   1. SLUG MOVE. For every renamed schema (`Verplichting` -> `Commitment`,
 *      ...) every object still stored under the OLD slug is re-pointed to the
 *      NEW slug, KEEPING ITS UUID, with its payload rewritten to the English
 *      property names and enum values. Keeping the uuid is not cosmetic: the
 *      whole domain is joined by uuid (`CommitmentLine.commitment`,
 *      `CommitmentMovement.commitment`, `ApprovalStep.commitment`,
 *      `TenderNedProcurement.commitmentId`, `OrderFulfilment.commitmentId`),
 *      so a copy under a fresh uuid would silently orphan every child row.
 *
 *   2. IN-PLACE KEY MOVE. For every schema in scope — including `Budget` and
 *      `Requisition`, whose slugs did NOT change but whose properties did —
 *      objects already stored under the NEW slug but still carrying OLD keys
 *      are rewritten in place. This covers a slug renamed by hand in the
 *      OpenRegister UI (which keeps the objects), a half-completed earlier
 *      run, and any row written by mixed-version code mid-upgrade.
 *
 * READING THE OLD VALUE STILL WORKS. Obsolete columns are not dropped —
 * `MagicMapper::syncTableForRegisterSchema` only ADDS columns and makes
 * obsolete ones nullable — and the read path is `SELECT *`, with
 * `rowToObjectEntity()` returning every column it finds. An undeclared
 * property therefore still comes back in `getObject()`, but under its raw
 * COLUMN name, which for a camelCase property is its snake_case form
 * (`verplichtingNummer` is stored in `verplichting_nummer` and reads back as
 * `verplichting_nummer` once the schema stops declaring it). Every camelCase
 * legacy name below is therefore listed with its snake_case column form as an
 * additional source key. Generic (non-magic) storage returns the key verbatim;
 * listing both covers both storage modes.
 *
 * THE SPLIT BRAIN. `Commitment` had TWO live vocabularies on opposite sides of
 * one flow: `verplichtingsnummer` (declared by the owning fragment
 * `bookkeeping-verplichtingenadministratie.json`, read by
 * `BudgetBlocker::canCommit`) and `verplichtingNummer` (written by
 * `TenderNedAwardDetectedListener`, read by the commitment guard). Both
 * spellings are separate columns, so stored data in the wild may carry either
 * — or both, disagreeing. Same for `looptijd_van`/`looptijdStart` and
 * `looptijd_tot`/`looptijdEind`.
 *
 * The rule, applied per target property: SOURCES ARE ORDERED BY PRECEDENCE AND
 * THE FIRST NON-EMPTY ONE WINS. The declared spelling of the OWNING fragment
 * always comes first, because that is the value every reader in the app
 * actually consumed. When two sources disagree the winner is applied AND a
 * warning naming the object uuid, both keys and both values is emitted, so the
 * loser is never lost silently. It is not lost at all: this step never clears
 * the legacy column, and OpenRegister only writes DECLARED columns on update,
 * so the losing value stays exactly where it was and remains recoverable.
 *
 * IDEMPOTENT, THREE WAYS.
 *   - Already migrated: a target property that already holds a non-empty value
 *     is NEVER overwritten; and on the slug pass an object whose uuid — OR whose
 *     business identity (see NATURAL_KEYS) — already resolves under the target
 *     schema is skipped whole, so a second run can never clobber an operator's
 *     post-migration edit with stale source data, and a row something else has
 *     already recreated under the new schema is left alone rather than doubled.
 *   - Never written under either name: no source key present means no change
 *     and no save at all.
 *   - Written under both: resolved by the precedence rule above, once — the
 *     second run sees the target already populated and does nothing.
 *
 * NON-DESTRUCTIVE. No object is deleted, no legacy column is cleared, no
 * `required` list is relaxed. An object that cannot satisfy the target
 * schema's `required` list (e.g. a legacy commitment that never carried
 * `soort`) fails its own save, is reported by uuid, and is left untouched for
 * an operator to fix — it is NOT forced through by weakening the schema.
 *
 * FAIL-SOFT. Per object and per schema. A repair step must not brick an
 * upgrade; every failure is warned + logged with the identifier needed to
 * re-run it.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude Data migration for the commitment-domain rename; the vocabulary
 *       is specified in docs/commitment-domain-english-rename-map.md and in the
 *       register fragments themselves, not in an openspec change.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that migrates stored commitment-domain objects from the Dutch
 * schema slugs / property names / enum values to their English replacements.
 *
 * @spec exclude Data migration for the commitment-domain rename; the vocabulary
 *       is specified in docs/commitment-domain-english-rename-map.md and in the
 *       register fragments themselves, not in an openspec change.
 */
class MigrateCommitmentDomainToEnglish implements IRepairStep
{
    use ReadsSourceRowsInBatches;

    /**
     * Renamed schema slugs: OLD slug => NEW slug.
     *
     * Objects stored under the old slug are re-pointed to the new one,
     * preserving their uuid so cross-schema references keep resolving.
     *
     * @var array<string,string>
     */
    public const SCHEMA_RENAMES = [
        'Verplichting'          => 'Commitment',
        'Verplichtingsregel'    => 'CommitmentLine',
        'Verplichtingsmutatie'  => 'CommitmentMovement',
        'Mandaat'               => 'Mandate',
        'Goedkeuringsstap'      => 'ApprovalStep',
        'TenderNedAanbesteding' => 'TenderNedProcurement',
        'OpdrachtUitvoering'    => 'OrderFulfilment',
    ];

    /**
     * Schemas whose SLUG did not change but whose PROPERTIES did.
     *
     * `Budget` carries the formula inputs `vrije_ruimte` is computed from
     * (`geautoriseerd_bedrag - gerealiseerd_bedrag - openstaande_verplichtingen`);
     * `Requisition` deliberately mirrors Commitment's field contract so
     * `BudgetBlocker::canCommit` runs unmodified against both. A half-migrated
     * Budget or Requisition therefore breaks the budget check silently — the
     * guard reads with `??` and a missing figure reads as a zero budget.
     *
     * @var array<int,string>
     */
    public const IN_PLACE_SCHEMAS = [
        'Budget',
        'Requisition',
    ];

    /**
     * Property migration map: TARGET schema slug => TARGET property =>
     * ORDERED list of legacy source keys, most authoritative first.
     *
     * Ordering is the split-brain policy: the first source key holding a
     * non-empty value wins. camelCase legacy names are followed by their
     * snake_case column form, which is how OpenRegister reads back a property
     * the schema no longer declares.
     *
     * @var array<string,array<string,array<int,string>>>
     */
    public const PROPERTY_SOURCES = [
        'Commitment'           => [
            // `verplichtingsnummer` is the spelling declared by the OWNING
            // fragment and read by BudgetBlocker; `verplichtingNummer` was
            // only ever written by TenderNedAwardDetectedListener, whose
            // writes the duplicated `required` list rejected outright.
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
     * Enum value migration map: TARGET schema slug => TARGET property =>
     * OLD value => NEW value.
     *
     * Applied AFTER the key move, to the value sitting on the target key. A
     * value not present in the map is left exactly as it is, which is what
     * makes this safe to re-run and safe for the values that were already
     * English (`Commitment.status` additionally carries `active` / `archived`
     * from the TenderNed fragment, and `TenderNedProcurement.status.open` is
     * unchanged — none of them appear here, so none of them are touched).
     *
     * `soort` deliberately maps to two DIFFERENT English properties depending
     * on the schema (`commitmentType` on Commitment, `movementType` on
     * CommitmentMovement); keying this map by schema is what keeps those apart.
     *
     * @var array<string,array<string,array<string,string>>>
     */
    public const VALUE_RENAMES = [
        'Commitment'           => [
            'commitmentType' => [
                'inkooporder'         => 'purchaseOrder',
                'raamovereenkomst'    => 'frameworkAgreement',
                'arbeidscontract'     => 'employmentContract',
                'subsidiebeschikking' => 'grantDecision',
                'huurovereenkomst'    => 'rentalAgreement',
                'leasing'             => 'lease',
                'overig'              => 'other',
            ],
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
            'commitmentType' => [
                'inkooporder'         => 'purchaseOrder',
                'raamovereenkomst'    => 'frameworkAgreement',
                'arbeidscontract'     => 'employmentContract',
                'subsidiebeschikking' => 'grantDecision',
                'huurovereenkomst'    => 'rentalAgreement',
                'leasing'             => 'lease',
                'overig'              => 'other',
            ],
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
            'commitmentType' => [
                'inkooporder'         => 'purchaseOrder',
                'raamovereenkomst'    => 'frameworkAgreement',
                'arbeidscontract'     => 'employmentContract',
                'subsidiebeschikking' => 'grantDecision',
                'huurovereenkomst'    => 'rentalAgreement',
                'leasing'             => 'lease',
                'overig'              => 'other',
            ],
        ],
    ];

    /**
     * Business identity per target schema, used to recognise a row that has
     * ALREADY been recreated under the new schema by something other than this
     * step — so migrating the legacy row would duplicate it rather than move it.
     *
     * This is not hypothetical. `InitializeSettings` runs FIRST (it is what
     * imports the register and creates the English schemas) and, among other
     * things, seeds the mandate templates, deduplicating on
     * `mandateCode` + `administrationId`. Against a freshly-created `Mandate`
     * schema it finds nothing and creates every template. Without the check
     * below, this step would then migrate the instance's old `Mandaat` rows on
     * top of them and leave TWO mandates per code — and two matching mandates
     * is not a cosmetic problem for a signing-authority lookup.
     *
     * Schemas with no meaningful business identity (movements, fulfilments) are
     * deliberately absent: they are append-only events, nothing else recreates
     * them, and inventing a key for them would suppress genuine rows.
     *
     * @var array<string,array<int,string>>
     */
    public const NATURAL_KEYS = [
        'Commitment'           => ['administrationId', 'commitmentNumber'],
        'CommitmentLine'       => ['administrationId', 'commitment', 'lineNumber'],
        'Mandate'              => ['administrationId', 'mandateCode'],
        'ApprovalStep'         => ['administrationId', 'commitment', 'stepNumber'],
        'TenderNedProcurement' => ['administrationId', 'procurementId'],
    ];

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService The settings service (register slug).
     * @param LoggerInterface    $logger          The logger interface.
     * @param ContainerInterface $container       The DI container (lazy OR ObjectService resolution).
     */
    public function __construct(
        private SettingsService $settingsService,
        private LoggerInterface $logger,
        private ContainerInterface $container,
    ) {
    }//end __construct()

    /**
     * The repair-step display name.
     *
     * @return string The display name.
     *
     * @spec exclude IRepairStep boilerplate — returns the operator-facing label.
     */
    public function getName(): string
    {
        return 'Shillinq: migrate stored commitment-domain objects to the English schema and property names';

    }//end getName()

    /**
     * Run the migration. Idempotent and non-destructive; see the class
     * docblock for the exact rules.
     *
     * @param IOutput $output The repair-step output (progress + warnings).
     *
     * @return void
     *
     * @spec exclude Data migration for the commitment-domain rename; the vocabulary
     *       is specified in docs/commitment-domain-english-rename-map.md and in the
     *       register fragments themselves, not in an openspec change.
     */
    public function run(IOutput $output): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->settingsService->getRegisterSlug();

            // Commitment objects carry a `documents` file reference, so their
            // save goes through the Files layer, which checks the ACTING
            // USER's folder access. A session-less repair context has none and
            // the write is denied silently (live-verified on 8080 for
            // RematerialiseConvertedCalculations, where 173 objects failed to
            // re-save). Resolve an admin IUser and pass it as currentUser.
            $admin = $this->resolveAdminUser();

            $moved   = 0;
            $updated = 0;

            // Pass 1: objects still stored under a renamed (old) schema slug.
            foreach (self::SCHEMA_RENAMES as $oldSlug => $newSlug) {
                $moved += $this->migrateSchema(
                    objectService: $objectService,
                    registerSlug: $registerSlug,
                    sourceSlug: $oldSlug,
                    targetSlug: $newSlug,
                    output: $output,
                    admin: $admin
                );
            }

            // Pass 2: objects already under the new slug (or a slug that never
            // changed) but still carrying legacy property keys / enum values.
            $inPlaceSlugs = array_merge(array_values(self::SCHEMA_RENAMES), self::IN_PLACE_SCHEMAS);
            foreach ($inPlaceSlugs as $slug) {
                $updated += $this->migrateSchema(
                    objectService: $objectService,
                    registerSlug: $registerSlug,
                    sourceSlug: $slug,
                    targetSlug: $slug,
                    output: $output,
                    admin: $admin
                );
            }

            $output->info(
                'Shillinq: commitment-domain English migration complete — '
                .$moved.' object(s) moved to a renamed schema, '
                .$updated.' object(s) migrated in place.'
            );
        } catch (\Throwable $e) {
            // Best-effort: a failed migration must NOT block the app upgrade.
            // Log + warn so an operator can re-run `occ maintenance:repair`.
            $output->warning('Shillinq: commitment-domain English migration failed: '.$e->getMessage());
            $this->logger->warning(
                'Shillinq: MigrateCommitmentDomainToEnglish failed',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end run()

    /**
     * Migrate every object of one source schema onto one target schema.
     *
     * When `$sourceSlug === $targetSlug` this is an in-place key/value
     * migration; when they differ it additionally re-points the object to the
     * target schema, keeping its uuid.
     *
     * Best-effort per object: one object failing is warned and skipped, never
     * fatal to the rest.
     *
     * @param object     $objectService The OR ObjectService.
     * @param string     $registerSlug  The register slug.
     * @param string     $sourceSlug    The schema slug to read from.
     * @param string     $targetSlug    The schema slug to write to.
     * @param IOutput    $output        The repair-step output.
     * @param IUser|null $admin         The acting admin user (folder access), or null.
     *
     * @return int The number of objects actually saved.
     */
    private function migrateSchema(
        object $objectService,
        string $registerSlug,
        string $sourceSlug,
        string $targetSlug,
        IOutput $output,
        ?IUser $admin
    ): int {
        try {
            $rows = $this->readAllRows(
                objectService: $objectService,
                registerSlug: $registerSlug,
                schema: $sourceSlug
            );
        } catch (\Throwable $e) {
            // A retired/never-created source schema is the NORMAL case on a
            // fresh or already-migrated instance — info, not a warning.
            $output->info(
                'Shillinq: no readable '.$sourceSlug.' objects to migrate ('.$e->getMessage().')'
            );
            return 0;
        }

        if ($rows === []) {
            return 0;
        }

        $isSlugMove = ($sourceSlug !== $targetSlug);
        $saved      = 0;

        // Build the "already present under the target" index ONCE per schema
        // rather than probing per row: one extra read instead of N.
        $alreadyThere = [];
        if ($isSlugMove === true) {
            $alreadyThere = $this->indexTarget(
                objectService: $objectService,
                registerSlug: $registerSlug,
                targetSlug: $targetSlug
            );
        }

        foreach ($rows as $row) {
            $data = $this->rowPayload(row: $row);
            $id   = (string) ($data['id'] ?? ($data['uuid'] ?? ''));
            if ($id === '') {
                // No persisted identifier — saving would CREATE a duplicate
                // rather than update. Skip rather than risk it.
                $output->warning(
                    'Shillinq: skipped an unidentifiable '.$sourceSlug.' object (no id/uuid)'
                );
                continue;
            }

            // Already migrated (slug pass only): the uuid already resolves under
            // the target schema, so re-writing it from this stale source row
            // would clobber whatever has happened to it since.
            if ($isSlugMove === true && isset($alreadyThere[$id]) === true) {
                continue;
            }

            $migrated = $this->migratePayload(
                data: $data,
                targetSlug: $targetSlug,
                id: $id,
                output: $output
            );

            // Already recreated under the target by something else (typically
            // InitializeSettings' seeds, which run first): moving this legacy
            // row would DUPLICATE the business object, not migrate it.
            if ($isSlugMove === true) {
                $naturalKey = $this->naturalKey(data: ($migrated ?? $data), schema: $targetSlug);
                if ($naturalKey !== null && isset($alreadyThere[$naturalKey]) === true) {
                    $output->info(
                        'Shillinq: '.$sourceSlug.' '.$id.' already exists under '.$targetSlug
                        .' as '.$naturalKey.' — left in place, not duplicated'
                    );
                    continue;
                }
            }

            // Nothing to do: never written under a legacy name, and not moving
            // schema either. No save at all — this is what makes a second run
            // a genuine no-op rather than a mass re-save.
            if ($migrated === null && $isSlugMove === false) {
                continue;
            }

            $payload = ($migrated ?? $data);

            try {
                $objectService->saveObject(
                    object: $payload,
                    register: $registerSlug,
                    schema: $targetSlug,
                    _rbac: false,
                    _multitenancy: false,
                    currentUser: $admin,
                );
                $saved++;
            } catch (\Throwable $e) {
                // Typically a `required` violation on a legacy object that
                // never carried the now-mandatory field. Report it by uuid and
                // leave it alone — the fix is the object or the operator's
                // data, never a weakened schema.
                $output->warning(
                    'Shillinq: failed to migrate '.$sourceSlug.' '.$id.' to '.$targetSlug.': '.$e->getMessage()
                );
                $this->logger->warning(
                    'Shillinq: MigrateCommitmentDomainToEnglish saveObject failed',
                    [
                        'sourceSchema' => $sourceSlug,
                        'targetSchema' => $targetSlug,
                        'id'           => $id,
                        'exception'    => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        return $saved;

    }//end migrateSchema()

    /**
     * Rewrite one object payload from the legacy vocabulary to the English one.
     *
     * Returns null when the payload needs no change at all, so the caller can
     * skip the save entirely.
     *
     * @param array<string,mixed> $data       The stored object payload.
     * @param string              $targetSlug The target schema slug.
     * @param string              $id         The object id/uuid (for warnings).
     * @param IOutput             $output     The repair-step output.
     *
     * @return array<string,mixed>|null The migrated payload, or null when unchanged.
     */
    private function migratePayload(array $data, string $targetSlug, string $id, IOutput $output): ?array
    {
        $sources = (self::PROPERTY_SOURCES[$targetSlug] ?? []);
        $result  = $data;
        $changed = false;

        foreach ($sources as $target => $legacyKeys) {
            // Already migrated: never overwrite a populated target. This is
            // the single rule that makes the step safe to re-run and safe on
            // an object written under BOTH vocabularies.
            if ($this->hasValue(data: $result, key: $target) === true) {
                continue;
            }

            $candidates = [];
            foreach ($legacyKeys as $legacyKey) {
                if ($this->hasValue(data: $data, key: $legacyKey) === true) {
                    $candidates[$legacyKey] = $data[$legacyKey];
                }
            }

            if ($candidates === []) {
                continue;
            }

            $winnerKey = (string) array_key_first($candidates);
            $winner    = $candidates[$winnerKey];

            $this->warnOnDisagreement(
                candidates: $candidates,
                winnerKey: $winnerKey,
                target: $target,
                targetSlug: $targetSlug,
                id: $id,
                output: $output
            );

            $result[$target] = $winner;
            $changed         = true;
        }//end foreach

        // Drop the legacy keys from the payload we send. The STORED legacy
        // column is untouched by this (OpenRegister writes only the columns the
        // schema declares), which is exactly why a losing split-brain value
        // stays recoverable.
        //
        // Deliberately does NOT set $changed. An already-migrated object still
        // READS its retained legacy column back on every run (obsolete columns
        // are never dropped and the read is `SELECT *`), so counting this as a
        // change would make every `occ maintenance:repair` re-save the entire
        // domain forever and report a migration that never converges.
        foreach ($sources as $legacyKeys) {
            foreach ($legacyKeys as $legacyKey) {
                unset($result[$legacyKey]);
            }
        }

        // Enum values, applied to the target keys.
        foreach ((self::VALUE_RENAMES[$targetSlug] ?? []) as $property => $valueMap) {
            if (array_key_exists($property, $result) === false || is_string($result[$property]) === false) {
                continue;
            }

            $current = $result[$property];
            if (array_key_exists($current, $valueMap) === false) {
                continue;
            }

            $result[$property] = $valueMap[$current];
            $changed           = true;
        }

        if ($changed === false) {
            return null;
        }

        return $result;

    }//end migratePayload()

    /**
     * Warn when two legacy spellings of the same field hold DIFFERENT values.
     *
     * The winner is still applied (precedence order); this only makes sure the
     * loser is never discarded silently. The losing value itself survives in
     * its own legacy column, which this step never clears.
     *
     * @param array<string,mixed> $candidates Non-empty legacy values, keyed by legacy key, in precedence order.
     * @param string              $winnerKey  The legacy key that won.
     * @param string              $target     The target property name.
     * @param string              $targetSlug The target schema slug.
     * @param string              $id         The object id/uuid.
     * @param IOutput             $output     The repair-step output.
     *
     * @return void
     */
    private function warnOnDisagreement(
        array $candidates,
        string $winnerKey,
        string $target,
        string $targetSlug,
        string $id,
        IOutput $output
    ): void {
        if (count($candidates) < 2) {
            return;
        }

        $winner = $candidates[$winnerKey];
        $losers = [];
        foreach ($candidates as $key => $value) {
            if ($key === $winnerKey) {
                continue;
            }

            if ($this->sameScalar(a: $value, b: $winner) === true) {
                continue;
            }

            $losers[] = $key.'='.$this->describe(value: $value);
        }

        if ($losers === []) {
            return;
        }

        $message = 'Shillinq: '.$targetSlug.' '.$id.' carries conflicting legacy values for '
            .$target.' — using '.$winnerKey.'='.$this->describe(value: $winner)
            .', NOT using '.implode(', ', $losers)
            .' (the unused value is preserved in its own legacy column)';

        $output->warning($message);
        $this->logger->warning(
            'Shillinq: MigrateCommitmentDomainToEnglish split-brain value conflict',
            [
                'schema'    => $targetSlug,
                'id'        => $id,
                'property'  => $target,
                'winnerKey' => $winnerKey,
                'losers'    => $losers,
            ]
        );

    }//end warnOnDisagreement()

    /**
     * Whether a payload carries a usable (present, non-null, non-empty-string)
     * value under a key.
     *
     * `0`, `0.0` and `false` ARE usable values — a zero amount and an
     * unapproved flag are real data, and treating them as absent is precisely
     * how a migration loses them.
     *
     * @param array<string,mixed> $data The payload.
     * @param string              $key  The key to test.
     *
     * @return bool True when the key holds a usable value.
     */
    private function hasValue(array $data, string $key): bool
    {
        if (array_key_exists($key, $data) === false) {
            return false;
        }

        $value = $data[$key];

        return ($value !== null && $value !== '' && $value !== []);

    }//end hasValue()

    /**
     * Loose equality for two stored values, used only to decide whether a
     * split brain is a genuine DISAGREEMENT or the same value written twice.
     *
     * @param mixed $a The first value.
     * @param mixed $b The second value.
     *
     * @return bool True when the two values are equivalent.
     */
    private function sameScalar(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) === false || is_scalar($b) === false) {
            return ($a == $b);
        }

        return ((string) $a === (string) $b);

    }//end sameScalar()

    /**
     * Render a stored value for a human-readable warning.
     *
     * @param mixed $value The value.
     *
     * @return string A short printable form.
     */
    private function describe(mixed $value): string
    {
        if (is_scalar($value) === true) {
            return (string) $value;
        }

        $encoded = json_encode($value);
        if ($encoded === false) {
            return '<unprintable>';
        }

        return substr($encoded, 0, 120);

    }//end describe()

    /**
     * Index everything already stored under the target schema, by uuid and by
     * business identity, as the slug-move idempotency gate.
     *
     * An unreadable/absent target schema yields an EMPTY index, which is the
     * correct reading: nothing is there yet, so nothing is a duplicate.
     *
     * @param object $objectService The OR ObjectService.
     * @param string $registerSlug  The register slug.
     * @param string $targetSlug    The target schema slug.
     *
     * @return array<string,true> A set of uuids and natural keys already present.
     */
    private function indexTarget(object $objectService, string $registerSlug, string $targetSlug): array
    {
        try {
            $rows = $this->readAllRows(
                objectService: $objectService,
                registerSlug: $registerSlug,
                schema: $targetSlug
            );
        } catch (\Throwable) {
            return [];
        }

        $index = [];
        foreach ($rows as $row) {
            $data = $this->rowPayload(row: $row);

            $id = (string) ($data['id'] ?? ($data['uuid'] ?? ''));
            if ($id !== '') {
                $index[$id] = true;
            }

            $naturalKey = $this->naturalKey(data: $data, schema: $targetSlug);
            if ($naturalKey !== null) {
                $index[$naturalKey] = true;
            }
        }

        return $index;

    }//end indexTarget()

    /**
     * Build the business identity of an object, or null when the schema has no
     * declared identity or the object does not carry a complete one.
     *
     * Returning null on an INCOMPLETE key is deliberate: a key built from
     * missing parts would be identical for every such object and would suppress
     * migrating all but the first of them.
     *
     * @param array<string,mixed> $data   The (already migrated) object payload.
     * @param string              $schema The target schema slug.
     *
     * @return string|null The identity string, or null when there is none.
     */
    private function naturalKey(array $data, string $schema): ?string
    {
        $parts = (self::NATURAL_KEYS[$schema] ?? []);
        if ($parts === []) {
            return null;
        }

        $values = [];
        foreach ($parts as $part) {
            if ($this->hasValue(data: $data, key: $part) === false) {
                return null;
            }

            if (is_scalar($data[$part]) === false) {
                return null;
            }

            $values[] = (string) $data[$part];
        }

        return $schema.'#'.implode('|', $values);

    }//end naturalKey()

    /**
     * Resolve the first admin-group member as an IUser (never a string) so OR
     * writes that touch the Files/folder layer have folder access. Returns
     * null when no admin exists (best-effort; the save then runs session-less).
     *
     * @return IUser|null The first admin-group member, or null.
     */
    private function resolveAdminUser(): ?IUser
    {
        try {
            $groupManager = $this->container->get(IGroupManager::class);
            $adminGroup   = $groupManager->get('admin');
            if ($adminGroup === null) {
                return null;
            }

            $users = $adminGroup->getUsers();
            if ($users === []) {
                return null;
            }

            return reset($users);
        } catch (\Throwable $e) {
            return null;
        }

    }//end resolveAdminUser()
}//end class
