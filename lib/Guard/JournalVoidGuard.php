<?php

/**
 * Journal Void Guard
 *
 * Lifecycle precondition for the JournalEntry `void` transition, referenced
 * from lib/Settings/shillinq_register.json. Thin PHP seam per ADR-031
 * §"PHP guards remain a legitimate seam" — voiding a posted journal MUST be
 * blocked until its materialised GLTransaction is already reversed per
 * REQ-GL-004 / REQ-JE-010, a cross-schema precondition the declarative engine
 * cannot express.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards the JournalEntry `void` (posted → voided) transition.
 *
 * Referenced by name from the JournalEntry schema's
 * x-openregister-lifecycle `transitions.void.requires` clause. Returns true
 * only when the journal's materialised GLTransaction already has an offsetting
 * reversal per REQ-GL-004; false otherwise (surfaced to the operator as
 * "storneer eerst de grootboektransactie").
 *
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md (REQ-JE-010)
 */
class JournalVoidGuard {
	/**
	 * Construct the guard with lazy DI of OR's ObjectService.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for dynamic register slug resolution.
	 * @param LoggerInterface $logger Nextcloud logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq' if unset.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Precondition for `void` (posted → voided): the journal's materialised
	 * GLTransaction MUST already be reversed per REQ-GL-004 — i.e. an
	 * offsetting GLTransaction whose `reversesTransactionId` points at the
	 * journal's `glTransactionId` exists. Without that reversal, voiding would
	 * strand the GL posting in the ledger; the transition is denied.
	 *
	 * Fail-closed: any error denies the void (a void that cannot prove the
	 * reversal is unsafe). When the GLTransaction register is not yet available
	 * (sibling change has not shipped) the void is likewise denied because no
	 * posting could have been materialised in the first place.
	 *
	 * @param array<string, mixed> $journal JournalEntry object array (loaded by OR)
	 *
	 * @return bool True when the journal may be voided
	 *
	 * @spec openspec/specs/bookkeeping-journal-entries/spec.md (REQ-JE-010)
	 */
	public function requireReversedGLTransaction(array $journal): bool {
		$glTransactionId = (string)($journal['glTransactionId'] ?? '');
		if ($glTransactionId === '') {
			// A posted journal must carry a glTransactionId; without one there
			// is nothing to reverse and the state is inconsistent — deny.
			$this->logger->info(
				'JournalVoidGuard: void denied — journal has no materialised glTransactionId',
				['journalNumber' => ($journal['journalNumber'] ?? 'unknown')]
			);
			return false;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			// Look for an offsetting GLTransaction that reverses this one
			// (REQ-GL-004's reversal mechanism sets reversesTransactionId).
			$reversals = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('GLTransaction')
				->findAll(
					[
						'filters' => [
							'reversesTransactionId' => $glTransactionId,
						],
						'limit' => 1,
					]
				);

			$reversed = is_array($reversals) === true && count($reversals) > 0;
			if ($reversed === false) {
				$this->logger->info(
					'JournalVoidGuard: void denied — storneer eerst de grootboektransactie',
					[
						'journalNumber' => ($journal['journalNumber'] ?? 'unknown'),
						'glTransactionId' => $glTransactionId,
					]
				);
			}

			return $reversed;
		} catch (Throwable $e) {
			$this->logger->error(
				'JournalVoidGuard: reversal check failed — denying void (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireReversedGLTransaction()
}//end class
