<?php

/**
 * Tests for StandardsPolicyController.
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
 * @spec openspec/specs/accounting-standards-policy/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\StandardsPolicyController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationManagementAccess;
use OCA\Shillinq\Service\StandardsPolicyService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The policy is read by members and saved by owners, per administration.
 *
 * The caller is the owner of adm-a and a plain member of adm-b; adm-c is not theirs.
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md
 */
final class StandardsPolicyControllerTest extends TestCase {

	/**
	 * Request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Policy service.
	 *
	 * @var StandardsPolicyService&MockObject
	 */
	private StandardsPolicyService&MockObject $policies;

	/**
	 * Controller.
	 *
	 * @var StandardsPolicyController
	 */
	private StandardsPolicyController $controller;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->policies = $this->createMock(StandardsPolicyService::class);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => in_array($id, ['adm-a', 'adm-b'], true));
		$access = $this->createMock(AdministrationManagementAccess::class);
		$access->method('canManage')->willReturnCallback(static fn (string $id): bool => $id === 'adm-a');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->controller = new StandardsPolicyController(
			request: $this->request,
			policies: $this->policies,
			administrationContext: $context,
			managementAccess: $access,
			l10n: $l10n,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * A member reads the policy and is told they cannot edit it.
	 *
	 * @return void
	 */
	public function testMemberReadsTheirAdministrationsPolicy(): void {
		$this->policies->expects(self::once())->method('policyFor')->with('adm-b')
			->willReturn(['id' => 'pol-b', 'frameworks' => [['key' => 'ifrs', 'enabled' => true, 'precedence' => 1]]]);

		$response = $this->controller->show(administrationId: 'adm-b');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse($response->getData()['canEdit']);
		self::assertSame('ifrs', $response->getData()['frameworks'][0]['key']);

	}//end testMemberReadsTheirAdministrationsPolicy()

	/**
	 * Another administration's policy reads as not found.
	 *
	 * @return void
	 */
	public function testNonMemberCannotReadThePolicy(): void {
		$this->policies->expects(self::never())->method('policyFor');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->show(administrationId: 'adm-c')->getStatus());

	}//end testNonMemberCannotReadThePolicy()

	/**
	 * The owner saves the policy of that administration only.
	 *
	 * @return void
	 */
	public function testOwnerSavesThePolicy(): void {
		$frameworks = [['key' => 'dutch-gaap', 'enabled' => true, 'precedence' => 1]];
		$this->request->method('getParam')->willReturnMap([['frameworks', null, $frameworks]]);
		$this->policies->expects(self::once())->method('savePolicy')->with('adm-a', $frameworks)
			->willReturn(['id' => 'pol-a', 'frameworks' => $frameworks]);

		$response = $this->controller->update(administrationId: 'adm-a');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['canEdit']);

	}//end testOwnerSavesThePolicy()

	/**
	 * A member who is not an owner is refused with 403, and nothing is saved.
	 *
	 * @return void
	 */
	public function testMemberWhoIsNotOwnerCannotSave(): void {
		$this->request->method('getParam')->willReturn([]);
		$this->policies->expects(self::never())->method('savePolicy');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->update(administrationId: 'adm-b')->getStatus());

	}//end testMemberWhoIsNotOwnerCannotSave()

	/**
	 * A non-member's save reads as not found.
	 *
	 * @return void
	 */
	public function testNonMemberSaveReadsAsNotFound(): void {
		$this->request->method('getParam')->willReturn([]);
		$this->policies->expects(self::never())->method('savePolicy');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->update(administrationId: 'adm-c')->getStatus());

	}//end testNonMemberSaveReadsAsNotFound()

	/**
	 * A malformed list is a 400.
	 *
	 * @return void
	 */
	public function testMalformedFrameworksAreRefused(): void {
		$this->request->method('getParam')->willReturn([['key' => 'ifrs']]);
		$this->policies->method('savePolicy')->willThrowException(new \InvalidArgumentException('bad'));

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update(administrationId: 'adm-a')->getStatus());

	}//end testMalformedFrameworksAreRefused()
}//end class
