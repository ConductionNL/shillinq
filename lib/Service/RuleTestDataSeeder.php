<?php

/**
 * Rule Test-Data Seeder
 *
 * Idempotently backfills the local TEST data so it satisfies the enforced
 * machine-checkable rules — every GLTransaction gets a `sourceReference`
 * (Belegfunktion) and at least two balanced GLLines (double-entry completeness).
 * Run after a clean-env reset so a fresh environment starts 100%-compliant in
 * `occ shillinq:rules:audit`.
 *
 * This is a TEST/DEV utility only: it writes with RBAC bypassed and as an admin
 * user to reach seeded objects' folders. No runtime path uses it; it exists so
 * the compliant-test-data state is reproducible rather than a one-off live edit.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-rule-testdata-seed/specs/bookkeeping-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Makes the local test data compliant with the enforced rules (idempotent).
 */
class RuleTestDataSeeder
{
    /**
     * @param ContainerInterface $container    DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig    App config for the register slug.
     * @param IUserManager       $userManager  To resolve an admin user for updates.
     * @param IGroupManager      $groupManager To find an admin user.
     * @param LoggerInterface    $logger       Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Backfill GL transactions to satisfy the enforced ledger rules.
     *
     * @return array<string, int> Counts: sourceReferencesAdded, linesAdded, alreadyCompliant.
     */
    public function seed(): array
    {
        $register = $this->register();
        $admin    = $this->adminUser();
        $os       = $this->objectService();

        $transactions     = $os->setRegister($register)->setSchema('GLTransaction')->findAll(['limit' => 10000]);
        $srcAdded         = 0;
        $linesAdded       = 0;
        $alreadyCompliant = 0;

        foreach ($transactions as $transaction) {
            $tx      = is_array($transaction) === true ? $transaction : $transaction->jsonSerialize();
            $id      = (string) ($tx['id'] ?? $tx['@self']['id'] ?? '');
            $num     = (string) ($tx['transactionNumber'] ?? '');
            $changed = false;

            if (trim((string) ($tx['sourceReference'] ?? '')) === '') {
                unset($tx['@self']);
                $tx['sourceReference'] = 'DOC-'.($num !== '' ? $num : substr($id, 0, 8));
                try {
                    $os->saveObject(object: $tx, register: $register, schema: 'GLTransaction', uuid: $id, _rbac: false, _multitenancy: false, currentUser: $admin);
                    $srcAdded++;
                    $changed = true;
                } catch (\Throwable $e) {
                    $this->logger->warning('RuleTestDataSeeder: sourceReference update failed for '.$id.': '.$e->getMessage());
                }
            }

            if ($this->lineCount($register, $id, $num) < 2) {
                $key      = $num !== '' ? $num : $id;
                $currency = (string) ($tx['currency'] ?? 'EUR');
                foreach ([['1000', 'debit'], ['8000', 'credit']] as $i => $spec) {
                    $line = [
                        'transactionId' => $key,
                        'lineNumber'    => ($i + 1),
                        'accountNumber' => $spec[0],
                        'side'          => $spec[1],
                        'amount'        => 100.00,
                        'currency'      => $currency,
                        'description'   => 'rules test-data seeder',
                    ];
                    try {
                        $os->saveObject(object: $line, register: $register, schema: 'GLLine', _rbac: false, _multitenancy: false, currentUser: $admin);
                        $linesAdded++;
                        $changed = true;
                    } catch (\Throwable $e) {
                        $this->logger->warning('RuleTestDataSeeder: GLLine create failed for '.$key.': '.$e->getMessage());
                    }
                }
            }//end if

            if ($changed === false) {
                $alreadyCompliant++;
            }
        }//end foreach

        // Purchase invoices: ensure a source-document URI (traceability).
        $purchases = $os->setRegister($register)->setSchema('APTransaction')->findAll(['limit' => 10000]);
        foreach ($purchases as $purchase) {
            $ap = is_array($purchase) === true ? $purchase : $purchase->jsonSerialize();
            $id = (string) ($ap['id'] ?? $ap['@self']['id'] ?? '');
            if (trim((string) ($ap['sourceDocumentUri'] ?? '')) !== '') {
                $alreadyCompliant++;
                continue;
            }

            unset($ap['@self']);
            $ap['sourceDocumentUri'] = 'doc://ap/'.(($ap['invoiceNumber'] ?? '') !== '' ? $ap['invoiceNumber'] : substr($id, 0, 8));
            try {
                $os->saveObject(object: $ap, register: $register, schema: 'APTransaction', uuid: $id, _rbac: false, _multitenancy: false, currentUser: $admin);
                $srcAdded++;
            } catch (\Throwable $e) {
                $this->logger->warning('RuleTestDataSeeder: APTransaction sourceDocumentUri update failed for '.$id.': '.$e->getMessage());
            }
        }

        // Sales invoices: backfill EN 16931 line items + VAT breakdown so the
        // structured-invoice rules (BR-12/16/21..26, BR-CO-04/10/13/14/17/18,
        // BR-45..48, BR-DEC-09/19/20/23) can be enforced. Derived from the
        // header net/vat, which already reconcile (BR-CO-15 is enforced).
        $invoiceLinesAdded = 0;
        $invoices          = $os->setRegister($register)->setSchema('ARInvoice')->findAll(['limit' => 10000]);
        foreach ($invoices as $invoice) {
            $ar       = is_array($invoice) === true ? $invoice : $invoice->jsonSerialize();
            $id       = (string) ($ar['id'] ?? $ar['@self']['id'] ?? '');
            $existing = ($ar['invoiceLines'] ?? null);
            if (is_array($existing) === true && $existing !== []) {
                $alreadyCompliant++;
                continue;
            }

            $net      = (float) ($ar['netAmount'] ?? 0);
            $vat      = (float) ($ar['vatAmount'] ?? 0);
            $currency = (string) ($ar['currency'] ?? 'EUR');
            $vatCents = (int) round($vat * 100);
            if ($vatCents === 0) {
                $category = 'Z';
                $rate     = 0.0;
            } else {
                $category = 'S';
                $rate     = ($net > 0) ? (($vat / $net) * 100) : 0.0;
            }

            unset($ar['@self']);
            $ar['invoiceLines'] = [
                [
                    'lineId'      => '1',
                    'quantity'    => 1,
                    'unitCode'    => 'C62',
                    'netAmount'   => $net,
                    'itemName'    => 'Seeded line item',
                    'netPrice'    => $net,
                    'vatCategory' => $category,
                ],
            ];
            $ar['vatBreakdown'] = [
                [
                    'category'      => $category,
                    'taxableAmount' => $net,
                    'taxAmount'     => $vat,
                    'rate'          => $rate,
                ],
            ];
            $ar['lineNetTotal'] = $net;

            try {
                $os->saveObject(object: $ar, register: $register, schema: 'ARInvoice', uuid: $id, _rbac: false, _multitenancy: false, currentUser: $admin);
                $invoiceLinesAdded++;
            } catch (\Throwable $e) {
                $this->logger->warning('RuleTestDataSeeder: ARInvoice line backfill failed for '.$id.': '.$e->getMessage());
            }
        }//end foreach

        return [
            'sourceReferencesAdded' => $srcAdded,
            'linesAdded'            => $linesAdded,
            'invoiceLinesAdded'     => $invoiceLinesAdded,
            'alreadyCompliant'      => $alreadyCompliant,
        ];

    }//end seed()

    /**
     * @param string $register Register slug.
     * @param string $id       Transaction OR id.
     * @param string $num      Transaction number.
     *
     * @return int Number of GLLines linked by either key.
     */
    private function lineCount(string $register, string $id, string $num): int
    {
        $os = $this->objectService();
        foreach (array_values(array_unique(array_filter([$id, $num]))) as $key) {
            $rows = $os->setRegister($register)->setSchema('GLLine')->findAll(['filters' => ['transactionId' => $key]]);
            if (is_array($rows) === true && count($rows) >= 2) {
                return count($rows);
            }
        }

        return 0;

    }//end lineCount()

    /**
     * Resolve an admin user (needed to update seeded objects' folders), or null.
     *
     * @return IUser|null
     */
    private function adminUser(): ?IUser
    {
        $admins = $this->groupManager->get('admin');
        if ($admins !== null) {
            foreach ($admins->getUsers() as $user) {
                return $user;
            }
        }

        return $this->userManager->get('admin');

    }//end adminUser()

    /**
     * @return mixed The OpenRegister ObjectService.
     */
    private function objectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * @return string The configured register slug.
     */
    private function register(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        return $register === '' ? 'shillinq' : $register;

    }//end register()
}//end class
