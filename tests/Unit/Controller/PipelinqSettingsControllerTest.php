<?php

/**
 * Unit tests for PipelinqSettingsController.
 *
 * Exercises the index/create/test JSON contract — in particular
 * that the token is never returned to the frontend (only the
 * `hasToken` boolean is) and that an absent token on update is
 * treated as "preserve existing", supporting the
 * configuration-is-persisted-securely scenario.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PipelinqSettingsController;
use OCA\Shillinq\Service\Pipelinq\PipelinqConfig;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PipelinqSettingsController.
 */
final class PipelinqSettingsControllerTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var PipelinqConfig&MockObject
	 */
	private PipelinqConfig&MockObject $pipelinqConfig;

	/**
	 * Controller under test.
	 *
	 * @var PipelinqSettingsController
	 */
	private PipelinqSettingsController $controller;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->pipelinqConfig = $this->createMock(PipelinqConfig::class);

		$this->controller = new PipelinqSettingsController(
			request: $this->request,
			pipelinqConfig: $this->pipelinqConfig,
		);

	}//end setUp()

	/**
	 * index() returns endpoint + hasToken — and NEVER the token itself.
	 *
	 * Verifies the "token reload-masked" half of the
	 * configuration-is-persisted-securely scenario.
	 */
	public function testIndexReturnsEndpointAndHasTokenFlagOnly(): void {
		$this->pipelinqConfig->method('getPipelinqEndpoint')->willReturn('https://pipelinq.example.com');
		$this->pipelinqConfig->method('hasPipelinqToken')->willReturn(true);
		// The controller MUST NOT call getPipelinqToken — only the flag.
		$this->pipelinqConfig->expects(self::never())->method('getPipelinqToken');

		$response = $this->controller->index();
		self::assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();

		self::assertSame('https://pipelinq.example.com', $data['endpoint']);
		self::assertTrue($data['hasToken']);
		self::assertArrayNotHasKey('token', $data, 'Token MUST NOT be returned to the frontend');

	}//end testIndexReturnsEndpointAndHasTokenFlagOnly()

	/**
	 * POST without a token preserves the currently-stored secret.
	 *
	 * Supports "edit endpoint only" without forcing the admin to
	 * re-enter the token.
	 */
	public function testCreatePreservesTokenWhenAbsent(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['endpoint', null, 'https://new.example.com'],
				['token', null, null],
			]
		);

		$this->pipelinqConfig->expects(self::once())
			->method('setPipelinqEndpoint')
			->with('https://new.example.com');
		$this->pipelinqConfig->expects(self::never())->method('setPipelinqToken');

		$this->pipelinqConfig->method('getPipelinqEndpoint')->willReturn('https://new.example.com');
		$this->pipelinqConfig->method('hasPipelinqToken')->willReturn(true);

		$response = $this->controller->create();
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertArrayNotHasKey('token', $data);

	}//end testCreatePreservesTokenWhenAbsent()

	/**
	 * POST with an explicit token rotates the stored secret.
	 */
	public function testCreateRotatesTokenWhenProvided(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['endpoint', null, 'https://pipelinq.example.com'],
				['token', null, 'rotated-secret'],
			]
		);

		$this->pipelinqConfig->expects(self::once())->method('setPipelinqEndpoint');
		$this->pipelinqConfig->expects(self::once())
			->method('setPipelinqToken')
			->with('rotated-secret');

		$this->pipelinqConfig->method('getPipelinqEndpoint')->willReturn('https://pipelinq.example.com');
		$this->pipelinqConfig->method('hasPipelinqToken')->willReturn(true);

		$response = $this->controller->create();
		self::assertTrue($response->getData()['success']);

	}//end testCreateRotatesTokenWhenProvided()

	/**
	 * test() delegates to PipelinqConfig::testConnection() and
	 * surfaces the outcome verbatim.
	 */
	public function testTestDelegatesToConfig(): void {
		$expected = [
			'success' => true,
			'status' => 200,
			'message' => 'Pipelinq connection succeeded.',
		];
		$this->pipelinqConfig->expects(self::once())
			->method('testConnection')
			->willReturn($expected);

		$response = $this->controller->test();
		self::assertSame($expected, $response->getData());

	}//end testTestDelegatesToConfig()
}//end class
