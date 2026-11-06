<?php

/**
 * FixedAssetDepreciationController: the asset page's missed depreciation is
 * listed with its amounts, posted only on request, and refused outside the
 * caller's administration (assets-method-change-and-reserve REQ-AMCR-001).
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\FixedAssetDepreciationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Asset\DepreciationPlanner;
use OCA\Shillinq\Service\Asset\DepreciationScheduleService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class FixedAssetDepreciationControllerTest extends TestCase {

	private const OVEN = '0a0e0000-0000-4000-8000-000000000001';

	private InMemoryObjectServiceStub $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectServiceStub(
			[
				'FixedAsset'           => [
					['id' => self::OVEN, 'assetNumber' => 'FA-2024-001', 'acquisitionDate' => '2026-06-01', 'acquisitionCost' => 60000, 'usefulLifeMonths' => 120, 'residualValue' => 0, 'depreciationMethod' => 'linear', 'assetAccountNumber' => '0300', 'accumulatedDepAccountNumber' => '0309', 'depreciationExpenseAccountNumber' => '4810', 'status' => 'active', 'administrationId' => 'adm-1'],
				],
				'DepreciationSchedule' => [],
				'JournalEntry'         => [],
			]
		);

	}//end setUp()

	public function testTheMissedMonthsAreListedAndPostedOnRequest(): void {
		$controller = $this->controller(user: 'controller', access: true, post: true);

		$listed = $controller->missed(self::OVEN);
		$this->assertSame(200, $listed->getStatus());
		$this->assertSame([['period' => '2026-06', 'amount' => 500.0], ['period' => '2026-07', 'amount' => 500.0], ['period' => '2026-08', 'amount' => 500.0], ['period' => '2026-09', 'amount' => 500.0]], $listed->getData()['rows']);
		$this->assertEquals(2000, $listed->getData()['total']);
		$this->assertSame([], $this->store->setSchema('JournalEntry')->findAll(), 'Listing posts nothing.');

		$posted = $controller->postMissed(self::OVEN);
		$this->assertSame(200, $posted->getStatus());
		$this->assertCount(4, $posted->getData()['rows']);
		$this->assertCount(4, $this->store->setSchema('JournalEntry')->findAll());
		$this->assertSame([], $controller->missed(self::OVEN)->getData()['rows']);

	}//end testTheMissedMonthsAreListedAndPostedOnRequest()

	public function testOtherAdministrationsAnonymousCallersAndViewersAreRefused(): void {
		$this->assertSame(404, $this->controller(user: 'other', access: false, post: false)->missed(self::OVEN)->getStatus());
		$this->assertSame(404, $this->controller(user: 'controller', access: true, post: true)->missed('no-such-asset')->getStatus());
		$this->assertSame(401, $this->controller(user: null, access: true, post: true)->postMissed(self::OVEN)->getStatus());
		$this->assertSame(403, $this->controller(user: 'viewer', access: true, post: false)->postMissed(self::OVEN)->getStatus());
		$this->assertSame([], $this->store->setSchema('JournalEntry')->findAll());

	}//end testOtherAdministrationsAnonymousCallersAndViewersAreRefused()

	private function controller(?string $user, bool $access, bool $post): FixedAssetDepreciationController {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('canAccess')->willReturn($access);
		$context->method('canPostJournalEntry')->willReturn($post);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$engine = new LifecycleFaithfulTransitionEngine($this->store, ['JournalEntry']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($engine);
		$records = new AssetRecords($this->store, new ObjectTransitionRunner(container: $container), $settings);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(strtotime('2026-10-01 09:00:00'));

		return new FixedAssetDepreciationController(
			$this->createMock(IRequest::class),
			$context,
			$records,
			new DepreciationScheduleService($records, new DepreciationPlanner()),
			$time
		);

	}//end controller()
}//end class
