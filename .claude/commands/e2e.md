---
description: Run the shillinq Playwright end-to-end suite against your own Nextcloud
---

The suite has no default target: an unset base URL is a hard failure, on purpose. Read the header of `tests/e2e/base-url.ts` before changing the target. Ask for the URL of an instance that is not shared if `PLAYWRIGHT_BASE_URL` is not set, and never point it at a shared development container.

```bash
npm run test:e2e:install                                   # one-time Chromium download
PLAYWRIGHT_BASE_URL=<your instance> npm run test:e2e       # tests/e2e
```

$ARGUMENTS
