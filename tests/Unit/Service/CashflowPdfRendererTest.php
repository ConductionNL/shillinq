<?php

/**
 * Unit tests for CashflowPdfRenderer.
 *
 * Covers REQ-CF-016 (PDF export sections + scenario overlay).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-29
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CashflowPdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Renderer payload tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CashflowPdfRendererTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var CashflowPdfRenderer
     */
    private CashflowPdfRenderer $renderer;

    /**
     * Set up fresh renderer per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->renderer = new CashflowPdfRenderer();

    }//end setUp()

    /**
     * The renderer returns a filename, mime type, and non-empty payload.
     *
     * @return void
     */
    public function testRenderReturnsExpectedEnvelope(): void
    {
        $horizon = [
            'horizonId'        => 'horizon-test-001',
            'horizonStart'     => '2026-05-25',
            'horizonEnd'       => '2026-08-23',
            'administrationId' => 'adm-001',
            'modelVersion'     => 'v4.1-klantspecifiek-betaalgedrag',
            'rolledOp'         => '2026-05-25T02:00:00Z',
        ];
        $weeks   = [
            [
                'weeknummer'     => 22,
                'inflows_total'  => 12500.0,
                'outflows_total' => 7200.0,
                'netMovement'    => 5300.0,
                'closingBalance' => 20120.0,
                'bufferStatus'   => 'ABOVE_BUFFER',
            ],
        ];

        $result = $this->renderer->render($horizon, $weeks);

        self::assertArrayHasKey('filename', $result);
        self::assertArrayHasKey('mimeType', $result);
        self::assertArrayHasKey('payload', $result);
        self::assertStringContainsString('horizon-test-001', $result['filename']);
        self::assertStringContainsString('text/plain', $result['mimeType']);
        self::assertStringContainsString('13-WEEK CASHFLOW FORECAST', $result['payload']);
        self::assertStringContainsString('WEEK-BY-WEEK SUMMARY', $result['payload']);
        self::assertStringContainsString('METHODOLOGY', $result['payload']);

    }//end testRenderReturnsExpectedEnvelope()

    /**
     * Weeks render in the summary table with their saldo + buffer status.
     *
     * @return void
     */
    public function testRenderIncludesPerWeekRows(): void
    {
        $horizon = ['horizonId' => 'h1', 'administrationId' => 'a1'];
        $weeks   = [
            ['weeknummer' => 22, 'inflows_total' => 100.0, 'outflows_total' => 50.0, 'netMovement' => 50.0, 'closingBalance' => 1050.0, 'bufferStatus' => 'ABOVE_BUFFER'],
            ['weeknummer' => 23, 'inflows_total' => 0.0, 'outflows_total' => 200.0, 'netMovement' => -200.0, 'closingBalance' => 850.0, 'bufferStatus' => 'PRE_ALERT'],
        ];

        $result = $this->renderer->render($horizon, $weeks);

        self::assertStringContainsString('22', $result['payload']);
        self::assertStringContainsString('23', $result['payload']);
        self::assertStringContainsString('PRE_ALERT', $result['payload']);

    }//end testRenderIncludesPerWeekRows()

    /**
     * Optional scenario section appears when a scenario is supplied and
     * carries naam + resultaat onderschrijdingBuffer flag.
     *
     * @return void
     */
    public function testRenderIncludesScenarioWhenSupplied(): void
    {
        $horizon  = ['horizonId' => 'h1'];
        $scenario = [
            'name'        => 'Acme pays late',
            'description' => 'Acme delays invoice by 4 weeks',
            'result'      => [
                'minBufferWeek'         => '2026-w26',
                'minBufferAmount'       => 100.0,
                'onderschrijdingBuffer' => true,
            ],
        ];

        $result = $this->renderer->render($horizon, [], $scenario);

        self::assertStringContainsString('SCENARIO: Acme pays late', $result['payload']);
        self::assertStringContainsString('YES', $result['payload']);
        self::assertStringContainsString('2026-w26', $result['payload']);

    }//end testRenderIncludesScenarioWhenSupplied()

    /**
     * Top-customer table renders when supplied, including offset + confidence.
     *
     * @return void
     */
    public function testRenderIncludesTopCustomersWhenSupplied(): void
    {
        $horizon      = ['horizonId' => 'h1'];
        $topCustomers = [
            ['customerId' => 'klant-municipality-amsterdam', 'gemiddeldeAfwijking' => '+48 days', 'betrouwbaarheidScore' => 0.95],
            ['customerId' => 'klant-acme-bv', 'gemiddeldeAfwijking' => '+8 days', 'betrouwbaarheidScore' => 0.92],
        ];

        $result = $this->renderer->render($horizon, [], null, $topCustomers);

        self::assertStringContainsString('TOP CUSTOMERS (BETALINGSGEDRAG)', $result['payload']);
        self::assertStringContainsString('klant-municipality-amsterdam', $result['payload']);
        self::assertStringContainsString('+48 days', $result['payload']);

    }//end testRenderIncludesTopCustomersWhenSupplied()

    /**
     * Empty input still renders a valid envelope (no fatal errors).
     *
     * @return void
     */
    public function testRenderTolerantOfEmptyInput(): void
    {
        $result = $this->renderer->render([], []);
        self::assertNotEmpty($result['payload']);
        self::assertStringContainsString('13-WEEK CASHFLOW FORECAST', $result['payload']);

    }//end testRenderTolerantOfEmptyInput()
}//end class
