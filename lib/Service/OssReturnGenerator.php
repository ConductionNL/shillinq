<?php

/**
 * OSS Quarterly Return Generator
 *
 * ADR-031 exception-path service that aggregates a closed quarter's OSS-eligible
 * invoices and credit notes into a draft OssReturn (REQ-OSS-004), grouped by
 * destination country and rate category, net of credit notes, with per-line and
 * grand totals. The cross-document conditional group-by is documented declaratively
 * on the OssReturn schema (x-openregister-aggregations.linesByCountryRate); this
 * service is the engine-side fallback. It also builds the type:correction skeleton
 * referencing the original period (REQ-OSS-010). Money arithmetic is in integer
 * cents to avoid float drift.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Generates draft OssReturn line items by aggregating OSS-eligible documents.
 *
 * Reads are delegated to OpenRegister's ObjectService (findAll) and scoped to the
 * server-resolved administration + reporting period, never a client trust boundary.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
class OssReturnGenerator {
	/**
	 * Construct the generator with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Convert a money amount to integer cents.
	 *
	 * @param mixed $amount Money amount.
	 *
	 * @return int Whole cents.
	 */
	private function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Aggregate a set of OSS-eligible documents into return line items (REQ-OSS-004).
	 *
	 * Pure logic: groups documents by (ossContext.destinationCountry,
	 * ossContext.appliedRateCategory), summing the net taxable base and the VAT
	 * amount in cents. Credit notes (documentType=credit-note or negative
	 * netAmount) net against the originating country/category so the return
	 * reflects the credit (REQ-OSS-004 second scenario). Returns lineItems plus the
	 * grand totalTaxableBase / totalVatAmount as float money.
	 *
	 * @param array<int,array<string,mixed>> $documents OSS-eligible invoices + credit notes for the period.
	 *
	 * @return array{lineItems: array<int,array<string,mixed>>, totalTaxableBase: float, totalVatAmount: float}
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function aggregate(array $documents): array {
		$buckets = [];
		foreach ($documents as $document) {
			$ossContext = ($document['ossContext'] ?? null);
			if (is_array($ossContext) === false || ($ossContext['ossEligible'] ?? false) !== true) {
				continue;
			}

			$country = (string)($ossContext['destinationCountry'] ?? '');
			$category = (string)($ossContext['appliedRateCategory'] ?? 'standard');
			$rate = (float)($ossContext['appliedVatRate'] ?? 0);
			if ($country === '') {
				continue;
			}

			$key = $country . '|' . $category;
			if (isset($buckets[$key]) === false) {
				$buckets[$key] = [
					'countryCode' => $country,
					'rateCategory' => $category,
					'vatRate' => $rate,
					'baseCents' => 0,
					'vatCents' => 0,
				];
			}

			$sign = 1;
			$isCredit = ((string)($document['documentType'] ?? '') === 'credit-note');
			$netAmount = (float)($document['netAmount'] ?? ($document['grossAmount'] ?? 0));
			$vatAmount = (float)($document['vatAmount'] ?? 0);
			if ($isCredit === true) {
				$sign = -1;
				$netAmount = abs($netAmount);
				$vatAmount = abs($vatAmount);
			}

			$baseCents = ($sign * $this->toCents(amount: $netAmount));
			$vatCents = (int)round($baseCents * $rate / 100);
			if ($vatAmount > 0) {
				$vatCents = ($sign * $this->toCents(amount: $vatAmount));
			}

			$buckets[$key]['baseCents'] += $baseCents;
			$buckets[$key]['vatCents'] += $vatCents;
		}//end foreach

		ksort($buckets);

		$lineItems = [];
		$totalBase = 0;
		$totalVat = 0;
		foreach ($buckets as $bucket) {
			$totalBase += $bucket['baseCents'];
			$totalVat += $bucket['vatCents'];
			$lineItems[] = [
				'countryCode' => $bucket['countryCode'],
				'rateCategory' => $bucket['rateCategory'],
				'taxableBase' => (float)($bucket['baseCents'] / 100),
				'vatRate' => (float)$bucket['vatRate'],
				'vatAmount' => (float)($bucket['vatCents'] / 100),
			];
		}

		return [
			'lineItems' => $lineItems,
			'totalTaxableBase' => (float)($totalBase / 100),
			'totalVatAmount' => (float)($totalVat / 100),
		];

	}//end aggregate()

	/**
	 * Generate a draft OssReturn for a closed quarter (REQ-OSS-004).
	 *
	 * Fetches every OSS-eligible invoice and credit note for the administration in
	 * the reporting period (YYYY-Qn), aggregates them, and returns a draft return
	 * payload (status:draft, type:regular) ready to persist.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param int $periodYear Fiscal year.
	 * @param string $periodQuarter Quarter (Q1..Q4).
	 * @param string $registrationId FK to the active OssRegistration.
	 *
	 * @return array<string,mixed> Draft OssReturn payload.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function generateDraft(string $administrationId, int $periodYear, string $periodQuarter, string $registrationId): array {
		$period = $periodYear . '-' . $periodQuarter;
		$register = $this->register();

		$invoices = $this->objectService
			->setRegister($register)
			->setSchema('Invoice')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$documents = [];
		foreach ($invoices as $invoice) {
			$ossContext = ($invoice['ossContext'] ?? null);
			if (is_array($ossContext) === true && (string)($ossContext['ossReportingPeriod'] ?? '') === $period) {
				$documents[] = $invoice;
			}
		}

		$aggregate = $this->aggregate(documents: $documents);

		return [
			'administrationId' => $administrationId,
			'periodYear' => $periodYear,
			'periodQuarter' => $periodQuarter,
			'registrationId' => $registrationId,
			'type' => 'regular',
			'status' => 'draft',
			'lineItems' => $aggregate['lineItems'],
			'totalTaxableBase' => $aggregate['totalTaxableBase'],
			'totalVatAmount' => $aggregate['totalVatAmount'],
		];

	}//end generateDraft()

	/**
	 * Build a correction-return skeleton linked to an original period (REQ-OSS-010).
	 *
	 * Returns a draft OssReturn of type:correction with correctsPeriod set to the
	 * amended period; the original return is never mutated. The caller persists
	 * this as a new record alongside the untouched original.
	 *
	 * @param string $administrationId Administration scope.
	 * @param int $periodYear Year of the correction filing window.
	 * @param string $periodQuarter Quarter of the correction filing window.
	 * @param string $registrationId FK to the OssRegistration.
	 * @param string $correctsPeriod The original period being corrected (e.g. '2026-Q1').
	 *
	 * @return array<string,mixed> Draft correction OssReturn payload.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function buildCorrection(
		string $administrationId,
		int $periodYear,
		string $periodQuarter,
		string $registrationId,
		string $correctsPeriod,
	): array {
		return [
			'administrationId' => $administrationId,
			'periodYear' => $periodYear,
			'periodQuarter' => $periodQuarter,
			'registrationId' => $registrationId,
			'type' => 'correction',
			'correctsPeriod' => $correctsPeriod,
			'status' => 'draft',
			'lineItems' => [],
			'totalTaxableBase' => 0.0,
			'totalVatAmount' => 0.0,
		];

	}//end buildCorrection()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
