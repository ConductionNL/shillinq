<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the Shillinq admin settings form and its delegation scope.
 *
 * @covers \OCA\Shillinq\Settings\AdminSettings
 */
final class AdminSettingsTest extends TestCase {
	/** @var IAppManager&MockObject */
	private IAppManager $appManager;

	/** @var IInitialState&MockObject */
	private IInitialState $initialState;

	private AdminSettings $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->settings = new AdminSettings($this->appManager, $this->initialState);
	}

	public function testItIsADelegatedSetting(): void {
		$this->assertInstanceOf(IDelegatedSettings::class, $this->settings);
	}

	public function testGetFormProvidesTheInstalledVersionAsInitialState(): void {
		$this->appManager->expects($this->once())
			->method('getAppVersion')
			->with('shillinq')
			->willReturn('1.4.2');
		$this->initialState->expects($this->once())
			->method('provideInitialState')
			->with('version', '1.4.2');

		$this->settings->getForm();
	}

	public function testGetFormRendersTheAdminTemplateOfTheApp(): void {
		$this->appManager->method('getAppVersion')->willReturn('0.0.1');

		$response = $this->settings->getForm();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('shillinq', $response->getApp());
		$this->assertSame('settings/admin', $response->getTemplateName());
		$this->assertSame([], $response->getParams());
	}

	public function testGetFormPassesAnEmptyVersionThrough(): void {
		$this->appManager->method('getAppVersion')->willReturn('');
		$this->initialState->expects($this->once())
			->method('provideInitialState')
			->with('version', '');

		$this->settings->getForm();
	}

	public function testItBelongsToTheShillinqSectionWithPriorityTen(): void {
		$this->assertSame('shillinq', $this->settings->getSection());
		$this->assertSame(10, $this->settings->getPriority());
	}

	public function testDelegationUsesTheSectionDefaultNameAndGrantsNoConfigKeys(): void {
		$this->assertNull($this->settings->getName());
		$this->assertSame([], $this->settings->getAuthorizedAppConfig());
	}
}
