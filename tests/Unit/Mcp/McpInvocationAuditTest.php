<?php

/**
 * Every MCP invocation reaches the Nextcloud audit log, and OpenRegister's
 * hash-chained audit trail where its recorder is published.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Mcp;

use OCA\Shillinq\Mcp\McpInvocationAudit;
use OCA\Shillinq\Mcp\McpResult;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The two audit writes of one invocation.
 */
#[CoversClass(McpInvocationAudit::class)]
#[UsesClass(McpResult::class)]
class McpInvocationAuditTest extends TestCase {

	/**
	 * What the recorder double was handed.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	public array $recorded = [];

	/**
	 * With the recorder published, the call is written to both, the arguments only to OpenRegister's.
	 *
	 * @return void
	 */
	public function testTheCallReachesBothAuditTrails(): void {
		$events = [];
		$audit = new McpInvocationAudit($this->dispatcher(events: $events), $this->container(recorder: $this->recorder()));

		$audit->record('shillinq.dunning.pause', 'alice', 'adm-1', McpResult::error(code: 'not_found', message: 'x'), ['details' => 'disputed']);

		$this->assertSame([['tool' => 'shillinq.dunning.pause', 'user' => 'alice', 'administrationId' => 'adm-1', 'outcome' => 'not_found']], $events);
		$this->assertSame(
			[['shillinq.dunning.pause', ['details' => 'disputed'], ['isError' => true, 'error' => 'not_found', 'message' => 'x']]],
			$this->recorded
		);
	}//end testTheCallReachesBothAuditTrails()

	/**
	 * Without OpenRegister's recorder only the Nextcloud audit log is written.
	 *
	 * @return void
	 */
	public function testWithoutTheRecorderOnlyTheAuditLogIsWritten(): void {
		$events = [];
		$container = $this->container(recorder: null);
		$container->expects($this->never())->method('get');

		(new McpInvocationAudit($this->dispatcher(events: $events), $container))->record('shillinq.invoice.issue', 'alice', 'adm-1', ['invoice' => []]);

		$this->assertSame('ok', $events[0]['outcome']);
		$this->assertSame([], $this->recorded);
	}//end testWithoutTheRecorderOnlyTheAuditLogIsWritten()

	/**
	 * A failing recorder is logged; the audit log entry still stands and nothing is thrown.
	 *
	 * @return void
	 */
	public function testAFailingRecorderIsLogged(): void {
		$events = [];
		$recorder = new class {
			/**
			 * Always fails.
			 *
			 * @return void
			 */
			public function record(): void {
				throw new RuntimeException('db down');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Shillinq MCP: OpenRegister tool-invocation recorder failed',
			['toolId' => 'shillinq.vat_return.submit', 'exception' => 'db down']
		);

		(new McpInvocationAudit($this->dispatcher(events: $events), $this->container(recorder: $recorder), $logger))
			->record('shillinq.vat_return.submit', 'alice', 'adm-1', ['vatReturn' => []]);

		$this->assertCount(1, $events);
	}//end testAFailingRecorderIsLogged()

	/**
	 * A dispatcher that collects the audit-log parameters.
	 *
	 * @param list<array<string, mixed>> $events Collects each event's parameters.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(array &$events): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use (&$events): void {
				$this->assertInstanceOf(CriticalActionPerformedEvent::class, $event);
				$events[] = $event->getParameters();
			}
		);

		return $dispatcher;
	}//end dispatcher()

	/**
	 * A container that publishes the recorder, or not.
	 *
	 * @param object|null $recorder The recorder, or null when OpenRegister is absent.
	 *
	 * @return ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function container(?object $recorder): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->with(McpInvocationAudit::RECORDER)->willReturn($recorder !== null);
		if ($recorder !== null) {
			$container->method('get')->with(McpInvocationAudit::RECORDER)->willReturn($recorder);
		}

		return $container;
	}//end container()

	/**
	 * A recorder double that keeps what it is handed.
	 *
	 * @return object
	 */
	private function recorder(): object {
		return new class($this) {
			/**
			 * Constructor.
			 *
			 * @param McpInvocationAuditTest $test The test that reads what was recorded.
			 */
			public function __construct(private readonly McpInvocationAuditTest $test) {
			}

			/**
			 * Keep one invocation.
			 *
			 * @param string               $toolId    The tool id.
			 * @param array<string, mixed> $arguments The arguments.
			 * @param array<string, mixed> $result    The answer.
			 *
			 * @return void
			 */
			public function record(string $toolId, array $arguments, array $result): void {
				$this->test->recorded[] = [$toolId, $arguments, $result];
			}
		};
	}//end recorder()
}//end class
