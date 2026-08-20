<?php

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

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Discovers generators, renders a report, stores + tags the file, records it.
 */
class ReportGenerationService {

	/**
	 * The OpenRegister register slug shillinq's objects live under.
	 */
	private const REGISTER = 'shillinq';

	/**
	 * The GeneratedReport schema slug.
	 */
	private const SCHEMA = 'GeneratedReport';

	/**
	 * The Nextcloud object type the system tags are mapped against.
	 */
	private const TAG_OBJECT_TYPE = 'files';

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
	 *
	 * @return array<string, mixed> The recorded GeneratedReport (incl. fileId + downloadPath),
	 *                              or an `{ error: ... }` envelope when generation cannot proceed.
	 *
	 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
	 */
	public function generate(string $reportType, string $period, string $administrationId, string $format): array {
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

		// Honour the requested format only when the generator supports it; otherwise
		// fall back to its preferred (first) supported format.
		$supported = $generator::supportedFormats();
		$useFormat = $format;
		if (in_array($format, $supported, true) === false) {
			$useFormat = ($supported[0] ?? $format);
		}

		$context = [
			'reportType' => $reportType,
			'period' => $period,
			'administrationId' => $administrationId,
		];

		try {
			$rendered = $generator->generate($context, $useFormat);
		} catch (DocudeskUnavailableException $e) {
			// ADR-081 rule 7: a source app MUST degrade gracefully when the
			// receiver is absent -- an unsent allocation is a visible pending
			// state, never a silent drop. Record the attempt (status:
			// 'unavailable') before returning, so it is findable via
			// listGenerated() rather than disappearing with the HTTP response.
			$this->logger->warning(
				'ReportGenerationService: docudesk unavailable, report generation deferred',
				['reportType' => $reportType, 'exception' => $e->getMessage()]
			);

			$record = $this->saveRecord(
				[
					'reportType' => $reportType,
					'reportLabel' => (string)($catalogue['label'] ?? $reportType),
					'category' => (string)($catalogue['category'] ?? ''),
					'period' => $period,
					'administrationId' => $administrationId,
					'format' => $useFormat,
					'status' => 'unavailable',
					'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
					'generatedBy' => ($this->userSession->getUser()?->getUID() ?? ''),
				]
			);

			return [
				'error' => 'docudesk-unavailable',
				'reportType' => $reportType,
				'message' => $e->getMessage(),
				'record' => $record,
			];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ReportGenerationService: generator failed',
				['reportType' => $reportType, 'exception' => $e->getMessage()]
			);
			return ['error' => 'generation-failed', 'reportType' => $reportType, 'message' => $e->getMessage()];
		}

		$userId = ($this->userSession->getUser()?->getUID() ?? '');

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
			$this->applyTags($stored['fileId'], $tags);
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
	}//end generate()

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

		if (is_numeric($fileId) === true && $userId !== '') {
			try {
				foreach ($this->rootFolder->getUserFolder($userId)->getById((int)$fileId) as $node) {
					if ($node instanceof File) {
						return $node;
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'ReportGenerationService: report file not resolvable in the user home',
					['fileId' => $fileId, 'exception' => $e->getMessage()]
				);
			}
		}

		if (is_numeric($fileId) === true && is_string($path) === true && $path !== '') {
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
		}

		return null;
	}//end resolveRecordFile()

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

			$object = $objectService
				->setRegister(self::REGISTER)
				->setSchema(self::SCHEMA)
				->find($id);

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
			if (class_exists($class) === false) {
				continue;
			}

			if (in_array(ReportGeneratorInterface::class, class_implements($class), true) === false) {
				continue;
			}

			// Skip the abstract document base (matches the glob + interface but is not instantiable).
			if ((new ReflectionClass($class))->isInstantiable() === false) {
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

			$folder = $this->ensureFolder($userFolder, $segments);
			if ($folder === null) {
				return ['filePath' => null, 'fileId' => null];
			}

			$fileName = $this->uniqueName($folder, $rendered->fileName);

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
	 * Ensure a nested folder path exists under a base folder, returning the leaf.
	 *
	 * @param Folder $base The base folder (the user's Files home).
	 * @param array<string> $segments Path segments to create/descend.
	 *
	 * @return Folder|null The leaf folder, or null on failure.
	 */
	private function ensureFolder(Folder $base, array $segments): ?Folder {
		$current = $base;
		foreach ($segments as $segment) {
			if ($current->nodeExists($segment) === true) {
				$node = $current->get($segment);
				if ($node instanceof Folder) {
					$current = $node;
					continue;
				}

				$this->logger->warning('ReportGenerationService: report path segment is not a folder', ['segment' => $segment]);
				return null;
			}

			$current = $current->newFolder($segment);
		}

		return $current;
	}//end ensureFolder()

	/**
	 * Produce a non-colliding file name within a folder (suffixes -1, -2, ...).
	 *
	 * @param Folder $folder The target folder.
	 * @param string $name The desired file name.
	 *
	 * @return string A name that does not yet exist in the folder.
	 */
	private function uniqueName(Folder $folder, string $name): string {
		if ($folder->nodeExists($name) === false) {
			return $name;
		}

		$dot = strrpos($name, '.');
		$stem = $dot === false ? $name : substr($name, 0, $dot);
		$extension = $dot === false ? '' : substr($name, $dot);

		$counter = 1;
		do {
			$candidate = $stem . '-' . $counter . $extension;
			$counter++;
		} while ($folder->nodeExists($candidate) === true && $counter < 1000);

		return $candidate;
	}//end uniqueName()

	/**
	 * Apply (creating if needed) the given system tags to a file id.
	 *
	 * @param int $fileId The Nextcloud file id.
	 * @param array<string> $tags The tag names to apply.
	 *
	 * @return void
	 */
	private function applyTags(int $fileId, array $tags): void {
		foreach ($tags as $tagName) {
			$tagName = trim($tagName);
			if ($tagName === '') {
				continue;
			}

			try {
				$tag = $this->resolveTag($tagName);
				$this->tagMapper->assignTags((string)$fileId, self::TAG_OBJECT_TYPE, [$tag->getId()]);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'ReportGenerationService: failed to apply system tag',
					['tag' => $tagName, 'fileId' => $fileId, 'exception' => $e->getMessage()]
				);
			}
		}

	}//end applyTags()

	/**
	 * Resolve a user-visible, assignable system tag by name, creating it on miss.
	 *
	 * @param string $tagName The tag name.
	 *
	 * @return \OCP\SystemTag\ISystemTag The resolved (or newly created) tag.
	 */
	private function resolveTag(string $tagName): \OCP\SystemTag\ISystemTag {
		try {
			$matches = $this->tagManager->getTag($tagName, true, true);
			return $matches;
		} catch (TagNotFoundException $e) {
			return $this->tagManager->createTag($tagName, true, true);
		}

	}//end resolveTag()

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
