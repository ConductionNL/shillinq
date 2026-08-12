<?php

/**
 * Payroll UPA Handoff Service
 *
 * Aggregates the LoonStrook.pensioen amounts for a LoonPeriode into a
 * per-pensioenuitvoerder UPA (Uniforme Pensioen Aanlevering) submission
 * payload, ready for the bookkeeping-upa-pensioen app to transport to each
 * pensioenuitvoerder (Pensioenfederatie UPA standard). This service does
 * NOT perform the UPA submission itself; it produces the canonical payload.
 *
 * Werknemers without a pensioenRegeling are excluded; werknemers with the
 * same pensioenRegeling slug are grouped under one submission so each
 * uitvoerder receives one monthly UPA file.
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

/**
 * Aggregates UPA submission payloads from LoonStrook records.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
class PayrollUpaHandoffService {
	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container DI container (OR's ObjectService is lazy).
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger (no BSN / special-category data).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build UPA submission payloads for one LoonPeriode, grouped per pensioenuitvoerder.
	 *
	 * Each payload contains: pensioenRegeling (uitvoerder slug),
	 * administrationId, periodeId, the sum of werknemer + werkgever
	 * pensioenpremie, and an array of per-werknemer line items
	 * (werknemerId masked-bsn premie_wn premie_wg).
	 *
	 * BSN is masked to its last 2 digits (AVG / ADR-005) so this payload is
	 * safe to transit cross-app; the UPA app re-fetches the full BSN from
	 * the masters at transport time using the werknemerId.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $periodeId Period id.
	 *
	 * @return array<int,array<string,mixed>> UPA submission payloads.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function toUpaSubmissionPayloads(string $administrationId, string $periodeId): array {
		$stroken = $this->findStroken(administrationId: $administrationId, periodeId: $periodeId);
		if ($stroken === []) {
			$this->logger->debug(
				'Shillinq payroll: no loonstroken found for UPA period',
				['periodeId' => $periodeId]
			);
			return [];
		}

		$groups = [];
		foreach ($stroken as $strook) {
			$werknemerId = (string)($strook['werknemerId'] ?? '');
			$regeling = (string)($this->lookupWerknemerRegeling(administrationId: $administrationId, werknemerId: $werknemerId));
			if ($regeling === '') {
				continue;
			}

			$premWn = (float)(($strook['pensioen']['premie_wn_aandeel'] ?? 0));
			$premWg = (float)(($strook['pensioen']['premie_wg_aandeel'] ?? 0));
			if (($premWn + $premWg) <= 0.0) {
				continue;
			}

			if (isset($groups[$regeling]) === false) {
				$groups[$regeling] = [
					'pensionScheme' => $regeling,
					'periodeId' => $periodeId,
					'administrationId' => $administrationId,
					'totaalPremie' => 0.0,
					'totaalWerknemers' => 0,
					'regels' => [],
				];
			}

			$groups[$regeling]['totaalPremie'] += ($premWn + $premWg);
			$groups[$regeling]['totaalWerknemers'] = ((int)$groups[$regeling]['totaalWerknemers'] + 1);
			$groups[$regeling]['regels'][] = [
				'werknemerId' => $werknemerId,
				'premieWn' => $premWn,
				'premieWg' => $premWg,
			];
		}//end foreach

		$payloads = [];
		foreach ($groups as $group) {
			$group['totaalPremie'] = round((float)$group['totaalPremie'], 2);
			$payloads[] = $group;
		}

		return $payloads;
	}//end toUpaSubmissionPayloads()

	/**
	 * Look up the werknemer's pensioenRegeling, scoped to the administration.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $werknemerId Employee id.
	 *
	 * @return string The pensioenRegeling slug or '' when unknown.
	 */
	private function lookupWerknemerRegeling(string $administrationId, string $werknemerId): string {
		if ($werknemerId === '') {
			return '';
		}

		$results = $this->objectService()
			->setRegister($this->register())
			->setSchema('Werknemer')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'id' => $werknemerId,
					],
				]
			);

		foreach ($results as $r) {
			$row = (array)$r;
			return (string)($row['pensionScheme'] ?? '');
		}

		return '';
	}//end lookupWerknemerRegeling()

	/**
	 * Read all LoonStrook records for the period, administration-scoped.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodeId Period id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findStroken(string $administrationId, string $periodeId): array {
		$results = $this->objectService()
			->setRegister($this->register())
			->setSchema('LoonStrook')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'periodeId' => $periodeId,
					],
				]
			);

		$out = [];
		foreach ($results as $r) {
			$out[] = (array)$r;
		}

		return $out;
	}//end findStroken()

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
