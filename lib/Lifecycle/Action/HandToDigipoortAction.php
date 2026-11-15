<?php

/**
 * Shillinq hand-to-Digipoort lifecycle action
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\External\Digipoort\IntegriqSbrHandoffAdapter;
use OCA\Shillinq\Service\Sbr\SbrFilingFileStore;
use RuntimeException;

/**
 * The action of XbrlInstance `submit` (hand-to-digipoort): stores the
 * instance's XML and hands it to the Digipoort port.
 *
 * With integriq enabled the port is IntegriqSbrHandoffAdapter, which emits
 * the filing request and answers pending, and the instance waits in
 * handed-off until integriq reports the delivery. With the log-only adapter
 * bound the action refuses, so the transition fails and nothing is marked
 * submitted that never left shillinq.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-submission-hands-the-instance-to-integriq-and-follows-its-reports-req-tdf-004
 */
class HandToDigipoortAction implements LifecycleActionInterface {

	/**
	 * The refusal when no filing connection is configured.
	 */
	public const NOT_CONFIGURED = 'SBR filing is not configured: integriq and its Digipoort connector file with the Belastingdienst and the KvK.';

	/**
	 * Constructor.
	 *
	 * @param DigipoortSbrAdapterInterface $adapter  The Digipoort port.
	 * @param SbrFilingFileStore           $files    Stores the XML.
	 * @param RegisterLocationInterface    $location The register, for the object URI.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.2
	 */
	public function __construct(
		private readonly DigipoortSbrAdapterInterface $adapter,
		private readonly SbrFilingFileStore $files,
		private readonly RegisterLocationInterface $location,
	) {
	}//end __construct()

	/**
	 * Hand the instance to Digipoort through integriq.
	 *
	 * @param array<string, mixed> $objectData   The XbrlInstance as the transition leaves it.
	 * @param array<string, mixed> $previousData The XbrlInstance before.
	 * @param array<string, mixed> $parameters   Declared parameters (none).
	 * @param string               $actionName   The action name.
	 *
	 * @return array<string, mixed> The instance with its file, source slug and correlation id.
	 *
	 * @throws RuntimeException When filing is not configured or the instance has no XML.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.2
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		if ($this->adapter->isDormant() === true) {
			throw new RuntimeException(self::NOT_CONFIGURED);
		}

		$xml = (string) ($objectData['instanceXml'] ?? '');
		$id  = trim((string) ($objectData['id'] ?? ($objectData['@self']['id'] ?? '')));
		if (trim($xml) === '' || $id === '') {
			throw new RuntimeException('The XBRL instance has no document to file.');
		}

		$administrationId = trim((string) ($objectData['administrationId'] ?? ''));
		$number           = trim((string) ($objectData['instanceNumber'] ?? ''));
		if ($number === '') {
			$number = $id;
		}

		$fileUri   = $this->files->store(administrationId: $administrationId, fileName: $number . '.xbrl', content: $xml);
		$objectUri = 'openregister://' . $this->location->getRegisterSlug() . '/XbrlInstance/' . $id;
		$result    = $this->adapter->submit(
			[
				'objectUri'        => $objectUri,
				'filingType'       => (string) ($objectData['filingType'] ?? ''),
				'recipient'        => self::recipient(entryPoint: (string) ($objectData['entryPoint'] ?? '')),
				'payloadFileUri'   => $fileUri,
				'administrationId' => $administrationId,
				'correlationId'    => $objectUri,
				'xbrlChecksum'     => (string) ($objectData['instanceHash'] ?? hash('sha256', $xml)),
				'taxonomyVersion'  => (string) ($objectData['taxonomyVersion'] ?? ''),
			]
		);

		$objectData['payloadFileUri']      = $fileUri;
		$objectData['digipoortSourceSlug'] = IntegriqSbrHandoffAdapter::SOURCE_SLUG;
		$objectData['handOffReference']    = $result->reference;
		$objectData['rejectionReason']     = '';

		return $objectData;
	}//end execute()

	/**
	 * Who receives a filing on this entry point.
	 *
	 * @param string $entryPoint The entry point.
	 *
	 * @return string `kvk` for the KvK entry points, else `belastingdienst`.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.2
	 */
	public static function recipient(string $entryPoint): string {
		if (str_starts_with($entryPoint, 'kvk-') === true) {
			return 'kvk';
		}

		return 'belastingdienst';
	}//end recipient()
}//end class
