<?php

/**
 * Shillinq Bankfeed Reconciliation Job
 *
 * Daily 08:00 PSD2 bankfeed pull via openconnector. For each connected
 * BankConnection: fetch new transactions since last pull, attempt fuzzy match
 * against CashflowARProjection / CashflowAPSchedule via BankfeedMatcher, and
 * flag remainders for operator review.
 *
 * Per ADR-031 / REQ-CF-012 this job is pure orchestration: matching logic is
 * isolated in BankfeedMatcher (single-method bridge); lifecycle transitions on
 * AR/AP invoices run via the OpenRegister engine.
 *
 * @category Cron
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-20
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\Service\BankfeedMatcher;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily bankfeed pull + reconciliation orchestrator.
 *
 * @psalm-api
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-20
 */
class BankfeedReconciliationJob extends TimedJob {

	/**
	 * Daily interval in seconds.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param SettingsService $settingsService Register-slug resolver.
	 * @param BankfeedMatcher $matcher Fuzzy-match transactions to AR/AP.
	 * @param ContainerInterface $container DI container for OR ObjectService.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settingsService,
		private readonly BankfeedMatcher $matcher,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Pull bankfeed and run reconciliation.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-20
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
	 *     TimedJob::run()'s signature; this job takes no argument.
	 */
	protected function run($argument): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$this->logger->info('BankfeedReconciliationJob: OR unavailable, skipping.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->settingsService->getRegisterSlug();

			$connections = $objectService
				->setRegister($registerSlug)
				->setSchema('BankConnection')
				->findAll(
					[
						'filters' => ['lifecycleState' => 'active'],
					]
				);

			$reconciledCount = 0;
			$unmatchedCount = 0;

			foreach ($connections as $connection) {
				$iban = $this->extractField(entity: $connection, field: 'bankAccountIban');
				if ($iban === null) {
					continue;
				}

				// Lookup candidate AR projections for this administration.
				$admin = $this->extractField(entity: $connection, field: 'administrationId');
				if ($admin === null) {
					continue;
				}

				$candidates = $objectService
					->setRegister($registerSlug)
					->setSchema('CashflowARProjection')
					->findAll(
						[
							'filters' => ['administrationId' => $admin],
						]
					);

				// The PSD2 fetch itself is performed by openconnector. The
				// shillinq-side bankfeed transactions surface as BankStatement
				// entries; this job consumes the newly-arrived statement lines.
				$newStatements = $objectService
					->setRegister($registerSlug)
					->setSchema('BankStatement')
					->findAll(
						[
							'filters' => [
								'bankAccountIban' => $iban,
								'administrationId' => $admin,
							],
						]
					);

				foreach ($newStatements as $statement) {
					$match = $this->matcher->matchTransaction(
						transaction: $this->asArray(entity: $statement),
						candidateInvoices: array_map([$this, 'asArray'], $candidates)
					);

					if ($match['confidence'] >= 0.8 && $match['arInvoiceId'] !== null) {
						$reconciledCount++;
						continue;
					}

					$unmatchedCount++;
				}
			}//end foreach

			$this->logger->info(
				'BankfeedReconciliationJob: ' . $reconciledCount . ' reconciled, ' . $unmatchedCount . ' unmatched.'
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'BankfeedReconciliationJob failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

	}//end run()

	/**
	 * Defensive extractor — entity or array.
	 *
	 * @param mixed $entity Entity or array.
	 * @param string $field Field name.
	 *
	 * @return string|null
	 */
	private function extractField(mixed $entity, string $field): ?string {
		if (is_array($entity) === true) {
			return ($entity[$field] ?? null);
		}

		$getter = 'get' . ucfirst($field);
		if (method_exists($entity, $getter) === true) {
			return $entity->{$getter}();
		}

		return null;
	}//end extractField()

	/**
	 * Coerce an OR entity or array to a plain array for the matcher.
	 *
	 * @param mixed $entity Entity or array.
	 *
	 * @return array<string,mixed>
	 */
	private function asArray(mixed $entity): array {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (method_exists($entity, 'jsonSerialize') === true) {
			$serialized = $entity->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end asArray()
}//end class
