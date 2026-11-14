<?php

/**
 * Unit tests for the Shillinq admin settings section.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Sections
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

namespace OCA\Shillinq\Tests\Unit\Sections;

use OCA\Shillinq\Sections\SettingsSection;
use OCA\Shillinq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The admin section hosts the admin form, is named through IL10N and shows
 * the app's dark icon.
 *
 * @covers \OCA\Shillinq\Sections\SettingsSection
 * @uses \OCA\Shillinq\Settings\AdminSettings
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SettingsSectionTest extends TestCase {

	/**
	 * The section over the given collaborators, defaulting to bare mocks.
	 *
	 * @param IL10N|null         $l10n The localization service.
	 * @param IURLGenerator|null $urls The URL generator.
	 *
	 * @return SettingsSection
	 */
	private function section(?IL10N $l10n = null, ?IURLGenerator $urls = null): SettingsSection {
		return new SettingsSection(
			$l10n ?? $this->createMock(IL10N::class),
			$urls ?? $this->createMock(IURLGenerator::class),
		);
	}//end section()

	/**
	 * The section's id is the one the admin form places itself in, so the form shows in it.
	 *
	 * @return void
	 */
	public function testTheAdminFormIsPlacedInThisSection(): void {
		$form = new AdminSettings($this->createMock(IAppManager::class), $this->createMock(IInitialState::class));

		$this->assertSame('shillinq', $this->section()->getID());
		$this->assertSame($form->getSection(), $this->section()->getID());
	}//end testTheAdminFormIsPlacedInThisSection()

	/**
	 * The name is the translation of "Shillinq", asked for once.
	 *
	 * @return void
	 */
	public function testTheNameIsTranslated(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')->with('Shillinq')->willReturn('Shillinq (vertaald)');

		$this->assertSame('Shillinq (vertaald)', $this->section(l10n: $l10n)->getName());
	}//end testTheNameIsTranslated()

	/**
	 * The icon is the app's dark SVG, resolved by the URL generator.
	 *
	 * @return void
	 */
	public function testTheIconIsTheAppsDarkSvg(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())
			->method('imagePath')
			->with('shillinq', 'app-dark.svg')
			->willReturn('/apps/shillinq/img/app-dark.svg');

		$this->assertSame('/apps/shillinq/img/app-dark.svg', $this->section(urls: $urls)->getIcon());
	}//end testTheIconIsTheAppsDarkSvg()

	/**
	 * The section sorts at priority 75 among the admin sections.
	 *
	 * @return void
	 */
	public function testThePriorityIsSeventyFive(): void {
		$this->assertSame(75, $this->section()->getPriority());
	}//end testThePriorityIsSeventyFive()
}//end class
