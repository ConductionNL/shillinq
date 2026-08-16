<?php

/**
 * Payment-run SEPA export orchestration service
 *
 * The orchestration layer behind the "Export to bank" action. It AUTO-DISCOVERS
 * the per-format generators (lib/PaymentRun/Generator/*.php implementing
 * PaymentRunGeneratorInterface, keyed by ::format()) — the same glob-and-filter
 * discovery style as the Reporting ReportGenerationService — validates that the
 * PaymentRun is in lifecycle state `approved` and that every line carries a
 * creditorIban, renders both the pain.001.001.03 XML and the CSV fallback,
 * persists the bytes as files in Nextcloud Files under
 * /Shillinq/PaymentRuns/<administrationId>/, applies system tags (payment-run
 * number, administration, file type), writes back exportedFileRef/exportedAt on
 * the PaymentRun, and requests the declarative approved → exported lifecycle
 * transition through OpenRegister's lifecycle engine (it does NOT hand-roll a
 * state machine — ADR-031).
 *
 * Rendering (XMLWriter / fputcsv) lives inside the individual generators, so
 * this service never `use`s an office/XML class itself and adds nothing to
 * shillinq's composer. Files/tag side effects are fail-soft (logged warnings,
 * graceful degradation) mirroring the Reporting service.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

use OCA\Shillinq\PaymentRun\Generator\PaymentRunGeneratorInterface;
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
 * Discovers generators, validates + renders the run, stores + tags the files,
 * writes back the file reference, and drives approved → exported.
 */
class PaymentRunExportService {

	/**
	 * The OpenRegister register slug shillinq's objects live under.
	 *
	 * @var string
	 */
	private const REGISTER = 'shillinq';

	/**
	 * The PaymentRun schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA = 'PaymentRun';

	/**
	 * The Nextcloud object type the system tags are mapped against.
	 *
	 * @var string
	 */
	private const TAG_OBJECT_TYPE = 'files';

	/**
	 * Memoised generator index (format => instance).
	 *
	 * @var array<string, PaymentRunGeneratorInterface>|null
	 */
	private ?array $generators = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container App container — lazily resolves
	 *                                      OpenRegister's ObjectService and
	 *                                      autowires the generators.
	 * @param IRootFolder $rootFolder Nextcloud Files root.
	 * @param ISystemTagManager $tagManager Resolves/creates the system tags.
	 * @param ISystemTagObjectMapper $tagMapper Maps tags onto the stored file id.
	 * @param IUserSession $userSession Current user (storage home).
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
	 * Validate, render, store + tag, write back and transition the PaymentRun.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return array<string, mixed> An envelope: on success
	 *                              `{ exportedFileRef, exportedAt, lifecycleState,
	 *                              files: [...] }`; on rejection `{ error: ... }`.
	 */
	public function export(array $paymentRun): array {
		$state = (string)($paymentRun['lifecycleState'] ?? $paymentRun['status'] ?? '');
		if ($state !== 'approved') {
			$this->logger->warning('PaymentRunExportService: run not approved', ['state' => $state]);
			return ['error' => 'not-approved', 'state' => $state];
		}

		$missing = $this->linesMissingCreditorIban(paymentRun: $paymentRun);
		if ($missing !== []) {
			$this->logger->warning('PaymentRunExportService: lines missing creditorIban', ['lines' => $missing]);
			return ['error' => 'missing-creditor-iban', 'lines' => $missing];
		}

		$rendered = $this->renderArtefacts(paymentRun: $paymentRun);
		if ($rendered === []) {
			return ['error' => 'no-generator'];
		}

		$administrationId = (string)($paymentRun['administrationId'] ?? '');
		$runNumber = (string)($paymentRun['runNumber'] ?? '');
		$userId = ($this->userSession->getUser()?->getUID() ?? '');

		$storedFiles = [];
		$xmlFileRef = null;
		foreach ($rendered as $file) {
			$stored = $this->storeFile(administrationId: $administrationId, rendered: $file, userId: $userId);

			if ($stored['fileId'] !== null) {
				$this->applyTags(
					fileId: $stored['fileId'],
					tags: [
						'shillinq-payment-run:' . $runNumber,
						'shillinq-administration:' . $administrationId,
						'shillinq-file-type:' . $file->format,
					]
				);
			}

			$storedFiles[] = [
				'format' => $file->format,
				'fileName' => $file->fileName,
				'filePath' => $stored['filePath'],
				'fileId' => $stored['fileId'],
			];

			if ($file->format === 'sepa-pain001' && $stored['filePath'] !== null) {
				$xmlFileRef = $stored['filePath'];
			}
		}//end foreach

		// Storage failed entirely — leave the run approved for an idempotent retry.
		if ($xmlFileRef === null) {
			$this->logger->warning('PaymentRunExportService: XML file storage failed; run stays approved');
			return ['error' => 'storage-failed', 'files' => $storedFiles];
		}

		$exportedAt = gmdate('Y-m-d\TH:i:s\Z');

		$update = array_merge(
			$paymentRun,
			[
				'exportedFileRef' => $xmlFileRef,
				'exportedAt' => $exportedAt,
				// Request the declarative approved → exported transition via the
				// OR lifecycle engine (it validates the transition on save).
				'lifecycleState' => 'exported',
				'status' => 'exported',
			]
		);

		$saved = $this->saveRun(run: $update);

		return [
			'exportedFileRef' => $xmlFileRef,
			'exportedAt' => $exportedAt,
			'lifecycleState' => (string)($saved['lifecycleState'] ?? 'exported'),
			'files' => $storedFiles,
			'paymentRun' => $saved,
		];

	}//end export()

