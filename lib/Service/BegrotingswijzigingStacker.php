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
	 * @param array<int,array<string,mixed>> $basisTaakvelden The vastgestelde basis Taakveld rows.
	 * @param array<int,array<string,mixed>> $wijzigingen The Begrotingswijziging rows (any status).
	 *
	 * @return array<string,array{baten:float,lasten:float}> Effective stand keyed by taakveldCode.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-26
	 */
	public function currentStand(array $basisTaakvelden, array $wijzigingen): array {
		$stand = [];
		foreach ($basisTaakvelden as $taakveld) {
			$code = (string)($taakveld['taakveldCode'] ?? '');
			if ($code === '') {
				continue;
			}

			$stand[$code] = [
				'batenCents' => (int)round(((float)($taakveld['baten'] ?? 0)) * 100),
				'lastenCents' => (int)round(((float)($taakveld['lasten'] ?? 0)) * 100),
			];
		}

		foreach ($wijzigingen as $wijziging) {
			if (($wijziging['status'] ?? 'draft') !== 'vastgesteld') {
				continue;
			}

			$mutaties = ($wijziging['mutaties'] ?? []);
			if (is_array($mutaties) === true) {
				$stand = $this->applyMutaties(stand: $stand, mutaties: $mutaties);
			}
		}

		$result = [];
		foreach ($stand as $code => $cents) {
			$result[$code] = [
				'baten' => (float)($cents['batenCents'] / 100),
				'lasten' => (float)($cents['lastenCents'] / 100),
			];
		}

		return $result;
	}//end currentStand()

	/**
	 * Apply one wijziging's mutaties onto the cent-keyed stand.
	 *
	 * @param array<string,array{batenCents:int,lastenCents:int}> $stand The accumulating cent-keyed stand.
	 * @param array<int,mixed> $mutaties The wijziging's delta rows.
	 *
	 * @return array<string,array{batenCents:int,lastenCents:int}> The updated stand.
	 */
	private function applyMutaties(array $stand, array $mutaties): array {
		foreach ($mutaties as $mutatie) {
			if (is_array($mutatie) === false) {
				continue;
			}

			$code = (string)($mutatie['taakveldCode'] ?? '');
			if ($code === '') {
				continue;
			}

			if (isset($stand[$code]) === false) {
				$stand[$code] = ['batenCents' => 0, 'lastenCents' => 0];
			}

			$stand[$code]['batenCents'] += (int)round(((float)($mutatie['baten_delta'] ?? 0)) * 100);
			$stand[$code]['lastenCents'] += (int)round(((float)($mutatie['lasten_delta'] ?? 0)) * 100);
		}

		return $stand;
	}//end applyMutaties()

	/**
	 * Return the effective authorized lasten for a single taakveldCode.
	 *
	 * Convenience wrapper used by the budget-overrun precondition: returns the
	 * vastgestelde basis lasten plus all vastgestelde wijziging deltas for one
	 * taakveld, in euro. Returns 0.0 when the taakveld is unknown.
	 *
	 * @param string $taakveldCode The taakveld to look up.
	 * @param array<int,array<string,mixed>> $basisTaakvelden The vastgestelde basis Taakveld rows.
	 * @param array<int,array<string,mixed>> $wijzigingen The Begrotingswijziging rows.
	 *
	 * @return float The effective authorized lasten in euro.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-27
	 */
	public function authorizedLasten(string $taakveldCode, array $basisTaakvelden, array $wijzigingen): float {
		$stand = $this->currentStand(basisTaakvelden: $basisTaakvelden, wijzigingen: $wijzigingen);
		return ($stand[$taakveldCode]['lasten'] ?? 0.0);
	}//end authorizedLasten()
}//end class
