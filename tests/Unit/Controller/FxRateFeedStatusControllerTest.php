<?php

/**
 * Unit tests for FxRateFeedStatusController: the endpoint returns the feed
 * status envelope and is open to any signed-in user.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\FxRateFeedStatusController;
use OCA\Shillinq\Service\Treasury\FxRateFeedStatus;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The feed status endpoint.
 */
#[CoversClass(FxRateFeedStatusController::class)]
final class FxRateFeedStatusControllerTest extends TestCase {
	/**
	 * The endpoint answers 200 with the service's envelope, unchanged.
	 *
	 * @return void
	 */
	public function testStatusReturnsTheServiceEnvelope(): void {
		$envelope = [
			'fromFeed' => false,
			'statusLine' => 'Rates are entered by hand',
			'newest' => [['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'newestDate' => '2026-09-25']],
			'newestLine' => 'Newest rate: USD to EUR on 2026-09-25',
		];
		$service = $this->createMock(FxRateFeedStatus::class);
		$service->expects($this->once())->method('status')->willReturn($envelope);

		$response = (new FxRateFeedStatusController($this->createMock(IRequest::class), $service))->status();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($envelope, $response->getData());

	}//end testStatusReturnsTheServiceEnvelope()

	/**
	 * The endpoint is for any signed-in user: NoAdminRequired, no public or CSRF exemption.
	 *
	 * @return void
	 */
	public function testStatusIsForSignedInUsersOnly(): void {
		$attributes = array_map(
			static fn ($attribute): string => $attribute->getName(),
			(new ReflectionMethod(FxRateFeedStatusController::class, 'status'))->getAttributes()
		);

		$this->assertSame([NoAdminRequired::class], $attributes);

	}//end testStatusIsForSignedInUsersOnly()
}//end class
