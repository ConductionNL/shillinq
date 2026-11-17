<?php

/**
 * Unit tests for CbcrEnteredFiguresController: Enter figures and Import
 * figures on a group entity, reached through the ultimate parent's
 * administration.
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\CbcrEnteredFiguresController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Cbcr\CbcrEnteredFiguresService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The controller over the real service and an in-memory register.
 */
#[CoversClass(CbcrEnteredFiguresController::class)]
#[UsesClass(CbcrEnteredFiguresService::class)]
#[UsesClass(ObjectIdentifier::class)]
final class CbcrEnteredFiguresControllerTest extends TestCase {

	/**
	 * The in-memory register of the last controller built.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The nine figures of the GmbH for 2026, as the form sends them.
	 *
	 * @return array<string,mixed>
	 */
	private static function gmbhFigures(): array {
		return [
			'fiscalYear' => '2026',
			'currency' => 'eur',
			'unrelatedPartyRevenue' => '120000000',
			'relatedPartyRevenue' => '40000000',
			'profitBeforeTax' => '9500000',
			'incomeTaxPaidCash' => '2800000',
			'incomeTaxAccrued' => '3000000',
			'statedCapital' => '25000',
			'accumulatedEarnings' => '14000000',
			'numberOfEmployees' => 210,
			'tangibleAssetsOtherThanCash' => '31000000',
		];
	}//end gmbhFigures()

	/**
	 * The controller with the given request parameters, for a member of adm-holding only.
	 *
	 * @param array<string,mixed> $parameters The request parameters.
	 *
	 * @return CbcrEnteredFiguresController
	 */
	private function controller(array $parameters): CbcrEnteredFiguresController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($parameters[$key] ?? $default));

		$this->store = new InMemoryObjectServiceStub(
			[
				'GroupEntityRegistry' => [
					['id' => 'ge-holding', 'entityName' => 'Voorbeeld Groep Holding B.V.', 'administrationId' => 'adm-holding'],
					[
						'id' => 'ge-productie',
						'entityName' => 'Voorbeeld Productie B.V.',
						'administrationId' => 'adm-productie',
						'ultimateParentEntity' => 'ge-holding',
					],
					['id' => 'ge-gmbh', 'entityName' => 'Voorbeeld GmbH', 'ultimateParentEntity' => 'ge-holding'],
					['id' => 'ge-other', 'entityName' => 'Andere Groep B.V.', 'administrationId' => 'adm-other'],
					['id' => 'ge-other-sub', 'entityName' => 'Andere Sub Ltd', 'ultimateParentEntity' => 'ge-other'],
					['id' => 'ge-orphan', 'entityName' => 'Wees GmbH'],
				],
			]
		);
		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-holding');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new CbcrEnteredFiguresController(
			$request,
			new CbcrEnteredFiguresService($this->store, $appConfig, $l10n),
			$this->store,
			$context,
			$appConfig,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * The stored figure rows.
	 *
	 * @return array<int,mixed>
	 */
	private function rows(): array {
		return $this->store->setSchema('CbcrEntityFigures')->findAll();
	}//end rows()

	/**
	 * Enter figures stores the GmbH's figures as entered, once per year.
	 *
	 * @return void
	 */
	public function testEnterStoresTheEntitysFiguresOncePerYear(): void {
		$controller = $this->controller(self::gmbhFigures());
		$response   = $controller->enter('ge-gmbh');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('ge-gmbh', $data['entity']);
		self::assertSame(2026, $data['fiscalYear']);
		self::assertSame('entered', $data['source']);
		self::assertSame('EUR', $data['currency']);
		self::assertSame(40000000.0, $data['relatedPartyRevenue']);
		self::assertSame(210, $data['numberOfEmployees']);

		$again = $controller->enter('ge-gmbh');
		self::assertSame(Http::STATUS_OK, $again->getStatus());
		self::assertSame($data['id'], $again->getData()['id']);
		self::assertCount(1, $this->rows());
	}//end testEnterStoresTheEntitysFiguresOncePerYear()

	/**
	 * Another group's entity, an entity without a reachable parent, or an unknown id is not found.
	 *
	 * @return void
	 */
	public function testAnEntityOutsideTheCallersGroupsIsNotFound(): void {
		foreach (['ge-other', 'ge-other-sub', 'ge-orphan', 'ge-unknown', '../ge-gmbh'] as $id) {
			$response = $this->controller(self::gmbhFigures())->enter($id);
			self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $id);
			self::assertSame([], $this->rows(), $id);
		}
	}//end testAnEntityOutsideTheCallersGroupsIsNotFound()

	/**
	 * Books kept here, a missing year or a malformed figure is refused, naming why, and stores nothing.
	 *
	 * @return void
	 */
	public function testARefusalSaysWhyAndStoresNothing(): void {
		$books = $this->controller(self::gmbhFigures())->enter('ge-productie');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $books->getStatus());
		self::assertSame('Voorbeeld Productie B.V. keeps its books in Shillinq; read its figures from the books instead.', $books->getData()['error']);

		$noYear = $this->controller(['fiscalYear' => 'twenty'] + self::gmbhFigures())->enter('ge-gmbh');
		self::assertSame(Http::STATUS_BAD_REQUEST, $noYear->getStatus());
		self::assertSame('Give the fiscal year, for example 2026.', $noYear->getData()['error']);

		$bad = $this->controller(['numberOfEmployees' => '12.5'] + self::gmbhFigures())->enter('ge-gmbh');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $bad->getStatus());
		self::assertSame('The number of employees is a whole number of at least zero.', $bad->getData()['error']);
		self::assertSame([], $this->rows());
	}//end testARefusalSaysWhyAndStoresNothing()

	/**
	 * Import figures reads pasted CSV text and stores it as imported.
	 *
	 * @return void
	 */
	public function testImportStoresThePastedFiguresAsImported(): void {
		$csv = "figure;amount\n";
		foreach (self::gmbhFigures() as $field => $value) {
			if ($field !== 'fiscalYear') {
				$csv .= $field . ';' . $value . "\n";
			}
		}

		$response = $this->controller(['fiscalYear' => 2026, 'figures' => $csv])->import('ge-gmbh');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('imported', $response->getData()['source']);
		self::assertSame(31000000.0, $response->getData()['tangibleAssetsOtherThanCash']);
		self::assertCount(1, $this->rows());
	}//end testImportStoresThePastedFiguresAsImported()

	/**
	 * An empty or oversized paste is a bad request, an unknown line is refused with its number.
	 *
	 * @return void
	 */
	public function testImportRefusesAnEmptyOversizedOrUnreadablePaste(): void {
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(['fiscalYear' => 2026, 'figures' => ' '])->import('ge-gmbh')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(['fiscalYear' => 2026, 'figures' => ['a']])->import('ge-gmbh')->getStatus());
		self::assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller(['fiscalYear' => 2026, 'figures' => str_repeat('x', 65537)])->import('ge-gmbh')->getStatus()
		);

		$unknown = $this->controller(['fiscalYear' => 2026, 'figures' => "turnover;5"])->import('ge-gmbh');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $unknown->getStatus());
		self::assertSame('Line 1 names no known figure: turnover.', $unknown->getData()['error']);

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(['fiscalYear' => 2026, 'figures' => "turnover;5"])->import('ge-other')->getStatus());
		self::assertSame([], $this->rows());
	}//end testImportRefusesAnEmptyOversizedOrUnreadablePaste()
}//end class
