<?php

namespace App\Commands;

use App\Libraries\Platform\Store;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use PDO;
use RuntimeException;
use Throwable;

class PlatformMigrateMysql extends BaseCommand
{
    protected $group = 'Platform';
    protected $name = 'platform:migrate-mysql';
    protected $description = 'Back up and copy the SQLite platform database into an empty MySQL database.';

    public function run(array $params)
    {
        $sourcePath = (string)env('PLATFORM_DB_PATH', WRITEPATH . 'platform.sqlite');
        if (!is_file($sourcePath)) {
            CLI::error('SQLite source database was not found: ' . $sourcePath);
            return EXIT_ERROR;
        }

        try {
            $source = new Store($sourcePath);
            if (!$source->installed()) {
                throw new RuntimeException('The SQLite source does not contain an installed platform.');
            }

            $sourceTables = $source->tableNames();
            $integrity = $source->all('PRAGMA foreign_key_check');
            $detachedAuditActors = [];
            foreach ($integrity as $violation) {
                if ($violation['table'] !== 'audit' || $violation['parent'] !== 'users') {
                    throw new RuntimeException('SQLite foreign-key integrity check failed; no data was copied.');
                }
                $audit = $source->one('SELECT id,actor_id FROM audit WHERE rowid=?', [(int)$violation['rowid']]);
                if (!$audit || $audit['actor_id'] === null || $source->one('SELECT id FROM users WHERE id=?', [(int)$audit['actor_id']])) {
                    throw new RuntimeException('SQLite foreign-key integrity check failed; no data was copied.');
                }
                $detachedAuditActors[(int)$audit['id']] = true;
            }

            $database = (string)env('PLATFORM_DB_NAME', '');
            if ($database === '' || !preg_match('/^[A-Za-z0-9_.-]+$/D', $database)) {
                throw new RuntimeException('PLATFORM_DB_NAME must be set to a valid MySQL database name.');
            }
            $host = (string)env('PLATFORM_DB_HOST', '127.0.0.1');
            $port = (int)env('PLATFORM_DB_PORT', 3306);
            $server = new PDO(
                'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
                (string)env('PLATFORM_DB_USER', ''),
                (string)env('PLATFORM_DB_PASSWORD', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $destination = new Store(null, 'mysql');
            $seedSettings = [
                'ai_enabled', 'analytics_enabled', 'bank_details', 'bot_enabled',
                'bot_knowledge', 'deepseek_init', 'facebook_enabled', 'line_enabled', 'role_menus',
                'shipping_satang', 'store_name', 'workspace_permissions_v1',
            ];
            foreach ($destination->tableNames() as $table) {
                $count = (int)$destination->one('SELECT COUNT(*) AS n FROM `' . $table . '`')['n'];
                if ($count === 0) { continue; }
                if ($table === 'settings') {
                    $keys = array_column($destination->all('SELECT setting_key FROM settings'), 'setting_key');
                    if ($keys !== [] && array_diff($keys, $seedSettings) === []) { continue; }
                }
                if ($count > 0) {
                    throw new RuntimeException('The target MySQL database contains data. Migration stopped without changing it.');
                }
            }

            $destination->install();
            $targetTables = $destination->tableNames();
            $missingTables = array_diff($sourceTables, $targetTables);
            if ($missingTables !== []) {
                throw new RuntimeException('Target schema is missing SQLite tables: ' . implode(', ', $missingTables));
            }

            $backupPath = $sourcePath . '.pre-mysql-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.bak';
            if (!copy($sourcePath, $backupPath)) {
                throw new RuntimeException('Could not back up the SQLite database; no data was copied.');
            }

            $destination->db->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                $destination->transaction(function () use ($source, $destination, $sourceTables, $detachedAuditActors): void {
                    $destination->exec('DELETE FROM settings');
                    foreach ($sourceTables as $table) {
                        if (!preg_match('/^[a-z_]+$/', $table)) {
                            throw new RuntimeException('Unexpected SQLite table name; migration stopped.');
                        }
                        $columns = $source->columns($table);
                        if ($columns === []) { throw new RuntimeException('Could not read SQLite columns for ' . $table); }
                        $targetColumns = array_map(static fn(string $column): string => $column === 'key' ? 'setting_key' : $column, $columns);
                        $quoted = implode(',', array_map(static fn(string $column): string => '`' . $column . '`', $targetColumns));
                        $placeholders = implode(',', array_fill(0, count($columns), '?'));
                        $insert = $destination->db->prepare('INSERT INTO `' . $table . '` (' . $quoted . ') VALUES (' . $placeholders . ')');
                        foreach ($source->all('SELECT * FROM `' . $table . '`') as $row) {
                            if ($table === 'audit' && isset($detachedAuditActors[(int)$row['id']])) {
                                $row['actor_id'] = null;
                            }
                            $insert->execute(array_values($row));
                        }
                    }

                    foreach ($sourceTables as $table) {
                        $sourceCount = (int)$source->one('SELECT COUNT(*) AS n FROM `' . $table . '`')['n'];
                        $targetCount = (int)$destination->one('SELECT COUNT(*) AS n FROM `' . $table . '`')['n'];
                        if ($sourceCount !== $targetCount) {
                            throw new RuntimeException('Row count verification failed for table ' . $table . '.');
                        }
                    }
                });
            } finally {
                $destination->db->exec('SET FOREIGN_KEY_CHECKS=1');
            }

            CLI::write('SQLite data copied and row counts verified.');
            CLI::write('SQLite backup: ' . $backupPath);
            CLI::write('MySQL tables ready: ' . count($targetTables));
            if ($detachedAuditActors !== []) {
                CLI::write('Detached ' . count($detachedAuditActors) . ' orphaned audit actor references; audit records were preserved.');
            }
            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            log_message('error', 'Platform SQLite-to-MySQL migration failed: {type}', ['type' => get_class($e)]);
            CLI::error($e->getMessage());
            return EXIT_ERROR;
        }
    }
}
