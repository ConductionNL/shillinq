<?php

/**
 * Unit tests for XbrlInstanceValidationGuard: the requires of XbrlInstance validate.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\Shillinq\Guard\XbrlInstanceValidationGuard;
use OCA\Shillinq\Lifecycle\PostingRefusedException;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Service\Sbr\XbrlInstanceCheck;
use OCA\Shillinq\Service\Sbr\XbrlTaxonomyLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(XbrlInstanceValidationGuard::class)]
#[UsesClass(RegisterRequiresGuardAdapter::class)]
final class XbrlInstanceValidationGuardTest extends TestCase {

	private const INSTANCE = ['entryPoint' => ' kvk-jaarrekening ', 'reportingPeriodStart' => '2025-01-01', 'reportingPeriodEnd' => '2025-12-31'];

	private function guard(?array $taxonomy, array $problems, ?\Throwable $failure = null): XbrlInstanceValidationGuard {
		$lookup = $this->createMock(XbrlTaxonomyLookup::class);
		$expect = $lookup->expects($this->once())->method('effectiveFor')->with('kvk-jaarrekening', '2025-01-01', '2025-12-31');
		if ($failure !== null) {
			$expect->willThrowException($failure);
		} else {
			$expect->willReturn($taxonomy);
		}

		$check = $this->createMock(XbrlInstanceCheck::class);
		$check->method('problems')->with(self::INSTANCE, $taxonomy)->willReturn($problems);

		return new XbrlInstanceValidationGuard($lookup, $check);
	}

	public function testACleanInstanceMayBeValidated(): void {
		$this->assertTrue($this->guard(['taxonomyId' => 't'], [])->requireValidInstance(self::INSTANCE));
	}

	public function testEveryFailingItemIsNamedInTheRefusal(): void {
		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage('The required concept jenv-bw2-i:Equity is missing. The entity identifier is missing.');

		$this->guard(['taxonomyId' => 't'], ['The required concept jenv-bw2-i:Equity is missing.', 'The entity identifier is missing.'])->requireValidInstance(self::INSTANCE);
	}

	public function testThroughTheAdapterTheRefusalIsADenyCarryingTheItems(): void {
		$adapter = new RegisterRequiresGuardAdapter(
			guard: $this->guard(null, ['No taxonomy is effective for this period that carries the entry point kvk-jaarrekening.']),
			method: 'requireValidInstance',
			denyMessage: 'generic',
			logger: $this->createMock(LoggerInterface::class)
		);

		$result = $adapter->check(self::INSTANCE, 'validate', 'admin');

		$this->assertFalse($result->isAllowed());
		$this->assertSame('No taxonomy is effective for this period that carries the entry point kvk-jaarrekening.', $result->getMessage());
	}

	public function testATaxonomyThatCannotBeReadDeniesThroughTheAdapter(): void {
		$adapter = new RegisterRequiresGuardAdapter(
			guard: $this->guard(null, [], new RuntimeException('database gone')),
			method: 'requireValidInstance',
			denyMessage: 'generic',
			logger: $this->createMock(LoggerInterface::class)
		);

		$result = $adapter->check(self::INSTANCE, 'validate', 'admin');

		$this->assertFalse($result->isAllowed());
		$this->assertSame('generic', $result->getMessage());
	}
}
