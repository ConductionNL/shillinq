<?php

/**
 * Receipt Failure Answer
 *
 * How a goods-receipt or service-receipt action answers a failure.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * A service refusal is answered by its message; anything else is logged and
 * answered with a bare 500.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class ReceiptFailureAnswer {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The controller's logger.
	 * @param array<int,string> $conflictMarkers Refusal message parts that mean a conflict.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly array $conflictMarkers,
	) {
	}//end __construct()

	/**
	 * Answer a failed action.
	 *
	 * @param array{0:string,1:string} $messages The logged message and the error of an unexpected failure.
	 * @param array<string,mixed> $context The log context, with the failure as exception.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function answer(array $messages, array $context): JSONResponse {
		$failure = $context['exception'];
		if ($failure instanceof \RuntimeException) {
			return $this->refusal(exception: $failure);
		}

		$context['exception'] = $failure->getMessage();
		$this->logger->error($messages[0], $context);
		return new JSONResponse(['error' => $messages[1]], Http::STATUS_INTERNAL_SERVER_ERROR);

	}//end answer()

	/**
	 * A service refusal answered by its message: 404 for "not found", 409 for
	 * any of the conflict markers, 400 otherwise.
	 *
	 * @param \RuntimeException $exception The refusal.
	 *
	 * @return JSONResponse
	 */
	private function refusal(\RuntimeException $exception): JSONResponse {
		$message = $exception->getMessage();
		if (str_contains($message, 'not found') === true) {
			return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
		}

		foreach ($this->conflictMarkers as $marker) {
			if (str_contains($message, $marker) === true) {
				return new JSONResponse(['error' => $message], Http::STATUS_CONFLICT);
			}
		}

		return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);

	}//end refusal()
}//end class