	/**
	 * Render every discovered generator's artefact for the run (no storage).
	 *
	 * Exposed for the structure tests, which assert the pain.001 / CSV bytes
	 * without touching Nextcloud Files.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return array<int, RenderedFile>
	 */
	public function renderArtefacts(array $paymentRun): array {
		$out = [];
		foreach ($this->generators() as $generator) {
			try {
				$out[] = $generator->render(paymentRun: $paymentRun);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'PaymentRunExportService: generator failed',
					['format' => $generator::format(), 'exception' => $e->getMessage()]
				);
			}
		}

		return $out;
	}//end renderArtefacts()

	/**
	 * Return the 1-based indexes of lines that have no creditorIban.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return array<int, int>
	 */
	private function linesMissingCreditorIban(array $paymentRun): array {
		$lines = ($paymentRun['paymentLines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		$missing = [];
		$index = 0;
		foreach (array_values($lines) as $line) {
			$index++;
			$iban = '';
			if (is_array($line) === true) {
				$iban = trim((string)($line['creditorIban'] ?? ''));
			}

			if ($iban === '') {
				$missing[] = $index;
			}
		}

		return $missing;
	}//end linesMissingCreditorIban()

	/**
	 * Discover and index the payment-run generators (memoised).
	 *
	 * Mirrors ReportGenerationService::generators(): glob
	 * lib/PaymentRun/Generator/*.php, keep the classes implementing
	 * PaymentRunGeneratorInterface, instantiate each (via the container so DI is
	 * autowired) and key it by its declared format().
	 *
	 * @return array<string, PaymentRunGeneratorInterface>
	 */
	private function generators(): array {
		if ($this->generators !== null) {
			return $this->generators;
		}

		$found = [];
		$files = glob(__DIR__ . '/Generator/*.php');
		if (is_array($files) === false) {
			$files = [];
		}

		foreach ($files as $file) {
			$class = '\\OCA\\Shillinq\\PaymentRun\\Generator\\' . basename($file, '.php');
			if (class_exists($class) === false) {
				continue;
			}

			if (in_array(PaymentRunGeneratorInterface::class, class_implements($class), true) === false) {
				continue;
			}

			if ((new ReflectionClass($class))->isInstantiable() === false) {
				continue;
			}

			try {
				// Resolve via the container so generators with constructor DI are autowired.
				$instance = $this->container->get($class);
				$found[$instance::format()] = $instance;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'PaymentRunExportService: failed to instantiate generator',
					['class' => $class, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		$this->generators = $found;
		return $found;
	}//end generators()

	/**
	 * Write the rendered bytes to /Shillinq/PaymentRuns/<administrationId>/.
	 *
	 * @param string $administrationId Administration the file is scoped to.
	 * @param RenderedFile $rendered The rendered payload.
	 * @param string $userId The owning user id (storage home).
	 *
	 * @return array{filePath: string|null, fileId: int|null}
	 */
	private function storeFile(string $administrationId, RenderedFile $rendered, string $userId): array {
		if ($userId === '') {
			$this->logger->warning('PaymentRunExportService: no user session, cannot store export file');
			return ['filePath' => null, 'fileId' => null];
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);

			$segments = ['Shillinq', 'PaymentRuns'];
			if (trim($administrationId) !== '') {
				$segments[] = $administrationId;
			}

			$folder = $this->ensureFolder(base: $userFolder, segments: $segments);
			if ($folder === null) {
				return ['filePath' => null, 'fileId' => null];
			}

			$fileName = $this->uniqueName(folder: $folder, name: $rendered->fileName);
			$file = $folder->newFile($fileName, $rendered->content);

			return ['filePath' => $file->getPath(), 'fileId' => $file->getId()];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'PaymentRunExportService: failed to store export file',
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
	 * @return Folder|null
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

				$this->logger->warning('PaymentRunExportService: path segment is not a folder', ['segment' => $segment]);
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
	 * @return string
	 */
	private function uniqueName(Folder $folder, string $name): string {
		if ($folder->nodeExists($name) === false) {
			return $name;
		}

		$dot = strrpos($name, '.');
		$stem = $name;
		$extension = '';
		if ($dot !== false) {
			$stem = substr($name, 0, $dot);
			$extension = substr($name, $dot);
		}

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
				$tag = $this->resolveTag(tagName: $tagName);
				$this->tagMapper->assignTags((string)$fileId, self::TAG_OBJECT_TYPE, [$tag->getId()]);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'PaymentRunExportService: failed to apply system tag',
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
	 * @return \OCP\SystemTag\ISystemTag
	 */
	private function resolveTag(string $tagName): \OCP\SystemTag\ISystemTag {
		try {
			return $this->tagManager->getTag($tagName, true, true);
		} catch (TagNotFoundException $e) {
			return $this->tagManager->createTag($tagName, true, true);
		}

	}//end resolveTag()

	/**
	 * Persist the updated PaymentRun through OpenRegister (drives the transition).
	 *
	 * @param array<string, mixed> $run The updated PaymentRun fields.
	 *
	 * @return array<string, mixed> The saved run (or the input on failure).
	 */
	private function saveRun(array $run): array {
		try {
			$objectService = $this->objectService();
			if ($objectService === null) {
				return $run;
			}

			$saved = $objectService
				->setRegister(self::REGISTER)
				->setSchema(self::SCHEMA)
				->saveObject($run);

			if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
				return (array)$saved->jsonSerialize();
			}

			if (is_array($saved) === true) {
				return $saved;
			}

			return $run;
		} catch (\Throwable $e) {
			$this->logger->warning('PaymentRunExportService: failed to save PaymentRun', ['exception' => $e->getMessage()]);
			return $run;
		}//end try

	}//end saveRun()

	/**
	 * Lazily resolve OpenRegister's ObjectService from the container (null on miss).
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning('PaymentRunExportService: ObjectService unavailable', ['exception' => $e->getMessage()]);
			return null;
		}

	}//end objectService()
}//end class
