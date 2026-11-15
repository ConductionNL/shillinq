<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\FxRateAdminController;
use OCA\Shillinq\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Nextcloud grants a delegated admin access by comparing the attribute's value
 * with the settings classes delegated to them, so it must name such a class.
 *
 * @coversNothing
 */
final class FxRateAdminControllerAuthorizationTest extends TestCase {

	public function testTheImportStatusNamesTheDelegatableSettingsClass(): void {
		$method = new ReflectionMethod(FxRateAdminController::class, 'status');
		$attributes = $method->getAttributes(AuthorizedAdminSetting::class);

		self::assertCount(1, $attributes);
		self::assertSame(['settings' => AdminSettings::class], self::named($attributes[0]->getArguments()));
		self::assertTrue(is_subclass_of(AdminSettings::class, IDelegatedSettings::class));
		self::assertSame([], $method->getAttributes(NoAdminRequired::class), 'stays closed to ordinary users');
	}

	/**
	 * @param array<int|string, mixed> $arguments The attribute's arguments.
	 *
	 * @return array<string, mixed>
	 */
	private static function named(array $arguments): array {
		return ['settings' => ($arguments['settings'] ?? $arguments[0] ?? null)];
	}
}
