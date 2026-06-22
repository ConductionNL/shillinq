<?php

/**
 * Set Order-extension JSON-Schema composition (allOf)
 *
 * OpenRegister resolves JSON-Schema `allOf` composition for object validation +
 * storage (an extension inherits its base schema's fields), but its REGISTER
 * IMPORT does not accept a schema-level `allOf` declared in a register fragment.
 * This repair step therefore sets the `all_of` column directly on each Order
 * extension schema after import, pointing it at the base `Order` schema id, so a
 * Grant / SalesOrder / PurchaseOrder / Quote / BlanketOrder object inherits the
 * base order fields (orderType, direction, counterparty, totalAmount, paymentTerms,
 * …) on top of its own tail. Idempotent.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Points each Order extension schema's `all_of` at the base Order schema id.
 */
class SetOrderExtensionComposition implements IRepairStep
{

    /**
     * The base schema slug every order extension inherits.
     */
    private const BASE = 'Order';

    /**
     * The extension schema slugs that compose the base Order.
     */
    private const EXTENSIONS = ['Grant', 'SalesOrder', 'PurchaseOrder', 'Quote', 'BlanketOrder'];


    /**
     * @param IDBConnection $db Database connection (direct schema-column update).
     */
    public function __construct(
        private readonly IDBConnection $db,
    ) {

    }//end __construct()


    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Compose Order extensions (Grant/SalesOrder/PurchaseOrder/Quote/BlanketOrder) onto the base Order via allOf';

    }//end getName()


    /**
     * Resolve the base Order schema id, then set each extension's all_of to
     * reference it. Skips silently when OpenRegister is not present (the
     * openregister_schemas table is absent) so the step is safe in any install.
     *
     * @param IOutput $output Repair output.
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        try {
            if ($this->db->tableExists('openregister_schemas') === false) {
                $output->info('Shillinq: OpenRegister schemas table absent — skipping Order composition.');
                return;
            }

            $baseId = $this->schemaId(self::BASE);
            if ($baseId === null) {
                $output->warning('Shillinq: base Order schema not found — skipping Order composition.');
                return;
            }

            $allOf   = json_encode([(string) $baseId]);
            $updated = 0;
            foreach (self::EXTENSIONS as $slug) {
                $id = $this->schemaId($slug);
                if ($id === null) {
                    continue;
                }

                $qb = $this->db->getQueryBuilder();
                $qb->update('openregister_schemas')
                    ->set('all_of', $qb->createNamedParameter($allOf))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
                $qb->executeStatement();
                $updated++;
            }

            $output->info(sprintf('Shillinq: composed %d Order extension schema(s) onto base Order #%s.', $updated, (string) $baseId));
        } catch (\Throwable $e) {
            $output->warning('Shillinq: Order composition repair failed: '.$e->getMessage());
        }//end try

    }//end run()


    /**
     * Resolve a schema id by slug, or null.
     *
     * @param string $slug The schema slug.
     *
     * @return int|string|null
     */
    private function schemaId(string $slug): int|string|null
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('openregister_schemas')
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
            ->setMaxResults(1);
        $id = $qb->executeQuery()->fetchOne();
        return ($id === false ? null : $id);

    }//end schemaId()


}//end class
