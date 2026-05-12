/**
 * Shillinq landing page.
 *
 * Written as .js (not .mdx) because the docs plugin is pointed at
 * `path: './'`, and an MDX file in src/pages/ trips the MDX-ESM
 * parser even with the docs plugin's `src/**` exclude. Authoring the
 * page in JSX keeps the same component composition.
 *
 * Kept deliberately simple (Layout + hero header + HomepageFeatures)
 * rather than the brand preset's <DetailHero> / <WidgetShelf> — this
 * was scaffolded by /journeydoc-init for an app with no prior docs
 * site; swap in the richer brand components when the marketing copy
 * and app mock are ready.
 */

import React from 'react';
import clsx from 'clsx';
import Link from '@docusaurus/Link';
import useDocusaurusContext from '@docusaurus/useDocusaurusContext';
import Layout from '@theme/Layout';
import HomepageFeatures from '@site/src/components/HomepageFeatures';

import styles from './index.module.css';

function HomepageHeader() {
  const { siteConfig } = useDocusaurusContext();
  return (
    <header className={clsx('hero hero--primary', styles.heroBanner)}>
      <div className="container">
        <h1 className="hero__title">{siteConfig.title}</h1>
        <p className="hero__subtitle">{siteConfig.tagline}</p>
        <div className={styles.buttons}>
          <Link className="button button--secondary button--lg" to="/docs/intro">
            Read the docs
          </Link>
        </div>
      </div>
    </header>
  );
}

export default function Home() {
  const { siteConfig } = useDocusaurusContext();
  return (
    <Layout
      title={siteConfig.title}
      description="Open-source business administration suite for Nextcloud — bookkeeping, invoicing, procurement, contract management."
    >
      <HomepageHeader />
      <main>
        <HomepageFeatures />
      </main>
    </Layout>
  );
}
