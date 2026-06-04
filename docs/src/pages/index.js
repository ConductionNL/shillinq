/**
 * Shillinq landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the openregister
 * docs landing at openregister.conduction.nl and the pipelinq page.
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude,likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3.10 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 *
 * Note: @conduction/docusaurus-preset has no `shillinq` <AppMock>
 * variant (1.5.1), so the right-side illustration is a small inline
 * token-painted "financials workspace" mock that follows the AppMock
 * visual conventions (white 16:10 frame, cobalt chrome, one orange
 * accent, tokens only,no images, no real text). Swap in
 * `<AppMock app="shillinq" />` once a variant ships in the preset.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
} from '@conduction/docusaurus-preset/components';

/* Shillinq glyph: a stroked "invoice with a ledger line and a euro
   mark". The brand <DetailHero> renders the icon as a stroke-only
   line glyph (the preset's `.titleIcon svg` forces `fill: none;
   stroke: currentColor`), so this is a line-drawing of the
   file-invoice-dollar concept from img/app.svg rather than the filled
   Font Awesome compound path,the same way OpenRegister's hero icon
   is the cylinder *outline* and Pipelinq's is a stroke polyline. */
const SHILLINQ_ICON = (
  <svg viewBox="0 0 24 24">
    <path d="M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" />
    <path d="M14 2v4h4" />
    <path d="M7.5 10.5h6" />
    <path d="M7.5 13h3.5" />
    <path d="M16 13.5c0-.8-.8-1.3-1.7-1.3s-1.8.5-1.8 1.4.9 1.2 1.8 1.5 1.8.6 1.8 1.5-.9 1.4-1.8 1.4-1.8-.5-1.8-1.3" />
    <path d="M14.2 11v1.2M14.2 17.4v1.2" />
  </svg>
);

const TAGLINE = (
  <>
    Open-source business administration on{' '}
    <span className="next-blue">Nextcloud</span>,bookkeeping and
    general ledger, sales invoicing &amp; e-invoicing (UBL / Peppol),
    procurement and approval chains, contract lifecycle, bank
    reconciliation, VAT reporting, and financial statements. For
    freelancers, sole proprietors, SMBs and corporations.
  </>
);

/* ------------------------------------------------------------------ *
 * Inline app illustration,a token-painted "financials workspace"
 * screen for the hero's right column. Mirrors the AppMock framing
 * (white 16:10 card, cobalt border, faux topbar + left nav + content)
 * without depending on a preset variant that doesn't exist yet.
 * Tokens only; one orange accent.
 * ------------------------------------------------------------------ */

