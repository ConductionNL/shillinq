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
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param SluitendCalculator $sluitend Computes the sluitend-flags and toezichtregime.
	 * @param ProgrammabegrotingExporter $exporter Produces iv3 / EMU / JSON export shapes.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
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
	 * @param string $budgetId The Programmabegroting.id to evaluate.
	 *
	 * @return array{budgetId:string,structurallyBalanced:bool,sluitendReëel:bool,supervisionRegime:string,jaren:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-19
	 */
	public function sluitendStatus(string $administrationId, string $budgetId): array {
		$budget = $this->fetchOne(schema: 'Programmabegroting', filters: ['id' => $budgetId, 'administrationId' => $administrationId]);
		$nominale = (float)($budget['nominalDevelopment'] ?? 2.0);

		$jaren = $this->fetchMany(schema: 'Meerjarenraming', filters: ['budgetId' => $budgetId, 'administrationId' => $administrationId]);

		$evaluated = [];
		foreach ($jaren as $year) {
			$result = $this->sluitend->evaluateYear(year: $year, nominalDevelopment: $nominale);
			$evaluated[] = [
				'year' => ($year['year'] ?? null),
				'balanceStructural' => $result['balanceStructural'],
				'saldoReëel' => $result['saldoReëel'],
				'sluitend' => $result['sluitend'],
			];
		}

		$flags = $this->sluitend->evaluateBegroting(years: $jaren, nominalDevelopment: $nominale);
		$regime = $this->sluitend->determineToezichtRegime(
			structurallyBalanced: $flags['structurallyBalanced'],
			sluitendReeel: $flags['sluitendReëel']
		);

		return [
			'budgetId' => $budgetId,
			'structurallyBalanced' => $flags['structurallyBalanced'],
			'sluitendReëel' => $flags['sluitendReëel'],
			'supervisionRegime' => $regime,
			'jaren' => $evaluated,
		];

	}//end sluitendStatus()

	/**
	 * Produce the OpenCatalogi JSON export for one begroting (REQ-012).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $budgetId The Programmabegroting.id to export.
	 *
	 * @return array<string,mixed> The JSON-serialisable export shape.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
	 */
	public function jsonExport(string $administrationId, string $budgetId): array {
		$budget = $this->fetchOne(schema: 'Programmabegroting', filters: ['id' => $budgetId, 'administrationId' => $administrationId]);
		$programmas = $this->fetchMany(schema: 'Programma', filters: ['budgetId' => $budgetId, 'administrationId' => $administrationId]);
		$taskFields = $this->fetchMany(schema: 'Taakveld', filters: ['budgetId' => $budgetId, 'administrationId' => $administrationId]);
		$paragrafen = $this->fetchMany(schema: 'Paragraaf', filters: ['budgetId' => $budgetId, 'administrationId' => $administrationId]);

		return $this->exporter->jsonExport(
			budget: $budget,
			programmas: $programmas,
			taskFields: $taskFields,
			paragrafen: $paragrafen
		);

	}//end jsonExport()

	/**
	 * Produce the iv3 taakveld-aggregated rows for one begroting (REQ-012).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $budgetId The Programmabegroting.id to export.
	 *
	 * @return array<int,array{taskFieldCode:string,revenue:float,expenses:float}> The iv3 rows.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
	 */
	public function iv3Export(string $administrationId, string $budgetId): array {
		$taskFields = $this->fetchMany(schema: 'Taakveld', filters: ['budgetId' => $budgetId, 'administrationId' => $administrationId]);
		return $this->exporter->iv3Rows(taskFields: $taskFields);
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
