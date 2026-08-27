<?php

/**
 * Unit tests for OrderFulfilmentGuard.
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\OrderFulfilmentGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for OrderFulfilmentGuard::canVoltooien per REQ-004 (bewijsstuk gate).
 *
 * Covers:
 * - No bewijsstukken → completion denied.
 * - Empty array → completion denied.
 * - A bewijsstuk without documentId → completion denied.
 * - A valid bewijsstuk (non-empty documentId) → completion permitted.
 * - A scalar bewijsstukken value → denied (fail-safe).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OrderFulfilmentGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var OrderFulfilmentGuard
	 */
	private OrderFulfilmentGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new OrderFulfilmentGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * A delivery with no bewijsstukken key cannot be completed (REQ-004).
	 *
	 * @return void
	 */
	public function testNoBewijsstukDeniesCompletion(): void {
		$assignment = [
			'commitmentId' => 'vpl-1',
			'milestoneId' => 'MS-001',
			'status' => 'in-progress',
		];
		$this->assertFalse($this->guard->canVoltooien($assignment));

	}//end testNoBewijsstukDeniesCompletion()

	/**
	 * An empty bewijsstukken array cannot be completed (REQ-004).
	 *
	 * @return void
	 */
	public function testEmptyBewijsstukkenDeniesCompletion(): void {
		$assignment = ['supportingDocuments' => []];
		$this->assertFalse($this->guard->canVoltooien($assignment));

	}//end testEmptyBewijsstukkenDeniesCompletion()

	/**
	 * A bewijsstuk without a documentId does not satisfy the gate (REQ-004).
	 *
	 * @return void
	 */
	public function testBewijsstukWithoutDocumentIdDeniesCompletion(): void {
		$assignment = ['supportingDocuments' => [['app' => 'docudesk', 'documentId' => '']]];
		$this->assertFalse($this->guard->canVoltooien($assignment));

	}//end testBewijsstukWithoutDocumentIdDeniesCompletion()

	/**
	 * A valid bewijsstuk permits completion (REQ-004).
	 *
	 * @return void
	 */
	public function testValidBewijsstukPermitsCompletion(): void {
		$assignment = [
			'supportingDocuments' => [
				['app' => 'docudesk', 'documentId' => 'doc-123', 'description' => 'Acceptatie-protocol'],
			],
		];
		$this->assertTrue($this->guard->canVoltooien($assignment));

	}//end testValidBewijsstukPermitsCompletion()

	/**
	 * A scalar (non-array) bewijsstukken value is rejected (fail-safe).
	 *
	 * @return void
	 */
	public function testScalarBewijsstukkenDeniesCompletion(): void {
		$assignment = ['supportingDocuments' => 'doc-123'];
		$this->assertFalse($this->guard->canVoltooien($assignment));

	}//end testScalarBewijsstukkenDeniesCompletion()
}//end class
