<?php

/**
 * Incasso Dossier Composer
 *
 * REQ-CCD-008. Assembles the dossier bundle POSTed to a configured incasso
 * bureau (Bos Incasso, Atradius Collections, Intrum) when the operator
 * triggers the stage-5 overdracht action. The bundle is the full audit
 * record needed by a bureau to take over the claim under Wki + Wsnp.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Composes the dossier-bundle JSON for a stage-5 overdracht.
 *
 * The actual POST is delegated to a DunningChannelAdapterInterface implementation
 * keyed by INCASSOBUREAU_API; this composer's only job is to gather all
 * audit-trail bits per REQ-CCD-008 into a single, self-describing payload.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
 */
class IncassoDossierComposer {
	/**
	 * Construct the composer with its DI dependencies.
	 *
	 * @param ContainerInterface $container DI for OR ObjectService.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Assemble the bundle.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $invoiceId Invoice FK.
	 * @param string $customerId Klant FK.
	 *
	 * @return array{invoiceId:string,content:array<string,mixed>}
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
	 */
	public function compose(string $administrationId, string $invoiceId, string $customerId): array {
		$register = $this->register();

		$dunningRuns = $this->findAll(
			register: $register,
			schema: 'DunningRun',
			filters: [
				'administrationId' => $administrationId,
				'invoiceId' => $invoiceId,
			]
		);
		$incassoCostAll = $this->findAll(
			register: $register,
			schema: 'IncassoKostenBerekening',
			filters: [
				'administrationId' => $administrationId,
				'invoiceId' => $invoiceId,
			]
		);
		$pauseAll = $this->findAll(
			register: $register,
			schema: 'DunningPauseDispute',
			filters: [
				'administrationId' => $administrationId,
				'invoiceId' => $invoiceId,
			]
		);

		// Pick the latest IncassoKostenBerekening (highest berekendOp date).
		usort(
			$incassoCostAll,
			static function (array $a, array $b): int {
				return strcmp(
					(string)($b['statutoryRente']['calculatedOn'] ?? ''),
					(string)($a['statutoryRente']['calculatedOn'] ?? '')
				);
			}
		);
		$latestIncassoCost = null;
		if ($incassoCostAll !== []) {
			$latestIncassoCost = $incassoCostAll[0];
		}

		$evidenceRefs = [];
		foreach ($dunningRuns as $run) {
			$hash = (string)($run['renderedPdfHash'] ?? '');
			if ($hash !== '') {
				$evidenceRefs[] = 'dunning-run:' . ((string)($run['id'] ?? '')) . ':sha256=' . $hash;
			}

			$barcode = (string)($run['postageStatus']['barcode'] ?? '');
			if ($barcode !== '') {
				$evidenceRefs[] = 'postnl:' . $barcode;
			}
		}

		return [
			'invoiceId' => $invoiceId,
			'content' => [
				'invoice' => [
					'invoiceId' => $invoiceId,
					'customerId' => $customerId,
					'administrationId' => $administrationId,
				],
				'dunningRuns' => $dunningRuns,
				'incassoKosten' => $latestIncassoCost,
				'pauseEvents' => $pauseAll,
				'klantGegevens' => [
					'customerId' => $customerId,
				],
				'evidenceRefs' => $evidenceRefs,
			],
		];

	}//end compose()

	/**
	 * Find all matching records via the canonical OR ObjectService API.
	 *
	 * @param string $register OR register slug.
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Filter map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $register, string $schema, array $filters): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll(['filters' => $filters]);
			if (is_array($rows) === true) {
				return $rows;
			}

			return [];
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: IncassoDossierComposer findAll(' . $schema . ') failed: ' . $e->getMessage());
			return [];
		}

	}//end findAll()

	/**
	 * Resolve the configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
