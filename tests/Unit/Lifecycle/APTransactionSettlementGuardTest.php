<?php

/**
 * Tests for APTransactionSettlementGuard.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\APTransactionSettlementGuard;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The AP payment transitions run only inside a bank match settlement.
 */
final class APTransactionSettlementGuardTest extends TestCase {

	/**
	 * A direct matchFull through the object API is refused.
	 *
	 * @return void
	 */
	public function testADirectPaymentTransitionIsRefused(): void {
		$result = (new APTransactionSettlementGuard(logger: new NullLogger()))->check(['id' => 'ap-1', 'state' => 'paid'], 'matchFull', 'bookkeeper1');

		self::assertFalse($result->isAllowed());
		self::assertSame(APTransactionSettlementGuard::MESSAGE, $result->getMessage());
	}//end testADirectPaymentTransitionIsRefused()

	/**
	 * The transition the settlement runs for a confirmed match is allowed.
	 *
	 * @return void
	 */
	public function testTheSettlementsOwnTransitionIsAllowed(): void {
		$guard = new APTransactionSettlementGuard(logger: new NullLogger());
		$verdicts = [];
		$runner = $this->createMock(ObjectTransitionRunner::class);
		$runner->method('run')->willReturnCallback(
			static function (string $objectId, string $action) use ($guard, &$verdicts): void {
				$verdicts[] = $guard->check(['id' => $objectId], $action, 'bookkeeper1')->isAllowed();
			}
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$store = new InMemoryObjectServiceStub(['APTransaction' => [['id' => 'ap-1', 'state' => 'issued', 'totalAmount' => 10.0]]]);

		(new InvoiceSettlementService(objectService: $store, transitions: $runner, settings: $settings, logger: new NullLogger()))
			->settle(['targetType' => 'ap-invoice', 'targetRefs' => ['ap-1'], 'isPartial' => false]);

		self::assertSame([true], $verdicts);
	}//end testTheSettlementsOwnTransitionIsAllowed()

	/**
	 * The accounts-payable register names the guard on both payment transitions.
	 *
	 * @return void
	 */
	public function testTheRegisterNamesTheGuardOnBothPaymentTransitions(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/zz-ap-settlement-guard.json'), true);
		$transitions = $fragment['components']['schemas']['APTransaction']['x-openregister-lifecycle']['transitions'];

		foreach (['matchFull', 'matchPartial'] as $transition) {
			self::assertSame(APTransactionSettlementGuard::class, $transitions[$transition]['requires']);
		}
	}//end testTheRegisterNamesTheGuardOnBothPaymentTransitions()
}//end class
