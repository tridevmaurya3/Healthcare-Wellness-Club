# Healthcare Wellness Club — STEP 19 Production Runbook

This runbook is intentionally provider-neutral. Do not put real passwords, tokens, API keys or backup passphrases in this file or in Git.

## 1. Prepare the host
- PHP 8.1+ with PDO MySQL, OpenSSL and zlib.
- MySQL/MariaDB database with a dedicated non-root application user.
- HTTPS certificate and a canonical domain/host.
- Private writable storage for application backups; storage directories must not be web-readable.

## 2. Configure server environment
Use `.env.example` only as a variable-name template. Configure values in the hosting control panel, service manager, Apache/Nginx/PHP-FPM environment or secret manager.

Required production variables:
- `HWC_APP_ENV=production`
- `HWC_APP_URL=https://...`
- `HWC_ALLOWED_HOSTS=...`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `HWC_HEALTH_TOKEN` (24+ random characters)
- `HWC_BACKUP_PASSPHRASE` for scheduled CLI backup jobs
- `HWC_OFFSITE_PATH` when a private mounted cloud/NAS destination exists

If HTTPS terminates at a reverse proxy/load balancer, set `HWC_TRUSTED_PROXIES` to the exact proxy IP address(es). Never trust forwarded protocol headers from arbitrary clients.

## 3. Create a recovery point before migration
From the existing environment:
1. Create an encrypted STEP 18 backup.
2. Verify it with its passphrase.
3. Download/copy the `.hwcbak` file to a separate trusted location.
4. Keep the passphrase separate from the backup file.

## 4. Deploy code
Deploy the `main` branch/release to the production web root. Do not upload local secret files. Ensure runtime storage folders are writable by PHP but not directly web-readable.

## 5. Prepare database
Create the target database and dedicated user. Let the application initialize required migration tables, then use STEP 19 Migration Center + STEP 18 Restore Preview to move the encrypted data package. Restore only after schema validation passes.

## 6. Validate production
- Open Production Health.
- Confirm HTTPS, allowed host, dedicated DB credentials and health token gates.
- Test Login/RBAC from a second device.
- Test session revocation.
- Create/verify a production encrypted backup.
- Configure an offsite target and verify SHA-256 copy when available.

## 7. Register release
Record the release version and exact Git commit SHA in Release Registry. Mark a production release as deployed only after production gates and a verified recovery point exist.

## 8. Scheduler
Configure host scheduler/cron for:
- encrypted daily STEP 18 backup,
- STEP 19 health check,
- offsite verified copy.

Secrets must come from the server environment, never from command-line literals committed to source control.

## 9. Maintenance and rollback
Before high-risk maintenance, enable Maintenance Mode and create a verified backup. If a deployment fails, use the recorded release history and verified backup/restore workflow. Do not manually rewrite legacy source facts as a rollback mechanism.
