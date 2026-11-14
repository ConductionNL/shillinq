<?php

/**
 * Unit tests for AbstractDocumentReportGenerator's failure answers and object loading.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-002
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.Commenting.VariableComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A container over a fixed service map.
 */
final class SeamsMapContainer implements ContainerInterface {

	/**
	 * @param array<string, mixed> $services Service id to instance.
	 */
	public function __construct(private readonly array $services = []) {
	}//end __construct()

	public function get(string $id): mixed {
		if (array_key_exists($id, $this->services) === false) {
			throw new RuntimeException('No service ' . $id);
		}

		return $this->services[$id];
	}//end get()

	public function has(string $id): bool {
		return array_key_exists($id, $this->services);
	}//end has()
}//end class

/**
 * An object service recording the scope and query it was given.
 */
final class SeamsObjectService {

	public array $calls = [];

	/**
	 * @param mixed           $rows   What findAll() answers.
	 * @param mixed           $found  What find() answers.
	 * @param \Throwable|null $throws What every read throws, when set.
	 */
	public function __construct(
		private readonly mixed $rows = [],
		private readonly mixed $found = null,
		private readonly ?\Throwable $throws = null,
	) {
	}//end __construct()

	public function setRegister(string $register): self {
		$this->calls[] = ['setRegister', $register];
		return $this;
	}//end setRegister()

	public function setSchema(string $schema): self {
		$this->calls[] = ['setSchema', $schema];
		return $this;
	}//end setSchema()

	public function findAll(array $config = []): mixed {
		$this->calls[] = ['findAll', $config];
		if ($this->throws !== null) {
			throw $this->throws;
		}

		return $this->rows;
	}//end findAll()

	public function find(string $id): mixed {
		$this->calls[] = ['find', $id];
		if ($this->throws !== null) {
			throw $this->throws;
		}

		return $this->found;
	}//end find()
}//end class

/**
 * A serialisable row.
 */
final class SeamsRow implements \JsonSerializable {

	/**
	 * @param array<string, mixed> $data The data.
	 */
	public function __construct(private readonly array $data) {
	}//end __construct()

	public function jsonSerialize(): array {
		return $this->data;
	}//end jsonSerialize()
}//end class

/**
 * A generator that keeps the base's loading and rendering, with docudesk replaced by the given services.
 */
final class SeamsDocumentReportGenerator extends AbstractDocumentReportGenerator {

	/**
	 * @param ContainerInterface $container       The container.
	 * @param object|null        $documentService The docudesk document service.
	 * @param object|null        $templateService The docudesk template service.
	 */
	public function __construct(
		ContainerInterface $container,
		private readonly ?object $documentService = null,
		private readonly ?object $templateService = null,
	) {
		parent::__construct($container);
	}//end __construct()

	public static function reportType(): string {
		return 'balance-sheet';
	}//end reportType()

	protected function documentTitle(): string {
		return 'Balans';
	}//end documentTitle()

	protected function build(ReportSection $section, array $context): void {
		$this->addAmountTable($section, 'Rekening', 'Saldo', []);
	}//end build()

	protected function docudeskAvailable(): bool {
		return true;
	}//end docudeskAvailable()

	protected function documentService(): object {
		return $this->documentService;
	}//end documentService()

	protected function templateService(): object {
		return $this->templateService;
	}//end templateService()

	public function objects(string $schema, array $filters = [], int $limit = 10000): array {
		return $this->loadObjects($schema, $filters, $limit);
	}//end objects()

	public function object(string $schema, string $id): ?array {
		return $this->loadObject($schema, $id);
	}//end object()

	public function registerSlug(): string {
		return $this->register();
	}//end registerSlug()
}//end class

