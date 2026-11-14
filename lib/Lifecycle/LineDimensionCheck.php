<?php

/**
 * Shillinq LineDimensionCheck
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Refuses a posting whose line names a cost centre, cost carrier or project
 * that is not an active analytical dimension of the administration on the
 * posting date (ledger-journal-line-dimensions REQ-LJD-002). The refusal names
 * the line and the code, so a bookkeeper and a sending app (humaniq's payroll
 * run) can see which one. Lines without codes pass as before.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-journal-line-dimensions/specs/bookkeeping-general-ledger/spec.md#requirement-posting-refuses-an-unknown-or-inactive-code-req-ljd-002
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Checks the dimension codes on a posting's lines against the administration's active dimensions.
 *
 * @spec openspec/changes/ledger-journal-line-dimensions/specs/bookkeeping-general-ledger/spec.md#requirement-posting-refuses-an-unknown-or-inactive-code-req-ljd-002
 */
class LineDimensionCheck {

	/**
	 * The line field of each checked code, and the dimension type it must name.
	 *
	 * @var array<string, string>
	 */
	public const CODE_TYPES = [
		'costCenterCode' => 'cost-center',
		'costCarrierCode' => 'cost-object',
		'projectCode' => 'project',
	];

	/**
	 * The most dimensions read for one administration.
	 *
	 * @var int
	 */
	private const DIMENSION_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     The register slug.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IL10N                  $l10n          Translates the refusal.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Refuse the posting when a line's code names no active dimension of its type.
	 *
	 * The dimensions are read once for the whole posting, and only when a
	 * line carries a code.
	 *
	 * @param list<array<string,mixed>> $lines            The posting's lines, in order.
	 * @param string                    $administrationId The administration the posting belongs to.
	 * @param string                    $postingDate      The posting date (Y-m-d), '' when unknown.
	 *
	 * @return void
	 *
	 * @throws PostingRefusedException Naming the line number and the code.
	 *
	 * @spec openspec/changes/ledger-journal-line-dimensions/specs/bookkeeping-general-ledger/spec.md#requirement-posting-refuses-an-unknown-or-inactive-code-req-ljd-002
	 */
	public function assertKnown(array $lines, string $administrationId, string $postingDate): void {
		$codes = $this->codesOf(lines: $lines);
		if ($codes === []) {
			return;
		}

		$known = $this->activeCodes(administrationId: $administrationId, postingDate: $postingDate);
		foreach ($codes as [$lineNumber, $field, $code]) {
			if (isset($known[self::CODE_TYPES[$field]][$code]) === true) {
				continue;
			}

			throw new PostingRefusedException(
				$this->l10n->t(
					'Line %1$d: %2$s %3$s does not exist or is not active in this administration',
					[$lineNumber, $this->kindLabel(field: $field), $code]
				)
			);
		}
	}//end assertKnown()

	/**
	 * Every code on the lines, as [line number, field, code], in line order.
	 *
	 * @param list<array<string,mixed>> $lines The lines.
	 *
	 * @return list<array{0: int, 1: string, 2: string}>
	 */
	private function codesOf(array $lines): array {
		$codes = [];
		foreach (array_values($lines) as $index => $line) {
			if (is_array($line) === false) {
				continue;
			}

			foreach (array_keys(self::CODE_TYPES) as $field) {
				$code = trim((string)($line[$field] ?? ''));
				if ($code !== '') {
					$codes[] = [($index + 1), $field, $code];
				}
			}
		}

		return $codes;
	}//end codesOf()

	/**
	 * The administration's dimensions in force on the posting date, as type => code => true.
	 *
	 * A dimension counts when it is active, belongs to the administration and
	 * its validity window, when set, holds the posting date. Without an
	 * administration nothing is known, so every code is refused.
	 *
	 * @param string $administrationId The administration.
	 * @param string $postingDate      The posting date (Y-m-d).
	 *
	 * @return array<string, array<string, true>>
	 */
	private function activeCodes(string $administrationId, string $postingDate): array {
		if ($administrationId === '') {
			return [];
		}

		$rows = $this->objectService->setRegister($this->register())->setSchema('AnalyticalDimension')->findAll(
			['filters' => ['administrationId' => $administrationId, 'lifecycleState' => 'active'], 'limit' => self::DIMENSION_LIMIT]
		);

		$known = [];
		foreach ($rows as $row) {
			$dimension = $this->asArray(row: $row);
			if (($dimension['lifecycleState'] ?? '') !== 'active'
				|| (string)($dimension['administrationId'] ?? '') !== $administrationId
				|| $this->inForce(dimension: $dimension, postingDate: $postingDate) === false
			) {
				continue;
			}

			$known[(string)($dimension['dimensionType'] ?? '')][(string)($dimension['code'] ?? '')] = true;
		}

		return $known;
	}//end activeCodes()

	/**
	 * Whether the dimension's validity window holds the posting date. An open end, or an unknown date, holds.
	 *
	 * @param array<string,mixed> $dimension   The AnalyticalDimension.
	 * @param string              $postingDate The posting date (Y-m-d).
	 *
	 * @return bool
	 */
	private function inForce(array $dimension, string $postingDate): bool {
		$date = substr($postingDate, 0, 10);
		if ($date === '') {
			return true;
		}

		$start = substr((string)($dimension['startDate'] ?? ''), 0, 10);
		$end = substr((string)($dimension['endDate'] ?? ''), 0, 10);

		return ($start === '' || $start <= $date) && ($end === '' || $date <= $end);
	}//end inForce()

	/**
	 * The words for the kind of code a field holds.
	 *
	 * @param string $field The line field.
	 *
	 * @return string
	 */
	private function kindLabel(string $field): string {
		return match ($field) {
			'costCarrierCode' => $this->l10n->t('cost carrier'),
			'projectCode' => $this->l10n->t('project'),
			default => $this->l10n->t('cost centre'),
		};
	}//end kindLabel()

	/**
	 * A found row as a plain array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string,mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end asArray()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
