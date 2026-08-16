<?php

/**
 * WBSO Administratie Service
 *
 * Tier-1 read-only realisatie computation for the WBSO S&O-administratie
 * (bookkeeping-wbso-sno-administratie). For one administration it aggregates the
 * realised speur- en ontwikkelingswerk hours per WbsoBeschikking from the
 * SoUurregistratie entries and compares them against the granted ceiling, using
 * the real OpenRegister ObjectService API (findAll) — there is NO derived
 * realisatie record authored by operators; the rows are materialised on demand
 * (REQ-WBSO-005, REQ-WBSO-010).
 *
 * Reads are scoped to a single administration: the caller passes the
 * administrationId resolved from the authenticated user's context, never a
 * client-supplied trust boundary, so no cross-administration data leaks
 * (REQ-WBSO-004).
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
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
 * Computes a per-beschikking realisatie summary from the S&O hour administration.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class WbsoAdministratieService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
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
	 * Compute the WBSO realisatie summary for one administration (REQ-WBSO-010).
	 *
	 * Returns one row per WbsoBeschikking carrying the granted hours, the realised
	 * hours (sum of confirmed + locked SoUurregistratie hours), the remaining
	 * headroom and a boolean exceeded flag. Hours are summed in integer-tenths to
	 * avoid IEEE-754 float drift, then returned as floats.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-WBSO-004).
	 *
	 * @return array{data: array<int,array<string,mixed>>, total: int}
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
	 */
	public function realisatieSummary(string $administrationId): array {
		$beschikkingen = $this->fetchBeschikkingen(administrationId: $administrationId);
		$realisedTenths = $this->realisedHoursByDecision(administrationId: $administrationId);

		$rows = [];
		foreach ($beschikkingen as $decisionNumber => $decision) {
			$grantedTenths = (int)round(((float)($decision['grantedSoHours'] ?? 0)) * 10);
			$realised = (int)($realisedTenths[$decisionNumber] ?? 0);
			$remaining = ($grantedTenths - $realised);
			$rows[] = [
				'decisionNumber' => (string)$decisionNumber,
				'rvoReference' => (string)($decision['rvoReference'] ?? ''),
				'projectNumber' => (string)($decision['projectNumber'] ?? ''),
				'state' => (string)($decision['state'] ?? ''),
				'grantedSoHours' => ((float)$grantedTenths / 10),
				'realisedSoHours' => ((float)$realised / 10),
				'remainingSoHours' => ((float)$remaining / 10),
				'exceeded' => ($realised > $grantedTenths),
				'administrationId' => $administrationId,
			];
		}//end foreach

		usort(
			$rows,
			static function (array $a, array $b): int {
				return strcmp((string)$a['decisionNumber'], (string)$b['decisionNumber']);
			}
		);

		return [
			'data' => $rows,
			'total' => count($rows),
		];

	}//end realisatieSummary()

	/**
	 * Sum realised S&O hours (confirmed + locked entries) per beschikking, in tenths.
	 *
	 * Draft entries are excluded — they are not yet part of the realisatie
	 * (REQ-WBSO-008). Each SoUurregistratie is scoped to the administration.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,int> beschikkingNumber => realised hours in tenths.
	 */
	private function realisedHoursByDecision(string $administrationId): array {
		$register = $this->register();

		$entries = $this->objectService
			->setRegister($register)
			->setSchema('SoUurregistratie')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$byDecision = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$state = (string)($entry['state'] ?? '');
			if ($state !== 'confirmed' && $state !== 'locked') {
				continue;
			}

			$decisionNumber = (string)($entry['decisionNumber'] ?? '');
			if ($decisionNumber === '') {
				continue;
			}

			$tenths = (int)round(((float)($entry['hours'] ?? 0)) * 10);
			if ($tenths < 0) {
				continue;
			}

			if (isset($byDecision[$decisionNumber]) === false) {
				$byDecision[$decisionNumber] = 0;
			}

			$byDecision[$decisionNumber] += $tenths;
		}//end foreach

		return $byDecision;
	}//end realisedHoursByBeschikking()

	/**
	 * Fetch the administration's WbsoBeschikking records keyed by beschikkingNumber.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,array<string,mixed>> beschikkingNumber => beschikking object.
	 */
	private function fetchBeschikkingen(string $administrationId): array {
		$records = $this->objectService
			->setRegister($this->register())
			->setSchema('WbsoBeschikking')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$byNumber = [];
		foreach ($records as $record) {
			if (is_array($record) === false) {
				continue;
			}

			$number = (string)($record['decisionNumber'] ?? '');
			if ($number !== '') {
				$byNumber[$number] = $record;
			}
		}

		return $byNumber;
	}//end fetchBeschikkingen()

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

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
