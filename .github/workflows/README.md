# Scrutium — GitHub Actions

## Workflows

| Workflow | File | Trigger | Purpose |
|----------|------|---------|---------|
| **CI** | `ci.yml` | Push / PR → `main`, `develop` | Lint, test, build, secrets scan |
| **CD** | `cd.yml` | Push to `main`, manual dispatch, releases | Build → Staging / Production |

## Setup checklist

1. **Environments** (Settings → Environments)
   - Create `staging` and `production`
   - On `production`, enable **Required reviewers** so deploys need approval

2. **Secrets** (Settings → Secrets and variables → Actions)
   - `STAGING_DEPLOY_TOKEN` / `PRODUCTION_DEPLOY_TOKEN` (or provider-specific tokens)
   - Any API keys your deploy steps need

3. **Enable real steps**
   - After you scaffold the app (e.g. Next.js / Nest / etc.), uncomment the Install / Lint / Test / Build steps in `ci.yml` and `cd.yml`

4. **Branch protection** (Settings → Branches)
   - Protect `main`: require PR, require CI status checks to pass

## Extending

- Add path filters (`paths:`) if the repo becomes a monorepo
- Add matrix jobs for multiple Node versions or packages
- Swap the Node setup for Python / Go / Docker as needed
