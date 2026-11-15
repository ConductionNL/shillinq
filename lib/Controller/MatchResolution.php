<?php

/**
 * Match Resolution
 *
 * The administration, three-way match and operator motivation of one
 * exception resolution (accept, dispute or reject).
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * One exception resolution, read from the request.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
 */
final class MatchResolution {
	/**
	 * Short-slug identifier pattern shared by every scope/path parameter.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Free-text motivation cap (characters); longer input is truncated.
	 *
	 * @var integer
	 */
	private const NOTES_MAX_LENGTH = 2000;

	/**
	 * Per action: the parameter carrying its motivation and the error a failure answers.
	 *
	 * @var array<string, array{notes: string, failure: string}>
	 */
	private const ACTIONS = [
		'accept' => ['notes' => 'resolutionNotes', 'failure' => 'Could not record acceptance'],
		'dispute' => ['notes' => 'disputeReason', 'failure' => 'Could not file dispute'],
		'reject' => ['notes' => 'rejectionReason', 'failure' => 'Could not record rejection'],
	];

	/**
	 * The resolution: accept, dispute or reject.
	 *
	 * @var string
	 */
	public readonly string $action;

	/**
	 * The administration, '' when blank or malformed.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The three-way match, '' when blank or malformed.
	 *
	 * @var string
	 */
	public readonly string $matchId;

	/**
	 * The trimmed, capped motivation ('' when blank).
	 *
	 * @var string
	 */
	public readonly string $notes;

	/**
	 * Read one resolution.
	 *
	 * @param IRequest $request The request.
	 * @param string   $action  accept, dispute or reject.
	 */
	public function __construct(IRequest $request, string $action) {
		$this->action = $action;
		$this->administrationId = self::slug(value: $request->getParam('administrationId', ''));
		$this->matchId = self::slug(value: $request->getParam('matchId', ''));
		$this->notes = self::capped(value: $request->getParam(self::ACTIONS[$action]['notes'], ''));

	}//end __construct()

	/**
	 * The parameter carrying this action's motivation.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	public function notesField(): string {
		return self::ACTIONS[$this->action]['notes'];
	}//end notesField()

	/**
	 * The error a failure of this action answers.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	public function failureError(): string {
		return self::ACTIONS[$this->action]['failure'];
	}//end failureError()

	/**
	 * The administration and match as log context, with the failure.
	 *
	 * @param \Throwable $exception The failure.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	public function logContext(\Throwable $exception): array {
		return [
			'administrationId' => $this->administrationId,
			'matchId' => $this->matchId,
			'exception' => $exception->getMessage(),
		];
	}//end logContext()

	/**
	 * A trimmed identifier, '' when blank or malformed.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return string
	 */
	private static function slug(mixed $value): string {
		$value = trim((string)$value);
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end slug()

	/**
	 * A trimmed free-text motivation capped at NOTES_MAX_LENGTH characters.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return string
	 */
	private static function capped(mixed $value): string {
		$value = trim((string)$value);
		if (mb_strlen($value) > self::NOTES_MAX_LENGTH) {
			return mb_substr($value, 0, self::NOTES_MAX_LENGTH);
		}

		return $value;
	}//end capped()
}//end class
