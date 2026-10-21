<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\Shillinq\Service\ServerWriteAuthority;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A grant holds for one purpose and one object, and only during the save.
 */
final class ServerWriteAuthorityTest extends TestCase {

	public function testGrantHoldsOnlyDuringTheWork(): void {
		$authority = new ServerWriteAuthority();
		self::assertFalse($authority->holds('p', 'x'));

		$seen = $authority->during('p', 'x', fn (): array => [
			$authority->holds('p', 'x'),
			$authority->holds('p', 'y'),
			$authority->holds('q', 'x'),
		]);

		self::assertSame([true, false, false], $seen);
		self::assertFalse($authority->holds('p', 'x'));
	}

	public function testGrantIsWithdrawnWhenTheWorkThrows(): void {
		$authority = new ServerWriteAuthority();
		try {
			$authority->during('p', 'x', static function (): void {
				throw new RuntimeException('save failed');
			});
		} catch (RuntimeException $e) {
			self::assertSame('save failed', $e->getMessage());
		}

		self::assertFalse($authority->holds('p', 'x'));
	}

	public function testEmptyObjectIdIsNeverGranted(): void {
		$authority = new ServerWriteAuthority();
		self::assertFalse($authority->during('p', '', fn (): bool => $authority->holds('p', '')));
	}
}
