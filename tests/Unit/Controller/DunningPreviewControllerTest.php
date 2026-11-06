<?php

/**
 * DunningPreviewControllerTest
 *
 * Task 4.1 of receivables-automatic-dunning (REQ-RAD-008): the Next run
 * preview with the last run report, per administration the caller holds, and
 * a ladder's stages with the subject and body its letters use.
 *
 * Built on the real DunningTickRunner, DunningRunService and
 * DunningStageRenderer over an in-memory register; only Nextcloud's request,
 * clock, language, lock provider and the caller's memberships are doubles.
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
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\DunningPreviewController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Dunning\DunningStageDefaultTexts;
use OCA\Shillinq\Service\Dunning\DunningStageRenderer;
use OCA\Shillinq\Service\Dunning\DunningTemplateRegistry;
use OCA\Shillinq\Service\Dunning\DunningTickRunner;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The Next run preview and the ladder stage texts, through the controller.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DunningPreviewControllerTest extends TestCase {

	/**
	 * The day of the spec's scenario.
	 */
	private const TODAY = '2026-10-22T06:00:00+00:00';

	/**
	 * The signed-in user, or null.
	 *
	 * @var string|null
	 */
	private ?string $userId = 'boekhouder';

	/**
	 * The administration values the caller holds a membership of.
	 *
	 * @var array<int, string>
	 */
	private array $memberOf = ['ADM-LOODS'];

	/**
	 * The query parameters.
	 *
	 * @var array<string, string>
	 */
	private array $params = [];

	/**
	 * App-config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The object store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Werkplaats De Loods B.V., dunning still off, one invoice 21 days overdue;
	 * Adviesbureau Kade B.V., which this caller does not hold.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$texts  = new DunningStageDefaultTexts();
		$stages = [];
		$ladder = [1 => [7, 'EMAIL'], 2 => [21, 'EMAIL'], 3 => [35, 'EMAIL'], 4 => [56, 'REGISTERED_POST'], 5 => [70, 'COLLECTION_AGENCY_API']];
		foreach ($ladder as $nr => [$days, $channel]) {
			$stage = ['nr' => $nr, 'daysAfterExpiryDate' => $days, 'channel' => $channel];
			if ($nr === 1) {
				$stage['subject'] = ['nl' => 'Herinnering {factuurNummer}', 'en' => 'Reminder {factuurNummer}'];
				$stage['body']    = ['nl' => 'Beste {klantNaam}, betaal alstublieft.', 'en' => 'Dear {klantNaam}, please pay.'];
			}

			$stages[] = $stage;
		}

		// Stored out of order: the table shows them by number.
		$stages = array_reverse($stages);

		$this->store = new InMemoryObjectServiceStub(
			data: [
				'Administration' => [
					['id' => 'uuid-adm-loods', 'administrationCode' => 'ADM-LOODS', 'name' => 'Werkplaats De Loods B.V.', 'dunningEnabled' => false],
					['id' => 'uuid-adm-kade', 'administrationCode' => 'ADM-KADE', 'name' => 'Adviesbureau Kade B.V.', 'dunningEnabled' => true],
				],
				'DunningLadder' => [
					['id' => 'ladder-loods', 'administrationId' => 'ADM-LOODS', 'customerGroup' => 'DEFAULT', 'lifecycleState' => 'active', 'stages' => $stages],
					['id' => 'ladder-kade', 'administrationId' => 'ADM-KADE', 'customerGroup' => 'DEFAULT', 'lifecycleState' => 'active', 'stages' => $stages],
				],
				'CustomerMaster' => [
					['id' => 'cm-loods', 'administrationId' => 'ADM-LOODS', 'legalName' => 'Garage Van Dijk', 'email' => 'info@garagevandijk.nl'],
				],
				'ARInvoice' => [
					[
						'id' => 'inv-loods',
						'administrationId' => 'ADM-LOODS',
						'invoiceNumber' => '2026-0077',
						'dueDate' => '2026-10-01',
						'lifecycleState' => 'issued',
						'grossAmount' => 1210.0,
						'buyerName' => 'Garage Van Dijk',
						'customerId' => 'cm-loods',
					],
				],
			],
			idFiltersMatchNothing: true
		);
	}//end setUp()

	/**
	 * The controller as the container builds it.
	 *
	 * @param string                      $language      The caller's language.
	 * @param ObjectServiceInterface|null $objectService The controller's own object service; the store when null.
	 *
	 * @return DunningPreviewController
	 */
	private function controller(string $language = 'en', ?ObjectServiceInterface $objectService = null): DunningPreviewController {
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		$context = $this->createStub(AdministrationContextService::class);
		$context->method('currentUserId')->willReturnCallback(fn (): ?string => $this->userId);
		$context->method('accessibleAdministrationIds')->willReturnCallback(fn (): array => $this->memberOf);
		$context->method('canAccess')->willReturnCallback(
			fn (string $administrationId): bool => in_array($administrationId, $this->memberOf, true)
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config + ['register' => 'shillinq'])[$key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$store     = $this->store;
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$dunning = new DunningRunService(container: $container, appConfig: $appConfig, logger: new NullLogger(), objectService: $store);
		$runner  = new DunningTickRunner(
			objectService: $store,
			dunning: $dunning,
			transitions: new ObjectTransitionRunner(container: $container),
			locks: $this->createStub(ILockingProvider::class),
			appConfig: $appConfig,
			logger: new NullLogger()
		);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::TODAY));
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn($language);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => $text);

		return new DunningPreviewController(
			request: $request,
			context: $context,
			runner: $runner,
			renderer: new DunningStageRenderer(registry: new DunningTemplateRegistry(appConfig: $appConfig)),
			objectService: ($objectService ?? $store),
			appConfig: $appConfig,
			time: $time,
			l10n: $l10n,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * The spec's scenario: dunning still off, the bookkeeper opens Next run and
	 * sees the stage and channel each overdue invoice would get; nothing is
	 * written. Only the administrations the caller holds are listed.
	 *
	 * @return void
	 */
	public function testTheNextRunListsTheCallersAdministrationsAndWritesNothing(): void {
		$response = $this->controller()->nextRun();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				[
					'administrationId' => 'ADM-LOODS',
					'id' => 'uuid-adm-loods',
					'name' => 'Werkplaats De Loods B.V.',
					'enabled' => false,
					'lastRun' => null,
					'rows' => [
						[
							'invoiceId' => 'inv-loods',
							'invoiceNumber' => '2026-0077',
							'customer' => 'Garage Van Dijk',
							'dueDate' => '2026-10-01',
							'amount' => 1210.0,
							'stageNr' => 1,
							'channel' => 'EMAIL',
							'ladderId' => 'ladder-loods',
						],
					],
				],
			],
			$response->getData()['administrations']
		);
		self::assertSame([], $this->store->setSchema('DunningRun')->findAll());
		self::assertSame('issued', $this->store->setSchema('ARInvoice')->findAll()[0]['lifecycleState']);
	}//end testTheNextRunListsTheCallersAdministrationsAndWritesNothing()

	/**
	 * Switch on reminders asks for one administration by its record id, and
	 * gets its last run with it.
	 *
	 * @return void
	 */
	public function testOneAdministrationIsAskedForByItsRecordId(): void {
		$this->config[DunningTickRunner::REPORT_KEY] = (string)json_encode(['ADM-LOODS' => ['administrationId' => 'ADM-LOODS', 'sent' => 2]]);
		$this->params = ['administrationId' => 'uuid-adm-loods'];

		$entries = $this->controller()->nextRun()->getData()['administrations'];

		self::assertCount(1, $entries);
		self::assertSame('uuid-adm-loods', $entries[0]['id']);
		self::assertSame(2, $entries[0]['lastRun']['sent']);
	}//end testOneAdministrationIsAskedForByItsRecordId()

	/**
	 * An administration the caller does not hold answers 404, not its invoices.
	 *
	 * @return void
	 */
	public function testAnAdministrationOutsideTheCallersScopeIsNotFound(): void {
		$this->params = ['administrationId' => 'ADM-KADE'];

		$response = $this->controller()->nextRun();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertArrayNotHasKey('administrations', $response->getData());
	}//end testAnAdministrationOutsideTheCallersScopeIsNotFound()

	/**
	 * A malformed id and a caller without a session are refused.
	 *
	 * @return void
	 */
	public function testAMalformedIdAndNoUserAreRefused(): void {
		$this->params = ['administrationId' => "ADM'; DROP"];
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->nextRun()->getStatus());

		$this->userId = null;
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->nextRun()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->ladderStages('ladder-loods')->getStatus());
	}//end testAMalformedIdAndNoUserAreRefused()

	/**
	 * The ladder's stages in order, with the subject and body a letter would
	 * use: the stage's own text, else the default text of that stage.
	 *
	 * @return void
	 */
	public function testTheLadderStagesShowSubjectAndBodyInTheCallersLanguage(): void {
		$rows = $this->controller(language: 'en')->ladderStages('ladder-loods')->getData()['rows'];

		self::assertSame([1, 2, 3, 4, 5], array_column($rows, 'nr'));
		self::assertSame([7, 21, 35, 56, 70], array_column($rows, 'days'));
		self::assertSame('Reminder {factuurNummer}', $rows[0]['subject']);
		self::assertSame('Dear {klantNaam}, please pay.', $rows[0]['body']);
		$default = (new DunningStageDefaultTexts())->for(stageNr: 4, language: 'en');
		self::assertSame($default['subject'], $rows[3]['subject']);
		self::assertSame($default['body'], $rows[3]['body']);
		self::assertSame('Registered post', $rows[3]['channelLabel']);

		$dutch = $this->controller(language: 'nl_NL')->ladderStages('ladder-loods')->getData()['rows'];
		self::assertSame('Herinnering {factuurNummer}', $dutch[0]['subject']);
	}//end testTheLadderStagesShowSubjectAndBodyInTheCallersLanguage()

	/**
	 * A ladder of an administration the caller does not hold, or no ladder at
	 * all, answers 404.
	 *
	 * @return void
	 */
	public function testALadderOutsideTheCallersScopeIsNotFound(): void {
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->ladderStages('ladder-kade')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->ladderStages('no-such-ladder')->getStatus());
	}//end testALadderOutsideTheCallersScopeIsNotFound()

	/**
	 * A membership can name an administration by its code and by its record id;
	 * the list shows that administration once. A membership whose
	 * administration no longer exists is left out instead of failing the page.
	 *
	 * @return void
	 */
	public function testTheListShowsEachAdministrationOnceAndSkipsAMissingOne(): void {
		$this->memberOf = ['ADM-LOODS', 'uuid-adm-loods', 'ADM-GONE'];

		$response = $this->controller()->nextRun();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['uuid-adm-loods'], array_column($response->getData()['administrations'], 'id'));
	}//end testTheListShowsEachAdministrationOnceAndSkipsAMissingOne()

	/**
	 * When the register cannot be opened the caller gets 404 for the one
	 * administration or ladder asked for, and an empty list otherwise; the
	 * error is logged, not shown.
	 *
	 * @return void
	 */
	public function testARegisterThatCannotBeOpenedAnswersNotFound(): void {
		$broken = $this->createStub(ObjectServiceInterface::class);
		$broken->method('setRegister')->willThrowException(new RuntimeException('Register shillinq not found'));

		self::assertSame([], $this->controller(objectService: $broken)->nextRun()->getData()['administrations']);
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(objectService: $broken)->ladderStages('ladder-loods')->getStatus());

		$this->params = ['administrationId' => 'ADM-LOODS'];
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(objectService: $broken)->nextRun()->getStatus());
	}//end testARegisterThatCannotBeOpenedAnswersNotFound()

	/**
	 * Every channel a ladder stage can hold has a name in the table; an
	 * unknown code is shown as it is stored.
	 *
	 * @return void
	 */
	public function testEveryStageChannelHasALabel(): void {
		$channels = ['EMAIL', 'eMAILPostRegistration', 'REGISTERED_POST', 'COLLECTION_AGENCY_API', 'FAX'];
		$stages   = [];
		foreach ($channels as $index => $channel) {
			$stages[] = ['nr' => ($index + 1), 'daysAfterExpiryDate' => (7 * ($index + 1)), 'channel' => $channel];
		}

		$this->store->setSchema('DunningLadder')->saveObject(
			object: ['id' => 'ladder-channels', 'administrationId' => 'ADM-LOODS', 'stages' => $stages]
		);

		$rows = $this->controller()->ladderStages('ladder-channels')->getData()['rows'];

		self::assertSame(
			['Email', 'Email and registered post', 'Registered post', 'Collection agency', 'FAX'],
			array_column($rows, 'channelLabel')
		);
	}//end testEveryStageChannelHasALabel()

	/**
	 * An empty register setting falls back to the shillinq register instead
	 * of opening a register without a name.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterSettingFallsBackToShillinq(): void {
		$this->config['register'] = '';
		$opened    = [];
		$recording = $this->createStub(ObjectServiceInterface::class);
		$recording->method('setRegister')->willReturnCallback(
			function (string|int $register) use (&$opened): never {
				$opened[] = $register;
				throw new RuntimeException('stop after the register is chosen');
			}
		);

		$this->controller(objectService: $recording)->ladderStages('ladder-loods');

		self::assertSame(['shillinq'], $opened);
	}//end testAnEmptyRegisterSettingFallsBackToShillinq()
}//end class
