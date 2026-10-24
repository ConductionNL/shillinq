<?php

/**
 * Inbound Payable Reader
 *
 * Answers the app that raised payouts with its own payouts (REQ-FDO-008), so
 * dossiq's penalty audit and its objection flow read the payout from finance
 * instead of keeping a copy.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Event\PayablesRequestedEvent;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Reads the payouts one app raised.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
 */
class InboundPayableReader {
	/**
	 * The schema holding payouts.
	 *
	 * @var string
	 */
	private const SCHEMA = 'InboundPayable';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param IAppManager $appManager Whether the asking app is installed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Answer the app that raised payouts with its own payouts, narrowed by
	 * correlation id and by the year they were paid in (REQ-FDO-008).
	 *
	 * @param PayablesRequestedEvent $event The question.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function listFor(PayablesRequestedEvent $event): void {
		$app = $event->getSourceApp();
		if ($app === '' || $this->appManager->isInstalled($app) === false) {
			$event->refuse(error: sprintf('Only an installed app can read its payouts; "%s" is not one.', $app));
			return;
		}

		$wanted = $event->getCorrelationIds();
		$year = $event->getPaidInYear();
		$payables = [];
		foreach ($this->payablesOf(app: $app, category: $event->getCategory()) as $payable) {
			if ($wanted !== [] && in_array((string)($payable['correlationId'] ?? ''), $wanted, true) === false) {
				continue;
			}

			if ($year > 0 && str_starts_with((string)($payable['paidOn'] ?? ''), $year . '-') === false) {
				continue;
			}

			$payables[] = [
				'payableId' => (string)$payable['id'],
				'reference' => (string)($payable['reference'] ?? ''),
				'correlationId' => (string)($payable['correlationId'] ?? ''),
				'category' => (string)($payable['category'] ?? ''),
				'amount' => (float)($payable['amount'] ?? 0),
				'payee' => [
					'name' => (string)($payable['payee']['name'] ?? ''),
					'iban' => (string)($payable['payee']['iban'] ?? ''),
				],
				'currency' => (string)($payable['currency'] ?? 'EUR'),
				'state' => (string)($payable['state'] ?? ''),
				'paidOn' => (string)($payable['paidOn'] ?? ''),
				'bankReference' => (string)($payable['bankReference'] ?? ''),
				'historical' => (($payable['historical'] ?? false) === true),
				'linkedObjects' => array_values((array)($payable['linkedObjects'] ?? [])),
			];
		}//end foreach

		$event->answer(payables: $payables);
	}//end listFor()

	/**
	 * Every payout one app raised, read past the caller's rights.
	 *
	 * @param string $app The raising app.
	 * @param string $category Only this category, when not empty.
	 *
	 * @return array<int, array<string, mixed>> The payouts, each with its id.
	 */
	private function payablesOf(string $app, string $category): array {
		$filters = ['sourceApp' => $app];
		if ($category !== '') {
			$filters['category'] = $category;
		}

		$scoped = $this->objectService->setRegister($this->registerSlug())->setSchema(self::SCHEMA);
		$found = [];
		for ($page = 0; $page < 50; $page++) {
			$rows = $scoped->findAll(['filters' => $filters, 'limit' => 200, 'offset' => ($page * 200)], _rbac: false, _multitenancy: false);
			foreach ($rows as $row) {
				$record = ObjectIdentifier::recordWithId(candidate: $row);
				if ($record !== null && (string)($record['id'] ?? '') !== '') {
					$found[] = $record;
				}
			}

			if (count($rows) < 200) {
				break;
			}
		}

		return $found;
	}//end payablesOf()

	/**
	 * The register slug shillinq's payouts live in.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
