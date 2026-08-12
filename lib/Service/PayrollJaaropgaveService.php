<?php

/**
 * Payroll Jaaropgave (Annual Statement) Service
 *
 * Aggregates all LoonStrook records for a (werknemer, jaar) into a per-employee
 * annual statement (Jaaropgave) per REQ-PAY-013. The payload contains the YTD
 * sums of fiscaalLoon, loonheffing, premies SV werkgever, ZVW werkgever,
 * pensioenpremie (werknemer aandeel) and uitgekeerd vakantietoeslag, plus a
 * consistency flag that asserts the period totals match the cumulatieven
 * snapshots stored on the strook (design.md D3). The persistence layer is
 * the regular OpenRegister ObjectService API (saveObject), scoped to the
 * administrationId resolved server-side from the caller's context — never a
 * client-supplied trust boundary (ADR-022).
 *
 * The PDF rendering and Digipoort SBR-submission stay with the bookkeeping
 * template engine and the bookkeeping-loonaangifte-sbr app respectively; this
 * service produces the canonical, machine-verifiable payload they consume.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Aggregates the yearly Jaaropgave per employee from the period loonstroken.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * @SuppressWarnings(PHPMD.ShortVariable) Pre-existing debt (issue #506):
 *     not in the project's curated idiomatic-abbreviation allowlist;
 *     deferred pending a dedicated rename pass.
 */
class PayrollJaaropgaveService {
	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is lazy-fetched.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param PayrollCalculator $calculator Cents arithmetic helper (no IO).
	 * @param LoggerInterface $logger Logger (no BSN / special-category data).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly PayrollCalculator $calculator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build a Jaaropgave payload for an employee and calendar year (REQ-PAY-013).
	 *
	 * Reads every LoonStrook for the (administration, werknemer, jaar) tuple
	 * and sums the relevant amounts in integer cents (no float drift). The
	 * cumulatieven snapshot of the last period of the year is captured as the
	 * "ytdSnapshot" and compared to the period-by-period sum; consistent is
	 * false when they disagree, which the dashboard surfaces as a warning.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $werknemerId Employee id.
	 * @param int $jaar Calendar year.
	 *
	 * @return array<string,mixed> The Jaaropgave payload.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function bouwJaaropgave(string $administrationId, string $werknemerId, int $jaar): array {
		$stroken = $this->findLoonStrokenVoorJaar(
			administrationId: $administrationId,
			werknemerId: $werknemerId,
			jaar: $jaar
		);

		$fiscaalC = 0;
		$loonhefC = 0;
		$svWgC = 0;
		$zvwWgC = 0;
		$pensWnC = 0;
		$pensWgC = 0;
		$vakUitbC = 0;
		$nettoC = 0;
		$ytdFiscaal = 0.0;
		$ytdVak = 0.0;
		foreach ($stroken as $s) {
			$fiscaalC += $this->calculator->toCents(amount: ($s['fiscaalLoon'] ?? 0));
			$loonhefC += $this->calculator->toCents(amount: ($s['loonheffing'] ?? 0));
			$svWgC += $this->calculator->toCents(amount: ($s['premiesSVWerkgever']['totaal_werkgever'] ?? 0));
			$zvwWgC += $this->calculator->toCents(amount: ($s['zvw']['afgedragen_wg'] ?? 0));
			$pensWnC += $this->calculator->toCents(amount: ($s['pensioen']['premie_wn_aandeel'] ?? 0));
			$pensWgC += $this->calculator->toCents(amount: ($s['pensioen']['premie_wg_aandeel'] ?? 0));
			$vakUitbC += $this->calculator->toCents(amount: ($s['brutoComponenten']['vakantietoeslag_uitbetaling'] ?? 0));
			$nettoC += $this->calculator->toCents(amount: ($s['nettoBetaald'] ?? 0));

			$cu = ($s['cumulatieven'] ?? []);
			if (is_array($cu) === true) {
				$ytdFiscaal = (float)($cu['fiscaalloon_ytd'] ?? $ytdFiscaal);
				$ytdVak = (float)($cu['vakantiegeld_reservering_ytd'] ?? $ytdVak);
			}
		}//end foreach

		$fiscaalLoon = $this->calculator->fromCents(cents: $fiscaalC);
		$cumulatievenMatch = ($this->calculator->toCents(amount: $ytdFiscaal) === $fiscaalC);

		return [
			'werknemerId' => $werknemerId,
			'year' => $jaar,
			'aantalPerioden' => count($stroken),
			'fiscaalLoonJTD' => $fiscaalLoon,
			'loonheffingJTD' => $this->calculator->fromCents(cents: $loonhefC),
			'premiesSVWgJTD' => $this->calculator->fromCents(cents: $svWgC),
			'zvwWgJTD' => $this->calculator->fromCents(cents: $zvwWgC),
			'pensioenWnJTD' => $this->calculator->fromCents(cents: $pensWnC),
			'pensioenWgJTD' => $this->calculator->fromCents(cents: $pensWgC),
			'vakantieUitbJTD' => $this->calculator->fromCents(cents: $vakUitbC),
			'nettoUitbetaaldJTD' => $this->calculator->fromCents(cents: $nettoC),
			'ytdSnapshot' => [
				'fiscaalloon_ytd' => $ytdFiscaal,
				'vakantiegeld_reservering_ytd' => $ytdVak,
			],
			'cumulatievenConsistent' => $cumulatievenMatch,
			'administrationId' => $administrationId,
			'status' => 'CONCEPT',
		];

	}//end bouwJaaropgave()

