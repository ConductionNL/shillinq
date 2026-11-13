<?php

/**
 * Unit tests for InstantiateCloseChecklistAction.
 *
 * The store is the in-memory object store; the administration is addressed
 * by its code, the way the app addresses it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-period-that-starts-closing-copies-the-checklist-template-req-lpe-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\InstantiateCloseChecklistAction;
use OCA\Shillinq\Service\RegisterLocation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Lifecycle\Action\InstantiateCloseChecklistAction
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class InstantiateCloseChecklistActionTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * Seed an administration and two templates for other forms.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectStore();
		$this->store->rows['Administration'] = [
			['id' => 'adm-uuid-1', 'administrationCode' => 'ADM-001', 'legalForm' => 'BV'],
			['id' => 'adm-uuid-2', 'administrationCode' => 'ADM-002', 'legalForm' => ''],
		];
		$this->store->rows['CloseChecklistTemplate'] = [
			['id' => 't-nv', 'templateName' => 'NV afsluiting', 'administrationTypeId' => 'NV', 'tasks' => [['taskId' => 'x', 'taskName' => 'Niet voor BV']]],
		];

	}//end setUp()

	/**
	 * The action over the store.
	 *
	 * @return InstantiateCloseChecklistAction
	 */
	private function action(): InstantiateCloseChecklistAction {
		return new InstantiateCloseChecklistAction(
			$this->store->mock($this),
			$this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq'])
		);

	}//end action()

	/**
	 * Add a template for BV.
	 *
	 * @param string            $name  Its name.
	 * @param array<int, mixed> $tasks Its tasks.
	 *
	 * @return void
	 */
	private function bvTemplate(string $name, array $tasks): void {
		$this->store->rows['CloseChecklistTemplate'][] = ['id' => 't-' . $name, 'templateName' => $name, 'administrationTypeId' => 'BV', 'tasks' => $tasks];

	}//end bvTemplate()

	public function testAnEmptyChecklistGetsTheTemplatesTasksAllOpen(): void {
		$this->bvTemplate(
			'Maandafsluiting',
			[
				['taskId' => 'bank', 'taskName' => 'Bankmutaties volledig verwerkt', 'category' => 'bank'],
				['taskId' => 'btw', 'taskName' => 'BTW-aangifte voorbereid', 'category' => 'vat'],
				['taskId' => 'memo', 'taskName' => 'Memoriaal nagelopen'],
				['taskName' => 'Zonder id', 'category' => 'unknown'],
				['taskId' => 'leeg', 'taskName' => '  '],
				'geen taak',
			]
		);

		$period = ['id' => 'p-2026-09', 'administrationId' => 'ADM-001', 'state' => 'closing', 'taskChecklistItems' => []];
		$result = $this->action()->execute($period, ['state' => 'open'], [], 'startClose');

		$this->assertSame(
			[
				['id' => 'bank', 'category' => 'bank', 'description' => 'Bankmutaties volledig verwerkt', 'resolved' => false, 'resolvedAt' => null, 'resolvedBy' => null],
				['id' => 'btw', 'category' => 'vat', 'description' => 'BTW-aangifte voorbereid', 'resolved' => false, 'resolvedAt' => null, 'resolvedBy' => null],
				['id' => 'memo', 'category' => 'other', 'description' => 'Memoriaal nagelopen', 'resolved' => false, 'resolvedAt' => null, 'resolvedBy' => null],
				['id' => 'task-4', 'category' => 'other', 'description' => 'Zonder id', 'resolved' => false, 'resolvedAt' => null, 'resolvedBy' => null],
			],
			$result['taskChecklistItems']
		);
		$this->assertSame('p-2026-09', $result['id']);
		$this->assertSame([], $this->store->saved, 'the action writes nothing itself');

	}//end testAnEmptyChecklistGetsTheTemplatesTasksAllOpen()

	public function testAChecklistThatAlreadyHasItemsIsKept(): void {
		$this->bvTemplate('Maandafsluiting', [['taskId' => 'bank', 'taskName' => 'Bank']]);
		$items  = [['id' => 'ap', 'category' => 'ap', 'description' => 'Crediteuren', 'resolved' => true]];
		$period = ['administrationId' => 'ADM-001', 'taskChecklistItems' => $items];

		$this->assertSame($period, $this->action()->execute($period, [], [], 'startClose'));

	}//end testAChecklistThatAlreadyHasItemsIsKept()

	public function testWithoutATemplateForTheLegalFormTheListStaysAsItWas(): void {
		$period = ['administrationId' => 'ADM-001'];

		$this->assertSame($period, $this->action()->execute($period, [], [], 'startClose'));

	}//end testWithoutATemplateForTheLegalFormTheListStaysAsItWas()

	public function testWithoutAnAdministrationOrALegalFormNothingIsCopied(): void {
		$this->bvTemplate('Maandafsluiting', [['taskId' => 'bank', 'taskName' => 'Bank']]);

		foreach ([[], ['administrationId' => ''], ['administrationId' => 'ADM-404'], ['administrationId' => 'ADM-002']] as $period) {
			$period['taskChecklistItems'] = [];
			$this->assertSame([], $this->action()->execute($period, [], [], 'startClose')['taskChecklistItems']);
		}

	}//end testWithoutAnAdministrationOrALegalFormNothingIsCopied()

	public function testOfSeveralTemplatesForOneFormTheFirstByNameIsUsed(): void {
		$this->bvTemplate('Zomer', [['taskId' => 'z', 'taskName' => 'Zomertaak']]);
		$this->bvTemplate('Maandafsluiting', [['taskId' => 'm', 'taskName' => 'Maandtaak']]);

		$result = $this->action()->execute(['administrationId' => 'ADM-001'], [], [], 'startClose');

		$this->assertSame(['Maandtaak'], array_column($result['taskChecklistItems'], 'description'));

	}//end testOfSeveralTemplatesForOneFormTheFirstByNameIsUsed()

	public function testTheItemsAreACopySoALaterTemplateEditDoesNotReachThem(): void {
		$this->bvTemplate('Maandafsluiting', [['taskId' => 'bank', 'taskName' => 'Bank']]);
		$started = $this->action()->execute(['administrationId' => 'ADM-001'], [], [], 'startClose');

		$this->store->rows['CloseChecklistTemplate'][1]['tasks'][] = ['taskId' => 'nieuw', 'taskName' => 'Nieuwe taak'];
		$again = $this->action()->execute($started, [], [], 'startClose');

		$this->assertSame($started['taskChecklistItems'], $again['taskChecklistItems']);
		$this->assertCount(1, $again['taskChecklistItems']);

	}//end testTheItemsAreACopySoALaterTemplateEditDoesNotReachThem()
}//end class
