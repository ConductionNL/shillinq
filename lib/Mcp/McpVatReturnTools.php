<?php

/**
 * Shillinq MCP VAT-return tools
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

use OCA\Shillinq\Kernel\Contracts\VATReturnServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * `shillinq.vat_return.create|rebase|submit|destroy`: the service paths behind
 * `VATReturnController::create`, `rebase`, `submit` and `destroy`, with the
 * same refusals.
 *
 * A call names the administration as well as the return; the return must
 * belong to it, so the audit record names the administration the call acted
 * on. Lifecycle rules (only a draft is submitted or deleted, only a submitted
 * return is rebased) stay where the controller keeps them: in the service, and
 * the draft-only delete check here as in `destroy()`.
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */
class McpVatReturnTools {

	/**
	 * The masked answer for a foreign or missing return, as the controller words it.
	 */
	public const NOT_FOUND = 'VAT return not found';

	/**
	 * The masked answer for an administration the caller may not reach, as `create()` words it.
	 */
	public const ADMINISTRATION_NOT_FOUND = 'Administration not found';

	/**
	 * Constructor.
	 *
	 * @param McpAdministrationGate     $gate      The authorisation ladder.
	 * @param VATReturnServiceInterface $service   The VAT-return lifecycle.
	 * @param ContainerInterface        $container Resolves OpenRegister's ObjectService for the delete, as the controller does.
	 * @param LoggerInterface           $logger    Logs an unexpected failure.
	 */
	public function __construct(
		private readonly McpAdministrationGate $gate,
		private readonly VATReturnServiceInterface $service,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a VAT return for a past or current period (`VATReturnController::create`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, period, periodYear, periodNumber, regime.
	 *
	 * @return array<string, mixed> `{vatReturn}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function create(array $args): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$period       = (string)$args['period'];
		$periodYear   = (int)$args['periodYear'];
		$periodNumber = (int)$args['periodNumber'];
		$problem = self::periodProblem(period: $period, periodYear: $periodYear, periodNumber: $periodNumber, today: gmdate('Y-m-d'));
		if ($problem !== null) {
			return McpResult::error(code: 'invalid_arguments', message: $problem);
		}

		try {
			return [
				'vatReturn' => $this->service->createReturn(
					administrationId: $administrationId,
					period: $period,
					periodYear: $periodYear,
					periodNumber: $periodNumber,
					regime: (string)$args['regime']
				),
			];
		} catch (Throwable $e) {
			return $this->failed(action: 'create', args: $args, exception: $e, message: 'Failed to create VAT return');
		}
	}//end create()

	/**
	 * Submit a draft VAT return (`VATReturnController::submit`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, returnId.
	 *
	 * @return array<string, mixed> `{vatReturn}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function submit(array $args): array {
		return $this->transition(
			action: 'submit',
			args: $args,
			run: fn (string $returnId, string $userId): array => $this->service->submitReturn(returnId: $returnId, userId: $userId)
		);
	}//end submit()

	/**
	 * Rebase a submitted VAT return back to draft (`VATReturnController::rebase`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, returnId.
	 *
	 * @return array<string, mixed> `{vatReturn}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function rebase(array $args): array {
		return $this->transition(
			action: 'rebase',
			args: $args,
			run: fn (string $returnId, string $userId): array => $this->service->rebaseReturn(returnId: $returnId, userId: $userId)
		);
	}//end rebase()

	/**
	 * Delete a draft VAT return (`VATReturnController::destroy`); a submitted one is refused.
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, returnId.
	 *
	 * @return array<string, mixed> `{id, deleted}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function destroy(array $args): array {
		$returnId = (string)$args['returnId'];
		try {
			$refusal = $this->loadRefusal(args: $args, vatReturn: $vatReturn);
			if ($refusal !== null) {
				return $refusal;
			}

			if ((string)($vatReturn['statusCode'] ?? '') !== 'draft') {
				return McpResult::error(code: 'conflict', message: 'Only draft returns can be deleted');
			}

			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService->setRegister(register: 'shillinq')->setSchema(schema: 'BtwAangifte')->deleteObject($returnId);
		} catch (Throwable $e) {
			return $this->failed(action: 'destroy', args: $args, exception: $e, message: 'Failed to delete VAT return');
		}

		return ['id' => $returnId, 'deleted' => true];
	}//end destroy()

	/**
	 * Why a create period is refused, as `VATReturnController::validateCreatePeriod()` words it, or null.
	 *
	 * The enums and the year range are in the tool's input schema; what is
	 * left is the per-period upper bound and the future-period rule.
	 *
	 * @param string $period       quarter | month | year.
	 * @param int    $periodYear   The fiscal year.
	 * @param int    $periodNumber The period within the year.
	 * @param string $today        Today as Y-m-d (UTC, as the controller reads it).
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public static function periodProblem(string $period, int $periodYear, int $periodNumber, string $today): ?string {
		if ($period === 'quarter' && $periodNumber > 4) {
			return 'periodNumber must be 1..4 for quarter';
		}

		if ($period === 'month' && $periodNumber > 12) {
			return 'periodNumber must be 1..12 for month';
		}

		$currentYear  = (int)substr($today, 0, 4);
		$currentMonth = (int)substr($today, 5, 2);
		$startMonth   = match ($period) {
			'quarter' => (1 + (($periodNumber - 1) * 3)),
			'month' => $periodNumber,
			default => 1,
		};
		if ($periodYear > $currentYear || ($periodYear === $currentYear && $startMonth > $currentMonth)) {
			return 'Cannot create returns for future periods';
		}

		return null;
	}//end periodProblem()

	/**
	 * Run one lifecycle transition for the signed-in user, after the gate.
	 *
	 * @param string               $action The tool's verb, for the log.
	 * @param array<string, mixed> $args   The validated arguments.
	 * @param callable             $run    (returnId, userId) => the return.
	 *
	 * @return array<string, mixed> `{vatReturn}` or a structured refusal.
	 */
	private function transition(string $action, array $args, callable $run): array {
		try {
			$refusal = $this->loadRefusal(args: $args, vatReturn: $vatReturn);
			if ($refusal !== null) {
				return $refusal;
			}

			return ['vatReturn' => $run((string)$args['returnId'], $this->gate->userId())];
		} catch (RuntimeException $e) {
			$this->logger->error('Shillinq MCP: VAT return ' . $action . ' refused', ['returnId' => $args['returnId'], 'exception' => $e->getMessage()]);
			return McpResult::error(code: 'conflict', message: 'Unable to ' . $action . ' VAT return');
		} catch (Throwable $e) {
			return $this->failed(action: $action, args: $args, exception: $e, message: 'Failed to ' . $action . ' VAT return');
		}
	}//end transition()

	/**
	 * Refuse an anonymous caller or an unreachable administration before
	 * anything loads, then load the return and refuse it unless it belongs to
	 * that administration.
	 *
	 * @param array<string, mixed>      $args      The validated arguments.
	 * @param array<string, mixed>|null $vatReturn Set to the loaded return.
	 *
	 * @return array<string, mixed>|null The refusal, or null when the call may run.
	 */
	private function loadRefusal(array $args, ?array &$vatReturn): ?array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$vatReturn = $this->service->findReturn(returnId: (string)$args['returnId']);

		return $this->gate->recordRefusal(administrationId: $administrationId, record: $vatReturn, notFound: self::NOT_FOUND);
	}//end loadRefusal()

	/**
	 * Log an unexpected failure and answer a generic one, as the controller's 500 does.
	 *
	 * @param string               $action    The tool's verb.
	 * @param array<string, mixed> $args      The arguments.
	 * @param Throwable            $exception The failure.
	 * @param string               $message   The generic answer.
	 *
	 * @return array<string, mixed> The structured failure.
	 */
	private function failed(string $action, array $args, Throwable $exception, string $message): array {
		$this->logger->error(
			'Shillinq MCP: VAT return ' . $action . ' failed',
			['administrationId' => $args['administrationId'], 'returnId' => ($args['returnId'] ?? null), 'exception' => $exception->getMessage()]
		);

		return McpResult::error(code: 'failed', message: $message);
	}//end failed()
}//end class
