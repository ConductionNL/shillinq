<?php

/**
 * Programmabegroting Guard
 *
 * ADR-031 exception-path lifecycle guards for the Programmabegroting register
 * (bookkeeping-programmabegroting, T2). Two preconditions are referenced from
 * the Programmabegroting schema's x-openregister-lifecycle transitions because
 * they require cross-schema lookups that OpenRegister's declarative `requires:`
 * clause cannot yet express:
 *
 *  - canBehandelen():   all seven verplichte paragrafen must exist and the
 *                       nominaleOntwikkeling must be set before the begroting
 *                       may move to in-behandeling (REQ-007 / REQ-011 / D7).
 *  - canVaststellen():  every paragraaf narrative must be non-empty and the
 *                       raadsbesluit (vaststellingsBesluit) FK must be set
 *                       before the raad may vaststellen; on success the caller
 *                       persists the SluitendCalculator flags (REQ-008 /
 *                       REQ-011).
 *
 * ADR-031 exception reason: the seven-paragraaf completeness check and the
 * narrative-non-empty cross-schema lookup are not yet expressible in the
 * declarative lifecycle DSL. When the engine gains those capabilities, replace
 * these references with declarative conditions and delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-18
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for Programmabegroting behandelen and vaststellen.
 *
 * Referenced from the Programmabegroting schema (register.d fragment)
 * x-openregister-lifecycle transitions.behandelen.requires as
 * OCA\Shillinq\Lifecycle\ProgrammabegrotingGuard::canBehandelen and
 * transitions.vaststellen.requires as ::canVaststellen.
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-18
 */
final class ProgrammabegrotingGuard {
	/**
	 * The seven mandated paragraaftypen that must all exist before behandeling.
	 *
	 * @var array<int,string>
	 */
	private const VERPLICHTE_PARAGRAFEN = [
		'lokaleHeffingen',
		'weerstandsvermogenRisicobeheersing',
		'onderhoudKapitaalgoederen',
		'financiering',
		'bedrijfsvoering',
		'verbondenPartijen',
		'grondbeleid',
	];

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff all seven paragrafen exist and nominaleOntwikkeling is set.
	 *
	 * REQ-007 / REQ-011 / design D7: the begroting may only move to
	 * in-behandeling when the seven verplichte paragrafen have been created and
	 * the loon- en prijsindexatie figure is configured (so reëel-sluitend can
	 * be computed). Fail-closed: returns false on any exception (CWE-863).
	 *
	 * @param string $begrotingId The Programmabegroting.id being transitioned.
	 * @param array<string,mixed>|null $object The Programmabegroting object being transitioned.
	 *
	 * @return bool True when the begroting may move to in-behandeling.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-18
	 */
	public function canBehandelen(string $begrotingId, ?array $object = null): bool {
		try {
			$begroting = ($object ?? $this->resolveBegroting(begrotingId: $begrotingId));
			if ($begroting === null) {
				return false;
			}

			$nominale = ($begroting['nominaleOntwikkeling'] ?? null);
			if ($nominale === null || $nominale === '') {
				return false;
			}

			$id = $this->resolveId(begrotingId: $begrotingId, object: $object);
			$paragrafen = $this->fetchParagrafen(begrotingId: $id);

			$types = [];
			foreach ($paragrafen as $paragraaf) {
				$types[(string)($paragraaf['type'] ?? '')] = true;
			}

			foreach (self::VERPLICHTE_PARAGRAFEN as $type) {
				if (isset($types[$type]) === false) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ProgrammabegrotingGuard: behandelen check failed — denying transition (fail-closed)',
				['begrotingId' => $begrotingId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canBehandelen()

	/**
	 * Returns true iff every paragraaf narrative is non-empty and raadsbesluit is set.
	 *
	 * REQ-007 / REQ-011: the raad may only vaststellen when all seven paragrafen
	 * carry a non-empty narrative and the vaststellingsBesluit FK is set. On a
	 * true result the caller computes and persists the sluitend-flags via
	 * SluitendCalculator. Fail-closed: returns false on any exception (CWE-863).
	 *
	 * @param string $begrotingId The Programmabegroting.id being transitioned.
	 * @param array<string,mixed>|null $object The Programmabegroting object being transitioned.
	 *
	 * @return bool True when the begroting may be vastgesteld.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-18
	 */
	public function canVaststellen(string $begrotingId, ?array $object = null): bool {
		try {
			$begroting = ($object ?? $this->resolveBegroting(begrotingId: $begrotingId));
			if ($begroting === null) {
				return false;
			}

			$besluit = (string)($begroting['vaststellingsBesluit'] ?? '');
			if (trim($besluit) === '') {
				return false;
			}

			$id = $this->resolveId(begrotingId: $begrotingId, object: $object);
			$paragrafen = $this->fetchParagrafen(begrotingId: $id);

			$types = [];
			foreach ($paragrafen as $paragraaf) {
				$type = (string)($paragraaf['type'] ?? '');
				$narrative = trim((string)($paragraaf['narrative'] ?? ''));
				if ($narrative === '') {
					return false;
				}

				$types[$type] = true;
			}

			foreach (self::VERPLICHTE_PARAGRAFEN as $type) {
				if (isset($types[$type]) === false) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ProgrammabegrotingGuard: vaststellen check failed — denying transition (fail-closed)',
				['begrotingId' => $begrotingId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canVaststellen()

	/**
	 * Resolve the begroting id from the in-flight object or the passed id.
	 *
	 * @param string $begrotingId The Programmabegroting.id.
	 * @param array<string,mixed>|null $object The in-flight object, if provided.
	 *
	 * @return string The id, or '' when unresolvable.
	 */
	private function resolveId(string $begrotingId, ?array $object): string {
		if ($object !== null && isset($object['id']) === true && (string)$object['id'] !== '') {
			return (string)$object['id'];
		}

		return $begrotingId;
	}//end resolveId()

	/**
	 * Fetch the Paragraaf rows for a begroting via ObjectService.
	 *
	 * @param string $begrotingId The Programmabegroting.id whose paragrafen to fetch.
	 *
	 * @return array<int,array<string,mixed>> The Paragraaf rows.
	 */
	private function fetchParagrafen(string $begrotingId): array {
		if ($begrotingId === '') {
			return [];
		}

		$register = $this->resolveRegister();

		$rows = $this->objectService
			->setRegister($register)
			->setSchema('Paragraaf')
			->findAll(['filters' => ['begrotingId' => $begrotingId]]);
		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end fetchParagrafen()

	/**
	 * Resolve the Programmabegroting object by id via ObjectService.
	 *
	 * @param string $begrotingId The Programmabegroting.id to look up.
	 *
	 * @return array<string,mixed>|null The begroting, or null when not found.
	 */
	private function resolveBegroting(string $begrotingId): ?array {
		if ($begrotingId === '') {
			return null;
		}

		$register = $this->resolveRegister();

		$rows = $this->objectService
			->setRegister($register)
			->setSchema('Programmabegroting')
			->findAll(['filters' => ['id' => $begrotingId]]);

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end resolveBegroting()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
