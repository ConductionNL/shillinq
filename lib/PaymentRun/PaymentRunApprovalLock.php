<?php

/**
 * Payment Run Approval Lock
 *
 * Decides what an approved payment run still allows. Outside `draft` the
 * fields that say who is paid, how much and from which account are frozen,
 * and the digest taken at approval binds the bank file to exactly the lines
 * the approver saw.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

/**
 * Pure rules for the payment run lock; no I/O.
 *
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 */
final class PaymentRunApprovalLock {

	public const DRAFT = 'draft';

	public const APPROVED = 'approved';

	/**
	 * The fields the approval digest covers: what is paid, to whom, from where and when.
	 */
	public const DIGEST_FIELDS = [
		'administrationId',
		'executionDate',
		'debtorAccountIban',
		'currency',
		'totalAmount',
		'paymentLines',
	];

	/**
	 * The fields frozen once a run has left `draft`.
	 */
	public const LOCKED_FIELDS = [
		'runNumber',
		'administrationId',
		'executionDate',
		'debtorAccountIban',
		'currency',
		'totalAmount',
		'paymentLines',
		'approvedLinesDigest',
		'approvedBy',
		'approvedAt',
	];

	/**
	 * Return the lifecycle state of a run, falling back to its status mirror.
	 *
	 * @param array<string,mixed> $run The payment run.
	 *
	 * @return string The state, lower case; empty when the run has none.
	 *
	 * @spec openspec/specs/payment-run-four-eyes/spec.md
	 */
	public function state(array $run): string {
		$state = ($run['lifecycleState'] ?? ($run['status'] ?? ''));
		if (is_string($state) === false) {
			return '';
		}

		return strtolower(trim($state));

	}//end state()

	/**
	 * Return the digest of the fields that decide what the bank file pays.
	 *
	 * @param array<string,mixed> $run The payment run.
	 *
	 * @return string A sha256 hex digest.
	 *
	 * @spec openspec/specs/payment-run-four-eyes/spec.md
	 */
	public function digest(array $run): string {
		$payload = [];
		foreach (self::DIGEST_FIELDS as $field) {
			$payload[$field] = $this->canonical(value: ($run[$field] ?? null));
		}

		return hash('sha256', (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

	}//end digest()

	/**
	 * Return the locked fields a write changes on a run that has left `draft`.
	 *
	 * @param array<string,mixed> $old The run as stored.
	 * @param array<string,mixed> $new The run as it would be stored.
	 *
	 * @return list<string> The changed locked fields; empty when the write may go ahead.
	 *
	 * @spec openspec/specs/payment-run-four-eyes/spec.md
	 */
	public function changedLockedFields(array $old, array $new): array {
		$changed = [];
		foreach (self::LOCKED_FIELDS as $field) {
			$before = $this->canonical(value: ($old[$field] ?? null));
			$after  = $this->canonical(value: ($new[$field] ?? null));
			if ($before !== $after) {
				$changed[] = $field;
			}
		}

		return $changed;

	}//end changedLockedFields()

	/**
	 * Normalise a value so equal content compares equal: object keys sorted,
	 * list order kept, numbers to two decimals, empty strings as null.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return mixed The normalised value.
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === true) {
			$out = [];
			foreach ($value as $key => $item) {
				$out[$key] = $this->canonical(value: $item);
			}

			if (array_is_list($out) === false) {
				ksort($out);
			}

			return $out;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return number_format((float)$value, 2, '.', '');
		}

		if (is_string($value) === true && trim($value) === '') {
			return null;
		}

		return $value;

	}//end canonical()
}//end class
