<?php

/**
 * Unit tests for OpdrachtUitvoeringGuard.
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

use OCA\Shillinq\Lifecycle\OpdrachtUitvoeringGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for OpdrachtUitvoeringGuard::canVoltooien per REQ-004 (bewijsstuk gate).
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
class OpdrachtUitvoeringGuardTest extends TestCase
{

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The guard under test.
     *
     * @var OpdrachtUitvoeringGuard
     */
    private OpdrachtUitvoeringGuard $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->guard  = new OpdrachtUitvoeringGuard(logger: $this->logger);

    }//end setUp()

    /**
     * A delivery with no bewijsstukken key cannot be completed (REQ-004).
     *
     * @return void
     */
    public function testNoBewijsstukDeniesCompletion(): void
    {
        $opdracht = [
            'verplichtingId' => 'vpl-1',
            'mijlpaalId'     => 'MS-001',
            'status'         => 'in-progress',
        ];
        $this->assertFalse($this->guard->canVoltooien($opdracht));

    }//end testNoBewijsstukDeniesCompletion()

    /**
     * An empty bewijsstukken array cannot be completed (REQ-004).
     *
     * @return void
     */
    public function testEmptyBewijsstukkenDeniesCompletion(): void
    {
        $opdracht = ['bewijsstukken' => []];
        $this->assertFalse($this->guard->canVoltooien($opdracht));

    }//end testEmptyBewijsstukkenDeniesCompletion()

    /**
     * A bewijsstuk without a documentId does not satisfy the gate (REQ-004).
     *
     * @return void
     */
    public function testBewijsstukWithoutDocumentIdDeniesCompletion(): void
    {
        $opdracht = ['bewijsstukken' => [['app' => 'docudesk', 'documentId' => '']]];
        $this->assertFalse($this->guard->canVoltooien($opdracht));

    }//end testBewijsstukWithoutDocumentIdDeniesCompletion()

    /**
     * A valid bewijsstuk permits completion (REQ-004).
     *
     * @return void
     */
    public function testValidBewijsstukPermitsCompletion(): void
    {
        $opdracht = [
            'bewijsstukken' => [
                ['app' => 'docudesk', 'documentId' => 'doc-123', 'omschrijving' => 'Acceptatie-protocol'],
            ],
        ];
        $this->assertTrue($this->guard->canVoltooien($opdracht));

    }//end testValidBewijsstukPermitsCompletion()

    /**
     * A scalar (non-array) bewijsstukken value is rejected (fail-safe).
     *
     * @return void
     */
    public function testScalarBewijsstukkenDeniesCompletion(): void
    {
        $opdracht = ['bewijsstukken' => 'doc-123'];
        $this->assertFalse($this->guard->canVoltooien($opdracht));

    }//end testScalarBewijsstukkenDeniesCompletion()
}//end class
