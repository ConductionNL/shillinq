<?php

/**
 * Unit tests for AssignmentHoursListener, AssignmentHours and BackfillAssignmentHours.
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
 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\AssignmentHoursListener;
use OCA\Shillinq\Repair\BackfillAssignmentHours;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Project\AssignmentHours;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Booking hours keeps the assignment's hours budget current and warns the project owner once per threshold.
 */
class AssignmentHoursListenerTest extends TestCase {

	private const PROJECT = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

	private const ASSIGNMENT = 'pa-bakker';

	private const OTHER_ASSIGNMENT = 'pa-smit';

	/**
	 * Every write the store received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Seed: the design's Adviesbureau Van Dijk project, a.bakker at 90 of 120 hours.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$hours = [];
		for ($i = 1; $i <= 9; $i++) {
			$hours[] = $this->hour(id: 'u' . $i, hours: 10, assignment: self::ASSIGNMENT);
		}

		$hours[] = $this->hour(id: 'u-smit', hours: 20, assignment: self::OTHER_ASSIGNMENT);

		$this->store = new InMemoryObjectServiceStub(
			[
				'engagement'        => [
					[
						'id'                  => self::PROJECT,
						'administrationId'    => 'adm-vandijk',
						'projectNumber'       => 'P-2026-031',
						'name'                => 'Herinrichting Wmo-loket',
						'customerId'          => 'cust-gemeente-voorbeeld',
						'state'               => 'active',
						'totalContractValue'  => 18000,
						'totalEstimatedCosts' => 12000,
						'recognitionMethod'   => 'percentage-of-completion-cost-to-cost',
						'recognitionStage'    => 'execution',
						'currency'            => 'EUR',
						'responsibleUser'     => 'j.devries',
					],
				],
				'ProjectAssignment' => [
					$this->assignment(id: self::ASSIGNMENT, person: 'a.bakker', estimate: 120),
					array_merge($this->assignment(id: self::OTHER_ASSIGNMENT, person: 'r.smit', estimate: 40), ['loggedHours' => 20]),
				],
				'UrenRegistratie'   => $hours,
			],
			$this->saved,
			true
		);

	}//end setUp()

	/**
	 * An assignment row.
	 *
	 * @param string $id       The id.
	 * @param string $person   The consultant.
	 * @param float  $estimate The estimated hours.
	 *
	 * @return array<string, mixed>
	 */
	private function assignment(string $id, string $person, float $estimate): array {
		return [
			'id'             => $id,
			'projectId'      => self::PROJECT,
			'personId'       => $person,
			'rateCardId'     => 'rc-senior',
			'recognisedRate' => 115,
			'estimatedHours' => $estimate,
			'startDate'      => '2026-09-01',
			'state'          => 'active',
		];

	}//end assignment()

	/**
	 * An hour row.
	 *
	 * @param string $id         The id.
	 * @param float  $hours      The hours.
	 * @param string $assignment The assignment it is booked on.
	 *
	 * @return array<string, mixed>
	 */
	private function hour(string $id, float $hours, string $assignment): array {
		return [
			'id'                  => $id,
			'administrationId'    => 'adm-vandijk',
			'personId'            => 'a.bakker',
			'date'                => '2026-09-28',
			'hours'               => $hours,
			'description'         => 'Werksessie Wmo-loket',
			'projectId'           => self::PROJECT,
			'projectAssignmentId' => $assignment,
		];

	}//end hour()

	/**
	 * The hours service over the store.
	 *
	 * @return AssignmentHours
	 */
	private function hours(): AssignmentHours {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new AssignmentHours($this->store, $settings);

	}//end hours()

