<?php

/**
 * Shillinq MCP hours tools
 *
 * @category Mcp
 * @package  OCA\Shillinq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Mcp;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\Contracts\TimeIntakeServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * `shillinq.hours.book`: the path behind BillingIntakeController::timeIntake(),
 * with the same refusals. The hours are booked for the signed-in user, as the
 * controller does; nothing in the arguments names a person.
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 */
class McpHoursTools {

	/**
	 * The refusal for an administration the caller cannot reach.
	 */
	public const NOT_FOUND = 'Administration not found';

	/**
	 * Constructor.
	 *
	 * @param McpAdministrationGate      $gate    The administration gate.
	 * @param TimeIntakeServiceInterface $intake  The time intake behind the billing time-intake route.
	 * @param LoggerInterface            $logger  The logger.
	 */
	public function __construct(
		private readonly McpAdministrationGate $gate,
		private readonly TimeIntakeServiceInterface $intake,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Book a batch of hours for the signed-in user and draft its invoice.
	 *
	 * @param array<string, mixed> $args The validated arguments: administrationId and the intake batch.
	 *
	 * @return array<string, mixed> The intake answer, or the refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function book(array $args): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$body = $args;
		unset($body['administrationId']);

		try {
			return ['booking' => $this->intake->ingest(administrationId: $administrationId, personId: $this->gate->userId(), body: $body)];
		} catch (InvalidArgumentException $e) {
			return McpResult::error(code: 'invalid_arguments', message: $e->getMessage());
		} catch (RuntimeException $e) {
			return self::refusedBy(exception: $e);
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq MCP: failed to book hours',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return McpResult::error(code: 'failed', message: 'Could not book the hours');
		}
	}//end book()

	/**
	 * The refusal a service exception maps to, as the controller maps it to 409 or 422.
	 *
	 * @param RuntimeException $exception The exception.
	 *
	 * @return array<string, mixed> The refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	private static function refusedBy(RuntimeException $exception): array {
		$message = $exception->getMessage();
		if (str_starts_with($message, 'Conflict:') === true) {
			return McpResult::error(code: 'conflict', message: $message);
		}

		return McpResult::error(code: 'invalid_arguments', message: $message);
	}//end refusedBy()
}//end class
