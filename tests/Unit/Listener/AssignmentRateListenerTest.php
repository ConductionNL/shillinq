<?php

/**
 * Unit tests for AssignmentRateListener and AssignmentRateRefresher: an
 * assignment shows the rate it bills at today, refreshed when its billing
 * role or rate card changes and when a rate card version becomes active
 * (REQ-BRPH-004).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Listener;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\AssignmentRateListener;
use OCA\Shillinq\Service\BillableRateService;
use OCA\Shillinq\Service\Billing\AssignmentRateRefresher;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Chris's assignment on Renovatie Kade 12 under rate card Standaard 2026.
 *
 * @covers \OCA\Shillinq\Listener\AssignmentRateListener
 * @covers \OCA\Shillinq\Service\Billing\AssignmentRateRefresher
 * @uses   \OCA\Shillinq\Service\BillableRateService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class AssignmentRateListenerTest extends TestCase {
	/**
	 * The administration.
	 *
	 * @var string
	 */
	private const ADMIN = 'adm-1';

	/**
	 * The project (an engagement of customer Het Anker).
	 *
	 * @var string
	 */
	private const PROJECT = '6b1d4e2a-9c37-4f58-8a21-3e7c5d9b0f12';

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Chris's assignment, without a billing role, showing EUR 95.
	 *
	 * @var array<string, mixed>
	 */
	private array $chris = [];

	/**
	 * Seed the example register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$schedule = static fn (string $id, string $version, string $tier, ?string $entity, float $rate): array => [
			'id' => $id, 'versionId' => $version, 'tier' => $tier, 'entityId' => $entity, 'rate' => $rate, 'unit' => 'hourly',
			'status' => 'active', 'effectiveDate' => '2026-01-01', 'administrationId' => self::ADMIN,
		];
		$this->chris = [
			'id' => 'pa-chris', 'administrationId' => self::ADMIN, 'personId' => 'user-chris', 'projectId' => self::PROJECT,
			'rateCardId' => 'RCT-STD', 'billingRoleId' => null, 'recognisedRate' => 95, 'startDate' => '2026-09-01', 'state' => 'active',
		];
		$this->store = new InMemoryObjectServiceStub(
			[
				'engagement' => [['id' => self::PROJECT, 'administrationId' => self::ADMIN, 'name' => 'Renovatie Kade 12', 'customerId' => 'cust-het-anker']],
				'ProjectAssignment' => [$this->chris],
				'RateCardTemplate' => [
					['id' => 'rct-std', 'templateId' => 'RCT-STD', 'administrationId' => self::ADMIN],
					['id' => 'rct-other', 'templateId' => 'RCT-OTHER', 'administrationId' => self::ADMIN],
				],
				'RateCardVersion' => [
					['id' => 'rcv-std', 'templateId' => 'RCT-STD', 'status' => 'active', 'effectiveDate' => '2026-01-01', 'administrationId' => self::ADMIN],
				],
				'RateSchedule' => [
					$schedule('rs-blended', 'rcv-std', 'blended', null, 85),
					$schedule('rs-senior', 'rcv-std', 'role', 'role-senior', 120),
					$schedule('rs-het-anker', 'rcv-std', 'client', 'cust-het-anker', 95),
				],
			]
		);
	}//end setUp()

	/**
	 * The listener over the store, on 14 November 2026.
	 *
	 * @return AssignmentRateListener
	 */
	private function listener(): AssignmentRateListener {
		$register = $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00Z'));
		$resolver = $this->createStub(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturnCallback(static fn (?object $entity): string => (string)$entity?->getSchema());

		return new AssignmentRateListener(
			new AssignmentRateRefresher(new BillableRateService($this->store, $register, $clock), $this->store, $register, $clock),
			$resolver,
			$this->createStub(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * An entity of a schema, as OpenRegister dispatches it.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)$data['id']);
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * The assignment's writes to the store.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function assignmentWrites(): array {
		return array_values(
			array_map(
				static fn (array $save): array => $save['object'],
				array_filter($this->store->saved, static fn (array $save): bool => $save['schema'] === 'ProjectAssignment')
			)
		);
	}//end assignmentWrites()

	/**
	 * The assignment as the store now holds it.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return (array)$this->store->find(id: 'pa-chris', schema: 'ProjectAssignment')?->getObject();
	}//end stored()

	/**
	 * Setting billing role senior on Chris's assignment makes it show EUR 120.
	 *
	 * @return void
	 */
	public function testSettingARoleUpdatesTheShownRate(): void {
		$senior = array_merge($this->chris, ['billingRoleId' => 'role-senior']);
		$this->store->saveObject(object: $senior, schema: 'ProjectAssignment');
		$this->store->saved = [];

		$this->listener()->handle(
			new ObjectUpdatedEvent($this->entity('ProjectAssignment', $senior), $this->entity('ProjectAssignment', $this->chris))
		);

		self::assertSame(120.0, $this->stored()['recognisedRate']);
		self::assertCount(1, $this->assignmentWrites());
		$records = array_filter($this->store->saved, static fn (array $save): bool => $save['schema'] === 'RateRecord');
		self::assertSame([], $records, 'a display snapshot writes no audit record');
	}//end testSettingARoleUpdatesTheShownRate()

	/**
	 * The listener's own write of the rate changes no field the rate depends
	 * on, so it is not refreshed again.
	 *
	 * @return void
	 */
	public function testTheRateWriteItselfIsNotRefreshedAgain(): void {
		$rewritten = array_merge($this->chris, ['recognisedRate' => 50]);

		$this->listener()->handle(
			new ObjectUpdatedEvent($this->entity('ProjectAssignment', $rewritten), $this->entity('ProjectAssignment', $this->chris))
		);

		self::assertSame([], $this->assignmentWrites());
	}//end testTheRateWriteItselfIsNotRefreshedAgain()

	/**
	 * A new assignment without a role shows the client rate of the project's customer.
	 *
	 * @return void
	 */
	public function testANewAssignmentShowsTheClientRateOfTheProjectsCustomer(): void {
		$created = array_merge($this->chris, ['recognisedRate' => 0]);
		$this->store->saveObject(object: $created, schema: 'ProjectAssignment');
		$this->store->saved = [];

		$this->listener()->handle(new ObjectCreatedEvent($this->entity('ProjectAssignment', $created)));

		self::assertSame(95.0, $this->stored()['recognisedRate']);
	}//end testANewAssignmentShowsTheClientRateOfTheProjectsCustomer()

	/**
	 * An unchanged rate is not written again.
	 *
	 * @return void
	 */
	public function testAnUnchangedRateIsNotWritten(): void {
		$this->listener()->handle(new ObjectCreatedEvent($this->entity('ProjectAssignment', $this->chris)));

		self::assertSame([], $this->assignmentWrites());
	}//end testAnUnchangedRateIsNotWritten()

	/**
	 * An assignment that resolves to no rate keeps the rate it shows.
	 *
	 * @return void
	 */
	public function testAnAssignmentWithoutARateKeepsItsShownRate(): void {
		$elsewhere = array_merge($this->chris, ['rateCardId' => 'RCT-MISSING']);

		$this->listener()->handle(
			new ObjectUpdatedEvent($this->entity('ProjectAssignment', $elsewhere), $this->entity('ProjectAssignment', $this->chris))
		);

		self::assertSame([], $this->assignmentWrites());
	}//end testAnAssignmentWithoutARateKeepsItsShownRate()

	/**
	 * Activating a new version of the card refreshes the assignments priced by it.
	 *
	 * @return void
	 */
	public function testActivatingAVersionRefreshesItsAssignments(): void {
		$draft = ['id' => 'rcv-2027', 'templateId' => 'rct-std', 'status' => 'draft', 'effectiveDate' => '2026-11-01', 'administrationId' => self::ADMIN];
		$active = array_merge($draft, ['status' => 'active']);
		$this->store->saveObject(object: $active, schema: 'RateCardVersion');
		$this->store->saveObject(
			object: [
				'id' => 'rs-het-anker-2027', 'versionId' => 'rcv-2027', 'tier' => 'client', 'entityId' => 'cust-het-anker', 'rate' => 99, 'unit' => 'hourly',
				'status' => 'active', 'effectiveDate' => '2026-11-01', 'administrationId' => self::ADMIN,
			],
			schema: 'RateSchedule'
		);
		$this->store->saved = [];

		$this->listener()->handle(
			new ObjectUpdatedEvent($this->entity('RateCardVersion', $active), $this->entity('RateCardVersion', $draft))
		);

		self::assertSame(99.0, $this->stored()['recognisedRate']);
	}//end testActivatingAVersionRefreshesItsAssignments()

	/**
	 * A version of another card, or one that was already active, changes nothing.
	 *
	 * @return void
	 */
	public function testAnotherCardsVersionChangesNothing(): void {
		$other = ['id' => 'rcv-other', 'templateId' => 'RCT-OTHER', 'status' => 'active', 'effectiveDate' => '2026-01-01'];
		$other['administrationId'] = self::ADMIN;
		$listener = $this->listener();

		$listener->handle(new ObjectCreatedEvent($this->entity('RateCardVersion', $other)));
		$stillActive = ['id' => 'rcv-std', 'templateId' => 'RCT-STD', 'status' => 'active', 'administrationId' => self::ADMIN];
		$listener->handle(new ObjectUpdatedEvent($this->entity('RateCardVersion', $stillActive), $this->entity('RateCardVersion', $stillActive)));

		self::assertSame([], $this->assignmentWrites());
	}//end testAnotherCardsVersionChangesNothing()
}//end class
