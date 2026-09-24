<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('court_blockouts', 'is_indefinite')) {
            Schema::table('court_blockouts', function (Blueprint $table) {
                $table->boolean('is_indefinite')->default(false)->after('end_time');
            });
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // Permite end_time NULL (NULL = bloqueio sem previsão de término).
            DB::statement('ALTER TABLE court_blockouts ALTER COLUMN end_time DROP NOT NULL');
        } elseif ($driver === 'sqlite') {
            // SQLite não tem DROP NOT NULL; reconstrói a tabela preservando os dados.
            $hasNullable = false;
            try {
                $cols = DB::select("PRAGMA table_info('court_blockouts')");
                foreach ($cols as $col) {
                    if ($col->name === 'end_time' && (int) $col->notnull === 0) {
                        $hasNullable = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $hasNullable = false;
            }

            if (!$hasNullable) {
                DB::statement('
                    CREATE TABLE court_blockouts_new (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        court_id INTEGER NOT NULL,
                        user_id INTEGER NULL,
                        start_time DATETIME NOT NULL,
                        end_time DATETIME NULL,
                        reason VARCHAR(255) NULL,
                        is_indefinite TINYINT(1) NOT NULL DEFAULT 0,
                        created_at DATETIME NULL,
                        updated_at DATETIME NULL,
                        FOREIGN KEY (court_id) REFERENCES courts(id) ON DELETE CASCADE,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                    )
                ');
                DB::statement('
                    INSERT INTO court_blockouts_new (id, court_id, user_id, start_time, end_time, reason, is_indefinite, created_at, updated_at)
                    SELECT id, court_id, user_id, start_time, end_time, reason, is_indefinite, created_at, updated_at FROM court_blockouts
                ');
                DB::statement('DROP TABLE court_blockouts');
                DB::statement('ALTER TABLE court_blockouts_new RENAME TO court_blockouts');
            }
        } else {
            DB::statement('ALTER TABLE `court_blockouts` MODIFY `end_time` DATETIME NULL');
        }
    }

    public function down(): void
    {
        DB::table('court_blockouts')->where('is_indefinite', true)->delete();

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE court_blockouts ALTER COLUMN end_time SET NOT NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement('
                CREATE TABLE court_blockouts_old (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    court_id INTEGER NOT NULL,
                    user_id INTEGER NULL,
                    start_time DATETIME NOT NULL,
                    end_time DATETIME NOT NULL,
                    reason VARCHAR(255) NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    FOREIGN KEY (court_id) REFERENCES courts(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                )
            ');
            DB::statement('
                INSERT INTO court_blockouts_old (id, court_id, user_id, start_time, end_time, reason, created_at, updated_at)
                SELECT id, court_id, user_id, start_time, end_time, reason, created_at, updated_at FROM court_blockouts
            ');
            DB::statement('DROP TABLE court_blockouts');
            DB::statement('ALTER TABLE court_blockouts_old RENAME TO court_blockouts');
            return;
        } else {
            DB::statement('ALTER TABLE `court_blockouts` MODIFY `end_time` DATETIME NOT NULL');
        }

        Schema::table('court_blockouts', function (Blueprint $table) {
            $table->dropColumn('is_indefinite');
        });
    }
};
