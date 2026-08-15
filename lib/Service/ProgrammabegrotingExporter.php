<?php

/**
 * Programmabegroting exporter.
 *
 * ADR-031 exception-path calculator for the three export modes of REQ-012:
 * iv3-aanlevering (CBS, taakveld-aggregated per economische categorie),
 * EMU-saldo (Wet Hof / SNA-2010), and JSON (OpenCatalogi hergebruik). These are
 * pure shape transformations over already-fetched register data — the transport
 * (CBS-portaal, OpenConnector) is out of scope. All monetary aggregation is in
 * integer euro-cents to avoid IEEE-754 drift. No persistence, no I/O.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure calculator producing iv3, EMU-saldo and JSON export shapes.
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
 */
class ProgrammabegrotingExporter {
	/**
	 * Build the iv3-aanlevering rows: one row per taakveldCode (REQ-012).
	 *
	 * Aggregates baten and lasten per taakveldCode across all programma's that
	 * contain that taakveld (summing in integer cents), exactly matching the
	 * taakveld-first view the raad adopted. Rows are sorted by taakveldCode for
	 * deterministic output.
	 *
	 * @param array<int,array<string,mixed>> $taskFields The vastgestelde Taakveld rows.
	 *
	 * @return array<int,array{taskFieldCode:string,revenue:float,expenses:float}> Sorted iv3 rows.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
	 */
	public function iv3Rows(array $taskFields): array {
		$byCode = [];
		foreach ($taskFields as $taskField) {
			$code = (string)($taskField['taskFieldCode'] ?? '');
			if ($code === '') {
				continue;
			}

			if (isset($byCode[$code]) === false) {
				$byCode[$code] = ['revenueCents' => 0, 'expensesCents' => 0];
			}

			$byCode[$code]['revenueCents'] += (int)round(((float)($taskField['revenue'] ?? 0)) * 100);
			$byCode[$code]['expensesCents'] += (int)round(((float)($taskField['expenses'] ?? 0)) * 100);
		}

		ksort($byCode);

		$rows = [];
		foreach ($byCode as $code => $cents) {
			$rows[] = [
				'taskFieldCode' => $code,
				'revenue' => (float)($cents['revenueCents'] / 100),
				'expenses' => (float)($cents['expensesCents'] / 100),
			];
		}

		return $rows;
	}//end iv3Rows()

	/**
	 * Compute the EMU-saldo per Wet Hof / SNA-2010 (REQ-012).
	 *
	 * EMU-saldo = Σ(baten) - Σ(lasten) with corrections: investeringen are
	 * capitalised (added back, as the cash outflow is not an EMU-relevant last),
	 * and reserve/voorziening mutations net to zero on the EMU basis (added
	 * back). Computed in integer cents and returned in euro.
	 *
	 * @param array<int,array<string,mixed>> $taskFields The vastgestelde Taakveld rows.
	 * @param array<int,array<string,mixed>> $investeringen The Investering rows (bruto capitalised).
	 * @param float $reserveMovements Net reserve mutations to correct out.
	 *
	 * @return float The EMU-saldo in euro (positive = overschot).
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-29
	 */
	public function emuSaldo(array $taskFields, array $investeringen = [], float $reserveMovements = 0.0): float {
		$revenueCents = 0;
		$expensesCents = 0;
		foreach ($taskFields as $taskField) {
			$revenueCents += (int)round(((float)($taskField['revenue'] ?? 0)) * 100);
			$expensesCents += (int)round(((float)($taskField['expenses'] ?? 0)) * 100);
		}

		$investmentCents = 0;
		foreach ($investeringen as $investment) {
			$investmentCents += (int)round(((float)($investment['gross'] ?? 0)) * 100);
		}

		$reserveCents = (int)round($reserveMovements * 100);

		// Nominal saldo, then add back capitalised investeringen and reserve
		// mutations that are not EMU-relevant lasten.
		$balanceCents = (($revenueCents - $expensesCents) + $investmentCents + $reserveCents);

		return (float)($balanceCents / 100);
	}//end emuSaldo()

	/**
	 * Build the OpenCatalogi JSON export shape (REQ-012).
	 *
	 * Serialises the vastgestelde Programmabegroting metadata, its programma's
	 * (with narratives), all taakvelden and all seven paragrafen into a single
	 * machine-readable array suitable for json_encode and OpenCatalogi
	 * publication.
	 *
	 * @param array<string,mixed> $budget The Programmabegroting row.
	 * @param array<int,array<string,mixed>> $programmas The Programma rows.
	 * @param array<int,array<string,mixed>> $taskFields The Taakveld rows.
	 * @param array<int,array<string,mixed>> $paragrafen The Paragraaf rows.
	 *
	 * @return array<string,mixed> The JSON-serialisable export shape.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
	 */
	public function jsonExport(array $budget, array $programmas, array $taskFields, array $paragrafen): array {
		return [
			'metadata' => [
				'budgetYear' => ($budget['budgetYear'] ?? null),
				'organisationType' => ($budget['organisationType'] ?? null),
				'status' => ($budget['status'] ?? null),
				'determinationDate' => ($budget['determinationDate'] ?? null),
				'structurallyBalanced' => ($budget['structurallyBalanced'] ?? null),
				'sluitendReëel' => ($budget['sluitendReëel'] ?? null),
				'supervisionRegime' => ($budget['supervisionRegime'] ?? null),
			],
			'programmas' => array_map(
				static function (array $programma): array {
					return [
						'number' => ($programma['number'] ?? null),
						'name' => ($programma['name'] ?? null),
						'doelstellingen' => ($programma['doelstellingen'] ?? null),
						'revenueTotal' => ($programma['revenueTotal'] ?? null),
						'expensesTotal' => ($programma['expensesTotal'] ?? null),
					];
				},
				$programmas
			),
			'taskFields' => $this->iv3Rows(taskFields: $taskFields),
			'paragrafen' => array_map(
				static function (array $paragraph): array {
					return [
						'type' => ($paragraph['type'] ?? null),
						'narrative' => ($paragraph['narrative'] ?? null),
						'keyFigures' => ($paragraph['keyFigures'] ?? null),
					];
				},
				$paragrafen
			),
		];

	}//end jsonExport()
}//end class
