<?php

/**
 * Programmabegroting read/compute service.
 *
 * Tier-2 read surface for the programmabegroting capability (REQ-011, REQ-012).
 * Reads are scoped to a single administration + begroting (callers pass the
 * administrationId resolved from the authenticated user's context, never a
 * client trust boundary); the service delegates persistence to OpenRegister's
 * ObjectService, which enforces multitenancy / RBAC. The service exposes the
 * computed sluitend-status (struktureel / reëel / toezichtregime) and the three
 * REQ-012 exports (iv3, EMU-saldo, JSON) by combining the fetched register rows
 * with the pure calculators. No writes occur here — vaststelling and wijziging
 * transitions go through the declarative lifecycle and its guards.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
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
 * Reads programmabegroting data and produces sluitend-status and exports.
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
 */
class ProgrammabegrotingService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 *                                      fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param SluitendCalculator $sluitend Computes the sluitend-flags and toezichtregime.
	 * @param ProgrammabegrotingExporter $exporter Produces iv3 / EMU / JSON export shapes.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly SluitendCalculator $sluitend,
		private readonly ProgrammabegrotingExporter $exporter,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return the computed sluitend-status for one begroting (REQ-008, REQ-011).
	 *
	 * Fetches the begroting and its meerjarenraming jaren scoped to the
	 * administration, evaluates each year and the overall flags via
	 * SluitendCalculator, and derives the toezichtregime.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $begrotingId The Programmabegroting.id to evaluate.
	 *
	 * @return array{begrotingId:string,sluitendStructureel:bool,sluitendReëel:bool,toezichtRegime:string,jaren:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-19
	 */
	public function sluitendStatus(string $administrationId, string $begrotingId): array {
		$begroting = $this->fetchOne(schema: 'Programmabegroting', filters: ['id' => $begrotingId, 'administrationId' => $administrationId]);
		$nominale = (float)($begroting['nominaleOntwikkeling'] ?? 2.0);

		$jaren = $this->fetchMany(schema: 'Meerjarenraming', filters: ['begrotingId' => $begrotingId, 'administrationId' => $administrationId]);

		$evaluated = [];
		foreach ($jaren as $jaar) {
			$result = $this->sluitend->evaluateYear(year: $jaar, nominaleOntwikkeling: $nominale);
			$evaluated[] = [
				'year' => ($jaar['year'] ?? null),
				'balanceStructural' => $result['balanceStructural'],
				'saldoReëel' => $result['saldoReëel'],
				'sluitend' => $result['sluitend'],
			];
		}

		$flags = $this->sluitend->evaluateBegroting(years: $jaren, nominaleOntwikkeling: $nominale);
		$regime = $this->sluitend->determineToezichtRegime(
			sluitendStructureel: $flags['sluitendStructureel'],
			sluitendReeel: $flags['sluitendReëel']
		);

		return [
			'begrotingId' => $begrotingId,
			'sluitendStructureel' => $flags['sluitendStructureel'],
			'sluitendReëel' => $flags['sluitendReëel'],
			'toezichtRegime' => $regime,
			'jaren' => $evaluated,
		];

	}//end sluitendStatus()

	/**
	 * Produce the OpenCatalogi JSON export for one begroting (REQ-012).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $begrotingId The Programmabegroting.id to export.
	 *
	 * @return array<string,mixed> The JSON-serialisable export shape.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
	 */
	public function jsonExport(string $administrationId, string $begrotingId): array {
		$begroting = $this->fetchOne(schema: 'Programmabegroting', filters: ['id' => $begrotingId, 'administrationId' => $administrationId]);
		$programmas = $this->fetchMany(schema: 'Programma', filters: ['begrotingId' => $begrotingId, 'administrationId' => $administrationId]);
		$taakvelden = $this->fetchMany(schema: 'Taakveld', filters: ['begrotingId' => $begrotingId, 'administrationId' => $administrationId]);
		$paragrafen = $this->fetchMany(schema: 'Paragraaf', filters: ['begrotingId' => $begrotingId, 'administrationId' => $administrationId]);

		return $this->exporter->jsonExport(
			begroting: $begroting,
			programmas: $programmas,
			taakvelden: $taakvelden,
			paragrafen: $paragrafen
		);

	}//end jsonExport()

	/**
	 * Produce the iv3 taakveld-aggregated rows for one begroting (REQ-012).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $begrotingId The Programmabegroting.id to export.
	 *
	 * @return array<int,array{taakveldCode:string,baten:float,lasten:float}> The iv3 rows.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
	 */
	public function iv3Export(string $administrationId, string $begrotingId): array {
		$taakvelden = $this->fetchMany(schema: 'Taakveld', filters: ['begrotingId' => $begrotingId, 'administrationId' => $administrationId]);
		return $this->exporter->iv3Rows(taakvelden: $taakvelden);
	}//end iv3Export()

	/**
	 * Fetch a single object row matching the filters, or an empty array.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters The ObjectService filters.
	 *
	 * @return array<string,mixed> The first matching row, or [].
	 */
	private function fetchOne(string $schema, array $filters): array {
		foreach ($this->fetchMany(schema: $schema, filters: $filters) as $row) {
			return $row;
		}

		return [];
	}//end fetchOne()

	/**
	 * Fetch object rows matching the filters via ObjectService.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters The ObjectService filters.
	 *
	 * @return array<int,array<string,mixed>> The matching rows.
	 */
	private function fetchMany(string $schema, array $filters): array {
		$register = $this->resolveRegister();

		$rows = $this->objectService->setRegister($register)->setSchema($schema)->findAll(['filters' => $filters]);
		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end fetchMany()

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
