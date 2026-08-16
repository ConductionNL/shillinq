<?php

/**
 * Begrotingswijziging delta stacker.
 *
 * ADR-031 exception-path calculator for the event-sourced begrotingswijziging
 * workflow (REQ-009, design D3). A vastgestelde Programmabegroting is immutable;
 * the current stand of any taakveld = vastgestelde basis + Σ(vastgestelde
 * wijzigingen). This calculator applies the delta-mutaties of all vastgestelde
 * wijzigingen onto the basis taakvelden, in integer euro-cents, so reversals
 * (a wijziging with negative delta) net out exactly. No persistence, no I/O.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-26
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure calculator that stacks vastgestelde wijzigingen onto the basis taakvelden.
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-26
 */
class BegrotingswijzigingStacker {
	/**
	 * Compute the current stand of the begroting per taakveldCode.
	 *
	 * Starts from the vastgestelde basis taakvelden, then applies the
	 * baten_delta / lasten_delta of every vastgestelde wijziging's mutaties.
	 * Draft wijzigingen are ignored (only `vastgesteld` status counts). The
	 * result is keyed by taakveldCode with effective baten/lasten in euro.
	 *
	 * @param array<int,array<string,mixed>> $basisTaskFields The vastgestelde basis Taakveld rows.
	 * @param array<int,array<string,mixed>> $wijzigingen The Begrotingswijziging rows (any status).
	 *
	 * @return array<string,array{revenue:float,expenses:float}> Effective stand keyed by taakveldCode.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-26
	 */
	public function currentStand(array $basisTaskFields, array $wijzigingen): array {
		$position = [];
		foreach ($basisTaskFields as $taskField) {
			$code = (string)($taskField['taskFieldCode'] ?? '');
			if ($code === '') {
				continue;
			}

			$position[$code] = [
				'revenueCents' => (int)round(((float)($taskField['revenue'] ?? 0)) * 100),
				'expensesCents' => (int)round(((float)($taskField['expenses'] ?? 0)) * 100),
			];
		}

		foreach ($wijzigingen as $wijziging) {
			if (($wijziging['status'] ?? 'draft') !== 'determined') {
				continue;
			}

			$movements = ($wijziging['movements'] ?? []);
			if (is_array($movements) === true) {
				$position = $this->applyMovements(position: $position, movements: $movements);
			}
		}

		$result = [];
		foreach ($position as $code => $cents) {
			$result[$code] = [
				'revenue' => (float)($cents['revenueCents'] / 100),
				'expenses' => (float)($cents['expensesCents'] / 100),
			];
		}

		return $result;
	}//end currentStand()

	/**
	 * Apply one wijziging's mutaties onto the cent-keyed stand.
	 *
	 * @param array<string,array{revenueCents:int,expensesCents:int}> $position The accumulating cent-keyed stand.
	 * @param array<int,mixed> $movements The wijziging's delta rows.
	 *
	 * @return array<string,array{revenueCents:int,expensesCents:int}> The updated stand.
	 */
	private function applyMovements(array $position, array $movements): array {
		foreach ($movements as $movement) {
			if (is_array($movement) === false) {
				continue;
			}

			$code = (string)($movement['taskFieldCode'] ?? '');
			if ($code === '') {
				continue;
			}

			if (isset($position[$code]) === false) {
				$position[$code] = ['revenueCents' => 0, 'expensesCents' => 0];
			}

			$position[$code]['revenueCents'] += (int)round(((float)($movement['baten_delta'] ?? 0)) * 100);
			$position[$code]['expensesCents'] += (int)round(((float)($movement['lasten_delta'] ?? 0)) * 100);
		}

		return $position;
	}//end applyMutaties()

	/**
	 * Return the effective authorized lasten for a single taakveldCode.
	 *
	 * Convenience wrapper used by the budget-overrun precondition: returns the
	 * vastgestelde basis lasten plus all vastgestelde wijziging deltas for one
	 * taakveld, in euro. Returns 0.0 when the taakveld is unknown.
	 *
	 * @param string $taskFieldCode The taakveld to look up.
	 * @param array<int,array<string,mixed>> $basisTaskFields The vastgestelde basis Taakveld rows.
	 * @param array<int,array<string,mixed>> $wijzigingen The Begrotingswijziging rows.
	 *
	 * @return float The effective authorized lasten in euro.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-27
	 */
	public function authorizedLasten(string $taskFieldCode, array $basisTaskFields, array $wijzigingen): float {
		$position = $this->currentStand(basisTaskFields: $basisTaskFields, wijzigingen: $wijzigingen);
		return ($position[$taskFieldCode]['expenses'] ?? 0.0);
	}//end authorizedLasten()
}//end class
