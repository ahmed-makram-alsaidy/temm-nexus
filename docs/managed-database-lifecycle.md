# Managed project databases

TEMM provisions an empty PostgreSQL database when a new project's TEMM-managed destination is confirmed. Provisioning is separate from source analysis and data transfer. Existing or interrupted projects recover through **Data → Connections → Provision database**.

## Environment selection

Project classification and active environment are separate. Connections displays both, and its Provision database button names the active environment. Provisioning, connection configuration, Data/Tables, Health, and the managed migration target use that environment's binding. The wizard displays the selected default environment before destination confirmation. No environment is switched automatically.

Select the intended environment explicitly before provisioning. Inactive environments must be activated through existing environment management rules. Production migration guards remain in force even when its empty database is configured.

## Lifecycle

1. Choose the workspace and environment in the new project wizard.
2. Verify the source. TEMM creates the project and Development, Staging, and Production records; the selected environment becomes the single active default.
3. Choose TEMM-managed infrastructure and press Continue. TEMM creates and verifies an empty database and its own login role.
4. Store the generated password encrypted in the existing vault, scoped to the environment. Store only safe coordinates and the secret reference in the connection binding.
5. Open Connections, Tables, and Health to verify the project database. A successful source connection or dry run does not establish destination readiness.
6. Analyze and run a dry run separately. A real managed migration resolves the same canonical connection as Data and Health, including its environment password reference.

External destinations use **Configure connection**. TEMM verifies existing credentials and encrypts the environment binding without database or role DDL. No schema migration is required for this feature.

## Provisioning and recovery

Provisioning requires project-scoped infrastructure and secrets permissions and an active environment. It is serialized by environment and database locks. A strong random password and ownership intent are encrypted and saved before PostgreSQL DDL, allowing retries after partial failures to reuse the same credentials.

The server creates a least-privileged login role and uses a SCRAM verifier rather than a plaintext password in role-creation SQL. Passwords are never displayed in the UI, included in audit metadata, or attached to raw driver errors. Technical connection identifiers remain LTR in Arabic.

Provisioning never drops a database, resets data, or alters an existing role password. Unknown existing databases or roles are refused. Known working legacy credentials can be reconciled without DDL. Partial resources are accepted only when their ownership matches the saved intent. Use Configure connection with verified existing credentials to reconcile an existing database; a pending operation cannot be redirected to a different endpoint, database, or role.

## Server configuration

The server-only provisioning account defaults to `POSTGRES_USER` and `POSTGRES_PASSWORD` on the managed endpoint. Optional overrides are `PROJECT_DB_HOST`, `PROJECT_DB_PORT`, `PROJECT_DB_ADMIN_USERNAME`, `PROJECT_DB_ADMIN_PASSWORD`, and `PROJECT_DB_SSLMODE`. Project connections never use the provisioning administrator role.

Preserve the installation's APP_KEY during upgrades so encrypted vault entries remain readable. Keep server credentials out of UI, logs, and public artifacts.
