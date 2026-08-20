<?php

/**
 * Annual Report Guard
 *
 * ADR-031 exception-path lifecycle guards for the AnnualReport register
 * (bookkeeping-titel-9-jaarrekening, T3). Two preconditions are referenced
 * from the AnnualReport schema's x-openregister-lifecycle transitions because
 * they require cross-schema lookups / arithmetic that OpenRegister's
 * declarative `requires:` clause cannot yet express:
 *
 *  - canOpmaken():    the linked BalanceSheet must balance (totalActiva equals
 *                     totalPassiva, or the rubrieken activa/passiva sums match)
 *                     before the bestuur may opmaken and freeze the immutable
 *                     snapshot (REQ-T9-002 / REQ-T9-010, design D2). A balans
 *                     that does not balance is non-compliant per art. 2:373 BW.
 *  - canVaststellen(): an accountantsverklaring MUST be attached when it is
 *                     wettelijk verplicht (middelgroot+; accountantsverklaring-
 *                     Vereist === true) before the AV may vaststellen
 *                     (REQ-T9-007 / REQ-T9-009).
 *
 * ADR-031 exception reason: cross-schema lookup (balans rubriek sums on a
 * sibling BalanceSheet) and conditional attachment checks are not yet
 * expressible in the declarative lifecycle DSL. When the engine gains those
 * capabilities, replace these references with declarative conditions and
 * delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-titel-9-jaarrekening/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for AnnualReport opmaken and vaststellen.
 *
 * Referenced from the AnnualReport schema (register.d fragment)
 * x-openregister-lifecycle transitions.opmaken.requires as
 * OCA\Shillinq\Lifecycle\AnnualReportGuard::canOpmaken and
 * transitions.{vaststellen,vaststellenZonderReview}.requires as
 * OCA\Shillinq\Lifecycle\AnnualReportGuard::canVaststellen.
 *
 * @spec openspec/specs/bookkeeping-titel-9-jaarrekening/spec.md
 */
class AnnualReportGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the jaarrekening's balans balances (activa = passiva).
	 *
	 * REQ-T9-002 / REQ-T9-010 (design D2): the bestuur may only opmaken a
	 * jaarrekening whose balans is arithmetically consistent. The balance is
	 * computed from the linked BalanceSheet's totalActiva/totalPassiva when
	 * present, otherwise from the activa/passiva rubriek sums, using
	 * integer-cent arithmetic to avoid IEEE-754 float equality issues.
	 *
	 * Fail-closed: returns false on any exception, a missing BalanceSheet, or
	 * an unbalanced balans (CWE-863).
	 *
	 * @param string $annualReportId The AnnualReport.id being transitioned.
	 * @param array<string,mixed>|null $object The AnnualReport object being transitioned.
	 *
	 * @return bool True when the balans balances and the report may opmaken.
	 *
	 * @spec openspec/specs/bookkeeping-titel-9-jaarrekening/spec.md
	 */
	public function canOpmaken(string $annualReportId, ?array $object = null): bool {
		try {
			$reportId = $this->resolveReportId(annualReportId: $annualReportId, object: $object);
			if ($reportId === '') {
				return false;
			}

			$balanceSheet = $this->resolveBalanceSheet(reportId: $reportId);
			if ($balanceSheet === null) {
				return false;
			}

			$totals = $this->balanceTotalsInCents(balanceSheet: $balanceSheet);
			if ($totals === null) {
				return false;
			}

			[$activaCents, $passivaCents] = $totals;

			return $activaCents > 0 && $activaCents === $passivaCents;
		} catch (\Throwable $e) {
			$this->logger->error(
				'AnnualReportGuard: opmaak balans check failed — denying opmaken transition (fail-closed)',
				['annualReportId' => $annualReportId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canOpmaken()

	/**
	 * Returns true iff an accountantsverklaring is attached when it is verplicht.
	 *
	 * REQ-T9-007 / REQ-T9-009: for middelgroot+ (accountantsverklaringVereist
	 * === true) the AV may only vaststellen once a goedkeurende/met-beperking/
	 * samenstelling/beoordeling accountantsverklaring has been recorded. When
	 * the verklaring is not verplicht (klein/micro) the AV may vaststellen
	 * freely.
	 *
	 * Fail-closed: returns false on any exception (CWE-863).
	 *
	 * @param string $annualReportId The AnnualReport.id being transitioned.
	 * @param array<string,mixed>|null $object The AnnualReport object being transitioned.
	 *
	 * @return bool True when the report may be vastgesteld.
	 *
	 * @spec openspec/specs/bookkeeping-titel-9-jaarrekening/spec.md
	 */
	public function canVaststellen(string $annualReportId, ?array $object = null): bool {
		try {
			$report = $object;
			if ($report === null || isset($report['auditorsStatementRequired']) === false) {
				$report = $this->resolveAnnualReport(annualReportId: $annualReportId);
			}

			if ($report === null) {
				return false;
			}

			$required = ($report['auditorsStatementRequired'] ?? false) === true;
			if ($required === false) {
				return true;
			}

			$status = (string)($report['auditorsStatementStatus'] ?? 'niet-vereist');
			$valid = ['goedkeurend', 'met-beperking', 'samenstelling', 'beoordeling'];

			return in_array($status, $valid, true);
		} catch (\Throwable $e) {
			$this->logger->error(
				'AnnualReportGuard: vaststelling verklaring check failed — denying vaststellen transition (fail-closed)',
				['annualReportId' => $annualReportId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canVaststellen()

	/**
	 * Compute the balans activa/passiva totals in integer cents.
	 *
	 * Prefers the persisted totalActiva/totalPassiva when both are present;
	 * otherwise sums the rubriek huidigJaar amounts grouped by zijde. Integer-
	 * cent arithmetic avoids IEEE-754 float equality issues.
	 *
	 * @param array<string,mixed> $balanceSheet The BalanceSheet to total.
	 *
	 * @return array{0:int,1:int}|null A [activaCents, passivaCents] pair, or
	 *                                 null when neither totals nor rubrieken
	 *                                 are available (fail-closed).
	 */
	private function balanceTotalsInCents(array $balanceSheet): ?array {
		$totalAssets = ($balanceSheet['totalAssets'] ?? null);
		$totalLiabilities = ($balanceSheet['totalLiabilities'] ?? null);
		if ($totalAssets !== null && $totalLiabilities !== null) {
			return [
				(int)round((float)$totalAssets * 100),
				(int)round((float)$totalLiabilities * 100),
			];
		}

		$rubrieken = ($balanceSheet['rubrieken'] ?? null);
		if (is_array($rubrieken) === false) {
			return null;
		}

		return [
			$this->sumRubriekCents(rubrieken: $rubrieken, side: 'assets'),
			$this->sumRubriekCents(rubrieken: $rubrieken, side: 'liabilities'),
		];
	}//end balanceTotalsInCents()

	/**
	 * Sum the huidigJaar amounts (in integer cents) of the rubrieken on one zijde.
	 *
	 * @param array<int,mixed> $rubrieken The balans rubriek rows.
	 * @param string $side The side to total ('assets' or 'liabilities').
	 *
	 * @return int The summed amount in integer cents.
	 */
	private function sumRubriekCents(array $rubrieken, string $side): int {
		$cents = 0;
		foreach ($rubrieken as $section) {
			if (is_array($section) === true && ($section['side'] ?? '') === $side) {
				$cents += (int)round((float)($section['currentYear'] ?? 0) * 100);
			}
		}

		return $cents;
	}//end sumRubriekCents()

	/**
	 * Resolve the AnnualReport reportId (its own id) from the object or lookup.
	 *
	 * @param string $annualReportId The AnnualReport.id.
	 * @param array<string,mixed>|null $object The in-flight object, if provided.
	 *
	 * @return string The report id, or '' when unresolvable.
	 */
	private function resolveReportId(string $annualReportId, ?array $object): string {
		if ($object !== null && isset($object['id']) === true && (string)$object['id'] !== '') {
			return (string)$object['id'];
		}

		return $annualReportId;
	}//end resolveReportId()

	/**
	 * Resolve the AnnualReport object by id via ObjectService.
	 *
	 * @param string $annualReportId The AnnualReport.id to look up.
	 *
	 * @return array<string,mixed>|null The report, or null when not found.
	 */
	private function resolveAnnualReport(string $annualReportId): ?array {
		if ($annualReportId === '') {
			return null;
		}

		$register = $this->resolveRegister();

		$reports = $this->objectService
			->setRegister($register)
			->setSchema('AnnualReport')
			->findAll(['filters' => ['id' => $annualReportId]]);

		foreach ($reports as $report) {
			if (is_array($report) === true) {
				return $report;
			}
		}

		return null;
	}//end resolveAnnualReport()

	/**
	 * Resolve the linked BalanceSheet for a given report id via ObjectService.
	 *
	 * @param string $reportId The AnnualReport.id whose balans to fetch.
	 *
	 * @return array<string,mixed>|null The BalanceSheet, or null when not found.
	 */
	private function resolveBalanceSheet(string $reportId): ?array {
		$register = $this->resolveRegister();

		$sheets = $this->objectService
			->setRegister($register)
			->setSchema('BalanceSheet')
			->findAll(['filters' => ['reportId' => $reportId]]);

		foreach ($sheets as $sheet) {
			if (is_array($sheet) === true) {
				return $sheet;
			}
		}

		return null;
	}//end resolveBalanceSheet()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
