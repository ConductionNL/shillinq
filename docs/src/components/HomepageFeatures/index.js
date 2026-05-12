import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'Bookkeeping & Invoicing',
    description: (
      <>
        Double-entry general ledger, accounts payable and receivable, sales
        invoicing with UBL / Peppol e-invoicing, bank reconciliation, and
        financial statements.
      </>
    ),
  },
  {
    title: 'Procurement & Contracts',
    description: (
      <>
        Purchase orders and procurement workflows, supplier management with
        approval chains, and contract lifecycle management — creation,
        renewal, obligations — with a searchable repository.
      </>
    ),
  },
  {
    title: 'Built on OpenRegister',
    description: (
      <>
        All data stored as flexible OpenRegister objects with a per-record
        audit trail. Dutch government compliance: BBV, IV3, SiSa,
        DigiInkoop.
      </>
    ),
  },
];

function Feature({ title, description }) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}
