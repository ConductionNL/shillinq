<?php

/**
 * Unit tests for IntercompanyLinkListener's guards: only an intercompany
 * journal entry moving to "gekoppeld" is linked, and a
 * failing link is logged rather than thrown.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\IntercompanyLinkListener;
use OCA\Shillinq\Service\IntercompanyLinkService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for IntercompanyLinkListener's guards.
 */
#[CoversClass(IntercompanyLinkListener::class)]
class IntercompanyLinkListenerGuardTest extends TestCase {

	/**
	 * A transition event double.
	 *
	 * @param string $schema The schema the event names.
	 * @param string $to     The target state.
	 * @param array  $object The object payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transition(string $schema, string $to, array $object): ObjectTransitionedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($object);

		return $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => $to, 'getSchema' => $schema]
		);
	}//end transition()

	/**
	 * Both schema spellings, in any case and padding, are linked.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function linkedSchemas(): array {
		return [
			'camel'  => ['IntercompanyJournalEntry'],
			'kebab'  => ['intercompany-journal-entry'],
			'padded' => [' INTERCOMPANYJOURNALENTRY '],
		];
	}//end linkedSchemas()

	/**
	 * A linked intercompany entry is handed to the link service.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return void
	 */
	#[DataProvider('linkedSchemas')]
	public function testALinkedEntryIsReconciled(string $schema): void {
		$entry = ['id' => 'ic-1', 'status' => 'gekoppeld'];
		$service = $this->createMock(IntercompanyLinkService::class);
		$service->expects($this->once())->method('linkAndReconcile')->with($entry)->willReturn([]);

		(new IntercompanyLinkListener(linkService: $service, logger: $this->createMock(LoggerInterface::class)))
			->handle($this->transition($schema, 'gekoppeld', $entry));
	}//end testALinkedEntryIsReconciled()

	/**
	 * Other events, schemas and target states (the state is case-sensitive) are ignored.
	 *
	 * @return void
	 */
	public function testEverythingElseIsIgnored(): void {
		$service = $this->createMock(IntercompanyLinkService::class);
		$service->expects($this->never())->method('linkAndReconcile');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$listener = new IntercompanyLinkListener(linkService: $service, logger: $logger);

		$listener->handle(new Event());
		$listener->handle($this->transition('JournalEntry', 'gekoppeld', ['id' => 'x']));
		$listener->handle($this->transition('IntercompanyJournalEntry', 'concept', ['id' => 'x']));
		$listener->handle($this->transition('IntercompanyJournalEntry', 'Gekoppeld', ['id' => 'x']));
	}//end testEverythingElseIsIgnored()

	/**
	 * A failing link is logged as an error and not rethrown.
	 *
	 * @return void
	 */
	public function testAFailingLinkIsLogged(): void {
		$service = $this->createMock(IntercompanyLinkService::class);
		$service->method('linkAndReconcile')->willThrowException(new RuntimeException('no counterpart'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'IntercompanyLinkListener: failed to mirror/reconcile the intercompany pair',
			['exception' => 'no counterpart']
		);

		(new IntercompanyLinkListener(linkService: $service, logger: $logger))
			->handle($this->transition('IntercompanyJournalEntry', 'gekoppeld', ['id' => 'ic-1']));
	}//end testAFailingLinkIsLogged()
}//end class
