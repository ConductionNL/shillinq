<?php

/**
 * Vpb Berekening Guard
 *
 * ADR-031 exception-path calculation fallbacks for the Vpb (vennootschapsbelasting)
 * registers of the bookkeeping-vpb-mkb change (T3). The declarative
 * x-openregister-calculations metadata on the VpbAangifte and Voorvoegingsverlies
 * schemas references the methods below for the arithmetic that the declarative DSL
 * cannot yet express:
 *
 *  - berekenVerschuldigdeVpb(): graduated-bracket schijftarief application against
 *                              the VpbTariefcatalogus of the belastingjaar
 *                              (REQ-VPB-003).
 *  - bepaalVerliesRegime():    per-verliesjaar regime determination
 *                              (9jr / 6jr / onbeperkt-50pct) (REQ-VPB-006).
 *  - bepaalVerjaardatum():     verjaring date per regime (REQ-VPB-006).
 *
 * Split out of VpbAangifteGuard so the cross-schema lifecycle preconditions and the
 * pure fiscal computations stay separately testable and each below the configured
 * class-complexity threshold. No tax-calculation service class is authored
 * (ADR-022/ADR-031): these are thin, fail-closed calculation fallbacks. When the
 * declarative calculation DSL gains schijftarief + per-jaar lookup capability,
 * replace these references with declarative formulas and delete this file.
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
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
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
 * Pure fiscal calculation fallbacks for the Vpb registers.
 *
 * Referenced from the bookkeeping-vpb-mkb register.d fragment calculation guards as
 * OCA\Shillinq\Lifecycle\VpbBerekeningGuard::<method>. Every method fails closed:
 * any exception or malformed input yields the neutral value (0.0 / '' / null).
 *
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
 */
class VpbBerekeningGuard {
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
	 * Compute the verschuldigde Vpb via the graduated schijftarief brackets.
	 *
	 * REQ-VPB-003: tarief1 over min(belastbaar, grens) + tarief2 over the excess,
	 * using the VpbTariefcatalogus of the aangifte's belastingjaar. Integer-cent
	 * arithmetic avoids IEEE-754 rounding drift; the result is returned in EUR.
	 * Returns 0.0 on a non-positive belastbaar bedrag and on any failure (the
	 * caller treats a missing tarief as "no liability computable yet").
	 *
	 * @param int|null $taxYear The belastingjaar to look up tarieven for.
	 * @param float|null $taxableProfit The belastbare fiscale winst (EUR).
	 *
	 * @return float The verschuldigde Vpb in EUR.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
	 */
	public function berekenVerschuldigdeVpb(?int $taxYear, ?float $taxableProfit): float {
		try {
			$taxable = (float)($taxableProfit ?? 0);
			if ($taxable <= 0.0 || $taxYear === null) {
				return 0.0;
			}

			$rate = $this->resolveTariefcatalogus(taxYear: $taxYear);
			if ($rate === null) {
				return 0.0;
			}

			return $this->applySchijftarief(rate: $rate, taxable: $taxable);
		} catch (\Throwable $e) {
			$this->logger->error(
				'VpbBerekeningGuard: berekenVerschuldigdeVpb failed — returning 0 (fail-closed)',
				['taxYear' => $taxYear, 'exception' => $e->getMessage()]
			);
			return 0.0;
		}//end try
	}//end berekenVerschuldigdeVpb()

	/**
	 * Apply the graduated schijftarief brackets to a belastbaar bedrag.
	 *
	 * Pure helper for berekenVerschuldigdeVpb: tarief1 over min(belastbaar, grens)
	 * plus tarief2 over the excess, using integer-cent arithmetic to avoid IEEE-754
	 * rounding drift. The result is returned in EUR.
	 *
	 * @param array<string,mixed> $rate The VpbTariefcatalogus record (tarief1/tarief2/belastbaarBedragGrens).
	 * @param float $taxable The belastbare fiscale winst (EUR).
	 *
	 * @return float The verschuldigde Vpb in EUR.
	 */
	private function applySchijftarief(array $rate, float $taxable): float {
		$tarief1 = (float)($rate['tarief1'] ?? 0);
		$tarief2 = (float)($rate['tarief2'] ?? 0);
		$grens = (float)($rate['taxableAmountThreshold'] ?? 0);

		$taxableCents = (int)round($taxable * 100);
		$grensCents = (int)round($grens * 100);

		$schijf1Cents = min($taxableCents, $grensCents);
		$schijf2Cents = max(0, ($taxableCents - $grensCents));

		$vpbCents = (($schijf1Cents * $tarief1) + ($schijf2Cents * $tarief2));

		return round(($vpbCents / 100), 2);
	}//end applySchijftarief()

	/**
	 * Determine the voorvoegingsverlies regime for a given verliesjaar.
	 *
	 * REQ-VPB-006: <= 2018 -> 9jr; 2019-2021 -> 6jr; >= 2022 -> onbeperkt-50pct.
	 * Returns an empty string on any failure (fail-closed).
	 *
	 * @param int|null $lossYear The year the loss was incurred.
	 *
	 * @return string The regime code, or '' on failure.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
	 */
	public function bepaalVerliesRegime(?int $lossYear): string {
		if ($lossYear === null) {
			return '';
		}

		if ($lossYear <= 2018) {
			return '9jr';
		}

		if ($lossYear <= 2021) {
			return '6jr';
		}

		return 'onbeperkt-50pct';
	}//end bepaalVerliesRegime()

	/**
	 * Compute the verjaring date for a voorvoegingsverlies per its regime.
	 *
	 * REQ-VPB-006: 9jr regime -> 31 December of (verliesjaar + 9); 6jr regime ->
	 * 31 December of (verliesjaar + 6); onbeperkt regime -> null (no expiry).
	 *
	 * @param int|null $lossYear The year the loss was incurred.
	 *
	 * @return string|null The verjaring date (YYYY-12-31), or null when unbounded/unknown.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
	 */
	public function bepaalVerjaardatum(?int $lossYear): ?string {
		$regime = $this->bepaalVerliesRegime(lossYear: $lossYear);
		if ($regime === '9jr') {
			return ($lossYear + 9) . '-12-31';
		}

		if ($regime === '6jr') {
			return ($lossYear + 6) . '-12-31';
		}

		return null;
	}//end bepaalVerjaardatum()

	/**
	 * Resolve the VpbTariefcatalogus record for a belastingjaar.
	 *
	 * @param int $taxYear The belastingjaar to look up.
	 *
	 * @return array<string,mixed>|null The tarief record, or null when absent.
	 */
	private function resolveTariefcatalogus(int $taxYear): ?array {
		$register = $this->resolveRegister();

		$records = $this->objectService
			->setRegister($register)
			->setSchema('VpbTariefcatalogus')
			->findAll(['filters' => ['taxYear' => $taxYear]]);

		foreach ($records as $record) {
			if (is_array($record) === true) {
				return $record;
			}
		}

		return null;
	}//end resolveTariefcatalogus()

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
