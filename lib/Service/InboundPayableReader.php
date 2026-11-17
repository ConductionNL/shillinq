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
use OCA\Shillinq\Kernel\Contracts\InboundPayableReaderInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Reads the payouts one app raised.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
 */
class InboundPayableReader implements InboundPayableReaderInterface {
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

		$payables = [];
		foreach ($this->payablesOf(app: $app, category: $event->getCategory()) as $payable) {
			if ($this->isAsked(payable: $payable, event: $event) === true) {
				$payables[] = ($this->identityOf(payable: $payable) + $this->settlementOf(payable: $payable));
			}
		}

		$event->answer(payables: $payables);
	}//end listFor()

	/**
	 * Whether a payout matches the question's correlation ids and paid-in
	 * year, each only when given.
	 *
	 * @param array<string, mixed>   $payable One payout.
	 * @param PayablesRequestedEvent $event   The question.
	 *
	 * @return boolean
	 */
	private function isAsked(array $payable, PayablesRequestedEvent $event): bool {
		$wanted = $event->getCorrelationIds();
		$year = $event->getPaidInYear();

		return ($wanted === [] || in_array((string)($payable['correlationId'] ?? ''), $wanted, true) === true)
			&& ($year <= 0 || str_starts_with((string)($payable['paidOn'] ?? ''), $year . '-') === true);
	}//end isAsked()

	/**
	 * What a payout is: id, reference, correlation, category and amount.
	 *
	 * @param array<string, mixed> $payable One payout.
	 *
	 * @return array<string, mixed>
	 */
	private function identityOf(array $payable): array {
		return [
			'payableId' => (string)$payable['id'],
			'reference' => (string)($payable['reference'] ?? ''),
			'correlationId' => (string)($payable['correlationId'] ?? ''),
			'category' => (string)($payable['category'] ?? ''),
			'amount' => (float)($payable['amount'] ?? 0),
		];
	}//end identityOf()

	/**
	 * How a payout was settled: payee, currency, state, payment and links.
	 *
	 * @param array<string, mixed> $payable One payout.
	 *
	 * @return array<string, mixed>
	 */
	private function settlementOf(array $payable): array {
		return [
			'payee' => ['name' => (string)($payable['payee']['name'] ?? ''), 'iban' => (string)($payable['payee']['iban'] ?? '')],
			'currency' => (string)($payable['currency'] ?? 'EUR'),
			'state' => (string)($payable['state'] ?? ''),
			'paidOn' => (string)($payable['paidOn'] ?? ''),
			'bankReference' => (string)($payable['bankReference'] ?? ''),
			'historical' => (($payable['historical'] ?? false) === true),
			'linkedObjects' => array_values((array)($payable['linkedObjects'] ?? [])),
		];
	}//end settlementOf()

	/**
	 * Every payout one app raised, read past the caller's rights.
	 *
	 * @param string $app The raising app.
	 * @param string $category Only this category, when not empty.
	 *
	 * @return array<int, array<string, mixed>> The payouts, each with its id.
	 */
	private function payablesOf(string $app, string $category): array {
		$filters = $this->filtersFor(app: $app, category: $category);
		$scoped = $this->objectService->setRegister($this->registerSlug())->setSchema(self::SCHEMA);
		$found = [];
		for ($page = 0; $page < 50; $page++) {
			$rows = $scoped->findAll(['filters' => $filters, 'limit' => 200, 'offset' => ($page * 200)], _rbac: false, _multitenancy: false);
			array_push($found, ...$this->recordsWithIds(rows: $rows));
			if (count($rows) < 200) {
				break;
			}
		}

		return $found;
	}//end payablesOf()

	/**
	 * The filters for one app's payouts, narrowed to a category when given.
	 *
	 * @param string $app      The raising app.
	 * @param string $category Only this category, when not empty.
	 *
	 * @return array<string, string>
	 */
	private function filtersFor(string $app, string $category): array {
		$filters = ['sourceApp' => $app];
		if ($category !== '') {
			$filters['category'] = $category;
		}

		return $filters;
	}//end filtersFor()

	/**
	 * The rows that are records carrying an id.
	 *
	 * @param array<int, mixed> $rows One page of findAll() rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function recordsWithIds(array $rows): array {
		$records = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && (string)($record['id'] ?? '') !== '') {
				$records[] = $record;
			}
		}

		return $records;
	}//end recordsWithIds()

	/**
	 * The register slug shillinq's payouts live in.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
