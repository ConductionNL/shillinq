<?php

/**
 * The multi-year reserve overview endpoint (public-sector-reserves-and-interest,
 * REQ-PSRI-002): members read it, outsiders and anonymous callers do not.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PublicSector;

use OCA\Shillinq\Controller\ReserveOverviewController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ReserveOverviewControllerTest extends TestCase {
	use PublicSectorFixture;

	protected function setUp(): void {
		$this->seed([['id' => 'plan-2027', 'administrationId' => 'adm-gov-1', 'reserveId' => $this->ids['maintenance'], 'year' => 2027, 'kind' => 'addition', 'amount' => 150000, 'status' => 'planned']]);
	}//end setUp()

	private function controller(array $params, bool $member = true, ?string $user = 'm.jansen'): ReserveOverviewController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('canAccess')->willReturn($member);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => 'adm-gov-1']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ReserveOverviewController($request, $this->balances(), $context, $l10n, $this->createMock(LoggerInterface::class));
	}//end controller()

	public function testAMemberReadsTheOverviewOfTheActiveAdministration(): void {
		$response = $this->controller(['year' => '2026'])->overview();
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(2026, $data['fromYear']);
		$this->assertCount(10, $data['rows']);
		$planned = array_values(array_filter($data['rows'], static fn (array $row): bool => $row['year'] === 2027 && $row['reserveName'] === 'Reserve onderhoud sportaccommodaties'));
		$this->assertTrue($planned[0]['planned']);
		$this->assertSame(950000.0, (float)$planned[0]['closing']);
	}//end testAMemberReadsTheOverviewOfTheActiveAdministration()

	public function testAnOutsiderGetsNotFound(): void {
		$response = $this->controller(['administrationId' => 'adm-other', 'year' => '2026'], member: false)->overview();
		$this->assertSame(404, $response->getStatus());
	}//end testAnOutsiderGetsNotFound()

	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->controller(['year' => '2026'], user: null)->overview();
		$this->assertSame(401, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()
}//end class
