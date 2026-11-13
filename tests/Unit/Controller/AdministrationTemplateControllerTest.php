<?php

/**
 * Unit tests for AdministrationTemplateController.
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
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\AdministrationTemplateController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ChartTemplate\ChartTemplateService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Templates are saved and seeded only within administrations the user may use.
 *
 * @covers \OCA\Shillinq\Controller\AdministrationTemplateController
 */
class AdministrationTemplateControllerTest extends TestCase {

	private AdministrationContextService&MockObject $context;

	private ChartTemplateService&MockObject $templates;

	private IRequest&MockObject $request;

	/**
	 * A controller for user `ann`, member of adm-1 (may post) and adm-2 (read only).
	 *
	 * @param string|null $user The signed-in user.
	 *
	 * @return AdministrationTemplateController
	 */
	private function controller(?string $user = 'ann'): AdministrationTemplateController {
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->context->method('currentUserId')->willReturn($user);
		$this->context->method('canAccess')->willReturnCallback(static fn (string $id): bool => in_array($id, ['adm-1', 'adm-2'], true));
		$this->context->method('canPostJournalEntry')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');
		$this->templates = $this->createMock(ChartTemplateService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnMap([['name', '', ' MKB '], ['templateId', '', 'tpl-1']]);

		return new AdministrationTemplateController(request: $this->request, context: $this->context, templates: $this->templates, logger: $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * A member saves a template of their administration; 201 with the template.
	 *
	 * @return void
	 */
	public function testAMemberSavesATemplate(): void {
		$controller = $this->controller();
		$this->templates->expects(self::once())->method('saveAsTemplate')->with('adm-2', ' MKB ')->willReturn(['id' => 'tpl-9']);

		$response = $controller->saveAs(id: ' adm-2 ');

		self::assertSame([201, ['id' => 'tpl-9']], [$response->getStatus(), $response->getData()]);
	}//end testAMemberSavesATemplate()

	/**
	 * Another administration, an anonymous user or an invalid name are refused
	 * and nothing is saved.
	 *
	 * @return void
	 */
	public function testSavingIsRefusedOutsideTheUsersAdministrations(): void {
		$controller = $this->controller();
		$this->templates->expects(self::never())->method('saveAsTemplate');
		self::assertSame([404, ['error' => 'Administration not found']], [$controller->saveAs(id: 'adm-9')->getStatus(), $controller->saveAs(id: 'adm-9')->getData()]);
		self::assertSame(404, $controller->saveAs(id: '  ')->getStatus());

		$anonymous = $this->controller(user: null);
		$this->templates->expects(self::never())->method('saveAsTemplate');
		self::assertSame(401, $anonymous->saveAs(id: 'adm-1')->getStatus());

		$invalid = $this->controller();
		$this->templates->method('saveAsTemplate')->willThrowException(new InvalidArgumentException('A template needs a name.'));
		self::assertSame([400, ['error' => 'A template needs a name.']], [$invalid->saveAs(id: 'adm-1')->getStatus(), $invalid->saveAs(id: 'adm-1')->getData()]);
	}//end testSavingIsRefusedOutsideTheUsersAdministrations()

	/**
	 * Seeding needs the right to post: a read-only member gets the masked 404;
	 * a poster gets the report; an unknown template is 404.
	 *
	 * @return void
	 */
	public function testSeedingNeedsTheRightToPost(): void {
		$controller = $this->controller();
		$this->templates->expects(self::once())->method('seedFromTemplate')->with('tpl-1', 'adm-1')->willReturn(['added' => ['Account:8050'], 'updated' => [], 'skipped' => []]);

		self::assertSame(404, $controller->seed(id: 'adm-2')->getStatus());
		$response = $controller->seed(id: 'adm-1');
		self::assertSame([200, ['added' => ['Account:8050'], 'updated' => [], 'skipped' => []]], [$response->getStatus(), $response->getData()]);

		$unknown = $this->controller();
		$this->templates->method('seedFromTemplate')->willThrowException(new InvalidArgumentException('Template not found.'));
		self::assertSame([404, ['error' => 'Template not found.']], [$unknown->seed(id: 'adm-1')->getStatus(), $unknown->seed(id: 'adm-1')->getData()]);
	}//end testSeedingNeedsTheRightToPost()

	/**
	 * Any signed-in user lists the active templates; anonymous is 401.
	 *
	 * @return void
	 */
	public function testTheTemplatesAreListed(): void {
		$controller = $this->controller();
		$this->templates->method('activeTemplates')->willReturn([['id' => 'tpl-1']]);
		self::assertSame([200, ['results' => [['id' => 'tpl-1']]]], [$controller->index()->getStatus(), $controller->index()->getData()]);
		self::assertSame(401, $this->controller(user: null)->index()->getStatus());
	}//end testTheTemplatesAreListed()
}//end class
