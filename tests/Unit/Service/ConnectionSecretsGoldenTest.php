<?php

/**
 * Pins how the decidesk webhook and the pipelinq connection keep their URL in
 * the app config and their bearer token in the secrets store: the exact keys,
 * the trimmed writes, a blank token deleting the secret, and what reads back
 * as no token.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\LeaseDecideskWebhookService;
use OCA\Shillinq\Service\Pipelinq\PipelinqConfig;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Records every app-config and secrets-store call both connections make.
 */
final class ConnectionSecretsGoldenTest extends TestCase {

	/**
	 * The calls made, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Both connections: [class, url getter, url setter, token getter, token setter, url key, secret id].
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function connections(): array {
		return [
			'decidesk webhook' => [LeaseDecideskWebhookService::class, 'getWebhookUrl', 'setWebhookUrl', 'getWebhookToken', 'setWebhookToken', 'decidesk_webhook_url', 'decidesk_webhook_token'],
			'pipelinq connection' => [PipelinqConfig::class, 'getPipelinqEndpoint', 'setPipelinqEndpoint', 'getPipelinqToken', 'setPipelinqToken', 'pipelinq_api_endpoint', 'pipelinq_api_token'],
		];

	}//end connections()

	/**
	 * Build the connection over recording doubles; the store holds $stored for every secret.
	 *
	 * @param string $class  The connection class.
	 * @param mixed  $stored What the secrets store returns.
	 *
	 * @return object
	 */
	private function connection(string $class, mixed $stored): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default): string {
				$this->calls[] = 'get-config ' . $app . ' ' . $key . ' default=' . $default;
				return 'https://example.test/' . $key;
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->calls[] = 'set-config ' . $app . ' ' . $key . ' "' . $value . '"';
				return true;
			}
		);
		$secrets = $this->createMock(ICredentialsManager::class);
		$secrets->method('retrieve')->willReturnCallback(
			function (string $userId, string $identifier) use ($stored): mixed {
				$this->calls[] = 'retrieve user="' . $userId . '" ' . $identifier;
				return $stored;
			}
		);
		$secrets->method('store')->willReturnCallback(
			function (string $userId, string $identifier, mixed $credentials): void {
				$this->calls[] = 'store user="' . $userId . '" ' . $identifier . ' "' . $credentials . '"';
			}
		);
		$secrets->method('delete')->willReturnCallback(
			function (string $userId, string $identifier): int {
				$this->calls[] = 'delete user="' . $userId . '" ' . $identifier;
				return 1;
			}
		);

		return new $class($appConfig, $secrets, $this->createMock(IClientService::class), new NullLogger());

	}//end connection()

	/**
	 * The URL and token round trip through their stores, keys and trimming as recorded.
	 *
	 * @return void
	 */
	#[DataProvider('connections')]
	public function testUrlAndTokenStorage(string $class, string $getUrl, string $setUrl, string $getToken, string $setToken, string $urlKey, string $secretId): void {
		$connection = $this->connection($class, '  kept as stored  ');
		self::assertSame('https://example.test/' . $urlKey, $connection->{$getUrl}());
		$connection->{$setUrl}('  https://new.test/hook  ');
		self::assertSame('  kept as stored  ', $connection->{$getToken}());
		$connection->{$setToken}("  s3cret \n");
		$connection->{$setToken}(" \t ");
		$connection->{$setToken}('');

		self::assertSame(
			[
				'get-config shillinq ' . $urlKey . ' default=',
				'set-config shillinq ' . $urlKey . ' "https://new.test/hook"',
				'retrieve user="" ' . $secretId,
				'store user="" ' . $secretId . ' "s3cret"',
				'delete user="" ' . $secretId,
				'delete user="" ' . $secretId,
			],
			$this->calls
		);

	}//end testUrlAndTokenStorage()

	/**
	 * An empty or non-string secret reads back as no token.
	 *
	 * @return void
	 */
	#[DataProvider('connections')]
	public function testNoTokenReadsAsEmpty(string $class, string $getUrl, string $setUrl, string $getToken): void {
		foreach ([null, '', false, 42, ['x']] as $stored) {
			self::assertSame('', $this->connection($class, $stored)->{$getToken}(), var_export($stored, true));
		}

	}//end testNoTokenReadsAsEmpty()
}//end class
