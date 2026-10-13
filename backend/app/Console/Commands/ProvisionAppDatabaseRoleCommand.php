<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea (o actualiza) el rol de PostgreSQL con el que corre la aplicación en
 * producción: NO es dueño de las tablas, así que no puede hacer DDL, ni
 * TRUNCATE, ni desactivar triggers. Los triggers de inmutabilidad (kardex,
 * audit_logs, patient_access_logs) bloquean UPDATE/DELETE pero no TRUNCATE;
 * con este rol la app tampoco puede truncar. En esas tres tablas, además, ni
 * siquiera tiene permiso de UPDATE/DELETE (defensa doble con el trigger).
 *
 * Se ejecuta con el usuario dueño (el que corre las migraciones), después de
 * `migrate`. Es idempotente. Las tablas que creen migraciones futuras quedan
 * cubiertas por ALTER DEFAULT PRIVILEGES. Ver docs/DEPLOY.md y S-101.
 *
 *   DB_APP_USERNAME=fartmar_app DB_APP_PASSWORD=... php artisan db:provision-app-role
 */
class ProvisionAppDatabaseRoleCommand extends Command
{
    /** Tablas de solo inserción: la app no puede modificarlas ni borrarlas. */
    public const APPEND_ONLY_TABLES = ['kardex_movements', 'audit_logs', 'patient_access_logs'];

    protected $signature = 'db:provision-app-role';

    protected $description = 'Crea/actualiza el rol de BD de la aplicación (sin propiedad de tablas ni TRUNCATE)';

    public function handle(): int
    {
        $username = (string) config('fartmar.db_app_role.username');
        $password = (string) config('fartmar.db_app_role.password');

        if ($username === '') {
            $this->info('DB_APP_USERNAME vacío: la aplicación usa el usuario dueño (solo desarrollo).');

            return self::SUCCESS;
        }

        // Los identificadores no admiten parámetros enlazados: se valida el
        // nombre y se cita con comillas dobles.
        if (preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/', $username) !== 1) {
            $this->error('DB_APP_USERNAME debe ser un identificador en minúsculas (a-z, 0-9, _).');

            return self::FAILURE;
        }
        if (strlen($password) < 12) {
            $this->error('DB_APP_PASSWORD es obligatorio (12 caracteres o más).');

            return self::FAILURE;
        }

        $owner = (string) DB::selectOne('select current_user as name')->name;
        if ($owner === $username) {
            $this->error('DB_APP_USERNAME no puede ser el mismo usuario que ejecuta las migraciones.');

            return self::FAILURE;
        }

        $role = '"'.$username.'"';
        $ownerRole = '"'.str_replace('"', '""', $owner).'"';
        $passwordLiteral = DB::getPdo()->quote($password);
        $appendOnly = implode(', ', self::APPEND_ONLY_TABLES);

        DB::transaction(function () use ($username, $role, $ownerRole, $passwordLiteral, $appendOnly): void {
            $exists = DB::selectOne('select 1 as found from pg_roles where rolname = ?', [$username]) !== null;
            if (! $exists) {
                DB::statement("CREATE ROLE {$role} LOGIN");
            }
            DB::statement("ALTER ROLE {$role} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD {$passwordLiteral}");

            $database = '"'.str_replace('"', '""', (string) DB::selectOne('select current_database() as name')->name).'"';
            DB::statement("GRANT CONNECT ON DATABASE {$database} TO {$role}");
            DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");
            DB::statement("REVOKE CREATE ON SCHEMA public FROM {$role}");

            // Tablas y secuencias existentes.
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}");
            DB::statement("REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA public FROM {$role}");
            DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$role}");

            // Tablas que creen migraciones futuras (ejecutadas por el dueño).
            DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$ownerRole} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
            DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$ownerRole} IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$role}");

            // Bitácoras y kardex: solo lectura e inserción.
            DB::statement("REVOKE UPDATE, DELETE ON {$appendOnly} FROM {$role}");
        });

        $this->info("Rol de aplicación {$username} listo (sin DDL, sin TRUNCATE; kardex y bitácoras solo inserción).");

        return self::SUCCESS;
    }
}