	/**
	 * Persist a Jaaropgave; refuse inconsistent cumulatieven (REQ-PAY-013).
	 *
	 * @param array<string,mixed> $jaaropgave The Jaaropgave payload.
	 *
	 * @return array<string,mixed> The saved object.
	 *
	 * @throws \RuntimeException When cumulatieven do not match the period sum.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function persistJaaropgave(array $jaaropgave): array {
		if (($jaaropgave['cumulatievenConsistent'] ?? false) !== true) {
			$this->logger->error(
				'Shillinq payroll: refusing to persist Jaaropgave with inconsistent cumulatieven',
				['werknemerId' => ($jaaropgave['werknemerId'] ?? null), 'year' => ($jaaropgave['year'] ?? null)]
			);
			throw new RuntimeException('Jaaropgave-cumulatieven matchen de som van de perioden niet.');
		}

		return (array)$this->objectService()
			->saveObject(object: $jaaropgave, register: $this->register(), schema: 'Jaaropgave');

	}//end persistJaaropgave()

	/**
	 * Read every LoonStrook for the calendar year, administration-scoped.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $werknemerId Employee id.
	 * @param int $jaar Calendar year.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findLoonStrokenVoorJaar(string $administrationId, string $werknemerId, int $jaar): array {
		$results = $this->objectService()
			->setRegister($this->register())
			->setSchema('LoonStrook')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'werknemerId' => $werknemerId,
					],
				]
			);

		$out = [];
		foreach ($results as $r) {
			$row = (array)$r;
			$perJaar = $this->extractJaarFromPeriodeId(periodeId: (string)($row['periodeId'] ?? ''));
			if ($perJaar !== null && $perJaar !== $jaar) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}//end findLoonStrokenVoorJaar()

	/**
	 * Best-effort year extractor from a periodeId of shape lp-YYYY-...
	 *
	 * @param string $periodeId Period id.
	 *
	 * @return int|null Year or null when not encoded.
	 */
	private function extractJaarFromPeriodeId(string $periodeId): ?int {
		if (preg_match('/(?<year>20[0-9]{2})/', $periodeId, $m) === 1) {
			return (int)$m['year'];
		}

		return null;
	}//end extractJaarFromPeriodeId()

	/**
	 * Lazily fetch OpenRegister's ObjectService.
	 *
	 * @return object The ObjectService.
	 */
	private function objectService(): object {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * Resolve the configured OpenRegister register slug.
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
