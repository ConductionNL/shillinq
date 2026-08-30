<?php

/**
 * OSS Destination-Country VAT Rate Resolver
 *
 * ADR-031 exception-path service for the Union One-Stop-Shop scheme (REQ-OSS-001,
 * REQ-OSS-011). Resolves the destination-country VAT rate that applies to a B2C
 * cross-border EU invoice from the EuVatRate table (the seeded mirror of the EU
 * Commission TEDB), effective on the invoice date and keyed by the invoice line's
 * rate category. The resolved rate carries a reference to the EuVatRate row id
 * (tedbRateVersion) so the audit trail survives subsequent TEDB refreshes
 * (REQ-OSS-007). When no rate covers the invoice date the resolution fails so the
 * caller can block the save with `oss.rate.missing` (design.md D2).
 *
 * The validity-window selection (validFrom <= date <= validUntil) is documented
 * declaratively on the EuVatRate schema
 * (x-openregister-aggregations.rateByCountryCategoryDate); this service is the
 * engine-side fallback the OR aggregation engine cannot yet express.
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
 * Resolves destination-country VAT rates from the seeded EuVatRate (TEDB) table.
 *
 * Reads are delegated to OpenRegister's ObjectService (find / findAll), which
 * enforces multitenancy; the country code and rate category come from the
 * invoice/counterparty, never a trust boundary the caller can spoof past the
 * register scope.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
class OssRateResolver {
	/**
	 * The set of NL-neighbouring EU member-state ISO codes the OSS scheme covers.
	 *
	 * NL is deliberately excluded: domestic turnover never enters the OSS pipeline.
	 *
	 * @var array<int,string>
	 */
	private const EU_MEMBER_STATES = [
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'ES',
		'FI',
		'FR',
		'GR',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
	];

	/**
	 * Construct the resolver with lazy DI of OpenRegister's ObjectService.
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
	 * Decide whether a destination country is an EU member state other than NL (REQ-OSS-001).
	 *
	 * @param string $countryCode ISO 3166-1 alpha-2 destination country.
	 *
	 * @return bool True when the country is an OSS destination (EU, non-NL).
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function isOssDestination(string $countryCode): bool {
		$code = strtoupper(trim($countryCode));
		if ($code === 'NL' || $code === '') {
			return false;
		}

		return in_array($code, self::EU_MEMBER_STATES, true);
	}//end isOssDestination()

	/**
	 * Select the EuVatRate row in force on a date from a candidate set (REQ-OSS-001, REQ-OSS-011).
	 *
	 * Pure logic: picks the row whose validFrom <= invoiceDate and whose validUntil
	 * is null or >= invoiceDate. When several rows qualify, the latest validFrom
	 * wins (most recent rate change in force). Returns null when none qualifies so
	 * the caller can block with `oss.rate.missing`.
	 *
	 * @param array<int,array<string,mixed>> $rates Candidate EuVatRate rows for one (country, category).
	 * @param string $invoiceDate Invoice date in YYYY-MM-DD.
	 *
	 * @return array<string,mixed>|null The applicable rate row, or null when none is in force.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function selectRateInForce(array $rates, string $invoiceDate): ?array {
		$best = null;
		$bestFrom = '';
		foreach ($rates as $rate) {
			$validFrom = (string)($rate['validFrom'] ?? '');
			if ($validFrom === '' || $validFrom > $invoiceDate) {
				continue;
			}

			$validUntil = ($rate['validUntil'] ?? null);
			if ($validUntil !== null && (string)$validUntil !== '' && (string)$validUntil < $invoiceDate) {
				continue;
			}

			if ($validFrom >= $bestFrom) {
				$best = $rate;
				$bestFrom = $validFrom;
			}
		}//end foreach

		return $best;
	}//end selectRateInForce()

	/**
	 * Resolve the applicable destination-country VAT rate at invoice time (REQ-OSS-001).
	 *
	 * Returns the resolved ossContext fields (appliedVatRate, appliedRateCategory,
	 * tedbRateVersion) keyed for direct merge into Invoice.ossContext, or null when
	 * no rate is in force on the invoice date (caller blocks with `oss.rate.missing`).
	 *
	 * @param string $countryCode ISO 3166-1 alpha-2 destination member state.
	 * @param string $rateCategory Rate category (standard / reduced1 / ...).
	 * @param string $invoiceDate Invoice date in YYYY-MM-DD.
	 *
	 * @return array{appliedVatRate: float, appliedRateCategory: string, tedbRateVersion: string}|null
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function resolve(string $countryCode, string $rateCategory, string $invoiceDate): ?array {
		$rates = $this->objectService
			->setRegister($this->register())
			->setSchema('EuVatRate')
			->findAll(
				['filters' => ['countryCode' => strtoupper(trim($countryCode)), 'rateCategory' => $rateCategory]]
			);

		// OpenRegister's findAll() returns ObjectEntity instances, not plain
		// arrays; normalise to the array shape selectRateInForce() reads.
		$normalised = [];
		foreach ($rates as $rate) {
			$normalised[] = $this->asArray(row: $rate);
		}

		$row = $this->selectRateInForce(rates: $normalised, invoiceDate: $invoiceDate);
		if ($row === null) {
			return null;
		}

		$rowId = (string)($row['id'] ?? ($row['@self']['id'] ?? ($row['@self']['slug'] ?? '')));

		return [
			'appliedVatRate' => (float)($row['ratePercentage'] ?? 0),
			'appliedRateCategory' => $rateCategory,
			'tedbRateVersion' => $rowId,
		];

	}//end resolve()

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

	/**
	 * Normalise an OpenRegister ObjectService row (ObjectEntity or array) to a
	 * plain array<string,mixed>.
	 *
	 * @param mixed $row Raw row from ObjectService::findAll()/find().
	 *
	 * @return array<string,mixed> The object as an array (empty array when unusable).
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		return [];
	}//end asArray()
}//end class
