<?php

/**
 * Pause Resumption Request
 *
 * The input of POST /api/dunning/pauses/{id}/resume as read from the request.
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
 * Which pause to end, how, and with what partial settlement.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
final class PauseResumptionRequest {
	/**
	 * The DunningPauseDispute to end.
	 *
	 * @var string
	 */
	public readonly string $pauseId;

	/**
	 * 'resolve' (default) or 'expire' as sent.
	 *
	 * @var string
	 */
	public readonly string $resolution;

	/**
	 * The partial settlement, null when not sent.
	 *
	 * @var float|null
	 */
	public readonly ?float $partialSettlement;

	/**
	 * Read the resumption from the request.
	 *
	 * @param IRequest $request The request.
	 * @param string $pauseId The pause from the route.
	 */
	public function __construct(IRequest $request, string $pauseId) {
		$this->pauseId = $pauseId;
		$this->resolution = (string)$request->getParam('resolution', 'resolve');
		$this->partialSettlement = self::amountOrNull(value: $request->getParam('partialSettlement'));

	}//end __construct()

	/**
	 * Whether the resolution is resolve or expire.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
	 */
	public function hasKnownResolution(): bool {
		return in_array($this->resolution, ['resolve', 'expire'], true);

	}//end hasKnownResolution()

	/**
	 * A sent amount as float, null when not sent.
	 *
	 * @param mixed $value The parameter value.
	 *
	 * @return float|null
	 */
	private static function amountOrNull(mixed $value): ?float {
		if ($value === null) {
			return null;
		}

		return (float)$value;

	}//end amountOrNull()
}//end class
