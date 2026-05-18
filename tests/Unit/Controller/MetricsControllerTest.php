<?php

/**
 * Unit tests for MetricsController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Controller\MetricsController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MetricsController.
 */
class MetricsControllerTest extends TestCase
{

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * The controller under test.
     *
     * @var MetricsController
     */
    private MetricsController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request    = $this->createMock(IRequest::class);
        $this->controller = new MetricsController(request: $this->request);
    }//end setUp()

    /**
     * Test that index() returns a JSONResponse with app and metrics keys.
     *
     * @return void
     */
    public function testIndexReturnsMetricsResponse(): void
    {
        $result = $this->controller->index();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertArrayHasKey('app', $result->getData());
        self::assertArrayHasKey('metrics', $result->getData());
        self::assertSame(Application::APP_ID, $result->getData()['app']);
    }//end testIndexReturnsMetricsResponse()

}//end class
