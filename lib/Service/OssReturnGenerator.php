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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\OssReturnGeneratorInterface;
use OCP\IAppConfig;

/**
 * Generates draft OssReturn line items by aggregating OSS-eligible documents.
 *
 * Reads are delegated to OpenRegister's ObjectService (findAll) and scoped to the
 * server-resolved administration + reporting period, never a client trust boundary.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
class OssReturnGenerator implements OssReturnGeneratorInterface {
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
			$share = $this->documentShare(document: $document);
			if ($share !== null) {
				$buckets[$share['key']] = $this->addedToBucket(bucket: ($buckets[$share['key']] ?? null), share: $share);
			}
		}

		ksort($buckets);

		return [
			'lineItems' => array_values(array_map(fn (array $bucket): array => $this->lineItem(bucket: $bucket), $buckets)),
			'totalTaxableBase' => (float)(array_sum(array_column($buckets, 'baseCents')) / 100),
			'totalVatAmount' => (float)(array_sum(array_column($buckets, 'vatCents')) / 100),
		];

	}//end aggregate()

	/**
	 * One OSS-eligible document's contribution to its (country, rate
	 * category) bucket, or null when it is not OSS-eligible or names no
	 * destination country.
	 *
	 * @param array<string,mixed> $document An invoice or credit note.
	 *
	 * @return array{key:string,countryCode:string,rateCategory:string,vatRate:float,baseCents:int,vatCents:int}|null
	 */
	private function documentShare(array $document): array|null {
		$ossContext = ($document['ossContext'] ?? null);
		if (is_array($ossContext) === false || ($ossContext['ossEligible'] ?? false) !== true) {
			return null;
		}

		$country = (string)($ossContext['destinationCountry'] ?? '');
		$category = (string)($ossContext['appliedRateCategory'] ?? 'standard');
		$rate = (float)($ossContext['appliedVatRate'] ?? 0);
		if ($country === '') {
			return null;
		}

		$share = ['key' => $country . '|' . $category, 'countryCode' => $country, 'rateCategory' => $category, 'vatRate' => $rate];

		return ($share + $this->documentCents(document: $document, rate: $rate));

	}//end documentShare()

	/**
	 * A document's signed taxable base and VAT in cents. A credit note nets
	 * against the bucket; VAT is derived from the rate unless the document
	 * states a positive amount.
	 *
	 * @param array<string,mixed> $document An invoice or credit note.
	 * @param float               $rate     The applied VAT rate.
	 *
	 * @return array{baseCents:int,vatCents:int}
	 */
	private function documentCents(array $document, float $rate): array {
		$sign = 1;
		$netAmount = (float)($document['netAmount'] ?? ($document['grossAmount'] ?? 0));
		$vatAmount = (float)($document['vatAmount'] ?? 0);
		if ((string)($document['documentType'] ?? '') === 'credit-note') {
			$sign = -1;
			$netAmount = abs($netAmount);
			$vatAmount = abs($vatAmount);
		}

		$baseCents = ($sign * $this->toCents(amount: $netAmount));
		if ($vatAmount > 0) {
			return ['baseCents' => $baseCents, 'vatCents' => ($sign * $this->toCents(amount: $vatAmount))];
		}

		return ['baseCents' => $baseCents, 'vatCents' => (int)round($baseCents * $rate / 100)];

	}//end documentCents()

	/**
	 * Add a document's share to its bucket; the first share opens the bucket
	 * and fixes its reported rate.
	 *
	 * @param array<string,mixed>|null $bucket The bucket so far, or null.
	 * @param array<string,mixed>      $share  One document's share.
	 *
	 * @return array<string,mixed>
	 */
	private function addedToBucket(array|null $bucket, array $share): array {
		if ($bucket === null) {
			return $share;
		}

		$bucket['baseCents'] += $share['baseCents'];
		$bucket['vatCents'] += $share['vatCents'];

		return $bucket;

	}//end addedToBucket()

	/**
	 * One return line item from a bucket.
	 *
	 * @param array<string,mixed> $bucket A (country, rate category) bucket.
	 *
	 * @return array<string,mixed>
	 */
	private function lineItem(array $bucket): array {
		return [
			'countryCode' => $bucket['countryCode'],
			'rateCategory' => $bucket['rateCategory'],
			'taxableBase' => (float)($bucket['baseCents'] / 100),
			'vatRate' => (float)$bucket['vatRate'],
			'vatAmount' => (float)($bucket['vatCents'] / 100),
		];

	}//end lineItem()

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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
