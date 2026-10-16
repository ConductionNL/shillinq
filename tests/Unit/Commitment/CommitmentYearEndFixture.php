<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Commitment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Commitment;

use DateTime;
use OCA\Shillinq\Lifecycle\Action\RecordCommitmentMovementAction;
use OCA\Shillinq\Service\Commitment\CommitmentCarryOverService;
use OCA\Shillinq\Service\Commitment\CommitmentInvoicing;
use OCA\Shillinq\Service\Commitment\CommitmentLedger;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Gemeente Voorbeeld's commitments over one store, with the declared lifecycle and its actions.
 */
trait CommitmentYearEndFixture {
	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The engine.
	 *
	 * @var LifecycleFaithfulTransitionEngine
	 */
	private LifecycleFaithfulTransitionEngine $engine;

	/**
	 * Ids as OpenRegister mints them.
	 *
	 * @var array<string,string>
	 */
	private array $ids = [
		'v0114'      => '11111111-0114-4000-8000-000000000114',
		'v0120'      => '11111111-0120-4000-8000-000000000120',
		'line0114'   => '22222222-0114-4000-8000-000000000114',
		'line0120'   => '22222222-0120-4000-8000-000000000120',
		'po031'      => '33333333-0031-4000-8000-000000000031',
		'invoice'    => '44444444-1500-4000-8000-000000001500',
		'budget04'   => '55555555-2026-4000-8000-000000000004',
		'budget71'   => '55555555-2026-4000-8000-000000000071',
		'budget71n'  => '55555555-2027-4000-8000-000000000071',
	];

	/**
	 * Seed V-2026-0114 (draft, EUR 20,000 on 0.4 from PO-2026-031) and V-2026-0120
	 * (committed, EUR 48,000 on 7.1 with EUR 30,000 invoiced), their budgets and an
	 * approved EUR 15,000 invoice on PO-2026-031.
	 *
	 * @return void
	 */
	private function seed(): void {
		$this->store = new InMemoryObjectServiceStub(
			[
				'Commitment'         => [
					['id' => $this->ids['v0114'], 'administrationId' => 'adm-voorbeeld', 'commitmentNumber' => 'V-2026-0114', 'sourceReference' => 'PO-2026-031', 'kind' => 'purchase_order', 'counterparty' => 'Drukkerij Van der Meer', 'total_amount_excl_vat' => 2000000, 'currency' => 'EUR', 'status' => 'draft'],
					['id' => $this->ids['v0120'], 'administrationId' => 'adm-voorbeeld', 'commitmentNumber' => 'V-2026-0120', 'kind' => 'frameworkAgreement', 'counterparty' => 'Wegenbouw Oost', 'total_amount_excl_vat' => 4800000, 'currency' => 'EUR', 'status' => 'partially_invoiced'],
				],
				'CommitmentLine'     => [
					['id' => $this->ids['line0114'], 'administrationId' => 'adm-voorbeeld', 'commitment' => 'V-2026-0114', 'ruleNumber' => 1, 'description' => 'Drukwerk', 'financialYear' => 2026, 'amount_excl_vat' => 2000000, 'programme' => '0.4', 'costCentre' => 'KP-100', 'invoiced_amount' => 0, 'afgesloten' => false],
					['id' => $this->ids['line0120'], 'administrationId' => 'adm-voorbeeld', 'commitment' => 'V-2026-0120', 'ruleNumber' => 1, 'description' => 'Onderhoud wegen', 'financialYear' => 2026, 'amount_excl_vat' => 4800000, 'programme' => '7.1', 'invoiced_amount' => 3000000, 'remaining_committed' => 1800000, 'afgesloten' => false],
				],
				'CommitmentBudget'   => [
					['id' => $this->ids['budget04'], 'administrationId' => 'adm-voorbeeld', 'programmeCode' => '0.4', 'financialYear' => 2026, 'authorised_amount' => 6000000, 'realised_amount' => 0, 'outstanding_commitments' => 0, 'free_capacity' => 6000000],
					['id' => $this->ids['budget71'], 'administrationId' => 'adm-voorbeeld', 'programmeCode' => '7.1', 'financialYear' => 2026, 'authorised_amount' => 5000000, 'realised_amount' => 3000000, 'outstanding_commitments' => 1800000, 'free_capacity' => 200000],
					['id' => $this->ids['budget71n'], 'administrationId' => 'adm-voorbeeld', 'programmeCode' => '7.1', 'financialYear' => 2027, 'authorised_amount' => 1000000, 'realised_amount' => 0, 'outstanding_commitments' => 0, 'free_capacity' => 1000000],
				],
				'CommitmentMovement' => [],
				'PurchaseOrder'      => [
					['id' => $this->ids['po031'], 'administrationId' => 'adm-voorbeeld', 'poNumber' => 'PO-2026-031', 'statusCode' => 'approved'],
				],
				'SupplierInvoice'    => [
					['id' => $this->ids['invoice'], 'administrationId' => 'adm-voorbeeld', 'invoiceNumber' => 'F-2026-7781', 'invoiceDate' => '2026-09-20', 'currency' => 'EUR', 'totalExclVat' => 1500000, 'costCenter' => 'KP-100', 'matchedPoIds' => [$this->ids['po031']], 'statusCode' => 'approved'],
				],
			]
		);

		$ledger = $this->ledger();
		$action = new RecordCommitmentMovementAction($ledger, $this->session());
		$this->engine = new LifecycleFaithfulTransitionEngine(
			$this->store,
			['Commitment'],
			static function (array $record, array $object) use ($action): void {
				$declared = RegisterSchema::schema('Commitment')['x-openregister-lifecycle']['transitions'][$record['action']];
				foreach (($declared['actions'] ?? []) as $step) {
					if ($step['action'] === RecordCommitmentMovementAction::class) {
						$action->execute($object, [], ($step['actionParameters'] ?? []), $step['action']);
					}
				}
			}
		);

	}//end seed()

