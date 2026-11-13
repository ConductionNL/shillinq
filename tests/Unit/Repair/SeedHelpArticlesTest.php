<?php

/**
 * Seeding the user guide: a new file is created, a changed one updated in
 * place, an unchanged one left alone (REQ-PHA-001).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-help-agent/specs/shillinq-mcp-tool-surface/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\SeedHelpArticles;
use OCA\Shillinq\Service\Help\HelpArticleSource;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SeedHelpArticles over a temporary app root and a mocked object service.
 */
#[CoversClass(SeedHelpArticles::class)]
#[UsesClass(HelpArticleSource::class)]
#[UsesClass(ObjectIdentifier::class)]
final class SeedHelpArticlesTest extends TestCase {

	/**
	 * The temporary app root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The saves made, as [sourcePath => uuid|null].
	 *
	 * @var array<string, string|null>
	 */
	private array $saves = [];

	/**
	 * A guide of three pages and a manifest linking one of them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/shillinq-seed-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/docs/user-guide/bookkeeping', 0777, true);
		mkdir($this->root . '/src', 0777, true);
		file_put_contents($this->root . '/docs/user-guide/bookkeeping/year-end.md', "# Periode afsluiten\n\nNew steps.\n");
		file_put_contents($this->root . '/docs/user-guide/bookkeeping/vat.md', "# Btw-aangifte voorbereiden\n");
		file_put_contents($this->root . '/docs/user-guide/bookkeeping/invoices.md', "# Een verkoopfactuur maken\n");
		file_put_contents(
			$this->root . '/src/manifest.json',
			json_encode(['pages' => [['id' => 'PeriodClose', 'config' => ['documentationUrl' => 'https://shillinq.conduction.nl/user-guide/bookkeeping/year-end']]]])
		);

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('runAsSystem')->willReturnCallback(static fn (callable $work): mixed => $work());
		$entity = $this->createMock(ObjectEntityInterface::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use ($entity): ObjectEntityInterface {
				if ($object['sourcePath'] === 'docs/user-guide/bookkeeping/invoices.md' && $uuid === 'boom') {
					throw new RuntimeException('save failed');
				}

				$this->saves[$object['sourcePath']] = $uuid;
				return $entity;
			}
		);
	}//end setUp()

	/**
	 * Remove the temporary app root.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
		parent::tearDown();
	}//end tearDown()

	/**
	 * The step over the temporary root.
	 *
	 * @param bool $openRegister Whether OpenRegister is available.
	 *
	 * @return SeedHelpArticles The step.
	 */
	private function step(bool $openRegister = true): SeedHelpArticles {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('isOpenRegisterAvailable')->willReturn($openRegister);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new SeedHelpArticles($location, $this->objects, $this->createMock(LoggerInterface::class), new HelpArticleSource(), $this->root);
	}//end step()

	/**
	 * The stored article rows the object service hands back.
	 *
	 * @param array<int, array<string, string>> $rows The rows.
	 *
	 * @return void
	 */
	private function stored(array $rows): void {
		$this->objects->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => array_slice($rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 200))
		);
	}//end stored()

	/**
	 * A new file is created, a changed one updated by its uuid, an unchanged one skipped.
	 *
	 * @return void
	 */
	public function testNewChangedAndUnchangedFiles(): void {
		$vat = (string)file_get_contents($this->root . '/docs/user-guide/bookkeeping/vat.md');
		$this->stored([
			['id' => 'uuid-year-end', 'sourcePath' => 'docs/user-guide/bookkeeping/year-end.md', 'sourceHash' => hash('sha256', "# Periode afsluiten\n\nOld steps.\n")],
			['id' => 'uuid-vat', 'sourcePath' => 'docs/user-guide/bookkeeping/vat.md', 'sourceHash' => hash('sha256', $vat)],
		]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Help articles: 1 created, 1 updated, 1 unchanged, 0 failed.');

		$this->step()->run($output);

		self::assertSame(
			['docs/user-guide/bookkeeping/invoices.md' => null, 'docs/user-guide/bookkeeping/year-end.md' => 'uuid-year-end'],
			$this->saves
		);
	}//end testNewChangedAndUnchangedFiles()

	/**
	 * The saved article carries the new body and the page ids from the manifest.
	 *
	 * @return void
	 */
	public function testTheSavedArticleCarriesTheNewTextAndItsPages(): void {
		$this->stored([]);
		$saved = [];
		$entity = $this->createMock(ObjectEntityInterface::class);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn([]);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $work): mixed => $work());
		$objects->expects($this->exactly(3))->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true) use (&$saved, $entity): ObjectEntityInterface {
				$saved[$object['sourcePath']] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'tenancy' => $_multitenancy];
				return $entity;
			}
		);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('isOpenRegisterAvailable')->willReturn(true);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		(new SeedHelpArticles($location, $objects, $this->createMock(LoggerInterface::class), new HelpArticleSource(), $this->root))->run($this->createMock(IOutput::class));

		$yearEnd = $saved['docs/user-guide/bookkeeping/year-end.md'];
		self::assertSame(['register' => 'shillinq', 'schema' => 'HelpArticle', 'rbac' => false, 'tenancy' => false], array_diff_key($yearEnd, ['object' => 1]));
		self::assertSame("# Periode afsluiten\n\nNew steps.\n", $yearEnd['object']['body']);
		self::assertSame(['PeriodClose'], $yearEnd['object']['pageIds']);
		self::assertSame([], $saved['docs/user-guide/bookkeeping/vat.md']['object']['pageIds']);
	}//end testTheSavedArticleCarriesTheNewTextAndItsPages()

	/**
	 * A failing save is counted and logged; the other articles still save.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsCountedNotFatal(): void {
		$this->stored([['id' => 'boom', 'sourcePath' => 'docs/user-guide/bookkeeping/invoices.md', 'sourceHash' => 'old']]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Help articles: 2 created, 0 updated, 0 unchanged, 1 failed.');

		$this->step()->run($output);

		self::assertCount(2, $this->saves);
	}//end testAFailingSaveIsCountedNotFatal()

	/**
	 * Without OpenRegister, or without a guide, nothing is read or saved.
	 *
	 * @return void
	 */
	public function testNothingIsSeededWithoutOpenRegisterOrAGuide(): void {
		$this->objects->expects($this->never())->method('findAll');
		$this->objects->expects($this->never())->method('saveObject');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly(2))->method('info');

		$this->step(openRegister: false)->run($output);
		exec('rm -rf ' . escapeshellarg($this->root . '/docs'));
		$this->step()->run($output);
	}//end testNothingIsSeededWithoutOpenRegisterOrAGuide()
}//end class
