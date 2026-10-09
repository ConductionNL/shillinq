<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Commitment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Commitment;

use OCA\Shillinq\Controller\CommitmentYearEndController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Carry-over and last-invoice endpoints over the real services, with roles.
 */
final class CommitmentYearEndControllerTest extends TestCase {
	use CommitmentYearEndFixture;

	/**
	 * Seed Gemeente Voorbeeld.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seed();
	}//end setUp()

	/**
	 * The controller for a user with a role in adm-voorbeeld.
	 *
	 * @param array<string,mixed> $params  Request parameters.
	 * @param string|null         $role    The role, null for no membership.
	 * @param bool                $mayPost Whether the user may post.
	 *
	 * @return CommitmentYearEndController
	 */
	private function controller(array $params, ?string $role = 'controller', bool $mayPost = true): CommitmentYearEndController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn($role !== null);
		$context->method('canPostJournalEntry')->willReturn($mayPost);
		$context->method('currentUserId')->willReturn('m.jansen');
		$context->method('buildContext')->willReturn(['administrations' => [['administrationId' => 'adm-voorbeeld', 'role' => (string)$role]]]);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new CommitmentYearEndController($request, $this->carryOver(), $this->invoicing(), $context, $session, $l10n, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * A controller previews and runs the carry-over; a bookkeeper and an outsider are refused.
	 *
	 * @return void
	 */
	public function testOnlyAControllerCarriesOver(): void {
		$params = ['administrationId' => 'adm-voorbeeld', 'fromYear' => '2026'];

		$this->assertSame(403, $this->controller($params, 'boekhouder')->previewCarryOver()->getStatus());
		$this->assertSame(403, $this->controller($params, 'boekhouder')->carryOver()->getStatus());
		$this->assertSame(404, $this->controller($params, null)->carryOver()->getStatus());

		$preview = $this->controller($params)->previewCarryOver();
		$this->assertSame(200, $preview->getStatus());
		$this->assertSame(800000, $preview->getData()['shortfalls'][0]['shortfall']);
		$this->assertSame(200, $this->controller($params, 'eigenaar')->carryOver()->getStatus());
		$this->assertSame([], $this->controller($params)->previewCarryOver()->getData()['lines']);
	}//end testOnlyAControllerCarriesOver()

	/**
	 * Marking the last invoice needs posting rights and an open commitment.
	 *
	 * @return void
	 */
	public function testMarkingTheLastInvoice(): void {
		$params = ['administrationId' => 'adm-voorbeeld'];

		$this->assertSame(403, $this->controller($params, 'inkijker', false)->markLastInvoice($this->ids['invoice'])->getStatus());
		$refused = $this->controller($params)->previewLastInvoice($this->ids['invoice']);
		$this->assertSame(422, $refused->getStatus(), 'V-2026-0114 is still a draft.');
		$this->assertSame('This invoice has no open commitment to close.', $refused->getData()['message']);

		$this->engine->transition($this->ids['v0114'], 'aangaan');
		$this->assertSame(2000000, $this->controller($params)->previewLastInvoice($this->ids['invoice'])->getData()['release']);
		$marked = $this->controller($params, 'boekhouder')->markLastInvoice($this->ids['invoice']);
		$this->assertSame(200, $marked->getStatus());
		$this->assertSame('closed', $this->get('Commitment', $this->ids['v0114'])['status']);
		$this->assertSame(422, $this->controller(['administrationId' => 'adm-voorbeeld'])->markLastInvoice('missing')->getStatus());
	}//end testMarkingTheLastInvoice()
}//end class