	/**
	 * The ledger over the store, today 2026-12-31.
	 *
	 * @return CommitmentLedger
	 */
	private function ledger(): CommitmentLedger {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-12-31'));
		return new CommitmentLedger($this->store, $settings, $time);

	}//end ledger()

	/**
	 * A signed-in controller.
	 *
	 * @return IUserSession
	 */
	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('m.jansen');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;

	}//end session()

	/**
	 * The transition runner over the engine.
	 *
	 * @return ObjectTransitionRunner
	 */
	private function runner(): ObjectTransitionRunner {
		$engine = $this->engine;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(static fn (): object => $engine);
		return new ObjectTransitionRunner(container: $container);

	}//end runner()

	/**
	 * The invoicing over the store.
	 *
	 * @return CommitmentInvoicing
	 */
	private function invoicing(): CommitmentInvoicing {
		return new CommitmentInvoicing($this->ledger(), $this->runner());

	}//end invoicing()

	/**
	 * The carry-over over the store.
	 *
	 * @return CommitmentCarryOverService
	 */
	private function carryOver(): CommitmentCarryOverService {
		return new CommitmentCarryOverService($this->ledger());

	}//end carryOver()

	/**
	 * One stored object.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>
	 */
	private function get(string $schema, string $id): array {
		return $this->store->setSchema($schema)->find($id)->getObject();

	}//end get()

	/**
	 * Every row of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all(string $schema): array {
		return $this->store->setSchema($schema)->findAll();

	}//end all()

	/**
	 * Register validation errors, the stub's obj-N ids mapped to uuids first.
	 *
	 * @param string              $schema The schema.
	 * @param array<string,mixed> $row    The row.
	 *
	 * @return array<string,mixed>
	 */
	private function registerErrors(string $schema, array $row): array {
		foreach ($row as $key => $value) {
			if (is_string($value) === true && preg_match('/^obj-\d+$/', $value) === 1) {
				$row[$key] = sprintf('00000000-0000-4000-8000-%012d', (int)substr($value, 4));
			}
		}

		return RegisterSchema::errors($schema, $row);

	}//end registerErrors()
}//end trait
