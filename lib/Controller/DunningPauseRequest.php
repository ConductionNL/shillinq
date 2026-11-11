<?php

/**
 * Dunning Pause Request
 *
 * The input of POST /api/dunning/pauses as read from the request.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The invoice, reason, details and evidence of one DunningPauseDispute.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
final class DunningPauseRequest {
	/**
	 * The reasons a dunning pause may be created for.
	 *
	 * @var array<int,string>
	 */
	private const REASONS = ['DISPUTED', 'PAYMENT_PLAN', 'OTHER'];

	/**
	 * The paused invoice, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $invoiceId;

	/**
	 * The reason (reden) as sent.
	 *
	 * @var string
	 */
	public readonly string $reason;

	/**
	 * Free-text details.
	 *
	 * @var string
	 */
	public readonly string $details;

	/**
	 * The user who pauses.
	 *
	 * @var string
	 */
	public readonly string $pausedBy;

	/**
	 * Evidence references when sent as a list, otherwise null.
	 *
	 * @var array<mixed>|null
	 */
	public readonly ?array $evidenceRefs;

	/**
	 * Read the pause from the request.
	 *
	 * @param IRequest $request The request.
	 * @param string $pausedBy The current user.
	 */
	public function __construct(IRequest $request, string $pausedBy) {
		$this->invoiceId = (string)$request->getParam('invoiceId', '');
		$this->reason = (string)$request->getParam('reason', '');
		$this->details = (string)$request->getParam('details', '');
		$this->pausedBy = $pausedBy;
		$this->evidenceRefs = self::listOrNull(value: $request->getParam('evidenceRefs'));

	}//end __construct()

	/**
	 * Whether the pause names an invoice and a known reason.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
	 */
	public function isComplete(): bool {
		return $this->invoiceId !== '' && in_array($this->reason, self::REASONS, true) === true;

	}//end isComplete()

	/**
	 * The value when it is an array, otherwise null.
	 *
	 * @param mixed $value The parameter value.
	 *
	 * @return array<mixed>|null
	 */
	private static function listOrNull(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		return null;

	}//end listOrNull()
}//end class
