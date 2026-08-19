# Healthcare Wellness Club — STEP 25 Production Launch

STEP 25 does not treat a successful local XAMPP run as a production deployment. The production release is valid only after the target server satisfies the existing STEP 19 gates, a verified STEP 18 recovery point exists, the STEP 25 automated audit passes, human UAT is signed off, and the exact release is recorded in Deployment Releases.

## Required order

1. Pull the exact `main` commit that will be deployed and run `business/step25_audit.php` locally. Resolve every automated `REVIEW` before proceeding.
2. Create and verify a fresh encrypted recovery package in Backup Center. Keep the passphrase outside Git and outside the web root.
3. Configure the target server from `.env.example`. Real database passwords, health tokens, backup passphrases, form peppers and relay tokens must remain server-only.
4. Set `HWC_APP_ENV=production`, use an HTTPS `HWC_APP_URL`, configure `HWC_ALLOWED_HOSTS`, use a dedicated non-root database user, and configure a 24+ character `HWC_HEALTH_TOKEN`.
5. Restore/migrate data through the established STEP 18/19 recovery and migration workflows. Do not import by bypassing the application audit boundary.
6. Run `business/production_health.php` on the target server and resolve every production gate.
7. Run the 10 real-device/browser/server UAT cases in `business/final_launch_center.php` and record UAT sign-off only after observing every expected result.
8. Smoke-test the public store, one order-request checkout, private tracking, staff order review, role restrictions, PWA/offline boundary, backup evidence and production health.
9. In Deployment Releases, record the exact version and Git commit SHA. Mark it `deployed` only after all preceding gates pass.
10. After launch, run the private `/business/healthz.php` probe with `X-HWC-Health-Token`, review logs/notifications and keep the pre-release backup until the release is stable.

## Rollback rule

If production health, checkout, authentication, data integrity or staff workflows regress after launch, enable maintenance mode, stop new business changes, use the verified pre-release recovery point and record the release as rolled back in Deployment Releases. Never repair production by deleting source/audit records or silently changing financial/order facts.

## What “live” means in STEP 25

The Final Launch Center can show local QA readiness, target-server production readiness and UAT sign-off independently. **Production is considered live only when the runtime is actually `production` and an authorized release record has status `deployed`.**
