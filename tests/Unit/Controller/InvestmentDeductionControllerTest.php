<?php

/**
 * Unit tests for InvestmentDeductionController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\InvestmentDeductionController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InvestmentDeduction\InvestmentDeductionService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The recalculation runs only in an administration the user may post in.
 *
 * @covers \OCA\Shillinq\Controller\InvestmentDeductionController
 */
class InvestmentDeductionControllerTest extends TestCase {

	/**
	 * Calls made to the calculation, as [administrationId, year].
	 *
	 * @var list<array{0: string, 1: int}>
	 */
	private array $calculated = [];

	/**
	 * A controller for `ann`, who may post in adm-1 (active) only.
	 *
	 * @param string|null    $user             The signed-in user.
	 * @param string         $administrationId The administrationId in the body.
	 * @param \Throwable|null $failure         What the calculation throws.
	 *
	 * @return InvestmentDeductionController
	 */
	private function controller(?string $user = 'ann', string $administrationId = '', ?\Throwable $failure = null): InvestmentDeductionController {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => 'adm-1']);
		$context->method('canPostJournalEntry')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');
		$service = $this->createMock(InvestmentDeductionService::class);
		$service->method('calculateYear')->willReturnCallback(
			function (string $administrationId, int $year) use ($failure): array {
				if ($failure !== null) {
					throw $failure;
				}

				$this->calculated[] = [$administrationId, $year];

				return ['year' => $year, 'totals' => ['KIA' => 1], 'claims' => 1, 'kept' => 0];
			}
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['administrationId', '', $administrationId]]);

		return new InvestmentDeductionController(request: $request, context: $context, deductions: $service, logger: $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * The active administration, or the one named, is calculated.
	 *
	 * @return void
	 */
	public function testTheYearIsCalculatedForAnAdministrationTheUserPostsIn(): void {
		$response = $this->controller()->calculate(year: 2026);
		self::assertSame([200, ['year' => 2026, 'totals' => ['KIA' => 1], 'claims' => 1, 'kept' => 0]], [$response->getStatus(), $response->getData()]);
		self::assertSame(200, $this->controller(administrationId: ' adm-1 ')->calculate(year: 2026)->getStatus());
		self::assertSame([['adm-1', 2026], ['adm-1', 2026]], $this->calculated);
	}//end testTheYearIsCalculatedForAnAdministrationTheUserPostsIn()

	/**
	 * Another administration is a masked 404, anonymous 401, an odd year 400,
	 * a year without tables 422 with its message; nothing is calculated.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame([404, ['error' => 'Administration not found']], [$this->controller(administrationId: 'adm-9')->calculate(year: 2026)->getStatus(), $this->controller(administrationId: 'adm-9')->calculate(year: 2026)->getData()]);
		self::assertSame(401, $this->controller(user: null)->calculate(year: 2026)->getStatus());
		self::assertSame(400, $this->controller()->calculate(year: 26)->getStatus());
		self::assertSame([], $this->calculated);

		$missing = $this->controller(failure: new InvalidArgumentException('No KIA tiers are loaded for 2027.'))->calculate(year: 2027);
		self::assertSame([422, ['error' => 'No KIA tiers are loaded for 2027.']], [$missing->getStatus(), $missing->getData()]);
		self::assertSame(500, $this->controller(failure: new \RuntimeException('down'))->calculate(year: 2026)->getStatus());
	}//end testRefusals()
}//end class