	/**
	 * The listener, with a resolver that reads the entity's schema as its slug.
	 *
	 * @return AssignmentHoursListener
	 */
	private function listener(): AssignmentHoursListener {
		$resolver = $this->createStub(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturnCallback(static fn (?object $entity): string => (string)$entity?->getSchema());

		return new AssignmentHoursListener($this->hours(), $resolver, $this->createStub(LoggerInterface::class));

	}//end listener()

	/**
	 * An entity as OpenRegister hands it to a listener.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The payload with its id.
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
	 * Book an hour: store it, then fire the created event as OpenRegister does.
	 *
	 * @param string $id    The hour id.
	 * @param float  $hours The hours.
	 *
	 * @return void
	 */
	private function book(string $id, float $hours): void {
		$row = $this->hour(id: $id, hours: $hours, assignment: self::ASSIGNMENT);
		$this->store->saveObject(object: $row, schema: 'UrenRegistratie');
		$this->listener()->handle(new ObjectCreatedEvent($this->entity('UrenRegistratie', $row)));

	}//end book()

	/**
	 * A stored object.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(string $schema, string $id): array {
		return $this->store->find(id: $id, schema: $schema)->getObject();

	}//end stored()

	/**
	 * Whether a declared notification of ProjectAssignment fires between two versions.
	 *
	 * Evaluates the rule as it is declared in the merged register, with the
	 * semantics of OpenRegister's `updated` trigger condition
	 * (AnnotationNotificationDispatcher::fieldChangeConditionMatches: scalars
	 * compared as strings, `from` compared against the old value).
	 *
	 * @param string               $rule The notification name.
	 * @param array<string, mixed> $old  The version before.
	 * @param array<string, mixed> $new  The version after.
	 *
	 * @return bool
	 */
	private function fires(string $rule, array $old, array $new): bool {
		$declared = RegisterSchema::schema(slug: 'ProjectAssignment')['x-openregister-notifications'][$rule];
		$this->assertSame('updated', $declared['trigger']['type']);
		$condition = $declared['trigger']['condition'];
		$field = $condition['field'];
		$str = static fn (mixed $value): string => is_scalar($value) === true ? (string)$value : '';

		return $str($new[$field] ?? null) === $str($condition['value'])
			&& $str($old[$field] ?? null) === $str($condition['from']);

	}//end fires()

	/**
	 * REQ-PHB-001 and REQ-PHB-002: a 6-hour booking takes a.bakker from 90 to 96 of 120 hours and warns j.devries once.
	 *
	 * @return void
	 */
	public function testABookingUpdatesTheAssignmentAndWarnsTheOwnerAt80(): void {
		$before = $this->stored('ProjectAssignment', self::ASSIGNMENT);

		$this->book(id: 'u10', hours: 6);

		$after = $this->stored('ProjectAssignment', self::ASSIGNMENT);
		$this->assertEquals(96, $after['loggedHours']);
		$this->assertEquals(24, $after['remainingHours']);
		$this->assertSame(80.0, $this->hours()->usedPercent(estimate: $after['estimatedHours'], logged: (float)$after['loggedHours']));
		$this->assertTrue($after['hoursWarnedAt80']);
		$this->assertFalse($after['hoursWarnedAt100']);
		$this->assertSame(['j.devries', 'Herinrichting Wmo-loket'], [$after['projectOwner'], $after['projectTitle']]);
		$this->assertTrue($this->fires(rule: 'onHoursAt80', old: $before, new: $after), 'the 80 percent warning fires');
		$this->assertFalse($this->fires(rule: 'onHoursAt100', old: $before, new: $after));
		$this->assertSame([], RegisterSchema::errors(slug: 'ProjectAssignment', object: $after), 'the written assignment validates against the register');

	}//end testABookingUpdatesTheAssignmentAndWarnsTheOwnerAt80()

	/**
	 * REQ-PHB-002: a further booking at 85 percent sends no second 80 percent warning.
	 *
	 * @return void
	 */
	public function testAFurtherBookingSendsNoSecondWarning(): void {
		$this->book(id: 'u10', hours: 6);
		$before = $this->stored('ProjectAssignment', self::ASSIGNMENT);

		$this->book(id: 'u11', hours: 6);

		$after = $this->stored('ProjectAssignment', self::ASSIGNMENT);
		$this->assertEquals(102, $after['loggedHours']);
		$this->assertFalse($this->fires(rule: 'onHoursAt80', old: $before, new: $after));

	}//end testAFurtherBookingSendsNoSecondWarning()

	/**
	 * REQ-PHB-002: at 100 percent the second warning fires.
	 *
	 * @return void
	 */
	public function testTheBudgetUsedUpSendsTheSecondWarning(): void {
		$this->book(id: 'u10', hours: 6);
		$before = $this->stored('ProjectAssignment', self::ASSIGNMENT);

		$this->book(id: 'u11', hours: 24);

		$after = $this->stored('ProjectAssignment', self::ASSIGNMENT);
		$this->assertTrue($this->fires(rule: 'onHoursAt100', old: $before, new: $after));
		$this->assertEquals(0, $after['remainingHours']);

	}//end testTheBudgetUsedUpSendsTheSecondWarning()

	/**
	 * REQ-PHB-001: changing an hour and moving it to another assignment re-sums both.
	 *
	 * @return void
	 */
	public function testAChangedHourReSumsBothAssignments(): void {
		$old = $this->hour(id: 'u1', hours: 10, assignment: self::ASSIGNMENT);
		$new = $this->hour(id: 'u1', hours: 4, assignment: self::OTHER_ASSIGNMENT);
		$this->store->saveObject(object: $new, schema: 'UrenRegistratie');

		$this->listener()->handle(new ObjectUpdatedEvent($this->entity('UrenRegistratie', $new), $this->entity('UrenRegistratie', $old)));

		$this->assertEquals(80, $this->stored('ProjectAssignment', self::ASSIGNMENT)['loggedHours']);
		$this->assertEquals(24, $this->stored('ProjectAssignment', self::OTHER_ASSIGNMENT)['loggedHours']);

	}//end testAChangedHourReSumsBothAssignments()

	/**
	 * REQ-PHB-001: a deleted hour leaves the sum, even while the engine still returns it.
	 *
	 * @return void
	 */
	public function testADeletedHourLeavesTheSum(): void {
		$this->listener()->handle(new ObjectDeletedEvent($this->entity('UrenRegistratie', $this->hour(id: 'u9', hours: 10, assignment: self::ASSIGNMENT))));

		$this->assertEquals(80, $this->stored('ProjectAssignment', self::ASSIGNMENT)['loggedHours']);

	}//end testADeletedHourLeavesTheSum()

	/**
	 * REQ-PHB-002: a changed estimate clears the flags, so the warning is sent again for the new estimate.
	 *
	 * @return void
	 */
	public function testAChangedEstimateArmsTheWarningsAgain(): void {
		$this->book(id: 'u10', hours: 6);
		$warned = $this->stored('ProjectAssignment', self::ASSIGNMENT);
		$raised = array_merge($warned, ['estimatedHours' => 110]);
		$this->store->saveObject(object: $raised, schema: 'ProjectAssignment');
		$this->saved = [];

		$this->listener()->handle(new ObjectUpdatedEvent($this->entity('ProjectAssignment', $raised), $this->entity('ProjectAssignment', $warned)));

		$writes = array_values(array_filter($this->saved, static fn (array $w): bool => $w['schema'] === 'ProjectAssignment'));
		$this->assertCount(2, $writes, 'first the flags are cleared, then set again');
		$this->assertFalse($writes[0]['object']['hoursWarnedAt80']);
		$this->assertTrue($this->fires(rule: 'onHoursAt80', old: $writes[0]['object'], new: $writes[1]['object']));

	}//end testAChangedEstimateArmsTheWarningsAgain()

	/**
	 * The listener's own write to the assignment (estimate unchanged) does not recurse.
	 *
	 * @return void
	 */
	public function testAnAssignmentWriteWithTheSameEstimateChangesNothing(): void {
		$row = $this->stored('ProjectAssignment', self::ASSIGNMENT);

		$this->listener()->handle(new ObjectUpdatedEvent($this->entity('ProjectAssignment', array_merge($row, ['loggedHours' => 90])), $this->entity('ProjectAssignment', $row)));

		$this->assertSame([], $this->saved);

	}//end testAnAssignmentWriteWithTheSameEstimateChangesNothing()

	/**
	 * REQ-PHB-003: the project carries the totals and is over budget once an assignment passes 100 percent.
	 *
	 * @return void
	 */
	public function testTheProjectCarriesTheTotalsAndTheOverBudgetFlag(): void {
		$this->book(id: 'u10', hours: 35);

		$project = $this->stored('engagement', self::PROJECT);
		$this->assertEquals([160, 145, 15, true], [$project['estimatedHoursTotal'], $project['loggedHoursTotal'], $project['remainingHoursTotal'], $project['hoursOverBudget']]);
		$this->assertSame([], RegisterSchema::errors(slug: 'engagement', object: $project), 'the written project validates against the register');

	}//end testTheProjectCarriesTheTotalsAndTheOverBudgetFlag()

	/**
	 * Hours on another schema and an hour without an assignment change nothing.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreIgnored(): void {
		$this->listener()->handle(new ObjectCreatedEvent($this->entity('ARInvoice', ['id' => 'x', 'projectAssignmentId' => self::ASSIGNMENT])));
		$this->listener()->handle(new ObjectCreatedEvent($this->entity('UrenRegistratie', ['id' => 'y', 'hours' => 3])));

		$this->assertSame([], $this->saved);

	}//end testOtherObjectsAreIgnored()

	/**
	 * The repair step sums the hours booked before the listener existed, and a rerun writes nothing.
	 *
	 * @return void
	 */
	public function testTheRepairStepSumsHistoryOnce(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly(2))->method('info')->willReturnCallback(
			function (string $message): void {
				static $calls = 0;
				$calls++;
				$expected = 'Shillinq: logged hours written on 2 of 2 project assignment(s).';
				if ($calls === 2) {
					$expected = 'Shillinq: logged hours written on 0 of 2 project assignment(s).';
				}

				$this->assertSame($expected, $message);
			}
		);
		$step = new BackfillAssignmentHours($this->hours(), $this->createStub(LoggerInterface::class));

		$step->run($output);
		$step->run($output);

		$this->assertEquals(90, $this->stored('ProjectAssignment', self::ASSIGNMENT)['loggedHours']);
		$this->assertEquals(20, $this->stored('ProjectAssignment', self::OTHER_ASSIGNMENT)['loggedHours']);

	}//end testTheRepairStepSumsHistoryOnce()

	/**
	 * The app registers the listener for the three events and the repair step (the wiring, from the caller).
	 *
	 * @return void
	 */
	public function testTheAppWiresTheListenerAndTheRepairStep(): void {
		$root = __DIR__ . '/../../..';
		$reporting = (string)file_get_contents($root . '/lib/AppInfo/ReportingRegistration.php');
		$registration = (string)file_get_contents($root . '/lib/AppInfo/ProjectHoursRegistration.php');
		$info = (string)file_get_contents($root . '/appinfo/info.xml');

		$this->assertStringContainsString('(new ProjectHoursRegistration())->register(context: $context);', $reporting);
		$this->assertStringContainsString('[ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class]', $registration);
		$this->assertStringContainsString('listener: AssignmentHoursListener::class', $registration);
		$this->assertStringContainsString('<step>OCA\Shillinq\Repair\BackfillAssignmentHours</step>', $info);

	}//end testTheAppWiresTheListenerAndTheRepairStep()
}//end class
