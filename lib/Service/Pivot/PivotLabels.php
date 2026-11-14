<?php

/**
 * Pivot Labels
 *
 * The display label of a pivot key on its axis, from the account and
 * customer lookups LedgerPivotService loaded.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pivot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pivot;

/**
 * Labels for the keys a pivot axis shows.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
final class PivotLabels {

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, string>> $labels accounts (number => name), customers (id => name).
	 */
	public function __construct(
		private readonly array $labels,
	) {

	}//end __construct()

	/**
	 * Key and label for each kept key; the label is null for "not set".
	 *
	 * @param array<int, string> $keys The keys.
	 * @param string             $axis The axis.
	 *
	 * @return array<int, array{key: string, label: ?string}> The descriptions.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function describe(array $keys, string $axis): array {
		$described = [];
		foreach ($keys as $key) {
			$described[] = ['key' => $key, 'label' => $this->label(key: $key, axis: $axis)];
		}

		return $described;

	}//end describe()

	/**
	 * The label of one key.
	 *
	 * @param string $key  The key.
	 * @param string $axis The axis.
	 *
	 * @return string|null The label, null when the key is empty.
	 */
	private function label(string $key, string $axis): ?string {
		if ($key === '') {
			return null;
		}

		return match ($axis) {
			'account'      => $this->accountLabel(key: $key, axis: $axis),
			'accountGroup' => $this->groupLabel(key: $key),
			'customer'     => ($this->labels['customers'][$key] ?? $key),
			default        => $key,
		};

	}//end label()

	/**
	 * A group's label: the type of a type group, else its parent account's label.
	 *
	 * @param string $key A parent account number, or 'type:' and an account type.
	 *
	 * @return string
	 */
	private function groupLabel(string $key): string {
		if (str_starts_with($key, 'type:') === true) {
			return substr($key, 5);
		}

		return $this->accountLabel(key: $key, axis: 'accountGroup');

	}//end groupLabel()

	/**
	 * An account's label: its name for a group, its number and name otherwise.
	 *
	 * @param string $key  The account number.
	 * @param string $axis 'account' or 'accountGroup'.
	 *
	 * @return string
	 */
	private function accountLabel(string $key, string $axis): string {
		$name = ($this->labels['accounts'][$key] ?? '');
		if ($axis === 'accountGroup' && $name !== '') {
			return $name;
		}

		return trim($key . ' ' . $name);

	}//end accountLabel()
}//end class
