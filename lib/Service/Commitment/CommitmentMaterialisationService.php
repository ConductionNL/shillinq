<?php

/**
 * Commitment Materialisation Service.
 *
 * REQ-VPL-010/011/012 — thin glue that closes the loop between the PO/
 * contract approval surfaces and the existing verplichtingenadministratie
 * (REQ-VPL-001..009, already shipped). When a PurchaseOrder reaches
 * `approved` or a Contract reaches `active` (the existing Contract
 * lifecycle's "in force" state — no separate `signed`/`executed` state
 * exists on the shipped Contract schema; `active` is treated as the
 * legally-binding trigger, per design.md's Open Question resolution),
 * this service assembles the matching `Verplichting` + `Verplichtingsregel`
 * rows from the source object and delegates to the ALREADY-SHIPPED
 * `MandaatEnforcer` and `BudgetBlocker` guards. It computes no budget or
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Commitment;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\BudgetBlocker;
use OCA\Shillinq\Lifecycle\MandaatEnforcer;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assembles a Verplichting + Verplichtingsregels from an approved
 * PurchaseOrder or an activated Contract and drives it through the
 * existing MandaatEnforcer / BudgetBlocker guards (REQ-VPL-010).
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class CommitmentMaterialisationService {
	/**
	 * CloudEvent name for the rechtmatigheid commitment-stage trigger (REQ-VPL-012).
	 *
	 * @var string
	 */
	public const EVENT_COMMITMENT_CREATED = 'shillinq.rechtmatigheid.commitment_created';

	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param MandaatEnforcer $mandaat Reused mandate-sufficiency guard (REQ-VPL-002).
	 * @param BudgetBlocker $budget Reused budget-room guard (REQ-VPL-001).
	 * @param IEventDispatcher $dispatcher NC event dispatcher (rechtmatigheid trigger transport).
	 * @param LoggerInterface $logger Logger for fail-soft/fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly MandaatEnforcer $mandaat,
		private readonly BudgetBlocker $budget,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Materialise a Verplichting from an approved PurchaseOrder (REQ-VPL-010).
	 *
	 * Fail-closed: throws {@see InsufficientCommitmentBudgetException} when
	 * budget is insufficient and no override-mandate applies, so the caller
	 * (the listener, running synchronously in the approval write path) lets
	 * the approval itself surface the denial.
	 *
	 * @param array<string, mixed> $purchaseOrder Approved PurchaseOrder payload.
	 *
	 * @return array<string, mixed>|null The materialised (or pre-existing) Verplichting, or null when there is nothing to materialise.
	 *
	 * @throws InsufficientCommitmentBudgetException When budget denies and no override applies.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function materialiseFromPurchaseOrder(array $purchaseOrder): ?array {
		$bronReferentie = trim((string)($purchaseOrder['poNumber'] ?? ''));
		if ($bronReferentie === '') {
			return null;
		}

		$administrationId = (string)($purchaseOrder['administrationId'] ?? '');
		$poId = (string)($purchaseOrder['id'] ?? ($purchaseOrder['@self']['id'] ?? ''));
		$lines = $this->findMany(schema: 'PurchaseOrderLine', filters: ['poId' => $poId]);
		$regelInputs = $this->buildRegelsFromPurchaseOrderLines(purchaseOrder: $purchaseOrder, lines: $lines);

		$tegenpartij = [
			'soort' => 'leverancier',
			'contactId' => (string)($purchaseOrder['supplierId'] ?? ''),
		];

		return $this->materialise(
			bronReferentie: $bronReferentie,
			soort: 'inkooporder',
			administrationId: $administrationId,
			regelInputs: $regelInputs,
			tegenpartij: $tegenpartij,
			failClosed: true
		);

	}//end materialiseFromPurchaseOrder()

	/**
	 * Materialise a Verplichting from an activated Contract (REQ-VPL-010,
	 * Task 2). Fail-soft: any denial is logged, never thrown — the
	 * Contract's own `activate` transition is a different capability
	 * (contract-lifecycle-management) not modified by this change.
	 *
	 * @param array<string, mixed> $contract Activated Contract payload.
	 *
	 * @return array<string, mixed>|null The materialised (or pre-existing) Verplichting, or null.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function materialiseFromContract(array $contract): ?array {
		$bronReferentie = trim((string)($contract['contractNumber'] ?? ''));
		if ($bronReferentie === '') {
			return null;
		}

		$administrationId = (string)($contract['administrationId'] ?? '');
		$regelInputs = $this->buildRegelsFromContract(contract: $contract);

		$tegenpartijSoort = 'overig';
		if ((string)($contract['direction'] ?? '') === 'inbound') {
			$tegenpartijSoort = 'leverancier';
		}

		$tegenpartij = [
			'soort' => $tegenpartijSoort,
			'contactId' => (string)($contract['counterpartyReference'] ?? ''),
		];

		try {
			return $this->materialise(
				bronReferentie: $bronReferentie,
				soort: $this->mapContractSoort(contractType: (string)($contract['contractType'] ?? '')),
				administrationId: $administrationId,
				regelInputs: $regelInputs,
				tegenpartij: $tegenpartij,
				failClosed: false
			);
		} catch (Throwable $e) {
			// Defensive: materialise() only throws when failClosed=true.
			// Caught here too so a future refactor cannot accidentally
			// make the Contract path block contract activation.
			$this->logger->warning(
				'CommitmentMaterialisationService: contract materialisation failed — fail-soft',
				['bronReferentie' => $bronReferentie, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end materialiseFromContract()

	/**
	 * Core materialisation: idempotency check, guard delegation, persistence,
	 * and rechtmatigheid linkage. Shared by both source paths (REQ-VPL-010).
	 *
	 * @param string $bronReferentie Source PO/contract business key (idempotency key).
	 * @param string $soort Verplichting.soort enum value.
	 * @param string $administrationId Owning administration.
	 * @param array<int, array<string,mixed>> $regelInputs Regel inputs built by the source-specific builder.
	 * @param array<string, mixed> $tegenpartij Embedded counterparty reference.
	 * @param bool $failClosed Whether a budget denial should throw (PO) or log (Contract).
	 *
	 * @return array<string, mixed>|null The materialised (or pre-existing) Verplichting, or null when nothing to do.
	 *
	 * @throws InsufficientCommitmentBudgetException When $failClosed and budget denies with no override.
	 */
	private function materialise(
		string $bronReferentie,
		string $soort,
		string $administrationId,
		array $regelInputs,
		array $tegenpartij,
		bool $failClosed,
	): ?array {
		$existing = $this->findExistingByBronReferentie(bronReferentie: $bronReferentie);
		if ($existing !== null) {
			// REQ-VPL-010 idempotency: a repeated transition is a no-op.
			return $existing;
		}

		if ($regelInputs === []) {
			$this->logger->info(
				'CommitmentMaterialisationService: no budget-coded lines — skipping materialisation',
				['bronReferentie' => $bronReferentie]
			);
			return null;
		}

		$totaal = 0;
		foreach ($regelInputs as $regel) {
			$totaal += (int)($regel['amount_excl_vat'] ?? 0);
		}

		$draft = [
			'administrationId' => $administrationId,
			'verplichtingsnummer' => $bronReferentie,
			'bronReferentie' => $bronReferentie,
			'soort' => $soort,
			'status' => 'concept',
			'totaalbedrag_excl_btw' => $totaal,
			'tegenpartij' => $tegenpartij,
			'regels' => $regelInputs,
			'aangaandatum' => (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d'),
		];

		// REQ-VPL-002 parity: no sufficient mandate routes to in_goedkeuring
		// (the existing `indienen` semantics) without a budget check — the
		// fail-closed budget guarantee only applies to the direct-commit path.
		if ($this->mandaat->hasSufficientMandate(verplichtingsnummer: $bronReferentie, object: $draft) === false) {
			$draft['status'] = 'in_goedkeuring';
			$saved = $this->persist(draft: $draft, regelInputs: $regelInputs);
			$this->dispatchRechtmatigheidTrigger(verplichting: $saved);
			return $saved;
		}

		if ($this->budget->canCommit(verplichtingsnummer: $bronReferentie, object: $draft) === false) {
			if ($failClosed === true) {
				throw new InsufficientCommitmentBudgetException(
					sprintf('Insufficient budget to materialise commitment for %s', $bronReferentie)
				);
			}

			$this->logger->warning(
				'CommitmentMaterialisationService: budget denied — skipping fail-soft materialisation',
				['bronReferentie' => $bronReferentie]
			);
			return null;
		}

		$draft['status'] = 'aangegaan';

		$applied = $this->mandaat->resolveApplicableMandate(verplichting: $draft);
		if ($applied !== null) {
			$draft['mandaat_toegepast'] = (string)($applied['mandaatcode'] ?? '');
		}

		$isOverride = $applied !== null && (bool)($applied['is_override'] ?? false) === true;
		if ($isOverride === true) {
			$draft['override_reden'] = sprintf(
				'Automatisch aangegaan onder override-mandaat %s bij materialisatie van %s (budget ontoereikend).',
				(string)($applied['mandaatcode'] ?? ''),
				$bronReferentie
			);
		}

		$saved = $this->persist(draft: $draft, regelInputs: $regelInputs);

		if ($isOverride === true) {
			$this->recordOverrideAfwijking(verplichting: $saved, regelInputs: $regelInputs);
		}

		$this->dispatchRechtmatigheidTrigger(verplichting: $saved);

		return $saved;
	}//end materialise()

	/**
	 * Group PurchaseOrderLine rows into regel inputs by budget
	 * coderingscombinatie (kostenplaats + grootboekrekening + boekjaar),
	 * resolving programma per line via the matching Budget (REQ-VPL-010).
	 * boekjaar is derived per line from its own expectedDeliveryDate
	 * (falling back to the order-level date, then to the current year), so
	 * a multi-year framework order naturally splits into one regel per
	 * boekjaar when its lines are dated across years — no new PurchaseOrder
	 * schema field is introduced for this (design.md scoped schema changes
	 * to Verplichting only).
	 *
	 * @param array<string, mixed> $purchaseOrder Parent PurchaseOrder payload.
	 * @param array<int, array<string,mixed>> $lines PurchaseOrderLine rows for this order.
	 *
	 * @return array<int, array<string,mixed>> Regel inputs (kostenplaats/grootboekrekening/boekjaar/bedrag_excl_btw/programma).
	 */
	private function buildRegelsFromPurchaseOrderLines(array $purchaseOrder, array $lines): array {
		$administrationId = (string)($purchaseOrder['administrationId'] ?? '');
		$orderCostCenter = (string)($purchaseOrder['costCenter'] ?? '');
		$orderDate = (string)($purchaseOrder['expectedDeliveryDate'] ?? '');

		$grouped = [];
		foreach ($lines as $line) {
			$kostenplaats = trim((string)($line['costCenter'] ?? $orderCostCenter));
			$grootboek = trim((string)($line['glAccount'] ?? ''));
			$lineDate = (string)($line['expectedDeliveryDate'] ?? $orderDate);
			$boekjaar = $this->resolveBoekjaar(dateString: $lineDate);
			$bedrag = (int)($line['lineTotal'] ?? 0);

			$key = $kostenplaats . '|' . $grootboek . '|' . $boekjaar;
			if (isset($grouped[$key]) === false) {
				$grouped[$key] = [
					'kostenplaats' => $kostenplaats,
					'grootboekrekening' => $grootboek,
					'boekjaar' => $boekjaar,
					'amount_excl_vat' => 0,
				];
			}

			$grouped[$key]['amount_excl_vat'] += $bedrag;
		}//end foreach

		foreach ($grouped as $key => $regel) {
			$grouped[$key]['programma'] = $this->resolveProgramma(
				administrationId: $administrationId,
				kostenplaats: $regel['kostenplaats'],
				boekjaar: $regel['boekjaar']
			);
		}

		return array_values($grouped);
	}//end buildRegelsFromPurchaseOrderLines()

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
	private function buildRegelsFromContract(array $contract): array {
		$administrationId = (string)($contract['administrationId'] ?? '');
		$kostenplaats = trim((string)($contract['costCenter'] ?? ''));
		$totaalCents = (int)round(((float)($contract['totalContractValue'] ?? 0)) * 100);

		$years = $this->resolveBoekjaarSpan(
			startDate: (string)($contract['startDate'] ?? ''),
			endDate: (string)($contract['endDate'] ?? '')
		);

		$yearCount = count($years);
		if ($yearCount === 0) {
			return [];
		}

		$perYear = intdiv($totaalCents, $yearCount);
		$remainder = $totaalCents - ($perYear * $yearCount);

		$regels = [];
		foreach ($years as $index => $boekjaar) {
			$bedrag = $perYear;
			if ($index === 0) {
				$bedrag += $remainder;
			}

			$regels[] = [
				'kostenplaats' => $kostenplaats,
				'grootboekrekening' => '',
				'boekjaar' => $boekjaar,
				'amount_excl_vat' => $bedrag,
				'programma' => $this->resolveProgramma(
					administrationId: $administrationId,
					kostenplaats: $kostenplaats,
					boekjaar: $boekjaar
				),
			];
		}

		return $regels;
	}//end buildRegelsFromContract()

	/**
	 * Map a Contract.contractType to the closest Verplichting.soort enum
	 * value. lease -> leasing; employment -> arbeidscontract; everything
	 * else (purchase/sales/service/subscription/other) -> overig, since
	 * Verplichting.soort has no generic "contract" bucket and inferring a
	 * more specific value from contractType alone would be unreliable.
	 *
	 * @param string $contractType Contract.contractType.
	 *
	 * @return string Verplichting.soort enum value.
	 */
	private function mapContractSoort(string $contractType): string {
		return match ($contractType) {
			'lease' => 'leasing',
			'employment' => 'arbeidscontract',
			default => 'overig',
		};

	}//end mapContractSoort()

	/**
	 * Resolve the boekjaar for a single date string, falling back to the
	 * current year when unset or unparsable.
	 *
	 * @param string $dateString ISO date string.
	 *
	 * @return int Fiscal year.
	 */
	private function resolveBoekjaar(string $dateString): int {
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
	private function resolveBoekjaarSpan(string $startDate, string $endDate): array {
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
	 * looking up the matching Budget row (which already carries an
	 * optional kostenplaats scope). Best-effort: when no Budget matches,
	 * returns an empty string and BudgetBlocker's own fail-closed
	 * "no matching budget" rule denies the commitment naturally — no
	 * silent success.
	 *
	 * @param string $administrationId Owning administration.
	 * @param string $kostenplaats Cost-centre code.
	 * @param int $boekjaar Fiscal year.
	 *
	 * @return string Resolved programma code, or '' when unresolved.
	 */
	private function resolveProgramma(string $administrationId, string $kostenplaats, int $boekjaar): string {
		if ($kostenplaats === '') {
			return '';
		}

		$budget = $this->findOne(
			schema: 'Budget',
			filters: [
				'administrationId' => $administrationId,
				'kostenplaats' => $kostenplaats,
				'boekjaar' => $boekjaar,
			]
		);

		return (string)($budget['programmaCode'] ?? '');
	}//end resolveProgramma()

	/**
	 * Persist the Verplichting and its Verplichtingsregel rows.
	 *
	 * @param array<string, mixed> $draft Assembled Verplichting.
	 * @param array<int, array<string,mixed>> $regelInputs Regel inputs to persist as Verplichtingsregel rows.
	 *
	 * @return array<string, mixed> The persisted Verplichting.
	 */
	private function persist(array $draft, array $regelInputs): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		$saved = $objectService
			->setRegister(register: $this->getRegisterSlug())
			->setSchema(schema: 'Verplichting')
			->saveObject(object: $draft);

		$regelnummer = 1;
		foreach ($regelInputs as $regel) {
			$objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'Verplichtingsregel')
				->saveObject(
					object: [
						'administrationId' => (string)($draft['administrationId'] ?? ''),
						'verplichting' => (string)($draft['verplichtingsnummer'] ?? ''),
						'regelnummer' => $regelnummer,
						'boekjaar' => (int)($regel['boekjaar'] ?? 0),
						'amount_excl_vat' => (int)($regel['amount_excl_vat'] ?? 0),
						'grootboekrekening' => (string)($regel['grootboekrekening'] ?? ''),
						'kostenplaats' => (string)($regel['kostenplaats'] ?? ''),
						'programma' => (string)($regel['programma'] ?? ''),
						'restant_verplicht' => (int)($regel['amount_excl_vat'] ?? 0),
					]
				);
			$regelnummer++;
		}

		if (is_array($saved) === true) {
			return $saved;
		}

		return $draft;
	}//end persist()

	/**
	 * Record an override-mandate afwijking as a Rechtmatigheidsbevinding
	 * (REQ-VPL-012). Rechtmatigheidsbevinding has no journaalpost FK
	 * requirement (unlike Rechtmatigheidstoets), so it is the correct
	 * target for an afwijking raised before any journaalpost exists —
	 * fail-soft: a write failure here never blocks the commitment itself.
	 *
	 * @param array<string, mixed> $verplichting Persisted Verplichting.
	 * @param array<int, array<string,mixed>> $regelInputs Regel inputs (for boekjaar/programma).
	 *
	 * @return void
	 */
	private function recordOverrideAfwijking(array $verplichting, array $regelInputs): void {
		try {
			$first = reset($regelInputs);
			$boekjaar = (int)($first['boekjaar'] ?? (int)(new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y'));
			$bedrag = ((float)($verplichting['totaalbedrag_excl_btw'] ?? 0)) / 100;

			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: 'Rechtmatigheidsbevinding')
				->saveObject(
					object: [
						'administrationId' => (string)($verplichting['administrationId'] ?? ''),
						'bevindingsnummer' => 'RV-' . ($verplichting['verplichtingsnummer'] ?? '') . '-OVERRIDE',
						'soort' => 'fout',
						'criterium' => 'begroting',
						'boekjaar' => $boekjaar,
						'programma' => (string)($first['programma'] ?? ''),
						'amount_error' => $bedrag,
						'omschrijving' => (string)($verplichting['override_reden'] ?? ''),
						'oorzaak' => sprintf(
							'Verplichting %s automatisch aangegaan onder override-mandaat wegens ontoereikende vrije_ruimte.',
							(string)($verplichting['verplichtingsnummer'] ?? '')
						),
						'status' => 'open',
					]
				);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CommitmentMaterialisationService: recording override afwijking failed — fail-soft',
				['verplichtingsnummer' => ($verplichting['verplichtingsnummer'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
		}//end try

	}//end recordOverrideAfwijking()

	/**
	 * Dispatch the rechtmatigheid commitment-stage trigger event
	 * (REQ-VPL-012). Fail-soft, mirroring
	 * {@see \OCA\Shillinq\Service\BudgetImpactEmitter::dispatch()}.
	 *
	 * @param array<string, mixed>|null $verplichting Persisted Verplichting, or null (no-op).
	 *
	 * @return void
	 */
	private function dispatchRechtmatigheidTrigger(?array $verplichting): void {
		if ($verplichting === null) {
			return;
		}

		try {
			$payload = [
				'eventName' => self::EVENT_COMMITMENT_CREATED,
				'verplichtingsnummer' => (string)($verplichting['verplichtingsnummer'] ?? ''),
				'bronReferentie' => (string)($verplichting['bronReferentie'] ?? ''),
				'soort' => (string)($verplichting['soort'] ?? ''),
				'administrationId' => (string)($verplichting['administrationId'] ?? ''),
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
	 * Look up an existing Verplichting by bronReferentie (idempotency, REQ-VPL-010).
	 *
	 * @param string $bronReferentie Source PO/contract business key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findExistingByBronReferentie(string $bronReferentie): ?array {
		return $this->findOne(schema: 'Verplichting', filters: ['bronReferentie' => $bronReferentie]);
	}//end findExistingByBronReferentie()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
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
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$query = ['filters' => $filters];
			if ($limit > 0) {
				$query['limit'] = $limit;
			}

			$result = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: $schema)
				->findAll($query);

			if (is_array($result) === false) {
				return [];
			}

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
