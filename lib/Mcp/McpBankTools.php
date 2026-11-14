<?php

/**
 * Shillinq MCP bank tools
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

use DomainException;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\ReconciliationResolutionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\StatementIntakeServiceInterface;
use OCA\Shillinq\Kernel\Contracts\StatementParserInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `shillinq.bank_statement.import` and `shillinq.bank_reconciliation.resolve`:
 * the service paths behind `BankStatementImportController::import` and
 * `ReconciliationResolutionController::resolve` / `bulkResolve`.
 *
 * Both act on the administration the call names, behind the gate: the import
 * lands there, and a reconciliation is resolved only when it belongs to it.
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */
class McpBankTools {

	/**
	 * The masked answer for an administration the caller may not reach.
	 */
	public const ADMINISTRATION_NOT_FOUND = 'Administration not found';

	/**
	 * The masked answer for a foreign or missing reconciliation, as the controller words a missing match.
	 */
	public const RECONCILIATION_NOT_FOUND = 'Unable to find the reconciliation match';

	/**
	 * Constructor.
	 *
	 * @param McpAdministrationGate                    $gate          The authorisation ladder.
	 * @param StatementParserInterface                 $parser        Parses camt.053, MT940 and CSV.
	 * @param StatementIntakeServiceInterface          $intake        Writes the parsed lines.
	 * @param ReconciliationResolutionServiceInterface $resolution    Resolves a reconciliation match.
	 * @param ObjectServiceInterface                   $objectService Loads a reconciliation to check its administration.
	 * @param IAppConfig                               $appConfig     The register slug.
	 * @param LoggerInterface                          $logger        Logs a refusal or an unexpected failure.
	 */
	public function __construct(
		private readonly McpAdministrationGate $gate,
		private readonly StatementParserInterface $parser,
		private readonly StatementIntakeServiceInterface $intake,
		private readonly ReconciliationResolutionServiceInterface $resolution,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Import a bank statement file (`BankStatementImportController::import`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, format, contents, bankAccountIban?, glAccountId?.
	 *
	 * @return array<string, mixed> `{statementId, transactionCount, matchedCount, unmatchedCount}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function importStatement(array $args): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$contents = (string)$args['contents'];
		if (trim($contents) === '') {
			return McpResult::error(code: 'invalid_arguments', message: 'Empty file — nothing to import');
		}

		$format = (string)$args['format'];
		try {
			$parsed = $this->parser->parse(contents: $contents, format: $format);
			if (empty($parsed) === true) {
				return McpResult::error(code: 'invalid_arguments', message: 'Could not parse any transactions from the supplied file');
			}

			$result = $this->intake->ingest(
				administrationId: $administrationId,
				iban: trim((string)($args['bankAccountIban'] ?? '')),
				lines: $parsed,
				meta: ['source' => 'file', 'format' => $format, 'glAccountId' => trim((string)($args['glAccountId'] ?? ''))]
			);
		} catch (InvalidArgumentException $e) {
			$this->logger->error('Shillinq MCP: bank statement import refused', ['exception' => $e->getMessage()]);
			return McpResult::error(code: 'invalid_arguments', message: 'Invalid bank statement import request');
		} catch (Throwable $e) {
			$this->logger->error('Shillinq MCP: bank statement import failed', ['exception' => $e->getMessage()]);
			return McpResult::error(code: 'failed', message: 'Unable to import bank statement');
		}

		$transactionCount = count((array)($result['written'] ?? []));

		return [
			'statementId' => $result['statementId'] ?? null,
			'transactionCount' => $transactionCount,
			'matchedCount' => 0,
			'unmatchedCount' => $transactionCount,
		];
	}//end importStatement()

	/**
	 * Resolve one or more matches of a bank reconciliation, as the signed-in user
	 * (`ReconciliationResolutionController::resolve` / `bulkResolve`).
	 *
	 * @param array<string, mixed> $args Validated arguments: administrationId, reconId, matchIds, resolutionStatus, resolutionReason.
	 *
	 * @return array<string, mixed> `{applied, failed}` or a structured refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function resolve(array $args): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::RECONCILIATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		$reason = trim((string)$args['resolutionReason']);
		if ($reason === '') {
			return McpResult::error(code: 'invalid_arguments', message: 'resolutionReason is required (audit-trailed per REQ-REC-004)');
		}

		$reconId = (string)$args['reconId'];
		$refusal = $this->gate->recordRefusal(
			administrationId: $administrationId,
			record: $this->findReconciliation(reconId: $reconId),
			notFound: self::RECONCILIATION_NOT_FOUND
		);
		if ($refusal !== null) {
			return $refusal;
		}

		$applied = 0;
		$failed  = [];
		foreach ((array)$args['matchIds'] as $matchId) {
			try {
				$this->resolution->resolveMatch(
					reconId: $reconId,
					matchId: (string)$matchId,
					resolutionStatus: (string)$args['resolutionStatus'],
					resolutionReason: $reason,
					actor: $this->gate->userId()
				);
				$applied++;
			} catch (Throwable $e) {
				$this->logger->error(
					'Shillinq MCP: reconciliation resolve failed for one match',
					['reconId' => $reconId, 'matchId' => $matchId, 'exception' => $e->getMessage()]
				);
				$failed[(string)$matchId] = self::failureMessage(exception: $e);
			}
		}

		return ['applied' => $applied, 'failed' => $failed];
	}//end resolve()

	/**
	 * The safe message for one failed match, as the controller words it.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return string
	 */
	private static function failureMessage(Throwable $exception): string {
		if ($exception instanceof OutOfBoundsException) {
			return 'Unable to find the reconciliation match';
		}

		if ($exception instanceof DomainException) {
			return 'Unable to resolve a match on a locked reconciliation';
		}

		return 'Unable to resolve this match';
	}//end failureMessage()

	/**
	 * The reconciliation by id, or null when it is absent or cannot be looked up.
	 *
	 * @param string $reconId The reconciliation id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findReconciliation(string $reconId): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			$register = 'shillinq';
		}

		try {
			$scoped = $this->objectService->setRegister($register)->setSchema('BankReconciliation');
		} catch (Throwable $e) {
			$this->logger->error('Shillinq MCP: cannot scope ObjectService for BankReconciliation', ['exception' => $e->getMessage()]);
			return null;
		}

		return ObjectIdentifier::findOne(scoped: $scoped, id: $reconId);
	}//end findReconciliation()
}//end class
