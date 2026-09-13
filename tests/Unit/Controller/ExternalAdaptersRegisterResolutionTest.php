<?php

/**
 * The adapters roster reads the slug this instance actually carries.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ExternalAdaptersAdminController;
use OCA\Shillinq\Tests\Unit\Support\FakeSlugResolver;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Migrated and unmigrated instances, told apart.
 *
 * ## Why the migrated case is the only one that matters
 *
 * Reinstating the pinned literal reddens the migrated-instance test below and
 * the static guard in {@see \OCA\Shillinq\Tests\Unit\Support\RegisterSlugPinTest}.
 * It does NOT redden the unmigrated-instance test, because on an unmigrated
 * instance the pinned literal happens to be the right answer. That is why this
 * defect survived: any test anyone might have written here would, in effect,
 * have been the unmigrated case.
 *
 * Watched failing, not assumed. With `->setRegister($registerSlug)` reverted to
 * `->setRegister('openconnector')` in
 * {@see ExternalAdaptersAdminController::resolveProvisioning()}, two assertions
 * reddened and they were the right two:
 *
 *  - `testAMigratedInstanceIsReadWithItsNewSlug`, which expected `integriq` and got
 *    `openconnector`.
 *  - `RegisterSlugPinTest::testNoSourceFilePinsASupersededRegisterSlug`, which
 *    named the file, the line and the canonical slug.
 *
 * `testAnUnmigratedInstanceIsReadWithItsOldSlug` stayed GREEN under that
 * mutation, exactly as it should. So did the absent case, because that one is
 * decided before the read is reached.
 *
 * A second mutation was run for the absent case: with the `isResolved()` branch
 * in `index()` removed and the slug taken as `$resolution->slug ?? 'integriq'`,
 * two assertions reddened and the static guard stayed green, which is correct
 * because that mutation types no superseded literal at all:
 *
 *  - `testAnInstanceWithoutTheRegisterAnswers404`, which got 200 and a
 *    fifteen-row roster instead of 404.
 *  - `testTheAppBeingEnabledDoesNotDecideTheRegisterSlug`, where the absent
 *    case read with `integriq` rather than reading with nothing.
 *
 * That is the defect in one line: the fallback reads as "no data". It is also
 * why the static guard is not sufficient on its own. It watches the literal
 * being TYPED; only these watch the resolved slug being IGNORED.
 *
 * ## Why the app id is not the answer either
 *
 * `IAppManager` says yes to `integriq` throughout this file, including in the
 * two cases where the register carries the old slug or is absent entirely. The
 * app id and the register slug are moved by two SEPARATE repair steps and
 * either can run first, so an instance can answer `integriq` to `IAppManager`
 * while its register row still reads `openconnector`.
 * `testTheAppBeingEnabledDoesNotDecideTheRegisterSlug` holds that distinction
 * in place.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ExternalAdaptersRegisterResolutionTest extends TestCase {

	/**
	 * The register slug the roster read with, captured from the object-service double.
	 *
	 * @var string|null
	 */
	private ?string $readRegister = null;

	/**
	 * How many register reads the roster performed.
	 *
	 * @var int
	 */
	private int $readCount = 0;

	/**
	 * An instance that has run Integriq's rename is read with the new slug.
	 *
	 * @return void
	 */
	public function testAMigratedInstanceIsReadWithItsNewSlug(): void {
		$response = $this->roster(presentSlugs: ['integriq']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('integriq', $this->readRegister);
	}//end testAMigratedInstanceIsReadWithItsNewSlug()

	/**
	 * An instance that has not run it is read with the old slug.
	 *
	 * This case passes both before and after the fix. It is here to prove that
	 * the resolution did not simply swap one literal for another, which would
	 * have moved the breakage to the other half of the estate rather than
	 * removing it.
	 *
	 * @return void
	 */
	public function testAnUnmigratedInstanceIsReadWithItsOldSlug(): void {
		$response = $this->roster(presentSlugs: ['openconnector']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('openconnector', $this->readRegister);
	}//end testAnUnmigratedInstanceIsReadWithItsOldSlug()

	/**
	 * An instance carrying the register under NEITHER slug answers 404.
	 *
	 * The absence has to have an HTTP shape. Before the resolution this path
	 * read `openconnector` regardless, matched nothing, and returned 200 with
	 * fifteen families all reporting `declared-not-provisioned`, a page of
	 * instructions telling an admin to provision Sources inside a register that
	 * is not there. That is indistinguishable from a register that exists and
	 * holds nothing, which is the whole defect.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutTheRegisterAnswers404(): void {
		$response = $this->roster(presentSlugs: []);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(0, $this->readCount, 'Nothing may be read when the register is absent.');

		$body = $response->getData();
		$this->assertSame('connector-register-absent', ($body['error'] ?? null));
		$this->assertSame('integriq', ($body['canonical'] ?? null));
		$this->assertSame(
			['integriq', 'openconnector'],
			($body['candidates'] ?? null),
			'The response must name every slug that was probed, so an admin can see what was looked for.'
		);
		$this->assertArrayNotHasKey(
			'adapters',
			$body,
			'An absent register must not be presented as a roster of adapters.'
		);
	}//end testAnInstanceWithoutTheRegisterAnswers404()

	/**
	 * The app being enabled does not decide which slug the register carries.
	 *
	 * @return void
	 */
	public function testTheAppBeingEnabledDoesNotDecideTheRegisterSlug(): void {
		$this->roster(presentSlugs: ['openconnector']);
		$enabledInstanceReadOldSlug = $this->readRegister;

		$this->readRegister = null;
		$this->readCount = 0;

		$this->roster(presentSlugs: []);

		$this->assertSame(
			'openconnector',
			$enabledInstanceReadOldSlug,
			'An enabled app with an unmigrated register must still be read with the old slug.'
		);
		$this->assertNull(
			$this->readRegister,
			'An enabled app with no register at all must not be read with any slug.'
		);
	}//end testTheAppBeingEnabledDoesNotDecideTheRegisterSlug()

	/**
	 * A resolver that cannot answer is not the same as a register that is absent.
	 *
	 * OpenRegister being unavailable used to fail soft to `unknown` per family,
	 * and it still does. Only a definite "the register is on none of its slugs"
	 * turns into a 404, because only that one is a fact about the instance
	 * rather than a fact about the lookup.
	 *
	 * @return void
	 */
	public function testAResolverThatThrowsStillRendersTheRosterAsUnknown(): void {
		$response = $this->roster(presentSlugs: ['integriq'], resolverThrows: true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $this->readCount, 'No slug, no read.');

		$body = $response->getData();
		$statuses = array_values(
			array_unique(array_map(static fn (array $a): string => $a['provisioning']['status'], $body['adapters']))
		);
		$this->assertSame(['unknown'], $statuses, 'Every family reports unknown, and none reports provisioned.');
		$this->assertSame(15, $body['summary']['total'], 'The roster itself is unaffected.');
	}//end testAResolverThatThrowsStillRendersTheRosterAsUnknown()

	/**
	 * Build the roster against an instance carrying the given register slugs.
	 *
	 * @param list<string> $presentSlugs   The register slugs this instance carries.
	 * @param bool         $resolverThrows Whether the resolver itself fails.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse The index response.
	 */
	private function roster(array $presentSlugs, bool $resolverThrows = false) {
		$logger = $this->createMock(LoggerInterface::class);

		// A duck-typed object service, not a mock of the published contract. The
		// controller resolves it from the container BY STRING
		// ('OCA\OpenRegister\Service\ObjectService'), so what matters is the
		// fluent setRegister/setSchema/findAll shape it actually calls.
		$objectService = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param object $test The test case capturing the read.
			 */
			public function __construct(private readonly object $test) {
			}//end __construct()

			/**
			 * Capture the register the roster read with.
			 *
			 * @param string $register The register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->test->captureRead($register);
				return $this;
			}//end setRegister()

			/**
			 * Accept the schema.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * No source is provisioned in any of these cases.
			 *
			 * @param array<string, mixed> $config The query config.
			 *
			 * @return array<int, mixed> An empty result set.
			 */
			public function findAll(array $config): array {
				return [];
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				// Every adapter port: dormant, which is what the shipped
				// log-only bindings report.
				return new class {
					/**
					 * Report dormant.
					 *
					 * @return bool
					 */
					public function isDormant(): bool {
						return true;
					}//end isDormant()
				};
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$resolver = new FakeSlugResolver($presentSlugs);
		if ($resolverThrows === true) {
			$resolver = new class implements \OCA\OpenRegister\Contract\RegisterSlugResolverInterface {
				/**
				 * Fail the way an absent OpenRegister fails.
				 *
				 * @param string       $canonical  The canonical slug.
				 * @param list<string> $candidates Explicit candidates.
				 *
				 * @return \OCA\OpenRegister\Contract\RegisterSlugResolution Never returns.
				 */
				public function resolve(string $canonical, array $candidates = []): \OCA\OpenRegister\Contract\RegisterSlugResolution {
					throw new RuntimeException('openregister is not available');
				}//end resolve()

				/**
				 * Fail the same way.
				 *
				 * @param string       $canonical  The canonical slug.
				 * @param list<string> $candidates Explicit candidates.
				 *
				 * @return string|null Never returns.
				 */
				public function slugOrNull(string $canonical, array $candidates = []): ?string {
					throw new RuntimeException('openregister is not available');
				}//end slugOrNull()
			};
		}

		$controller = new ExternalAdaptersAdminController(
			$this->createMock(IRequest::class),
			$container,
			$logger,
			$appManager,
			$resolver
		);

		return $controller->index();
	}//end roster()

	/**
	 * Record one register read made by the roster.
	 *
	 * Public because the duck-typed object service above is an anonymous class
	 * and therefore not a member of this one.
	 *
	 * @param string $register The register slug read with.
	 *
	 * @return void
	 */
	public function captureRead(string $register): void {
		$this->readCount++;
		$this->readRegister = $register;
	}//end captureRead()
}//end class
