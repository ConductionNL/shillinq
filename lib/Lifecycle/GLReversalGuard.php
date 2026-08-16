<?php

/**
 * GL Reversal Guard
 *
 * Lifecycle precondition shared by the `void` transitions of
 * ExpenseClaimEntry, APInvoice, and ARInvoice (T1 REQ-GL-004:
 * "the materialised GLTransaction MUST already be reversed"). ADR-031
 * exception-path PHP guard: resolving the sibling GLTransaction and
 * inspecting its state is not expressible in the declarative lifecycle DSL.
 *
 * shillinq#425: class did not exist prior to this change; every `void`
 * transition referencing it hard-failed (RuntimeException from
 * LifecycleGuardRegistry).
 *
 * Note: APInvoice and ARInvoice are currently declared at the wrong JSON
 * nesting level in lib/Settings/shillinq_register.json (`components.APInvoice`
 * / `components.ARInvoice` instead of `components.schemas.APInvoice` /
 * `components.schemas.ARInvoice`), so OpenRegister's ImportHandler — which
 * reads strictly from `components.schemas` (openregister/lib/Service/
 * Configuration/ImportHandler.php:1602) — never creates those two schemas at
 * all. That is a separate, pre-existing defect (filed as shillinq#434) and
 * out of scope here; this guard is still correct and will function the
 * moment that nesting bug is fixed. ExpenseClaimEntry (properly nested) is
 * unaffected and already exercises this guard for real today.
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
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards `void` transitions — the object's `glTransactionId` must resolve to
 * a GLTransaction already in state `reversed` (T1 REQ-GL-004).
 *
 * Fail-closed: a missing `glTransactionId`, an unresolvable GLTransaction, or
 * any lookup exception all deny the void.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class GLReversalGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition for `void`: the linked GLTransaction must be `reversed`.
	 *
	 * @param array<string, mixed> $object The object being transitioned (ExpenseClaimEntry,
	 *                                     APInvoice, or ARInvoice).
	 *
	 * @return bool True when the linked GLTransaction is reversed and void may proceed.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function isReversed(array $object): bool {
		$glTransactionId = trim((string)($object['glTransactionId'] ?? ''));
		if ($glTransactionId === '') {
			// Nothing was ever materialised — refuse to void (fail-closed;
			// an operator should reject/cancel, not void, in that state).
			return false;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$found = $objectService
				->setRegister($this->register())
				->setSchema('GLTransaction')
				->findAll(['filters' => ['id' => $glTransactionId], 'limit' => 1]);

			if (is_array($found) === false || $found === []) {
				$this->logger->info(
					'GLReversalGuard: linked GLTransaction not found — denying void',
					['glTransactionId' => $glTransactionId]
				);
				return false;
			}

			$transaction = (array)$found[0];
			return (string)($transaction['state'] ?? '') === 'reversed';
		} catch (\Throwable $e) {
			$this->logger->error(
				'GLReversalGuard: isReversed check failed — denying void (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isReversed()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
