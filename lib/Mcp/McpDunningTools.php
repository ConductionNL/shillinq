<?php

/**
 * Shillinq MCP dunning tools
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\DunningRunServiceInterface;
use OCA\Shillinq\Kernel\Contracts\IncassoDossierComposerInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `shillinq.dunning.dossier|execute_run|pause|resume|write_off`: the service
 * paths behind `DunningController::dossier`, `executeRun`, `pause`,
 * `resumePause` and `writeOff`, with the same refusals.
 *
 * Each tool names only the fields its controller twin reads for that action;
 * the rest (the ladder, the letter, the staging rules, the pause check before
 * a run) stays in DunningRunService. A pause is resumed only when it belongs to
 * the administration the call names.
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */
class McpDunningTools {

	/**
	 * The masked answer for an administration the caller may not reach, as the controller words it.
	 */
	public const ADMINISTRATION_NOT_FOUND = 'Administration not found';

	/**
	 * The masked answer for a foreign or missing pause, as the controller words it.
	 */
	public const PAUSE_NOT_FOUND = 'Dunning pause not found';

	/**
	 * Constructor.
	 *
	 * @param McpAdministrationGate           $gate          The authorisation ladder.
	 * @param DunningRunServiceInterface      $runs          The dunning run orchestrator.
	 * @param IncassoDossierComposerInterface $dossier       Composes the collection dossier.
	 * @param ObjectServiceInterface          $objectService Loads a pause to check its administration.
	 * @param IAppConfig                      $appConfig     The register slug.
	 * @param LoggerInterface                 $logger        Logs an unexpected failure.
	 */
	public function __construct(
		private readonly McpAdministrationGate $gate,
		private readonly DunningRunServiceInterface $runs,
		private readonly IncassoDossierComposerInterface $dossier,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compose the collection dossier of an invoice (`DunningController::dossier`). Changes nothing.
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, invoiceId, customerId.
	 *
	 * @return array<string, mixed> `{dossier}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function dossier(array $args): array {
		return $this->guarded(
			action: 'dossier',
			args: $args,
			failure: 'Failed to compose dossier',
			run: fn (string $administrationId): array => [
				'dossier' => $this->dossier->compose(
					administrationId: $administrationId,
					invoiceId: (string)$args['invoiceId'],
					customerId: (string)$args['customerId']
				),
			]
		);
	}//end dossier()

	/**
	 * Execute the next dunning stage for an invoice (`DunningController::executeRun`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, invoiceId, ladderId?, stageNr?.
	 *
	 * @return array<string, mixed> `{dunningRun}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function executeRun(array $args): array {
		$refusal = $this->gate->refusal(administrationId: (string)$args['administrationId'], notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$params = array_intersect_key($args, array_flip(['invoiceId', 'ladderId', 'stageNr']));
		try {
			return ['dunningRun' => $this->runs->executeStage(administrationId: (string)$args['administrationId'], params: $params)];
		} catch (Throwable $e) {
			$this->logger->error('Shillinq MCP: dunning run failed', ['invoiceId' => $args['invoiceId'], 'exception' => $e->getMessage()]);
			return McpResult::error(code: 'conflict', message: 'Unable to execute the dunning run');
		}
	}//end executeRun()

	/**
	 * Pause dunning on an invoice, as the signed-in user (`DunningController::pause`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, invoiceId, reason, details?.
	 *
	 * @return array<string, mixed> `{pause}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function pause(array $args): array {
		return $this->guarded(
			action: 'pause',
			args: $args,
			failure: 'Failed to create dunning pause',
			run: fn (string $administrationId): array => [
				'pause' => $this->runs->pause(
					administrationId: $administrationId,
					invoiceId: (string)$args['invoiceId'],
					reason: (string)$args['reason'],
					details: (string)($args['details'] ?? ''),
					pausedBy: $this->gate->userId()
				),
			]
		);
	}//end pause()

	/**
	 * Resolve or expire a dunning pause (`DunningController::resumePause`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, pauseId, resolution?, partialSettlement?.
	 *
	 * @return array<string, mixed> `{pause}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function resume(array $args): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$pauseId = (string)$args['pauseId'];
		$refusal = $this->gate->recordRefusal(
			administrationId: $administrationId,
			record: $this->findPause(pauseId: $pauseId),
			notFound: self::PAUSE_NOT_FOUND
		);
		if ($refusal !== null) {
			return $refusal;
		}

		$settlement = ($args['partialSettlement'] ?? null);
		if ($settlement !== null) {
			$settlement = (float)$settlement;
		}

		try {
			return [
				'pause' => $this->runs->resumePause(
					administrationId: $administrationId,
					pauseId: $pauseId,
					resolution: (string)($args['resolution'] ?? 'resolve'),
					partialSettlement: $settlement
				),
			];
		} catch (Throwable $e) {
			$this->logger->error('Shillinq MCP: dunning resume failed', ['pauseId' => $pauseId, 'exception' => $e->getMessage()]);
			return McpResult::error(code: 'not_found', message: 'Unable to resume the dunning pause');
		}
	}//end resume()

	/**
	 * Write off the principal of an invoice under art. 29 OB (`DunningController::writeOff`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, invoiceId, principalDepreciated,
	 *                                   art29OBDeclaration, vatAmount?, vatTaxReturnPeriod?, evidenceRef?.
	 *
	 * @return array<string, mixed> `{writeOff}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function writeOff(array $args): array {
		$refusal = $this->gate->refusal(administrationId: (string)$args['administrationId'], notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		if ((float)$args['principalDepreciated'] <= 0 || trim((string)$args['art29OBDeclaration']) === '') {
			return McpResult::error(
				code: 'invalid_arguments',
				message: 'factuurId, hoofdsomAfgeschreven (>0) and art29OBVerklaring are required'
			);
		}

		$params = array_intersect_key(
			$args,
			array_flip(['invoiceId', 'principalDepreciated', 'art29OBDeclaration', 'vatAmount', 'vatTaxReturnPeriod', 'evidenceRef'])
		);
		try {
			return ['writeOff' => $this->runs->writeOff(administrationId: (string)$args['administrationId'], params: $params)];
		} catch (Throwable $e) {
			$this->logger->error('Shillinq MCP: write-off failed', ['invoiceId' => $args['invoiceId'], 'exception' => $e->getMessage()]);
			return McpResult::error(code: 'failed', message: 'Failed to record write-off');
		}
	}//end writeOff()

	/**
	 * Run an action on an accessible administration; an unexpected failure is the controller's generic 500.
	 *
	 * @param string               $action  The tool's verb, for the log.
	 * @param array<string, mixed> $args    The validated arguments.
	 * @param string               $failure The generic failure message.
	 * @param callable             $run     (administrationId) => the result.
	 *
	 * @return array<string, mixed>
	 */
	private function guarded(string $action, array $args, string $failure, callable $run): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $run($administrationId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq MCP: dunning ' . $action . ' failed',
				['invoiceId' => ($args['invoiceId'] ?? null), 'exception' => $e->getMessage()]
			);
			return McpResult::error(code: 'failed', message: $failure);
		}
	}//end guarded()

	/**
	 * The pause by id, or null when it is absent or cannot be looked up.
	 *
	 * @param string $pauseId The pause id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findPause(string $pauseId): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			$register = 'shillinq';
		}

		try {
			$scoped = $this->objectService->setRegister($register)->setSchema('DunningPauseDispute');
		} catch (Throwable $e) {
			$this->logger->error('Shillinq MCP: cannot scope ObjectService for DunningPauseDispute', ['exception' => $e->getMessage()]);
			return null;
		}

		return ObjectIdentifier::findOne(scoped: $scoped, id: $pauseId);
	}//end findPause()
}//end class
