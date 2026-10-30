<?php

/**
 * Unit tests for ContributionController.
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
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTime;
use OCA\Shillinq\Controller\ContributionController;
use OCA\Shillinq\Service\ContributionDebtorResolver;
use OCA\Shillinq\Service\ContributionInvoiceBuilder;
use OCA\Shillinq\Service\ContributionRaiseService;
use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Service\PaymentRequestFinder;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the endpoint's session check, its action check and its status codes.
 */
final class ContributionControllerTest extends TestCase {
	/**
	 * The store behind the real service.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Build the controller over a real raise service and an in-memory store.
	 *
	 * @param array<string, mixed> $params What the request carries.
	 * @param bool $signedIn Whether there is a session.
	 * @param bool $allowed Whether the caller carries payment.request.
	 *
	 * @return ContributionController The controller.
	 */
	private function controller(array $params, bool $signedIn = true, bool $allowed = true): ContributionController {
		$this->store = new InMemoryObjectServiceStub(
			data: ['CustomerMaster' => [['id' => 'cm-1', 'customerId' => 'G-1', 'administrationId' => 'adm-school-1']]],
			findAllRendersEntities: true
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register' ? 'shillinq' : $default)
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('coordinator');
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($allowed);
		$authorizer = new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groups);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-27'));
		$validator = new ObjectPaymentRequestValidator();

		$raiser = new ContributionRaiseService(
			authorizer: $authorizer,
			builder: new ContributionInvoiceBuilder(l10nFactory: $factory),
			debtors: new ContributionDebtorResolver(
				objectService: $this->store,
				dispatcher: $this->createMock(IEventDispatcher::class),
				appConfig: $appConfig,
				logger: $this->createMock(LoggerInterface::class),
			),
			validator: $validator,
			finder: new PaymentRequestFinder(objectService: $this->store, validator: $validator, appConfig: $appConfig),
			objectService: $this->store,
			appConfig: $appConfig,
			timeFactory: $time,
			logger: $this->createMock(LoggerInterface::class),
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new ContributionController(
			appName: 'shillinq',
			request: $request,
			raiser: $raiser,
			authorizer: $authorizer,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A well-formed call for one guardian.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The call.
	 */
	private function call(array $overrides = []): array {
		return array_merge(
			[
				'chargeable' => ['app' => 'portaliq', 'type' => 'activity-offer', 'register' => 'portaliq', 'schema' => 'activityOffer', 'id' => 'act-1'],
				'kind' => 'activity',
				'description' => 'Schaakclub najaar',
				'amount' => 25,
				'voluntary' => false,
				'administrationId' => 'adm-school-1',
				'recipients' => [['debtor' => ['customerMasterId' => 'cm-1'], 'beneficiary' => ['type' => 'child', 'id' => 'child-a']]],
				'_route' => 'shillinq.contribution.raise',
			],
			$overrides
		);
	}//end call()

	/**
	 * No session, no raise: 401 and nothing written.
	 *
	 * @return void
	 */
	public function testRefusesAnUnauthenticatedCaller(): void {
		$response = $this->controller($this->call(), false)->raise();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame([], $this->store->saved);
	}//end testRefusesAnUnauthenticatedCaller()

	/**
	 * A caller without payment.request gets 403 and nothing is written
	 * (REQ-SCON-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-002)
	 */
	public function testRefusesACallerWithoutThePaymentRequestAction(): void {
		$response = $this->controller($this->call(), true, false)->raise();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertStringContainsString('payment.request', $response->getData()['error']);
		self::assertSame([], $this->store->saved);
	}//end testRefusesACallerWithoutThePaymentRequestAction()

	/**
	 * A call that cannot be raised as a whole answers 400 naming the problem.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function testAMalformedCallAnswers400(): void {
		$response = $this->controller($this->call(['kind' => 'donation']))->raise();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('donation', $response->getData()['error']);
		self::assertSame([], $this->store->saved);
	}//end testAMalformedCallAnswers400()

	/**
	 * A good call answers 200 with the result per guardian; the framework's own
	 * keys never reach the raise.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function testRaisesAndAnswersWithTheResultPerGuardian(): void {
		$response = $this->controller($this->call())->raise();
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(1, $data['raised']);
		self::assertSame('raised', $data['results'][0]['status']);
		self::assertSame('cm-1', $data['results'][0]['customerMasterId']);
		self::assertCount(2, $this->store->saved);
	}//end testRaisesAndAnswersWithTheResultPerGuardian()
}//end class