function ShillinqMock() {
  const navItems = [true, false, false, false, false, false];
  const ledgerRows = [
    { tone: 'var(--c-mint-500)', w: '72%' },
    { tone: 'var(--c-cobalt-300)', w: '58%' },
    { tone: 'var(--c-orange-knvb)', w: '64%' },
    { tone: 'var(--c-lavender-300)', w: '50%' },
    { tone: 'var(--c-cobalt-300)', w: '66%' },
    { tone: 'var(--c-mint-500)', w: '44%' },
  ];
  return (
    <div
      style={{
        width: '100%',
        maxWidth: 640,
        aspectRatio: '16 / 10',
        background: 'white',
        border: '1px solid var(--c-cobalt-100)',
        borderRadius: 'var(--radius-lg, 12px)',
        boxShadow: '0 12px 40px rgba(20, 33, 61, 0.18)',
        overflow: 'hidden',
        display: 'flex',
        flexDirection: 'column',
        fontFamily: 'var(--conduction-typography-font-family-code)',
      }}
    >
      {/* topbar */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 6,
          padding: '7px 10px',
          borderBottom: '1px solid var(--c-cobalt-50)',
        }}
      >
        <span
          style={{
            width: 12,
            height: 14,
            clipPath: 'var(--hex-pointy-top)',
            background: 'var(--c-orange-knvb)',
          }}
        />
        {Array.from({ length: 10 }).map((_, i) => (
          <span
            key={i}
            style={{
              width: 11,
              height: 11,
              borderRadius: 2,
              background: 'var(--c-cobalt-100)',
            }}
          />
        ))}
        <span style={{ flex: 1 }} />
        <span
          style={{
            width: 14,
            height: 14,
            borderRadius: '50%',
            background: 'var(--c-cobalt-200)',
          }}
        />
      </div>
      {/* body */}
      <div style={{ display: 'flex', flex: 1, minHeight: 0 }}>
        {/* left nav */}
        <div
          style={{
            width: 96,
            borderRight: '1px solid var(--c-cobalt-50)',
            padding: '10px 8px',
            display: 'flex',
            flexDirection: 'column',
            gap: 7,
          }}
        >
          <div
            style={{
              height: 5,
              width: '60%',
              background: 'var(--c-cobalt-400)',
              borderRadius: 1,
              marginBottom: 4,
            }}
          />
          {navItems.map((active, i) => (
            <div
              key={i}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 6,
                padding: '3px 4px',
                borderRadius: 3,
                background: active ? 'var(--c-cobalt-50)' : 'transparent',
              }}
            >
              <span
                style={{
                  width: 8,
                  height: 8,
                  borderRadius: 1,
                  background: active
                    ? 'var(--c-blue-cobalt)'
                    : 'var(--c-cobalt-200)',
                }}
              />
              <span
                style={{
                  flex: 1,
                  height: 4,
                  background: active
                    ? 'var(--c-cobalt-700)'
                    : 'var(--c-cobalt-200)',
                  borderRadius: 1,
                }}
              />
            </div>
          ))}
        </div>
        {/* content */}
        <div
          style={{
            flex: 1,
            padding: '12px 14px',
            display: 'flex',
            flexDirection: 'column',
            gap: 10,
            minWidth: 0,
          }}
        >
          {/* header row */}
          <div
            style={{ display: 'flex', alignItems: 'center', gap: 8 }}
          >
            <div
              style={{
                height: 7,
                width: '34%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <span style={{ flex: 1 }} />
            <span
              style={{
                width: 30,
                height: 12,
                borderRadius: 3,
                background: 'var(--c-cobalt-100)',
              }}
            />
            <span
              style={{
                width: 38,
                height: 12,
                borderRadius: 3,
                background: 'var(--c-blue-cobalt)',
              }}
            />
          </div>
          {/* KPI strip */}
          <div style={{ display: 'flex', gap: 8 }}>
            {[
              { tone: 'var(--c-mint-500)' },
              { tone: 'var(--c-lavender-300)' },
              { tone: 'var(--c-orange-knvb)' },
            ].map((kpi, i) => (
              <div
                key={i}
                style={{
                  flex: 1,
                  padding: '7px 8px',
                  border: '1px solid var(--c-cobalt-50)',
                  borderRadius: 4,
                  display: 'flex',
                  alignItems: 'center',
                  gap: 6,
                }}
              >
                <span
                  style={{
                    width: 9,
                    height: 10,
                    clipPath: 'var(--hex-pointy-top)',
                    background: kpi.tone,
                  }}
                />
                <div
                  style={{
                    flex: 1,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 3,
                  }}
                >
                  <div
                    style={{
                      height: 6,
                      width: '70%',
                      background: 'var(--c-cobalt-700)',
                      borderRadius: 1,
                    }}
                  />
                  <div
                    style={{
                      height: 3,
                      width: '50%',
                      background: 'var(--c-cobalt-200)',
                      borderRadius: 1,
                    }}
                  />
                </div>
              </div>
            ))}
          </div>
          {/* ledger / invoice list */}
          <div
            style={{
              flex: 1,
              border: '1px solid var(--c-cobalt-50)',
              borderRadius: 4,
              padding: '8px 10px',
              display: 'flex',
              flexDirection: 'column',
              gap: 6,
              minHeight: 0,
            }}
          >
            {ledgerRows.map((row, i) => (
              <div
                key={i}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 8,
                }}
              >
                <span
                  style={{
                    width: 10,
                    height: 11,
                    clipPath: 'var(--hex-pointy-top)',
                    background: row.tone,
                    flexShrink: 0,
                  }}
                />
                <span
                  style={{
                    height: 4,
                    width: row.w,
                    background: 'var(--c-cobalt-300)',
                    borderRadius: 1,
                  }}
                />
                <span style={{ flex: 1 }} />
                <span
                  style={{
                    height: 4,
                    width: 34,
                    background: 'var(--c-cobalt-200)',
                    borderRadius: 1,
                  }}
                />
                <span
                  style={{
                    height: 4,
                    width: 22,
                    background:
                      i === 2 ? 'var(--c-orange-knvb)' : 'var(--c-cobalt-100)',
                    borderRadius: 1,
                  }}
                />
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ *
 * Mock "widget panel" components,coloured-row lists painted with
 * design tokens only, mirroring the panels on the openregister and
 * pipelinq landings (no images, no real text).
 * ------------------------------------------------------------------ */

function RecentInvoicesPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', amount: '€ 4 280', status: 'paid' },
    { tone: 'var(--c-orange-knvb)', amount: '€ 1 950', status: 'overdue' },
    { tone: 'var(--c-lavender-300)', amount: '€ 3 110', status: 'sent' },
    { tone: 'var(--c-cobalt-300)', amount: '€ 870', status: 'draft' },
    { tone: 'var(--c-mint-500)', amount: '€ 6 540', status: 'paid' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '4px 0',
            borderBottom:
              i < rows.length - 1 ? '1px solid var(--c-cobalt-50)' : 'none',
          }}
        >
          <span
            style={{
              width: 11,
              height: 12,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 2,
            }}
          >
            <div
              style={{
                height: 4,
                width: '70%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '48%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-500)',
            }}
          >
            {row.status}
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 10,
              fontWeight: 700,
              color: 'var(--c-cobalt-700)',
              minWidth: 56,
              textAlign: 'right',
            }}
          >
            {row.amount}
          </div>
        </div>
      ))}
    </div>
  );
}

