<?php

/**
 * Unit tests for RequisitionConversionGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/purchase-requisition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\RequisitionConversionGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fail-closed precondition guard for the Requisition `convertToPO`
 * transition (REQ-REQ-005): only a 'approved' requisition may convert.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RequisitionConversionGuardTest extends TestCase {

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Verifies canConvert() returns true for an approved requisition, passed
	 * directly as the $object parameter (the lifecycle-engine call shape).
	 *
	 * @return void
	 */
	public function testCanConvertTrueWhenApproved(): void {
		$container = $this->createMock(ContainerInterface::class);
		$guard = new RequisitionConversionGuard(container: $container, appConfig: $this->appConfig, logger: $this->logger);

		$result = $guard->canConvert(requisitionId: 'req-1', object: ['statusCode' => 'approved']);

		self::assertTrue($result);

	}//end testCanConvertTrueWhenApproved()

	/**
	 * Verifies canConvert() returns false for every non-approved status —
	 * draft, submitted, rejected, converted.
	 *
	 * @return void
	 */
	public function testCanConvertFalseWhenNotApproved(): void {
		$container = $this->createMock(ContainerInterface::class);
		$guard = new RequisitionConversionGuard(container: $container, appConfig: $this->appConfig, logger: $this->logger);

		foreach (['draft', 'submitted', 'rejected', 'converted', ''] as $status) {
			self::assertFalse(
				$guard->canConvert(requisitionId: 'req-1', object: ['statusCode' => $status]),
				"canConvert() must deny status '$status'"
			);
		}

	}//end testCanConvertFalseWhenNotApproved()

	/**
	 * Verifies canConvert() denies (fail-closed) when the requisition cannot
	 * be found — neither passed directly nor resolvable via ObjectService.
	 *
	 * @return void
	 */
	public function testCanConvertFalseWhenRequisitionMissing(): void {
		$stub = new class {
			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Always empty — simulates "not found".
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				return [];
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$guard = new RequisitionConversionGuard(container: $container, appConfig: $this->appConfig, logger: $this->logger);

		self::assertFalse($guard->canConvert(requisitionId: 'missing-req', object: null));

	}//end testCanConvertFalseWhenRequisitionMissing()

	/**
	 * Verifies canConvert() denies (fail-closed) when the ObjectService
	 * lookup throws — an infrastructure failure must never be treated as
	 * "allow".
	 *
	 * @return void
	 */
	public function testCanConvertFalseOnLookupException(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('OpenRegister unavailable'));

		$guard = new RequisitionConversionGuard(container: $container, appConfig: $this->appConfig, logger: $this->logger);

		self::assertFalse($guard->canConvert(requisitionId: 'req-1', object: null));

	}//end testCanConvertFalseOnLookupException()
}//end class