/**
 * Rendering failures are named; loading answers empty instead of throwing; the register comes from app config.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\DocudeskTemplateSelection
 * @uses   \OCA\Shillinq\Kernel\Model\GeneratedFile
 * @uses   \OCA\Shillinq\Reporting\ReportSection
 * @uses   \OCA\Shillinq\Reporting\ReportCatalogue
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class AbstractDocumentReportGeneratorSeamsTest extends TestCase {

	private static function templates(): object {
		return new class {
			public function getTemplatesByNamespace(string $namespace): array {
				return [['id' => 'tpl-1', 'category' => 'shillinq-balans']];
			}
		};
	}//end templates()

	private static function documents(mixed $answer): object {
		return new class ($answer) {
			public array $calls = [];

			public function __construct(private readonly mixed $answer) {
			}

			public function generateDocument(string $templateId, array $dataRefs, array $options = []): array {
				$this->calls[] = [$templateId, $options];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end documents()

	private function appConfig(string $register): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($register): string {
				if ($key === 'register') {
					return $register;
				}

				return $default;
			}
		);

		return $config;
	}//end appConfig()

	public function testAFailingRenderIsNamedWithItsCause(): void {
		$cause     = new RuntimeException('renderer crashed');
		$generator = new SeamsDocumentReportGenerator(new SeamsMapContainer(), self::documents($cause), self::templates());

		try {
			$generator->generate(['period' => '2026'], 'pdf');
			$this->fail('A failing render was not reported.');
		} catch (RuntimeException $e) {
			$this->assertSame('Docudesk rendering failed: renderer crashed', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testAFailingRenderIsNamedWithItsCause()

	public function testAnEmptyRenderIsRefused(): void {
		$documents = self::documents(['content' => '']);
		$generator = new SeamsDocumentReportGenerator(new SeamsMapContainer(), $documents, self::templates());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Docudesk returned no document content for "balance-sheet".');
		$generator->generate([], 'pdf');
	}//end testAnEmptyRenderIsRefused()

	public function testAnEmptyAmountTableBecomesANote(): void {
		$documents = self::documents(['content' => 'BYTES']);
		$generator = new SeamsDocumentReportGenerator(new SeamsMapContainer(), $documents, self::templates());

		$file = $generator->generate(['administrationId' => 'Gemeente Voorbeeld', 'period' => '2026 Q1'], 'odt');

		$this->assertSame('BYTES', $file->content);
		$this->assertSame('balance-sheet-2026-Q1-Gemeente-Voorbeeld.odt', $file->fileName);
		$this->assertSame('tpl-1', $documents->calls[0][0]);
		$this->assertSame(
			[['type' => 'note', 'text' => 'Geen posten in deze periode.']],
			$documents->calls[0][1]['adHocData']['report']['blocks']
		);
		$this->assertSame('system', $documents->calls[0][1]['userId']);
	}//end testAnEmptyAmountTableBecomesANote()

	public function testAFailingTemplateLookupIsNamed(): void {
		$templates = new class {
			public function getTemplatesByNamespace(string $namespace): array {
				throw new RuntimeException('docudesk database offline');
			}
		};
		$documents = self::documents(['content' => 'BYTES']);
		$generator = new SeamsDocumentReportGenerator(new SeamsMapContainer(), $documents, $templates);

		try {
			$generator->generate([], 'pdf');
			$this->fail('A failing template lookup was not reported.');
		} catch (RuntimeException $e) {
			$this->assertSame('Could not look up docudesk templates: docudesk database offline', $e->getMessage());
		}

		$this->assertSame([], $documents->calls);
	}//end testAFailingTemplateLookupIsNamed()

	public function testObjectsAreLoadedFromTheConfiguredRegisterAndNormalised(): void {
		$service   = new SeamsObjectService(rows: [['id' => 'a'], new SeamsRow(['id' => 'b']), 'junk', 7]);
		$generator = new SeamsDocumentReportGenerator(
			new SeamsMapContainer(
				[
					'OCA\\OpenRegister\\Service\\ObjectService' => $service,
					IAppConfig::class                         => $this->appConfig('ledger'),
				]
			)
		);

		$rows = $generator->objects('JournalLine', ['administrationId' => 'adm-1'], 50);

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $rows);
		$this->assertSame(
			[['setRegister', 'ledger'], ['setSchema', 'JournalLine'], ['findAll', ['limit' => 50, 'filters' => ['administrationId' => 'adm-1']]]],
			$service->calls
		);
	}//end testObjectsAreLoadedFromTheConfiguredRegisterAndNormalised()

	public function testLoadingWithoutFiltersSendsOnlyTheLimit(): void {
		$service   = new SeamsObjectService(rows: 'not a list');
		$generator = new SeamsDocumentReportGenerator(new SeamsMapContainer(['OCA\\OpenRegister\\Service\\ObjectService' => $service]));

		$this->assertSame([], $generator->objects('Account'));
		$this->assertSame(['findAll', ['limit' => 10000]], $service->calls[2]);
		$this->assertSame(['setRegister', 'shillinq'], $service->calls[0], 'Without app config the register is shillinq');
	}//end testLoadingWithoutFiltersSendsOnlyTheLimit()

	public function testLoadingAnswersEmptyWhenTheStoreIsMissingOrFails(): void {
		$missing = new SeamsDocumentReportGenerator(new SeamsMapContainer());
		$failing = new SeamsDocumentReportGenerator(
			new SeamsMapContainer(['OCA\\OpenRegister\\Service\\ObjectService' => new SeamsObjectService(throws: new RuntimeException('down'))])
		);

		$this->assertSame([], $missing->objects('Account'));
		$this->assertNull($missing->object('Account', 'a-1'));
		$this->assertSame([], $failing->objects('Account'));
		$this->assertNull($failing->object('Account', 'a-1'));
	}//end testLoadingAnswersEmptyWhenTheStoreIsMissingOrFails()

	public function testASingleObjectIsReadAsAnArray(): void {
		$service   = new SeamsObjectService(found: new SeamsRow(['id' => 'a-1', 'name' => 'Kas']));
		$generator = new SeamsDocumentReportGenerator(new SeamsMapContainer(['OCA\\OpenRegister\\Service\\ObjectService' => $service]));

		$this->assertSame(['id' => 'a-1', 'name' => 'Kas'], $generator->object('Account', 'a-1'));
		$this->assertSame(['find', 'a-1'], $service->calls[2]);
	}//end testASingleObjectIsReadAsAnArray()

	public function testABlankIdOrAMissIsNull(): void {
		$service   = new SeamsObjectService(found: null);
		$generator = new SeamsDocumentReportGenerator(new SeamsMapContainer(['OCA\\OpenRegister\\Service\\ObjectService' => $service]));

		$this->assertNull($generator->object('Account', '   '));
		$this->assertSame([], $service->calls, 'A blank id is not looked up');
		$this->assertNull($generator->object('Account', 'gone'));
	}//end testABlankIdOrAMissIsNull()

	public function testAnEmptyConfiguredRegisterFallsBackToShillinq(): void {
		$generator = new SeamsDocumentReportGenerator(new SeamsMapContainer([IAppConfig::class => $this->appConfig('')]));

		$this->assertSame('shillinq', $generator->registerSlug());
		$configured = new SeamsDocumentReportGenerator(new SeamsMapContainer([IAppConfig::class => $this->appConfig('ledger')]));
		$this->assertSame('ledger', $configured->registerSlug());
	}//end testAnEmptyConfiguredRegisterFallsBackToShillinq()
}//end class