function ReceivablesPanel() {
  const buckets = [
    { label: 'current', val: '60%', tone: 'var(--c-mint-500)' },
    { label: '1–30 d', val: '45%', tone: 'var(--c-cobalt-300)' },
    { label: '31–60 d', val: '28%', tone: 'var(--c-lavender-300)' },
    { label: '60 d +', val: '18%', tone: 'var(--c-orange-knvb)' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <div
        style={{
          display: 'flex',
          alignItems: 'baseline',
          gap: 6,
          marginBottom: 4,
        }}
      >
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 24,
            fontWeight: 700,
            color: 'var(--c-cobalt-700)',
          }}
        >
          € 38.4k
        </div>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 10,
            letterSpacing: '0.05em',
            color: 'var(--c-orange-knvb)',
          }}
        >
          outstanding
        </div>
      </div>
      {buckets.map((b, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 6 }}
        >
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-500)',
              width: 54,
            }}
          >
            {b.label}
          </div>
          <div
            style={{
              flex: 1,
              height: 6,
              background: 'var(--c-cobalt-100)',
              borderRadius: 1,
            }}
          >
            <div
              style={{
                height: '100%',
                width: b.val,
                background: b.tone,
                borderRadius: 1,
              }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

function CashPositionPanel() {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 26,
            fontWeight: 700,
            color: 'var(--c-cobalt-900)',
          }}
        >
          € 112k
        </div>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 11,
            fontWeight: 600,
            color: 'var(--c-mint-500)',
          }}
        >
          +8%
        </div>
      </div>
      <svg
        viewBox="0 0 200 60"
        preserveAspectRatio="none"
        style={{ width: '100%', height: 50 }}
      >
        <path
          d="M 0 44 L 28 40 L 56 46 L 84 30 L 112 34 L 140 22 L 168 26 L 200 12"
          stroke="var(--c-blue-cobalt)"
          strokeWidth="2"
          fill="none"
        />
        <path
          d="M 0 44 L 28 40 L 56 46 L 84 30 L 112 34 L 140 22 L 168 26 L 200 12 L 200 60 L 0 60 Z"
          fill="var(--c-cobalt-100)"
        />
        <circle cx="200" cy="12" r="3" fill="var(--c-orange-knvb)" />
      </svg>
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Recent invoices',
    desc: 'Sales invoices generated, sent, paid, and overdue. DocuDesk fills the template and signs with your instance certificate; UBL / Peppol goes out the same flow.',
    panel: <RecentInvoicesPanel />,
  },
  {
    title: 'Outstanding receivables',
    desc: 'Aged debtor buckets straight off the ledger,current through 60-days-plus. The number you actually chase, not a nightly export.',
    panel: <ReceivablesPanel />,
  },
  {
    title: 'Cash position',
    desc: 'Bank balance reconciled against the general ledger, with the trend the last quarter built. Reconciliation runs against imported statements, no spreadsheet round-trip.',
    panel: <CashPositionPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="Shillinq, bookkeeping and e-invoicing on Nextcloud"
      description="Open-source business administration on Nextcloud. Bookkeeping, e-invoicing, procurement, contracts, bank reconciliation, VAT, and statements."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          appId="shillinq"
          status={{ label: 'Beta', color: 'var(--c-orange-knvb)' }}
          version="v0.1"
          locales="EN"
          title="Shillinq"
          tagline={TAGLINE}
          primaryCta={{
            label: 'Install from app store',
            href: 'https://apps.nextcloud.com/apps/shillinq',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          tertiaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/shillinq',
          }}
          iconColor="var(--c-orange-knvb)"
          icon={SHILLINQ_ICON}
          illustration={<ShillinqMock />}
        />

        <WidgetShelf
          eyebrow="Widgets we ship"
          title="The books on the dashboard the team already opens."
          lede="Install Shillinq and these widgets show up on the home screen. Recent invoices first, outstanding receivables next, cash position below,bookkeeping, e-invoicing, and reconciliation all behind them."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
