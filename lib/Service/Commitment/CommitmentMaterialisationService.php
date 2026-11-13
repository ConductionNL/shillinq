<?php

/**
 * Commitment Materialisation Service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-VPL-010/011/012 — thin glue that closes the loop between the PO/
 * contract approval surfaces and the existing verplichtingenadministratie
 * (REQ-VPL-001..009, already shipped). When a PurchaseOrder reaches
 * `approved` or a Contract reaches `active` (the existing Contract
 * lifecycle's "in force" state — no separate `signed`/`executed` state
 * exists on the shipped Contract schema; `active` is treated as the
 * legally-binding trigger, per design.md's Open Question resolution),
 * this service assembles the matching `Commitment` + `CommitmentLine`
 * rows from the source object and delegates to the ALREADY-SHIPPED
 * `MandateEnforcer` and `BudgetBlocker` guards. It computes no budget or
 * mandate logic of its own (ADR-031 thin-glue exception).
 *
 * Fail-closed vs fail-soft: the PO-approval path is fail-closed (an
 * unfunded, non-overridden commitment throws
 * {@see InsufficientCommitmentBudgetException} so the approval itself
 * surfaces the denial, per REQ-VPL-010 scenario "Insufficient budget
 * blocks the approval, not just the invoice"). The Contract-activation
 * path is fail-soft (denial is logged, not thrown) because the existing
 * Contract lifecycle transitions (contract-lifecycle-management) are a
 * different capability's schema and are not modified by this change; the
 * spec's fail-closed scenarios are PO-scoped only.
 *
 * Idempotent on `bronReferentie` (REQ-VPL-010): a repeated transition for
 * the same source object is a no-op.
 *
 * REQ-VPL-012 rechtmatigheid linkage: this service does not create the
 * rechtmatigheid toetsing itself (the toetsing engine is a different
 * capability, out of scope — design.md "does not modify the toetsing
 * engine"). It dispatches a `shillinq.rechtmatigheid.commitment_created`
 * CloudEvent (same lightweight GenericEvent pattern as
 * {@see \OCA\Shillinq\Service\BudgetImpactEmitter}) so a future/external
 * toetsing consumer can react at commitment stage rather than invoice
 * time, and it records any override-mandate reason as a
 * `Rechtmatigheidsbevinding` afwijking (REQ-RV-005 aggregation target) —
 * `Rechtmatigheidsbevinding` has no `journaalpost` FK requirement, unlike
 * `Rechtmatigheidstoets`, so this is the correct target schema for an
 * afwijking raised before any journaalpost exists.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Commitment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Commitment;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\BudgetBlockerInterface;
use OCA\Shillinq\Kernel\Contracts\CommitmentMaterialisationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\MandateEnforcerInterface;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\Shillinq\Kernel\Exception\InsufficientCommitmentBudgetException;

/**
 * Assembles a Commitment + CommitmentLines from an approved
 * PurchaseOrder or an activated Contract and drives it through the
 * existing MandateEnforcer / BudgetBlocker guards (REQ-VPL-010).
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class CommitmentMaterialisationService implements CommitmentMaterialisationServiceInterface {
	/**
	 * CloudEvent name for the rechtmatigheid commitment-stage trigger (REQ-VPL-012).
	 *
	 * @var string
	 */
	public const EVENT_COMMITMENT_CREATED = 'shillinq.rechtmatigheid.commitment_created';

	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param MandateEnforcerInterface $mandate Reused mandate-sufficiency guard (REQ-VPL-002).
	 * @param BudgetBlockerInterface $budget Reused budget-room guard (REQ-VPL-001).
	 * @param IEventDispatcher $dispatcher NC event dispatcher (rechtmatigheid trigger transport).
	 * @param LoggerInterface $logger Logger for fail-soft/fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly MandateEnforcerInterface $mandate,
		private readonly BudgetBlockerInterface $budget,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Materialise a Commitment from an approved PurchaseOrder (REQ-VPL-010).
	 *
	 * Fail-closed: throws {@see InsufficientCommitmentBudgetException} when
	 * budget is insufficient and no override-mandate applies, so the caller
	 * (the listener, running synchronously in the approval write path) lets
	 * the approval itself surface the denial.
	 *
	 * @param array<string, mixed> $purchaseOrder Approved PurchaseOrder payload.
	 *
	 * @return array<string, mixed>|null The materialised (or pre-existing) Commitment, or null when there is nothing to materialise.
	 *
	 * @throws InsufficientCommitmentBudgetException When budget denies and no override applies.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function materialiseFromPurchaseOrder(array $purchaseOrder): ?array {
		$sourceReference = trim((string)($purchaseOrder['poNumber'] ?? ''));
		if ($sourceReference === '') {
			return null;
		}

		return $this->materialise(
			source: $this->purchaseOrderSource(purchaseOrder: $purchaseOrder, sourceReference: $sourceReference)
		);

	}//end materialiseFromPurchaseOrder()

	/**
	 * Materialise a Commitment from an activated Contract (REQ-VPL-010,
	 * Task 2). Fail-soft: any denial is logged, never thrown — the
	 * Contract's own `activate` transition is a different capability
	 * (contract-lifecycle-management) not modified by this change.
	 *
	 * @param array<string, mixed> $contract Activated Contract payload.
	 *
	 * @return array<string, mixed>|null The materialised (or pre-existing) Commitment, or null.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function materialiseFromContract(array $contract): ?array {
		$sourceReference = trim((string)($contract['contractNumber'] ?? ''));
		if ($sourceReference === '') {
			return null;
		}

		$source = $this->contractSource(contract: $contract, sourceReference: $sourceReference);

		try {
			return $this->materialise(source: $source);
		} catch (Throwable $e) {
			// Defensive: materialise() only throws when failClosed=true.
			// Caught here too so a future refactor cannot accidentally
			// make the Contract path block contract activation.
			$this->logger->warning(
				'CommitmentMaterialisationService: contract materialisation failed — fail-soft',
				['sourceReference' => $sourceReference, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end materialiseFromContract()

	/**
	 * The source of an approved PurchaseOrder: its budget-coded lines as
	 * regels, its supplier as counterparty, fail-closed on budget.
	 *
	 * @param array<string, mixed> $purchaseOrder   Approved PurchaseOrder payload.
	 * @param string               $sourceReference The trimmed poNumber.
	 *
	 * @return CommitmentSource
	 */
	private function purchaseOrderSource(array $purchaseOrder, string $sourceReference): CommitmentSource {
		$source = new CommitmentSource();
		$source->sourceReference  = $sourceReference;
		$source->kind             = 'purchase_order';
		$source->administrationId = (string)($purchaseOrder['administrationId'] ?? '');

		$poId = (string)($purchaseOrder['id'] ?? ($purchaseOrder['@self']['id'] ?? ''));
		$source->ruleInputs = $this->buildRulesFromPurchaseOrderLines(
			purchaseOrder: $purchaseOrder,
			lines: $this->findMany(schema: 'PurchaseOrderLine', filters: ['poId' => $poId])
		);

		$source->counterparty = ['kind' => 'supplier', 'contactId' => (string)($purchaseOrder['supplierId'] ?? '')];
		$source->failClosed   = true;
		return $source;
	}//end purchaseOrderSource()

	/**
	 * The source of an activated Contract: its regels, its counterparty
	 * (supplier when inbound, else other), fail-soft on budget.
	 *
	 * @param array<string, mixed> $contract        Activated Contract payload.
	 * @param string               $sourceReference The trimmed contractNumber.
	 *
	 * @return CommitmentSource
	 */
	private function contractSource(array $contract, string $sourceReference): CommitmentSource {
		$source = new CommitmentSource();
		$source->sourceReference  = $sourceReference;
		$source->administrationId = (string)($contract['administrationId'] ?? '');
		$source->ruleInputs       = $this->buildRulesFromContract(contract: $contract);

		$counterpartyKind = 'other';
		if ((string)($contract['direction'] ?? '') === 'inbound') {
			$counterpartyKind = 'supplier';
		}

		$source->counterparty = ['kind' => $counterpartyKind, 'contactId' => (string)($contract['counterpartyReference'] ?? '')];
		$source->kind         = $this->mapContractKind(contractType: (string)($contract['contractType'] ?? ''));
		$source->failClosed   = false;
		return $source;
	}//end contractSource()

	/**
	 * Core materialisation: idempotency check, guard delegation, persistence,
	 * and rechtmatigheid linkage. Shared by both source paths (REQ-VPL-010).
	 *
	 * @param CommitmentSource $source The PO or contract to materialise.
	 *
	 * @return array<string, mixed>|null The materialised (or pre-existing) Commitment, or null when nothing to do.
	 *
	 * @throws InsufficientCommitmentBudgetException When fail-closed and budget denies with no override.
	 */
	private function materialise(CommitmentSource $source): ?array {
		$existing = $this->findExistingBySourceReference(sourceReference: $source->sourceReference);
		if ($existing !== null) {
			// REQ-VPL-010 idempotency: a repeated transition is a no-op.
			return $existing;
		}

		if ($source->ruleInputs === []) {
			$this->logger->info(
				'CommitmentMaterialisationService: no budget-coded lines — skipping materialisation',
				['sourceReference' => $source->sourceReference]
			);
			return null;
		}

		return $this->guardAndPersist(draft: $this->draftFor(source: $source), source: $source);
	}//end materialise()

	/**
	 * The draft Commitment for a source, dated today (UTC).
	 *
	 * @param CommitmentSource $source The PO or contract to materialise.
	 *
	 * @return array<string, mixed> The draft Commitment.
	 */
	private function draftFor(CommitmentSource $source): array {
		$total = 0;
		foreach ($source->ruleInputs as $rule) {
			$total += (int)($rule['amount_excl_vat'] ?? 0);
		}

		return [
			'administrationId' => $source->administrationId,
			'commitmentNumber' => $source->sourceReference,
			'sourceReference' => $source->sourceReference,
			'kind' => $source->kind,
			'status' => 'draft',
			'total_amount_excl_vat' => $total,
			'counterparty' => $source->counterparty,
			'rules' => $source->ruleInputs,
			'commencementDate' => (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d'),
		];
	}//end draftFor()

	/**
	 * Run the draft through the mandate and budget guards and persist what
	 * they allow.
	 *
	 * REQ-VPL-002 parity: no sufficient mandate routes to in_approval (the
	 * existing `indienen` semantics) without a budget check — the fail-closed
	 * budget guarantee only applies to the direct-commit path.
	 *
	 * @param array<string, mixed> $draft  The draft Commitment.
	 * @param CommitmentSource     $source The PO or contract to materialise.
	 *
	 * @return array<string, mixed>|null The saved Commitment, or null when budget denies fail-soft.
	 *
	 * @throws InsufficientCommitmentBudgetException When fail-closed and budget denies.
	 */
	private function guardAndPersist(array $draft, CommitmentSource $source): ?array {
		if ($this->mandate->hasSufficientMandate(commitmentNumber: $source->sourceReference, object: $draft) === false) {
			$draft['status'] = 'in_approval';
			return $this->persistAndAnnounce(draft: $draft, source: $source);
		}

		if ($this->budget->canCommit(commitmentNumber: $source->sourceReference, object: $draft) === false) {
			$this->refuseUnfunded(source: $source);
			return null;
		}

		$draft['status'] = 'committed';
		$draft = $this->withApplicableMandate(draft: $draft, sourceReference: $source->sourceReference);
		return $this->persistAndAnnounce(draft: $draft, source: $source);
	}//end guardAndPersist()

	/**
	 * Refuse an unfunded commitment: throw on the fail-closed path, log and
	 * skip on the fail-soft one.
	 *
	 * @param CommitmentSource $source The PO or contract to materialise.
	 *
	 * @return void
	 *
	 * @throws InsufficientCommitmentBudgetException When fail-closed.
	 */
	private function refuseUnfunded(CommitmentSource $source): void {
		if ($source->failClosed === true) {
			throw new InsufficientCommitmentBudgetException(
				sprintf('Insufficient budget to materialise commitment for %s', $source->sourceReference)
			);
		}

		$this->logger->warning(
			'CommitmentMaterialisationService: budget denied — skipping fail-soft materialisation',
			['sourceReference' => $source->sourceReference]
		);
	}//end refuseUnfunded()

	/**
	 * The committed draft with the mandate it falls under, and the override
	 * reason when that mandate is an override.
	 *
	 * @param array<string, mixed> $draft           The committed draft.
	 * @param string               $sourceReference The source business key.
	 *
	 * @return array<string, mixed> The draft with its mandate fields.
	 */
	private function withApplicableMandate(array $draft, string $sourceReference): array {
		$applied = $this->mandate->resolveApplicableMandate(commitment: $draft);
		if ($applied === null) {
			return $draft;
		}

		$draft['mandate_applied'] = (string)($applied['mandateCode'] ?? '');
		if ((bool)($applied['is_override'] ?? false) === true) {
			$draft['override_reason'] = sprintf(
				'Automatisch aangegaan onder override-mandaat %s bij materialisatie van %s (budget ontoereikend).',
				(string)($applied['mandateCode'] ?? ''),
				$sourceReference
			);
		}

		return $draft;
	}//end withApplicableMandate()

	/**
	 * Persist the draft with its regels, record the override deviation when
	 * it was committed under an override, and fire the rechtmatigheid trigger.
	 *
	 * @param array<string, mixed> $draft  The draft to persist.
	 * @param CommitmentSource     $source The PO or contract to materialise.
	 *
	 * @return array<string, mixed> The saved Commitment.
	 */
	private function persistAndAnnounce(array $draft, CommitmentSource $source): array {
		$saved = $this->persist(draft: $draft, ruleInputs: $source->ruleInputs);

		if (array_key_exists('override_reason', $draft) === true) {
			$this->recordOverrideDeviation(commitment: $saved, ruleInputs: $source->ruleInputs);
		}

		$this->dispatchLawfulnessTrigger(commitment: $saved);
		return $saved;
	}//end persistAndAnnounce()

	/**
	 * Group PurchaseOrderLine rows into regel inputs by budget
	 * coderingscombinatie (kostenplaats + grootboekrekening + boekjaar),
	 * resolving programma per line via the matching Budget (REQ-VPL-010).
	 * boekjaar is derived per line from its own expectedDeliveryDate
	 * (falling back to the order-level date, then to the current year), so
	 * a multi-year framework order naturally splits into one regel per
	 * boekjaar when its lines are dated across years — no new PurchaseOrder
	 * schema field is introduced for this (design.md scoped schema changes
	 * to Commitment only).
	 *
	 * @param array<string, mixed> $purchaseOrder Parent PurchaseOrder payload.
	 * @param array<int, array<string,mixed>> $lines PurchaseOrderLine rows for this order.
	 *
	 * @return array<int, array<string,mixed>> Regel inputs (kostenplaats/grootboekrekening/boekjaar/bedrag_excl_btw/programma).
	 */
	private function buildRulesFromPurchaseOrderLines(array $purchaseOrder, array $lines): array {
		$grouped = [];
		foreach ($lines as $line) {
			$rule = $this->lineRule(purchaseOrder: $purchaseOrder, line: $line);
			$key  = $rule['costCentre'] . '|' . $rule['generalLedgerAccount'] . '|' . $rule['financialYear'];
			if (isset($grouped[$key]) === false) {
				$grouped[$key] = $rule;
				$grouped[$key]['amount_excl_vat'] = 0;
			}

			$grouped[$key]['amount_excl_vat'] += $rule['amount_excl_vat'];
		}

		$administrationId = (string)($purchaseOrder['administrationId'] ?? '');
		foreach ($grouped as $key => $rule) {
			$grouped[$key]['programme'] = $this->resolveProgramma(
				administrationId: $administrationId,
				costCentre: $rule['costCentre'],
				financialYear: $rule['financialYear']
			);
		}

		return array_values($grouped);
	}//end buildRegelsFromPurchaseOrderLines()

	/**
	 * One PurchaseOrderLine as an ungrouped regel: its coding (falling back
	 * to the order's cost centre and date) and its line total.
	 *
	 * @param array<string, mixed> $purchaseOrder Parent PurchaseOrder payload.
	 * @param array<string, mixed> $line          One PurchaseOrderLine row.
	 *
	 * @return array{costCentre:string,generalLedgerAccount:string,financialYear:int,amount_excl_vat:int}
	 */
	private function lineRule(array $purchaseOrder, array $line): array {
		$lineDate = (string)($line['expectedDeliveryDate'] ?? (string)($purchaseOrder['expectedDeliveryDate'] ?? ''));

		return [
			'costCentre' => trim((string)($line['costCenter'] ?? (string)($purchaseOrder['costCenter'] ?? ''))),
			'generalLedgerAccount' => trim((string)($line['glAccount'] ?? '')),
			'financialYear' => $this->resolveFinancialYear(dateString: $lineDate),
			'amount_excl_vat' => (int)($line['lineTotal'] ?? 0),
		];
	}//end lineRule()

	/**
	 * Build regel inputs for a Contract (Task 2). A Contract carries a
	 * single totalContractValue rather than lines; when startDate/endDate
	 * span multiple boekjaren the value is split evenly per year (any
	 * rounding remainder is assigned to the first year to avoid float
	 * drift), consistent with REQ-VPL-004's per-boekjaar isolation.
	 * grootboekrekening is left blank — Contract has no GL-account field.
	 *
	 * @param array<string, mixed> $contract Activated Contract payload.
	 *
	 * @return array<int, array<string,mixed>> Regel inputs.
	 */
	private function buildRulesFromContract(array $contract): array {
		$administrationId = (string)($contract['administrationId'] ?? '');
		$costCentre = trim((string)($contract['costCenter'] ?? ''));
		$totalCents = (int)round(((float)($contract['totalContractValue'] ?? 0)) * 100);

		$years = $this->resolveFinancialYearSpan(
			startDate: (string)($contract['startDate'] ?? ''),
			endDate: (string)($contract['endDate'] ?? '')
		);

		$rules = [];
		foreach ($this->splitPerYear(totalCents: $totalCents, years: $years) as $financialYear => $amount) {
			$rules[] = [
				'costCentre' => $costCentre,
				'generalLedgerAccount' => '',
				'financialYear' => $financialYear,
				'amount_excl_vat' => $amount,
				'programme' => $this->resolveProgramma(
					administrationId: $administrationId,
					costCentre: $costCentre,
					financialYear: $financialYear
				),
			];
		}

		return $rules;
	}//end buildRegelsFromContract()

	/**
	 * Split an amount evenly over the boekjaren, the rounding remainder on the
	 * first year.
	 *
	 * @param int        $totalCents The amount in cents.
	 * @param array<int> $years      The ordered boekjaren.
	 *
	 * @return array<int, int> Cents per boekjaar, in year order.
	 */
	private function splitPerYear(int $totalCents, array $years): array {
		$yearCount = count($years);
		if ($yearCount === 0) {
			return [];
		}

		$perYear = intdiv($totalCents, $yearCount);
		$amounts = array_fill_keys($years, $perYear);
		$amounts[reset($years)] += ($totalCents - ($perYear * $yearCount));

		return $amounts;
	}//end splitPerYear()

	/**
	 * Map a Contract.contractType to the closest Commitment.kind enum
	 * value. lease -> leasing; employment -> employment_contract; everything
	 * else (purchase/sales/service/subscription/other) -> other, since
	 * Commitment.kind has no generic "contract" bucket and inferring a
	 * more specific value from contractType alone would be unreliable.
	 *
	 * @param string $contractType Contract.contractType.
	 *
	 * @return string Commitment.kind enum value.
	 */
	private function mapContractKind(string $contractType): string {
		return match ($contractType) {
			'lease' => 'leasing',
			'employment' => 'employment_contract',
			default => 'other',
		};

	}//end mapContractKind()

	/**
	 * Resolve the boekjaar for a single date string, falling back to the
	 * current year when unset or unparsable.
	 *
	 * @param string $dateString ISO date string.
	 *
	 * @return int Fiscal year.
	 */
	private function resolveFinancialYear(string $dateString): int {
		if ($dateString !== '') {
			try {
				return (int)(new DateTimeImmutable($dateString))->format('Y');
			} catch (Throwable $e) {
				// Fall through to current year.
			}
		}

		return (int)(new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y');
	}//end resolveBoekjaar()

	/**
	 * Resolve the inclusive boekjaar span [startYear..endYear] for a
	 * Contract's startDate/endDate. Falls back to a single current-year
	 * entry when startDate is unset or unparsable.
	 *
	 * @param string $startDate Contract.startDate.
	 * @param string $endDate Contract.endDate.
	 *
	 * @return array<int, int> Ordered list of boekjaren.
	 */
	private function resolveFinancialYearSpan(string $startDate, string $endDate): array {
		$currentYear = (int)(new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y');
		if ($startDate === '') {
			return [$currentYear];
		}

		try {
			$startYear = (int)(new DateTimeImmutable($startDate))->format('Y');
		} catch (Throwable $e) {
			return [$currentYear];
		}

		$endYear = $startYear;
		if ($endDate !== '') {
			try {
				$endYear = (int)(new DateTimeImmutable($endDate))->format('Y');
			} catch (Throwable $e) {
				$endYear = $startYear;
			}
		}

		if ($endYear < $startYear) {
			$endYear = $startYear;
		}

		return range($startYear, $endYear);
	}//end resolveBoekjaarSpan()

	/**
	 * Resolve the BBV programma code for a kostenplaats + boekjaar by
	 * looking up the matching CommitmentBudget row (which already carries an
	 * optional kostenplaats scope). Best-effort: when no CommitmentBudget
	 * matches, returns an empty string and BudgetBlocker's own fail-closed
	 * "no matching budget" rule denies the commitment naturally — no
	 * silent success.
	 *
	 * @param string $administrationId Owning administration.
	 * @param string $costCentre Cost-centre code.
	 * @param int $financialYear Fiscal year.
	 *
	 * @return string Resolved programma code, or '' when unresolved.
	 */
	private function resolveProgramma(string $administrationId, string $costCentre, int $financialYear): string {
		if ($costCentre === '') {
			return '';
		}

		$budget = $this->findOne(
			schema: 'CommitmentBudget',
			filters: [
				'administrationId' => $administrationId,
				'costCentre' => $costCentre,
				'financialYear' => $financialYear,
			]
		);

		return (string)($budget['programmeCode'] ?? '');
	}//end resolveProgramma()

	/**
	 * Persist the Commitment and its CommitmentLine rows.
	 *
	 * @param array<string, mixed> $draft Assembled Commitment.
	 * @param array<int, array<string,mixed>> $ruleInputs Regel inputs to persist as CommitmentLine rows.
	 *
	 * @return array<string, mixed> The persisted Commitment.
	 */
	private function persist(array $draft, array $ruleInputs): array {

		$saved = $this->objectService
			->setRegister(register: $this->getRegisterSlug())
			->setSchema(schema: 'Commitment')
			->saveObject(object: $draft);

		foreach (array_values($ruleInputs) as $index => $rule) {
			$rule['ruleNumber'] = ($index + 1);
			$this->objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'CommitmentLine')
				->saveObject(object: $this->commitmentLine(draft: $draft, rule: $rule));
		}

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
		// is_array() arm here was unreachable by type and persist() returned the
		// unsaved DRAFT on every call — the Commitment's stored id never
		// reached the caller.
		return (array)$saved->jsonSerialize();
	}//end persist()

	/**
	 * The CommitmentLine row for one numbered regel.
	 *
	 * @param array<string, mixed> $draft The Commitment being persisted.
	 * @param array<string, mixed> $rule  One regel input with its ruleNumber.
	 *
	 * @return array<string, mixed> The CommitmentLine fields.
	 */
	private function commitmentLine(array $draft, array $rule): array {
		return [
			'administrationId' => (string)($draft['administrationId'] ?? ''),
			'commitment' => (string)($draft['commitmentNumber'] ?? ''),
			'ruleNumber' => (int)$rule['ruleNumber'],
			'financialYear' => (int)($rule['financialYear'] ?? 0),
			'amount_excl_vat' => (int)($rule['amount_excl_vat'] ?? 0),
			'generalLedgerAccount' => (string)($rule['generalLedgerAccount'] ?? ''),
			'costCentre' => (string)($rule['costCentre'] ?? ''),
			'programme' => (string)($rule['programme'] ?? ''),
			'remaining_committed' => (int)($rule['amount_excl_vat'] ?? 0),
		];
	}//end commitmentLine()

	/**
	 * Record an override-mandate afwijking as a Rechtmatigheidsbevinding
	 * (REQ-VPL-012). Rechtmatigheidsbevinding has no journaalpost FK
	 * requirement (unlike Rechtmatigheidstoets), so it is the correct
	 * target for an afwijking raised before any journaalpost exists —
	 * fail-soft: a write failure here never blocks the commitment itself.
	 *
	 * @param array<string, mixed> $commitment Persisted Commitment.
	 * @param array<int, array<string,mixed>> $ruleInputs Regel inputs (for boekjaar/programma).
	 *
	 * @return void
	 */
	private function recordOverrideDeviation(array $commitment, array $ruleInputs): void {
		try {
			$this->objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'Rechtmatigheidsbevinding')
				->saveObject(object: $this->overrideFinding(commitment: $commitment, firstRule: reset($ruleInputs)));
		} catch (Throwable $e) {
			$this->logger->warning(
				'CommitmentMaterialisationService: recording override afwijking failed — fail-soft',
				['commitmentNumber' => ($commitment['commitmentNumber'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
		}//end try

	}//end recordOverrideAfwijking()

	/**
	 * The Rechtmatigheidsbevinding for a commitment entered under an
	 * override-mandate, dated by its first regel (else this year).
	 *
	 * @param array<string, mixed>       $commitment Persisted Commitment.
	 * @param array<string, mixed>|false $firstRule  The first regel input, or false when there is none.
	 *
	 * @return array<string, mixed> The finding fields.
	 */
	private function overrideFinding(array $commitment, array|false $firstRule): array {
		$financialYear = (int)($firstRule['financialYear'] ?? (int)(new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y'));

		return [
			'administrationId' => (string)($commitment['administrationId'] ?? ''),
			'findingNumber' => 'RV-' . ($commitment['commitmentNumber'] ?? '') . '-OVERRIDE',
			'kind' => 'error',
			'criterium' => 'begroting',
			'financialYear' => $financialYear,
			'programme' => (string)($firstRule['programme'] ?? ''),
			'amount_error' => (((float)($commitment['total_amount_excl_vat'] ?? 0)) / 100),
			'description' => (string)($commitment['override_reason'] ?? ''),
			'cause' => sprintf(
				'Commitment %s automatisch aangegaan onder override-mandaat wegens ontoereikende vrije_ruimte.',
				(string)($commitment['commitmentNumber'] ?? '')
			),
			'status' => 'open',
		];
	}//end overrideFinding()

	/**
	 * Dispatch the rechtmatigheid commitment-stage trigger event
	 * (REQ-VPL-012). Fail-soft, mirroring
	 * {@see \OCA\Shillinq\Service\BudgetImpactEmitter::dispatch()}.
	 *
	 * @param array<string, mixed>|null $commitment Persisted Commitment, or null (no-op).
	 *
	 * @return void
	 */
	private function dispatchLawfulnessTrigger(?array $commitment): void {
		if ($commitment === null) {
			return;
		}

		try {
			$payload = [
				'eventName' => self::EVENT_COMMITMENT_CREATED,
				'commitmentNumber' => (string)($commitment['commitmentNumber'] ?? ''),
				'sourceReference' => (string)($commitment['sourceReference'] ?? ''),
				'kind' => (string)($commitment['kind'] ?? ''),
				'administrationId' => (string)($commitment['administrationId'] ?? ''),
				'emittedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
			];

			$event = new GenericEvent(null, $payload);
			$this->dispatcher->dispatch(self::EVENT_COMMITMENT_CREATED, $event);
		} catch (Throwable $e) {
			$this->logger->info(
				'CommitmentMaterialisationService: rechtmatigheid trigger dispatch failed — fail-soft',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end dispatchRechtmatigheidTrigger()

	/**
	 * Look up an existing Commitment by bronReferentie (idempotency, REQ-VPL-010).
	 *
	 * @param string $sourceReference Source PO/contract business key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findExistingBySourceReference(string $sourceReference): ?array {
		return $this->findOne(schema: 'Commitment', filters: ['sourceReference' => $sourceReference]);
	}//end findExistingByBronReferentie()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Find a single record by exact-match filters in the configured register.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 *
	 * @return array<string, mixed>|null First matching record, or null.
	 */
	private function findOne(string $schema, array $filters): ?array {
		$result = $this->findMany(schema: $schema, filters: $filters, limit: 1);
		if (count($result) === 0) {
			return null;
		}

		return reset($result);
	}//end findOne()

	/**
	 * Find records by exact-match filters in the configured register. Uses
	 * the real OpenRegister ObjectService fluent API (ADR-022):
	 * setRegister/setSchema/findAll.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 * @param int $limit Maximum records to return (0 = no explicit limit).
	 *
	 * @return array<int, array<string, mixed>> Matching records (possibly empty).
	 */
	private function findMany(string $schema, array $filters, int $limit = 0): array {
		try {
			$query = ['filters' => $filters];
			if ($limit > 0) {
				$query['limit'] = $limit;
			}

			$result = $this->objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: $schema)
				->findAll($query);

			return array_values($result);
		} catch (Throwable $e) {
			$this->logger->debug(
				'CommitmentMaterialisationService: schema lookup unavailable — treating as absent',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end findMany()
}//end class
