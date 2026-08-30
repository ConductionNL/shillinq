<?php

/**
 * Unit tests for TenderNedStatusSync.
 *
 * Verifies the REQ-006 outbound status-sync contract (Task 6.1):
 *
 *  - No verplichtingId on the oplevering -> sync skipped (false).
 *  - No linked aanbesteding -> sync skipped (false).
 *  - Tenant KvK does not match aanbestedende dienst -> sync DENIED (false).
 *  - Tenant KvK matches aanbestedende dienst + openconnector gateway
 *    absent -> structured-log fallback path, returns true (attempt was made).
 *  - Tenant KvK matches + gateway present -> gateway.send() invoked with the
 *    completion payload (aanbestedingId, status: afgerond, eindopleveringId).
 *  - Gateway raises -> swallowed (fail-soft, returns true since the attempt
 *    was made).
 *  - Throwable from any resolution path -> swallowed (fail-soft, false).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Integration;

use OCA\Shillinq\Service\TenderNedStatusSync;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for TenderNedStatusSync (REQ-006).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TenderNedStatusSyncTest extends TestCase {

	/**
	 * Build an IAppConfig returning a fixed tenant KvK.
	 *
	 * @param string $tenantKvk Tenant KvK value.
	 *
	 * @return IAppConfig
	 */
	private function appConfigWithTenantKvk(string $tenantKvk): IAppConfig {
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($tenantKvk): string {
				if ($key === 'tenant_kvk') {
					return $tenantKvk;
				}
				return $default;
			}
		);
		return $cfg;
	}//end appConfigWithTenantKvk()

	/**
	 * Build a container that resolves the OR ObjectService to a fluent stub
	 * returning a fixed aanbesteding, and resolves the openconnector gateway
	 * either to a recorder or to a not-bound exception.
	 *
	 * @param array<string,mixed>|null $tender Aanbesteding row or null.
	 * @param object|null $gateway Spy gateway or null.
	 *
	 * @return ContainerInterface
	 */
	private function container(?array $tender, ?object $gateway): ContainerInterface {
		$objectService = new class($tender) {

			/**
			 * @var array<string,mixed>|null
			 */
			private ?array $tender;

			/**
			 * @param array<string,mixed>|null $tender Aanbesteding.
			 */
			public function __construct(?array $tender) {
				$this->tender = $tender;
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			public function findAll(array $opts = []): array {
				if ($this->tender === null) {
					return [];
				}

				return [$this->tender];
			}
		};

		return new class($objectService, $gateway) implements ContainerInterface {
			public function __construct(
				private readonly object $objectService,
				private readonly ?object $gateway,
			) {
			}

			public function get(string $id): mixed {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				if ($id === 'OCA\\OpenConnector\\Service\\OutboundIntegrationGateway') {
					if ($this->gateway === null) {
						throw new class('not bound') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
						};
					}
					return $this->gateway;
				}

				throw new class('not bound') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
				};
			}

			public function has(string $id): bool {
				return $id === 'OCA\\OpenRegister\\Service\\ObjectService'
					|| ($id === 'OCA\\OpenConnector\\Service\\OutboundIntegrationGateway' && $this->gateway !== null);
			}
		};

	}//end container()

	/**
	 * Build a spying openconnector gateway.
	 *
	 * @return object
	 */
	private function spyGateway(): object {
		return new class {
			/**
			 * @var array<int, array{source: string, payload: array<string,mixed>}>
			 */
			public array $sends = [];

			public function send(string $source, array $payload): void {
				$this->sends[] = ['source' => $source, 'payload' => $payload];

			}//end send()
		};

	}//end spyGateway()

	/**
	 * Empty `verplichtingId` -> skip (false).
	 *
	 * @return void
	 */
	public function testNoVerplichtingIdSkips(): void {
		$sync = new TenderNedStatusSync(
			$this->container(null, null),
			$this->appConfigWithTenantKvk('30280353'),
			new NullLogger()
		);

		$result = $sync->syncCompletion(['milestoneId' => 'M-EIND']);

		$this->assertFalse($result);

	}//end testNoVerplichtingIdSkips()

	/**
	 * No linked aanbesteding -> skip (false).
	 *
	 * @return void
	 */
	public function testNoLinkedAanbestedingSkips(): void {
		$sync = new TenderNedStatusSync(
			$this->container(null, null),
			$this->appConfigWithTenantKvk('30280353'),
			new NullLogger()
		);

		$result = $sync->syncCompletion(['commitmentId' => 'TN-X', 'milestoneId' => 'M-EIND']);

		$this->assertFalse($result);

	}//end testNoLinkedAanbestedingSkips()

	/**
	 * Tenant KvK does not match the aanbestedende dienst -> sync DENIED.
	 *
	 * @return void
	 */
	public function testNonAanbestedendeDienstIsDenied(): void {
		$sync = new TenderNedStatusSync(
			$this->container(
				[
					'tenderId' => 'TN-2026-0001',
					'contractingService' => '99999999 Gemeente Anders',
					'commitmentId' => 'TN-X',
				],
				$this->spyGateway()
			),
			$this->appConfigWithTenantKvk('30280353'),
			new NullLogger()
		);

		$result = $sync->syncCompletion(
			[
				'commitmentId' => 'TN-X',
				'milestoneId' => 'M-EIND',
				'deliveryDate' => '2026-12-15',
				'supportingDocuments' => [['documentId' => 'doc-1']],
			]
		);

		$this->assertFalse($result);

	}//end testNonAanbestedendeDienstIsDenied()

	/**
	 * Tenant KvK matches + openconnector gateway absent -> log-only success.
	 *
	 * @return void
	 */
	public function testGatewayAbsentLogsAndReturnsTrue(): void {
		$sync = new TenderNedStatusSync(
			$this->container(
				[
					'tenderId' => 'TN-2026-0001',
					'contractingService' => '30280353 Gemeente Utrecht',
					'commitmentId' => 'TN-X',
				],
				null
			),
			$this->appConfigWithTenantKvk('30280353'),
			new NullLogger()
		);

		$result = $sync->syncCompletion(
			[
				'commitmentId' => 'TN-X',
				'milestoneId' => 'M-EIND',
				'deliveryDate' => '2026-12-15',
				'supportingDocuments' => [['documentId' => 'doc-1']],
			]
		);

		$this->assertTrue($result);

	}//end testGatewayAbsentLogsAndReturnsTrue()

	/**
	 * Tenant KvK matches + gateway present -> gateway.send() invoked with
	 * the completion payload.
	 *
	 * @return void
	 */
	public function testGatewayPresentSendsCompletionPayload(): void {
		$gateway = $this->spyGateway();
		$sync = new TenderNedStatusSync(
			$this->container(
				[
					'tenderId' => 'TN-2026-0001',
					'contractingService' => '30280353 Gemeente Utrecht',
					'commitmentId' => 'TN-X',
				],
				$gateway
			),
			$this->appConfigWithTenantKvk('30280353'),
			new NullLogger()
		);

		$result = $sync->syncCompletion(
			[
				'commitmentId' => 'TN-X',
				'milestoneId' => 'M-EIND',
				'deliveryDate' => '2026-12-15',
				'supportingDocuments' => [['documentId' => 'doc-1']],
				'administrationId' => 'adm-utrecht',
			]
		);

		$this->assertTrue($result);
		$this->assertCount(1, $gateway->sends);
		$this->assertSame('tenderned.completion', $gateway->sends[0]['source']);
		$this->assertSame('TN-2026-0001', $gateway->sends[0]['payload']['tenderId']);
		$this->assertSame(TenderNedStatusSync::TENDERNED_STATUS_AFGEROND, $gateway->sends[0]['payload']['status']);
		$this->assertSame('M-EIND', $gateway->sends[0]['payload']['eindopleveringId']);
		$this->assertSame(1, $gateway->sends[0]['payload']['bewijsstukCount']);

	}//end testGatewayPresentSendsCompletionPayload()

	/**
	 * Gateway raises -> swallowed (fail-soft).
	 *
	 * @return void
	 */
	public function testGatewayExceptionIsSwallowed(): void {
		$gateway = new class {

			public function send(string $source, array $payload): void {
				throw new \RuntimeException('upstream down');
			}
		};

		$sync = new TenderNedStatusSync(
			$this->container(
				[
					'tenderId' => 'TN-2026-0001',
					'contractingService' => '30280353 Gemeente Utrecht',
					'commitmentId' => 'TN-X',
				],
				$gateway
			),
			$this->appConfigWithTenantKvk('30280353'),
			new NullLogger()
		);

		$result = $sync->syncCompletion(
			[
				'commitmentId' => 'TN-X',
				'milestoneId' => 'M-EIND',
				'deliveryDate' => '2026-12-15',
				'supportingDocuments' => [],
			]
		);

		// The attempt was made (we reached `send()`), so the contract
		// returns true; the failure is logged-only.
		$this->assertTrue($result);

	}//end testGatewayExceptionIsSwallowed()
}//end class
