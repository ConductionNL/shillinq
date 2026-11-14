<?php

/**
 * Shillinq MCP period-close tools
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

use OCA\Shillinq\Kernel\Contracts\PeriodCloseAssistantServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PeriodCloseServiceInterface;
use OCA\Shillinq\Kernel\Exception\PeriodCloseException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `shillinq.period_close.status|start|close|reopen`: the service paths behind
 * `PeriodCloseController::show`, `startClose`, `close` and `reopen`.
 *
 * The gate refuses an anonymous caller and an administration the caller may
 * not reach, as the controller does; every transition rule (who may close,
 * which state may move where, the reopen reason) stays in the service, so the
 * tool refuses exactly where its REST twin does.
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */
class McpPeriodCloseTools {

	/**
	 * The refusal for an unknown or unreachable period, as the controller words it.
	 */
	public const NOT_FOUND = 'Period not found';

	/**
	 * Constructor.
	 *
	 * @param McpAdministrationGate                $gate      Refuses an anonymous caller or an unreachable administration.
	 * @param PeriodCloseServiceInterface          $periods   The period-close transitions.
	 * @param PeriodCloseAssistantServiceInterface $assistant The close-readiness flags the status read carries.
	 * @param LoggerInterface                      $logger    For an unexpected failure.
	 */
	public function __construct(
		private readonly McpAdministrationGate $gate,
		private readonly PeriodCloseServiceInterface $periods,
		private readonly PeriodCloseAssistantServiceInterface $assistant,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The period's close status with its readiness flags (`PeriodCloseController::show`).
	 *
	 * @param array<string, mixed> $args The validated arguments.
	 *
	 * @return array<string, mixed> `{period}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function status(array $args): array {
		$refusal = $this->gate->refusal(administrationId: (string)$args['administrationId'], notFound: self::NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$administrationId = (string)$args['administrationId'];
		$periodId         = (string)$args['periodId'];
		try {
			$record = $this->periods->getPeriodForClose(periodId: $periodId, administrationId: $administrationId);
			if ($record === null) {
				return McpResult::error(code: 'not_found', message: self::NOT_FOUND);
			}

			$record['aiFlags'] = $this->assistant->analyse(
				administrationId: $administrationId,
				periodId: (string)($record['periodId'] ?? $periodId),
				endDate: (string)($record['endDate'] ?? '')
			);

			return ['period' => $record];
		} catch (Throwable $e) {
			return $this->failed(action: 'status', args: $args, exception: $e);
		}
	}//end status()

	/**
	 * Start closing the period (`PeriodCloseController::startClose`).
	 *
	 * @param array<string, mixed> $args The validated arguments.
	 *
	 * @return array<string, mixed> `{period}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function start(array $args): array {
		return $this->transition(
			action: 'start',
			args: $args,
			run: fn (string $periodId, string $administrationId, string $userId): array => $this->periods->startClose(
				periodId: $periodId,
				administrationId: $administrationId,
				userId: $userId
			)
		);
	}//end start()

	/**
	 * Close the period (`PeriodCloseController::close`).
	 *
	 * @param array<string, mixed> $args The validated arguments.
	 *
	 * @return array<string, mixed> `{period}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function close(array $args): array {
		return $this->transition(
			action: 'close',
			args: $args,
			run: fn (string $periodId, string $administrationId, string $userId): array => $this->periods->closePeriod(
				periodId: $periodId,
				administrationId: $administrationId,
				userId: $userId
			)
		);
	}//end close()

	/**
	 * Reopen a closed period with a reason (`PeriodCloseController::reopen`).
	 *
	 * @param array<string, mixed> $args The validated arguments.
	 *
	 * @return array<string, mixed> `{period}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function reopen(array $args): array {
		$closeReason = trim((string)($args['closeReason'] ?? ''));

		return $this->transition(
			action: 'reopen',
			args: $args,
			run: fn (string $periodId, string $administrationId, string $userId): array => $this->periods->reopenPeriod(
				periodId: $periodId,
				administrationId: $administrationId,
				closeReason: $closeReason,
				userId: $userId
			)
		);
	}//end reopen()

	/**
	 * Run one transition for the signed-in user, after the gate.
	 *
	 * @param string               $action The tool's verb, for the log.
	 * @param array<string, mixed> $args   The validated arguments.
	 * @param callable             $run    (periodId, administrationId, userId) => the period.
	 *
	 * @return array<string, mixed> `{period}` or a structured refusal.
	 */
	private function transition(string $action, array $args, callable $run): array {
		$refusal = $this->gate->refusal(administrationId: (string)$args['administrationId'], notFound: self::NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return ['period' => $run((string)$args['periodId'], (string)$args['administrationId'], $this->gate->userId())];
		} catch (PeriodCloseException $e) {
			return McpResult::error(code: self::codeOf(status: $e->getStatus()), message: $e->getMessage());
		} catch (Throwable $e) {
			return $this->failed(action: $action, args: $args, exception: $e);
		}
	}//end transition()

	/**
	 * The refusal code of a service status, as the controller maps it to HTTP.
	 *
	 * @param string $status One of the service's ERR_* statuses.
	 *
	 * @return string forbidden (403), not_found (404), invalid_arguments (422) or conflict (409).
	 */
	private static function codeOf(string $status): string {
		return match ($status) {
			PeriodCloseServiceInterface::ERR_FORBIDDEN => 'forbidden',
			PeriodCloseServiceInterface::ERR_NOT_FOUND => 'not_found',
			PeriodCloseServiceInterface::ERR_VALIDATION => 'invalid_arguments',
			default => 'conflict',
		};
	}//end codeOf()

	/**
	 * Log an unexpected failure and answer a generic one, as the controller's 500 does.
	 *
	 * @param string               $action    The tool's verb.
	 * @param array<string, mixed> $args      The arguments.
	 * @param Throwable            $exception The failure.
	 *
	 * @return array<string, mixed> The structured failure.
	 */
	private function failed(string $action, array $args, Throwable $exception): array {
		$this->logger->error(
			'Shillinq MCP: period close ' . $action . ' failed',
			['administrationId' => $args['administrationId'], 'periodId' => $args['periodId'], 'exception' => $exception->getMessage()]
		);

		return McpResult::error(code: 'failed', message: 'An unexpected error occurred');
	}//end failed()
}//end class
