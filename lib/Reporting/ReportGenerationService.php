<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Report generation orchestration service
 *
 * The orchestration layer behind "Reporting & Compliance". It AUTO-DISCOVERS the
 * per-report-type generators (lib/Reporting/Generator/*.php implementing
 * ReportGeneratorInterface, indexed by ::reportType() — the same glob-and-filter
 * discovery style as OCA\Shillinq\Standards\RuleEngine::providers()), runs the one
 * whose reportType() matches the requested report, persists the rendered bytes as a
 * file in Nextcloud Files under /Shillinq/Reports/<administrationId>/, applies the
 * system tags that let the generated-reports index find the file (report type,
 * period, administration, category) and records a GeneratedReport object pointing at
 * it. Listing reads those GeneratedReport records back through OpenRegister.
 *
 * Persistence of GeneratedReport records goes through OpenRegister's ObjectService
 * (resolved from the container). DOCUMENT-kind generators hand off rendering to
 * docudesk (`OCA\DocuDesk\Service\DocumentService`, resolved by string FQCN inside
 * the generator — ADR-075); DATA-kind generators render bytes natively (XMLWriter/
 * fputcsv). Either way this service only moves the rendered bytes to Files and
 * records metadata — it never `use`s an office/PDF/docudesk class itself and adds
 * nothing to shillinq's composer.
 *
 * A DocudeskUnavailableException from a DOCUMENT generator is handled distinctly
 * from any other generator failure (REQ-RVD-005, ADR-081 rule 7): the attempt is
 * still recorded — `status: 'unavailable'` — so a docudesk outage is a visible,
 * findable state rather than a silent drop.
 *
 * Every external call (container, Files, tags, object persistence) is fail-soft:
 * failures are logged as warnings and degrade gracefully rather than crash the
 * request, mirroring the resilient posture of the rest of the bookkeeping stack.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.Commenting.BlockComment, Squiz.Operators.ComparisonOperatorUsage, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

use OCA\Shillinq\Kernel\Contracts\ReportGenerationServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use OCA\Shillinq\Kernel\Model\GeneratedFile;

/**
 * Discovers generators, renders a report, stores + tags the file, records it.
 */
class ReportGenerationService implements ReportGenerationServiceInterface {

	/**
	 * The OpenRegister register slug shillinq's objects live under.
	 */
	private const REGISTER = 'shillinq';

	/**
	 * The GeneratedReport schema slug.
	 */
	private const SCHEMA = 'GeneratedReport';

	/**
	 * Memoised generator index (reportType => instance).
	 *
	 * @var array<string, ReportGeneratorInterface>|null
	 */
	private ?array $generators = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container App container —
	 *                                      lazily resolves
	 *                                      OpenRegister's
	 *                                      ObjectService.
	 * @param IRootFolder $rootFolder Nextcloud Files root
	 *                                — the report bytes
	 *                                are written here.
	 * @param ISystemTagManager $tagManager Resolves/creates the system
	 *                                      tags applied to the stored
	 *                                      file.
	 * @param ISystemTagObjectMapper $tagMapper Maps the resolved tags onto the
	 *                                          stored file id.
	 * @param IUserSession $userSession Current user —
	 *                                  records who generated
	 *                                  the report + the
	 *                                  storage home to write
	 *                                  into.
	 * @param LoggerInterface $logger Fail-soft warning logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IRootFolder $rootFolder,
		private readonly ISystemTagManager $tagManager,
		private readonly ISystemTagObjectMapper $tagMapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Generate a report: render it, store + tag the file, record a GeneratedReport.
	 *
	 * @param string $reportType ReportCatalogue report-type id (e.g. 'vat-return').
	 * @param string $period Reporting period (e.g. '2026', '2026-Q1', '2026-03').
	 * @param string $administrationId Administration the report is generated for.
	 * @param string $format One of the report's catalogue formats.
	 * @param string|null $asUserId The user the report is produced for when there
	 *                              is no session (a scheduled run); null = the session user.
	 *
	 * @return array<string, mixed> The recorded GeneratedReport (incl. fileId + downloadPath),
	 *                              or an `{ error: ... }` envelope when generation cannot proceed.
	 *
	 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function generate(string $reportType, string $period, string $administrationId, string $format, ?string $asUserId=null): array {
		$userId = ($asUserId ?? ($this->userSession->getUser()?->getUID() ?? ''));

		$prepared = $this->prepareGeneration(reportType: $reportType, administrationId: $administrationId, format: $format);
		if (isset($prepared['error']) === true) {
			return $prepared;
		}

		$generator = $prepared['generator'];
		$useFormat = $prepared['format'];

		$context = [
			'reportType' => $reportType,
			'period' => $period,
			'administrationId' => $administrationId,
		];

		try {
			$rendered = $generator->generate($context, $useFormat);
		} catch (DocudeskUnavailableException $e) {
			return $this->recordDocudeskUnavailable(exception: $e, prepared: $prepared, context: $context, userId: $userId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ReportGenerationService: generator failed',
				['reportType' => $reportType, 'exception' => $e->getMessage()]
			);
			return ['error' => 'generation-failed', 'reportType' => $reportType, 'message' => $e->getMessage()];
		}

		return $this->storeAndRecord(rendered: $rendered, catalogue: $prepared['catalogue'], context: $context, userId: $userId);
	}//end generate()

	/**
	 * Validate the request and resolve the catalogue entry, generator and format to render with.
	 *
	 * @param string $reportType       ReportCatalogue report-type id.
	 * @param string $administrationId Administration the report is generated for.
	 * @param string $format           The requested format.
	 *
	 * @return array<string, mixed> `{ catalogue, generator, format }`, or an `{ error: ... }` envelope.
	 */
	private function prepareGeneration(string $reportType, string $administrationId, string $format): array {
		// The generators read every administration's ledger when none is named.
		if (trim($administrationId) === '') {
			return ['error' => 'missing-administration', 'reportType' => $reportType];
		}

		$catalogue = ReportCatalogue::byId($reportType);
		if ($catalogue === null) {
			$this->logger->warning('ReportGenerationService: unknown report type', ['reportType' => $reportType]);
			return ['error' => 'unknown-report-type', 'reportType' => $reportType];
		}

		$generator = $this->generators()[$reportType] ?? null;
		if ($generator === null) {
			$this->logger->warning('ReportGenerationService: no generator for report type', ['reportType' => $reportType]);
			return ['error' => 'no-generator', 'reportType' => $reportType];
		}

		return [
			'catalogue' => $catalogue,
			'generator' => $generator,
			'format' => $this->resolveFormat(generator: $generator, format: $format),
		];
	}//end prepareGeneration()

	/**
	 * The requested format when the generator supports it, otherwise its preferred (first) supported format.
	 *
	 * @param ReportGeneratorInterface $generator The generator to render with.
	 * @param string                   $format    The requested format.
	 *
	 * @return string The format to render in.
	 */
	private function resolveFormat(ReportGeneratorInterface $generator, string $format): string {
		// Honour the requested format only when the generator supports it; otherwise
		// fall back to its preferred (first) supported format.
		$supported = $generator::supportedFormats();
		$useFormat = $format;
		if (in_array($format, $supported, true) === false) {
			$useFormat = ($supported[0] ?? $format);
		}

		return $useFormat;
	}//end resolveFormat()

	/**
	 * Record an 'unavailable' GeneratedReport when docudesk is absent and return the error envelope.
	 *
	 * @param DocudeskUnavailableException $exception The generator's unavailability error.
	 * @param array<string, mixed>         $prepared  The `{ catalogue, generator, format }` of this run.
	 * @param array<string, string>        $context   The reportType, period and administrationId.
	 * @param string                       $userId    The user the report is produced for.
	 *
	 * @return array<string, mixed> The `docudesk-unavailable` envelope with the recorded attempt.
	 */
	private function recordDocudeskUnavailable(DocudeskUnavailableException $exception, array $prepared, array $context, string $userId): array {
		$catalogue = $prepared['catalogue'];
		$reportType = $context['reportType'];

		// ADR-081 rule 7: a source app MUST degrade gracefully when the
		// receiver is absent -- an unsent allocation is a visible pending
		// state, never a silent drop. Record the attempt (status:
		// 'unavailable') before returning, so it is findable via
		// listGenerated() rather than disappearing with the HTTP response.
		$this->logger->warning(
			'ReportGenerationService: docudesk unavailable, report generation deferred',
			['reportType' => $reportType, 'exception' => $exception->getMessage()]
		);

		$record = $this->saveRecord(
			[
				'reportType' => $reportType,
				'reportLabel' => (string)($catalogue['label'] ?? $reportType),
				'category' => (string)($catalogue['category'] ?? ''),
				'period' => $context['period'],
				'administrationId' => $context['administrationId'],
				'format' => $prepared['format'],
				'status' => 'unavailable',
				'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				'generatedBy' => $userId,
			]
		);

		return [
			'error' => 'docudesk-unavailable',
			'reportType' => $reportType,
			'message' => $exception->getMessage(),
			'record' => $record,
		];
	}//end recordDocudeskUnavailable()

	/**
	 * Store and tag the rendered file, then record the ready GeneratedReport.
	 *
	 * @param GeneratedFile         $rendered  The rendered report.
	 * @param array<string, mixed>  $catalogue The report's catalogue entry.
	 * @param array<string, string> $context   The reportType, period and administrationId.
	 * @param string                $userId    The user the report is produced for.
	 *
	 * @return array<string, mixed> The recorded GeneratedReport incl. downloadPath.
	 */
	private function storeAndRecord(GeneratedFile $rendered, array $catalogue, array $context, string $userId): array {
		$reportType = $context['reportType'];
		$period = $context['period'];
		$administrationId = $context['administrationId'];

		$stored = $this->storeFile($administrationId, $rendered, $userId);

		$category = (string)($catalogue['category'] ?? '');
		$label = (string)($catalogue['label'] ?? $reportType);

		$tags = [
			'shillinq-report:' . $reportType,
			'shillinq-period:' . $period,
			'shillinq-administration:' . $administrationId,
			'shillinq-category:' . $category,
		];

		if ($stored['fileId'] !== null) {
			$this->fileStore()->applyTags(fileId: $stored['fileId'], tags: $tags);
		}

		$record = [
			'reportType' => $reportType,
			'reportLabel' => $label,
			'category' => $category,
			'period' => $period,
			'administrationId' => $administrationId,
			'format' => $rendered->format,
			'fileName' => $rendered->fileName,
			'filePath' => $stored['filePath'],
			'fileId' => $stored['fileId'],
			'sizeBytes' => strlen($rendered->content),
			'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'generatedBy' => $userId,
			'status' => 'ready',
			'tags' => $tags,
		];

		$saved = $this->saveRecord($record);

		// Surface a stable download path for the UI regardless of persistence result.
		$saved['downloadPath'] = '/index.php/apps/shillinq/api/reporting/download/' . ((string)($saved['id'] ?? ''));

		return $saved;
	}//end storeAndRecord()

	/**
	 * List recorded GeneratedReport records, optionally filtered.
	 *
	 * @param array<string, mixed> $filters Optional filters: reportType, period,
	 *                                      administrationId, category.
	 *
	 * @return array<int, mixed> The matching GeneratedReport records (empty on failure).
	 */
	public function listGenerated(array $filters): array {
		$allowed = [];
		foreach (['reportType', 'period', 'administrationId', 'category'] as $key) {
			$value = ($filters[$key] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				$allowed[$key] = trim($value);
			}
		}

		try {
			$objectService = $this->objectService();
			if ($objectService === null) {
				return [];
			}

			$results = $objectService
				->setRegister(self::REGISTER)
				->setSchema(self::SCHEMA)
				->findAll(['filters' => $allowed]);

			return is_array($results) === true ? $results : [];
		} catch (\Throwable $e) {
			$this->logger->warning('ReportGenerationService: listGenerated failed', ['exception' => $e->getMessage()]);
			return [];
		}

	}//end listGenerated()

	/**
	 * Resolve the stored Nextcloud File for a GeneratedReport id.
	 *
	 * @param string $id The GeneratedReport id.
	 *
	 * @return File|null The stored file, or null when the record/file is not resolvable.
	 */
	public function resolveFile(string $id): ?File {
		$record = $this->findRecord($id);
		if ($record === null) {
			return null;
		}

		return $this->resolveRecordFile(record: $record);
	}//end resolveFile()

	/**
	 * Resolve the stored Nextcloud File for an ALREADY-AUTHORISED GeneratedReport record.
	 *
	 * ⚠️ This method performs NO authorisation. The caller must have established
	 * that the current user may read `$record` — in practice
	 * `AdministrationContextService::canAccess($record['administrationId'])` —
	 * BEFORE calling it. It is split out from resolveFile() precisely so the
	 * caller can interpose that check between "load the record" and "read the
	 * bytes" (ADR-005; see ReportingController::download()).
	 *
	 * Resolution order, tightest first:
	 *  1. the CURRENT user's Files home — the same home storeFile() writes into
	 *     (`:410 getUserFolder($userId)`), so this is the path a report's own
	 *     author always takes;
	 *  2. the instance-wide file cache, but only for a node whose path is exactly
	 *     the `filePath` recorded on the report. Previously this branch was an
	 *     unconstrained `IRootFolder::getById()` followed by an unconstrained
	 *     `IRootFolder::get($path)`, which resolves across EVERY user's storage.
	 *     It is retained (narrowed) so a colleague in the same administration can
	 *     still download a report a co-worker generated.
	 *
	 * @param array<string,mixed> $record The GeneratedReport record.
	 *
	 * @return File|null The stored file, or null when the record/file is not resolvable.
	 */
	public function resolveRecordFile(array $record): ?File {
		$fileId = ($record['fileId'] ?? null);
		$path = ($record['filePath'] ?? null);
		$userId = ($this->userSession->getUser()?->getUID() ?? '');

		$inHome = $this->fileInUserHome(fileId: $fileId, userId: $userId);
		if ($inHome !== null) {
			return $inHome;
		}

		if ($this->hasRecordedPath(fileId: $fileId, path: $path) === true) {
			return $this->fileAtRecordedPath(fileId: $fileId, path: $path);
		}

		return null;
	}//end resolveRecordFile()

	/**
	 * Whether the record carries a numeric fileId and a non-empty filePath.
	 *
	 * @param mixed $fileId The recorded fileId.
	 * @param mixed $path   The recorded filePath.
	 *
	 * @return bool True when both are usable for the instance-wide lookup.
	 */
	private function hasRecordedPath(mixed $fileId, mixed $path): bool {
		return is_numeric($fileId) === true && is_string($path) === true && $path !== '';
	}//end hasRecordedPath()

	/**
	 * Resolve a report file by id in the current user's Files home.
	 *
	 * @param mixed  $fileId The recorded fileId.
	 * @param string $userId The current user, or '' without a session.
	 *
	 * @return File|null The file, or null when it is not in the user's home.
	 */
	private function fileInUserHome(mixed $fileId, string $userId): File|null {
		if (is_numeric($fileId) === true && $userId !== '') {
			try {
				return $this->firstFile(nodes: $this->rootFolder->getUserFolder($userId)->getById((int)$fileId));
			} catch (\Throwable $e) {
				$this->logger->warning(
					'ReportGenerationService: report file not resolvable in the user home',
					['fileId' => $fileId, 'exception' => $e->getMessage()]
				);
			}
		}

		return null;
	}//end fileInUserHome()

	/**
	 * The first File among the given nodes.
	 *
	 * @param iterable<mixed> $nodes The nodes to search.
	 *
	 * @return File|null The first File, or null when there is none.
	 */
	private function firstFile(iterable $nodes): File|null {
		foreach ($nodes as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}//end firstFile()

	/**
	 * Resolve a report file by id anywhere on the instance, only at exactly its recorded path.
	 *
	 * @param mixed  $fileId The recorded fileId (numeric).
	 * @param string $path   The recorded filePath.
	 *
	 * @return File|null The file, or null (logged) when no node with that id sits at that path.
	 */
	private function fileAtRecordedPath(mixed $fileId, string $path): File|null {
		foreach ($this->rootFolder->getById((int)$fileId) as $node) {
			// The recorded path is server-written by storeFile(); requiring an
			// exact match stops a stale or tampered fileId resolving to some
			// unrelated node elsewhere on the instance.
			if ($node instanceof File && $node->getPath() === $path) {
				return $node;
			}
		}

		$this->logger->warning(
			'ReportGenerationService: stored report file missing',
			['fileId' => $fileId, 'path' => $path]
		);

		return null;
	}//end fileAtRecordedPath()

	/**
	 * Look up a single GeneratedReport record by id.
	 *
	 * @param string $id The GeneratedReport id.
	 *
	 * @return array<string, mixed>|null The record, or null when missing.
	 */
	public function findRecord(string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		try {
			$objectService = $this->objectService();
			if ($objectService === null) {
				return null;
			}

			$object = ObjectIdentifier::findScoped(
				scoped: $objectService->setRegister(self::REGISTER)->setSchema(self::SCHEMA),
				id: $id
			);

			if ($object === null) {
				return null;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				return (array)$object->jsonSerialize();
			}

			return (array)$object;
		} catch (\Throwable $e) {
			$this->logger->warning('ReportGenerationService: findRecord failed', ['id' => $id, 'exception' => $e->getMessage()]);
			return null;
		}//end try

	}//end findRecord()

	/**
	 * Discover and index the report generators (memoised).
	 *
	 * Mirrors RuleEngine::providers(): glob lib/Reporting/Generator/*.php, keep the
	 * classes that implement ReportGeneratorInterface, instantiate each and key it
	 * by its declared reportType().
	 *
	 * @return array<string, ReportGeneratorInterface>
	 */
	private function generators(): array {
		if ($this->generators !== null) {
			return $this->generators;
		}

		$found = [];
		foreach ((glob(__DIR__ . '/Generator/*.php') ?: []) as $file) {
			$class = '\\OCA\\Shillinq\\Reporting\\Generator\\' . basename($file, '.php');
			if (self::isGeneratorClass(class: $class) === false) {
				continue;
			}

			try {
				// Resolve via the container so generators that declare constructor
				// DI (ContainerInterface/LoggerInterface) are autowired, while no-arg
				// generators instantiate just the same.
				/*
				 * @var ReportGeneratorInterface $instance
				 */
				$instance = $this->container->get($class);
				$found[$instance::reportType()] = $instance;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'ReportGenerationService: failed to instantiate generator',
					['class' => $class, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		$this->generators = $found;
		return $found;
	}//end generators()

	/**
	 * Whether the class exists, implements ReportGeneratorInterface and can be instantiated.
	 *
	 * @param string $class The fully qualified class name.
	 *
	 * @return bool True for a concrete generator.
	 */
	private static function isGeneratorClass(string $class): bool {
		if (class_exists($class) === false) {
			return false;
		}

		if (in_array(ReportGeneratorInterface::class, class_implements($class), true) === false) {
			return false;
		}

		// Skip the abstract document base (matches the glob + interface but is not instantiable).
		return (new ReflectionClass($class))->isInstantiable();
	}//end isGeneratorClass()

	/**
	 * Write the rendered bytes to Nextcloud Files under the user's
	 * /Shillinq/Reports/<administrationId>/ folder (folders created as needed).
	 *
	 * @param string $administrationId Administration the file is scoped to.
	 * @param GeneratedFile $rendered The rendered report payload.
	 * @param string $userId The owning user id (storage home).
	 *
	 * @return array{filePath: string|null, fileId: int|null} The stored path + id (nulls on failure).
	 */
	private function storeFile(string $administrationId, GeneratedFile $rendered, string $userId): array {
		if ($userId === '') {
			$this->logger->warning('ReportGenerationService: no user session, cannot store report file');
			return ['filePath' => null, 'fileId' => null];
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);

			$segments = ['Shillinq', 'Reports'];
			if (trim($administrationId) !== '') {
				$segments[] = $administrationId;
			}

			$folder = $this->fileStore()->ensureFolder(base: $userFolder, segments: $segments);
			if ($folder === null) {
				return ['filePath' => null, 'fileId' => null];
			}

			$fileName = $this->fileStore()->uniqueName(folder: $folder, name: $rendered->fileName);

			$file = $folder->newFile($fileName, $rendered->content);

			return ['filePath' => $file->getPath(), 'fileId' => $file->getId()];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ReportGenerationService: failed to store report file',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return ['filePath' => null, 'fileId' => null];
		}//end try

	}//end storeFile()

	/**
	 * The store this service writes its files through, logging in its own words.
	 *
	 * @return TaggedFileStore
	 */
	private function fileStore(): TaggedFileStore {
		$messages = new TaggedFileStoreMessages(
			notFolder: 'ReportGenerationService: report path segment is not a folder',
			tagFailed: 'ReportGenerationService: failed to apply system tag',
		);
		$tags = new SystemTagAssigner(tagManager: $this->tagManager, tagMapper: $this->tagMapper);
		return new TaggedFileStore(tags: $tags, log: new TaggedFileStoreLog(logger: $this->logger, messages: $messages));
	}//end fileStore()

	/**
	 * Persist a GeneratedReport record through OpenRegister, returning it as an
	 * array (the input record on failure, so the caller still gets a useful shape).
	 *
	 * @param array<string, mixed> $record The GeneratedReport fields.
	 *
	 * @return array<string, mixed> The saved record (with id), or the input on failure.
	 */
	private function saveRecord(array $record): array {
		try {
			$objectService = $this->objectService();
			if ($objectService === null) {
				return $record;
			}

			$saved = $objectService->saveObject(
				object: $record,
				register: self::REGISTER,
				schema: self::SCHEMA,
			);

			if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
				return (array)$saved->jsonSerialize();
			}

			return (array)$saved;
		} catch (\Throwable $e) {
			$this->logger->warning('ReportGenerationService: failed to record GeneratedReport', ['exception' => $e->getMessage()]);
			return $record;
		}//end try

	}//end saveRecord()

	/**
	 * Lazily resolve OpenRegister's ObjectService from the container (null on miss).
	 *
	 * @return object|null The ObjectService, or null when unavailable.
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning('ReportGenerationService: ObjectService unavailable', ['exception' => $e->getMessage()]);
			return null;
		}

	}//end objectService()
}//end class
