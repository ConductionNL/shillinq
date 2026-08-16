<?php

/**
 * Elimination Guard
 *
 * ADR-031 exception-path lifecycle guard for the IntercompanyTransaction
 * register (bookkeeping-intercompany-posting, T5 GR consolidation). The
 * IntercompanyTransaction schema's x-openregister-lifecycle transitions
 * (eliminate / exclude / restore / reinstate) reference
 * EliminationGuard::canChangeEliminationStatus because the immutability rule
 * (REQ-ICP-006) needs a cross-schema lookup that OpenRegister's declarative
 * `requires:` clause cannot yet express: the eliminationStatus of a
 * transaction may only change while the ConsolidatedReport it was consolidated
 * into is still editable (status draft / final but not published — see below).
 *
 * Once the owning ConsolidatedReport is `finalized`/`final` or `published`, the
 * published consolidated statement is based on a fixed set of eliminations, so
 * eliminationStatus is frozen. A new ConsolidatedReport must be created to apply
 * different elimination logic.
 *
 * ADR-031 exception reason: cross-schema state lookup (read the linked
 * ConsolidatedReport's status) is not yet expressible in the declarative
 * lifecycle DSL. When the engine gains that capability, replace this reference
 * with a declarative condition and delete this file.
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
 * @spec openspec/changes/bookkeeping-gr-consolidation/specs/bookkeeping-intercompany-posting.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guard for IntercompanyTransaction elimination-status changes.
 *
 * Referenced from the IntercompanyTransaction schema (register.d fragment)
 * x-openregister-lifecycle transitions.{eliminate,exclude,restore,reinstate}.requires
 * as OCA\Shillinq\Lifecycle\EliminationGuard::canChangeEliminationStatus.
 *
 * @spec openspec/changes/bookkeeping-gr-consolidation/specs/bookkeeping-intercompany-posting.md
 */
class EliminationGuard {
	/**
	 * ConsolidatedReport statuses that freeze the eliminations they contain.
	 *
	 * Mirrors the ConsolidatedReport lifecycle states (draft → final → published
	 * → archived); `final`, `published`, and `archived` are immutable. The legacy
	 * `finalized` spelling from the spec text is accepted defensively.
	 *
	 * @var array<int,string>
	 */
	private const FROZEN_REPORT_STATES = ['final', 'finalized', 'published', 'archived'];

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns true iff the transaction's eliminationStatus may still change.
	 *
	 * REQ-ICP-006: the eliminationStatus of an IntercompanyTransaction included
	 * in a finalized or published ConsolidatedReport cannot change. A transaction
	 * that has not yet been consolidated into any report (consolidatedReportId
	 * unset) is always free to change. A transaction whose linked report is still
	 * a draft is free to change. Only a transaction whose linked report is in a
	 * frozen state is locked.
	 *
	 * Fail-closed: returns false on any exception (REQ-ICP-006 / CWE-863).
	 *
	 * @param string $transactionId The IntercompanyTransaction.id
	 *                              (unused; present for call-signature
	 *                              parity with the lifecycle engine).
	 * @param array<string,mixed>|null $object The IntercompanyTransaction being transitioned.
	 *
	 * @return bool True when the elimination status may change.
	 *
	 * @spec openspec/changes/bookkeeping-gr-consolidation/specs/bookkeeping-intercompany-posting.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function canChangeEliminationStatus(string $transactionId, ?array $object = null): bool {
		try {
			$reportId = '';
			if ($object !== null && isset($object['consolidatedReportId']) === true) {
				$reportId = (string)$object['consolidatedReportId'];
			}

			// Not yet consolidated into any report — always editable (REQ-ICP-006).
			if ($reportId === '') {
				return true;
			}

			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->resolveRegister();

			$reports = $objectService
				->setRegister($register)
				->setSchema('ConsolidatedReport')
				->findAll(['filters' => ['id' => $reportId]]);

			foreach ($reports as $report) {
				if (is_array($report) === false) {
					continue;
				}

				$status = (string)($report['status'] ?? '');
				if (in_array($status, self::FROZEN_REPORT_STATES, true) === true) {
					$this->logger->info(
						'EliminationGuard: elimination status frozen — owning ConsolidatedReport is ' . $status,
						['intercompanyTransactionId' => $transactionId, 'consolidatedReportId' => $reportId]
					);
					return false;
				}
			}

			// No frozen report found for the referenced id — permit the change.
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'EliminationGuard: immutability check failed — denying status change (fail-closed)',
				['intercompanyTransactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canChangeEliminationStatus()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
