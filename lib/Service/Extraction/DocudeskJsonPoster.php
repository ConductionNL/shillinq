<?php

/**
 * Docudesk JSON Poster
 *
 * Posts one JSON body to a docudesk intra-instance route the way every
 * shillinq docudesk client does: a short timeout and connect timeout, JSON
 * content-type and accept headers, and the body encoded without escaped
 * slashes (an encoding failure throws).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Extraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Extraction;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;

/**
 * Posts a JSON body to docudesk with the shared request options.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */
final class DocudeskJsonPoster {
	/**
	 * Request timeout in seconds — the docudesk endpoints only need to accept
	 * the request, so a short budget is enough.
	 *
	 * @var int
	 */
	private const REQUEST_TIMEOUT_SECONDS = 10;

	/**
	 * Hold the HTTP client.
	 *
	 * @param IClient $client The HTTP client.
	 */
	public function __construct(
		private readonly IClient $client,
	) {
	}//end __construct()

	/**
	 * POST the body as JSON.
	 *
	 * @param string              $url  The absolute route URL.
	 * @param array<string,mixed> $body The body to encode.
	 *
	 * @return IResponse The response.
	 *
	 * @throws \Throwable When encoding or the transport fails.
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	public function post(string $url, array $body): IResponse {
		return $this->client->post(
			$url,
			[
				'timeout' => self::REQUEST_TIMEOUT_SECONDS,
				'connect_timeout' => self::REQUEST_TIMEOUT_SECONDS,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				],
				'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
			]
		);
	}//end post()
}//end class
