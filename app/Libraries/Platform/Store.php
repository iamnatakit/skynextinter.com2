<?php

namespace App\Libraries\Platform;

use PDO;
use RuntimeException;
use Throwable;

/** Local transactional store. All money is integer satang, never floating point. */
class Store
{
    public PDO $db;
    private string $driver;
    private bool $transactionActive = false;
    private int $savepointNumber = 0;

    public function __construct(?string $path = null, ?string $driver = null)
    {
        $this->driver = strtolower($driver ?? ($path !== null ? 'sqlite' : (string)env('PLATFORM_DB_DRIVER', 'mysql')));
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        if ($this->driver === 'sqlite') {
            $path ??= (string)env('PLATFORM_DB_PATH', WRITEPATH . 'platform.sqlite');
            $this->db = new PDO('sqlite:' . $path, null, null, $options);
            $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
        } elseif ($this->driver === 'mysql') {
            $host = (string)env('PLATFORM_DB_HOST', '127.0.0.1');
            $port = (int)env('PLATFORM_DB_PORT', 3306);
            $database = (string)env('PLATFORM_DB_NAME', '');
            if ($database === '' || !preg_match('/^[A-Za-z0-9_.-]+$/D', $database)) {
                throw new RuntimeException('PLATFORM_DB_NAME must be set to a valid MySQL database name');
            }
            $this->db = new PDO(
                'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
                (string)env('PLATFORM_DB_USER', ''),
                (string)env('PLATFORM_DB_PASSWORD', ''),
                $options + [PDO::ATTR_EMULATE_PREPARES => false]
            );
            $this->db->exec("SET time_zone = '+00:00'");
        } else {
            throw new RuntimeException('PLATFORM_DB_DRIVER must be mysql or sqlite');
        }
        if (env('DEEPSEEK_API_KEY') && $this->installed() && $this->tableExists('settings') && $this->setting('deepseek_init') !== '1') {
            $this->set('ai_enabled', '1');
            $this->set('deepseek_init', '1');
        }
    }

    public function installed(): bool { return $this->tableExists('users'); }
    public function driver(): string { return $this->driver; }
    public function all(string $sql, array $params = []): array { $q = $this->db->prepare($this->sql($sql)); $q->execute($params); return $q->fetchAll(); }
    public function one(string $sql, array $params = []): ?array { return $this->all($sql, $params)[0] ?? null; }
    public function exec(string $sql, array $params = []): int { $q = $this->db->prepare($this->sql($sql)); $q->execute($params); return $q->rowCount(); }

    private function sql(string $sql): string
    {
        if ($this->driver !== 'mysql') { return $sql; }
        $sql = preg_replace('/\bINSERT\s+OR\s+IGNORE\b/i', 'INSERT IGNORE', $sql);
        $sql = preg_replace('/\bCAST\(\?\s+AS\s+BLOB\)/i', '?', $sql);
        $sql = preg_replace('/\bGLOB\s+\'\[0-9\]\*\'/i', "REGEXP '^[0-9]'", $sql);
        $sql = preg_replace('/\bON\s+CONFLICT(?:\s*\([^)]*\))?\s+DO\s+UPDATE\s+SET\b/i', 'ON DUPLICATE KEY UPDATE', $sql);
        $sql = preg_replace('/\bexcluded\.([a-z_][a-z0-9_]*)/i', 'VALUES($1)', $sql);
        $sql = preg_replace("/datetime\\('now','-([0-9]+) days'\\)/i", 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL $1 DAY)', $sql);
        $sql = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON DUPLICATE __MYSQL_DUPLICATE_KEY__ UPDATE', $sql);
        $sql = preg_replace('/\bkey\b/i', 'setting_key', $sql);
        $sql = str_replace('__MYSQL_DUPLICATE_KEY__', 'KEY', $sql);
        return $sql;
    }

    private function installMySqlSchema(): void
    {
        $files = [
            'platform.sql',
            'platform_billing.sql',
            'workspace.sql',
            'registration_plans.sql',
            'member_photos.sql',
            'member_promotion.sql',
            'member_name_publications.sql',
            'pending_referrals.sql',
        ];
        $tables = [];
        $indexes = [];
        foreach ($files as $file) {
            $source = file_get_contents(APPPATH . 'Database/' . $file);
            if ($source === false) { throw new RuntimeException('Cannot read platform schema: ' . $file); }
            $source = preg_replace('/^\s*--.*$/m', '', $source);
            foreach (preg_split('/;\s*(?:\r?\n|$)/', trim($source), -1, PREG_SPLIT_NO_EMPTY) as $statement) {
                $statement = trim($statement);
                if (preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([a-z_]+)/i', $statement, $match)) {
                    $tables[$match[1]] = $statement;
                } elseif (preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\b/i', $statement)) {
                    $indexes[] = $statement;
                }
            }
        }
        $expectedTables = array_keys($tables);

        while ($tables !== []) {
            $progress = false;
            foreach ($tables as $name => $statement) {
                preg_match_all('/\bREFERENCES\s+([a-z_]+)/i', $statement, $matches);
                $dependencies = array_diff(array_unique($matches[1]), [$name]);
                if (array_diff($dependencies, $this->tableNames()) !== []) { continue; }
                $this->db->exec($this->mysqlCreateTable($statement));
                unset($tables[$name]);
                $progress = true;
            }
            if (!$progress) { throw new RuntimeException('Could not resolve MySQL schema table dependencies'); }
        }

        foreach ($expectedTables as $table) {
            if (!$this->tableExists($table)) { throw new RuntimeException('MySQL schema table was not created: ' . $table); }
        }

        foreach ($indexes as $statement) {
            if (preg_match('/\bWHERE\b/i', $statement)) { continue; }
            if (!preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+([a-z_]+)\s+ON\s+([a-z_]+)/i', $statement, $match)) {
                throw new RuntimeException('Unsupported MySQL index definition');
            }
            if (!$this->indexExists($match[3], $match[2])) {
                $this->db->exec(preg_replace('/\bIF\s+NOT\s+EXISTS\b/i', '', $statement));
            }
        }

        $this->installMySqlConditionalIndexes();
    }

    private function mysqlCreateTable(string $statement): string
    {
        $statement = preg_replace('/(^|[,(]\s*)key(?=\s+TEXT\b)/im', '$1setting_key', $statement);
        $statement = preg_replace('/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i', 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $statement);
        $statement = preg_replace('/\bINTEGER\s+PRIMARY\s+KEY\b/i', 'BIGINT UNSIGNED NOT NULL PRIMARY KEY', $statement);
        $statement = preg_replace('/\bINTEGER\b/i', 'BIGINT UNSIGNED', $statement);
        $statement = preg_replace('/\bBLOB\b/i', 'LONGBLOB', $statement);
        $largeText = ['body', 'description', 'excerpt', 'detail', 'private_data', 'notes', 'note', 'review_note', 'bot_knowledge', 'affiliate_bio', 'headline', 'intro', 'benefits', 'offer', 'address', 'error', 'short_description', 'value'];
        $wideText = ['path', 'source_path'];
        $statement = preg_replace_callback('/\b([a-z_][a-z0-9_]*)\s+TEXT\b/i', static function (array $match) use ($largeText, $wideText): string {
            $column = strtolower($match[1]);
            if (in_array($column, $largeText, true)) { return $match[1] . ' MEDIUMTEXT'; }
            if (in_array($column, $wideText, true)) { return $match[1] . ($column === 'path' ? ' VARCHAR(2048)' : ' VARCHAR(512)'); }
            if (in_array($column, ['created_at', 'updated_at', 'submitted_at', 'published_at', 'paid_at', 'accepted_at', 'reviewed_at', 'sent_at', 'due_at', 'expires_at', 'trial_started_at', 'trial_ends_at', 'verified_at', 'consent_at'], true)) {
                return $match[1] . ' VARCHAR(32)';
            }
            return $match[1] . (in_array($column, ['email', 'contact', 'recipient', 'account', 'bank_account', 'line_url', 'facebook_url'], true) ? ' VARCHAR(255)' : ' VARCHAR(191)');
        }, $statement);
        $statement = preg_replace('/\s+COLLATE\s+NOCASE\b/i', '', $statement);
        return $statement . ' ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool)$this->one(
            'SELECT index_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
            [$table, $index]
        );
    }

    private function installMySqlConditionalIndexes(): void
    {
        if (!$this->columnExists('payments', 'pending_order_id')) {
            $this->db->exec("ALTER TABLE payments ADD COLUMN pending_order_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('pending','approved') THEN order_id ELSE NULL END) VIRTUAL");
        }
        if (!$this->indexExists('payments', 'one_pending_payment')) {
            $this->db->exec('CREATE UNIQUE INDEX one_pending_payment ON payments(pending_order_id)');
        }
        if (!$this->columnExists('platform_invoices', 'open_invoice_user_id')) {
            $this->db->exec("ALTER TABLE platform_invoices ADD COLUMN open_invoice_user_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('pending','checking','review') THEN user_id ELSE NULL END) VIRTUAL");
        }
        if (!$this->indexExists('platform_invoices', 'platform_open_invoice')) {
            $this->db->exec('CREATE UNIQUE INDEX platform_open_invoice ON platform_invoices(open_invoice_user_id)');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    public function tableNames(): array
    {
        $rows = $this->driver === 'sqlite'
            ? $this->all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            : $this->all('SELECT table_name AS name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name');
        return array_column($rows, 'name');
    }

    public function tableExists(string $table): bool
    {
        if (!preg_match('/^[a-z_]+$/', $table)) { throw new RuntimeException('Invalid table'); }
        return $this->driver === 'sqlite'
            ? (bool)$this->one("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table])
            : (bool)$this->one('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]);
    }

    public function columns(string $table): array
    {
        if (!preg_match('/^[a-z_]+$/', $table)) { throw new RuntimeException('Invalid table'); }
        $rows = $this->driver === 'sqlite'
            ? $this->all('PRAGMA table_info(' . $table . ')')
            : $this->all('SHOW COLUMNS FROM `' . $table . '`');
        return array_column($rows, $this->driver === 'sqlite' ? 'name' : 'Field');
    }
    public function insert(string $table, array $data): int
    {
        // Invariant: every account receives the next immutable Affiliate ID.
        if ($table === 'users' && !$this->transactionActive) {
            return $this->transaction(fn() => $this->insert($table, $data));
        }
        if ($table === 'users') { $data['referral_code'] = Affiliate::nextCode($this); }
        if (!preg_match('/^[a-z_]+$/', $table) || array_filter(array_keys($data), fn($k) => !preg_match('/^[a-z_]+$/', $k))) { throw new RuntimeException('Invalid column'); }
        $this->exec('INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')', array_values($data));
        $id = (int) $this->db->lastInsertId();
        if ($table === 'users') { $this->set('affiliate_sequence', $data['referral_code']); }
        return $id;
    }
    public function transaction(callable $callback): mixed
    {
        if ($this->transactionActive) {
            $savepoint='sn_nested_'.(++$this->savepointNumber);
            $this->db->exec('SAVEPOINT '.$savepoint);
            try { $result=$callback();$this->db->exec('RELEASE SAVEPOINT '.$savepoint);return $result; }
            catch(Throwable $e){$this->db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$this->db->exec('RELEASE SAVEPOINT '.$savepoint);throw $e;}
        }
        if ($this->driver === 'sqlite') { $this->db->exec('BEGIN IMMEDIATE'); }
        else { $this->db->beginTransaction(); }
        $this->transactionActive = true;
        try {
            $result = $callback();
            if ($this->driver === 'sqlite') { $this->db->exec('COMMIT'); }
            else { $this->db->commit(); }
            return $result;
        } catch (Throwable $e) {
            if ($this->driver === 'sqlite') { $this->db->exec('ROLLBACK'); }
            elseif ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
        finally { $this->transactionActive = false; }
    }
    public function audit(?int $actor, string $action, string $entity, int $id = 0, string $detail = ''): void
    { $this->insert('audit', ['actor_id' => $actor, 'action' => $action, 'entity' => $entity, 'entity_id' => $id, 'detail' => $detail, 'created_at' => self::now()]); }
    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
    public function setting(string $key, string $default = ''): string { return $this->one('SELECT value FROM settings WHERE key=?', [$key])['value'] ?? $default; }
    public function set(string $key, string $value): void { $this->exec('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key, $value]); }
    public static function amount(string $value): int
    {
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) { throw new RuntimeException('จำนวนเงินไม่ถูกต้อง'); }
        [$whole, $decimal] = array_pad(explode('.', $value), 2, '');
        return (int) $whole * 100 + (int) str_pad($decimal, 2, '0');
    }
    public function rate(string $key, int $limit, int $seconds): void
    {
        $key = hash('sha256', $key . ':' . (int) floor(time() / $seconds));
        $this->exec('INSERT INTO rate_limits(key,hits,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET hits=hits+1', [$key, time() + $seconds]);
        if (($this->one('SELECT hits FROM rate_limits WHERE key=?', [$key])['hits'] ?? 0) > $limit) { throw new RuntimeException('ทำรายการถี่เกินไป กรุณารอสักครู่'); }
    }
    public function install(): void
    {
        if ($this->driver === 'mysql') {
            $this->installMySqlSchema();
        } else {
            $this->db->exec(file_get_contents(APPPATH . 'Database/platform.sql'));
            $this->db->exec(file_get_contents(APPPATH . 'Database/platform_billing.sql'));
            $this->db->exec(file_get_contents(APPPATH . 'Database/affiliate_sequence.sql'));
            $this->db->exec(file_get_contents(APPPATH . 'Database/workspace.sql'));
            $this->db->exec(file_get_contents(APPPATH . 'Database/registration_plans.sql'));
            $this->db->exec(file_get_contents(APPPATH . 'Database/member_photos.sql'));
            $this->db->exec(file_get_contents(APPPATH . 'Database/member_promotion.sql'));
            $this->db->exec(file_get_contents(APPPATH . 'Database/member_name_publications.sql'));
            $this->db->exec(file_get_contents(APPPATH . 'Database/pending_referrals.sql'));
            $this->migrateAnnualOfferAmounts();
            $this->migrateTeamRoles();
            $this->migrateProductPV();
        }
        $productColumns = $this->columns('products');
        $productDefinitions = [
            'member_price_satang' => $this->driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : 'INTEGER CHECK(member_price_satang IS NULL OR member_price_satang>=0)',
            'inquiry_only' => 'INTEGER NOT NULL DEFAULT 0',
            'short_description' => "TEXT NOT NULL DEFAULT ''",
            'inquiry_label' => "TEXT NOT NULL DEFAULT ''",
            'discount_kind' => "TEXT NOT NULL DEFAULT '' CHECK(discount_kind IN ('','fixed','percent'))",
            'discount_value' => 'INTEGER NOT NULL DEFAULT 0 CHECK(discount_value>=0)',
            'pv' => 'INTEGER NOT NULL DEFAULT 0',
        ];
        foreach ($productDefinitions as $column => $definition) {
            if (!in_array($column, $productColumns, true)) { $this->db->exec('ALTER TABLE products ADD COLUMN ' . $column . ' ' . $definition); }
        }
        if ($this->driver === 'mysql' && $this->tableExists('order_items') && !$this->columnExists('order_items', 'pv')) {
            $this->db->exec('ALTER TABLE order_items ADD COLUMN pv BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }
        $userColumns = $this->columns('users');
        if (!in_array('auth_version', $userColumns, true)) {
            $this->db->exec('ALTER TABLE users ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 0');
        }
        $columns = $this->columns('users');
        foreach(['public_province'=>"TEXT NOT NULL DEFAULT ''",'facebook_url'=>"TEXT NOT NULL DEFAULT ''",'line_url'=>"TEXT NOT NULL DEFAULT ''",'public_name'=>"TEXT NOT NULL DEFAULT ''",'affiliate_headline'=>"TEXT NOT NULL DEFAULT ''",'affiliate_bio'=>"TEXT NOT NULL DEFAULT ''",'affiliate_public'=>'INTEGER NOT NULL DEFAULT 0','bank_name'=>"TEXT NOT NULL DEFAULT ''",'bank_account'=>"TEXT NOT NULL DEFAULT ''",'bank_account_name'=>"TEXT NOT NULL DEFAULT ''"] as $column=>$definition){
            if(!in_array($column,$columns,true)){
                if ($this->driver === 'mysql') {
                    $definition = in_array($column, ['affiliate_bio'], true) ? "MEDIUMTEXT NOT NULL DEFAULT ''" : "VARCHAR(191) NOT NULL DEFAULT ''";
                }
                $this->db->exec('ALTER TABLE users ADD COLUMN '.$column.' '.$definition);
            }
        }
        $defaultRoleMenus = json_encode(Access::DEFAULT_ROLE_MENUS, JSON_UNESCAPED_UNICODE);
        foreach (['store_name' => 'Project Field Shop', 'shipping_satang' => '0', 'bank_details' => '', 'bot_enabled' => '1', 'ai_enabled' => '0', 'analytics_enabled' => '1', 'bot_knowledge' => 'ยินดีต้อนรับ สามารถดูสินค้าและสั่งซื้อได้ที่หน้าร้าน หากต้องการคำแนะนำให้ฝากข้อมูลติดต่อและทีมงานจะติดต่อกลับ', 'line_enabled' => '0', 'facebook_enabled' => '0', 'role_menus' => $defaultRoleMenus] as $k => $v) {
            $this->exec('INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)', [$k, $v]);
        }
        if ($this->setting('workspace_permissions_v1') !== '1') {
            $menus=json_decode($this->setting('role_menus','{}'),true)?:[];
            foreach(Access::DEFAULT_ROLE_MENUS as $role=>$defaults){$menus[$role]=array_values(array_unique([...($menus[$role]??$defaults),'salespages']));}
            $this->set('role_menus',json_encode($menus,JSON_UNESCAPED_UNICODE));$this->set('workspace_permissions_v1','1');
        }
    }
    /** Preserve columns, indexes, triggers and references while expanding SQLite's role CHECK. */
    public function migrateTeamRoles(): void
    {
        $sql=$this->one("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")['sql']??'';
        if (str_contains($sql,"'downline_team'")) { return; }
        $updated=preg_replace("/CHECK\\s*\\(role IN \\(([^)]*)\\)\\)/i", "CHECK(role IN ($1,'downline_team','external_team'))",$sql,1,$count);
        if ($count!==1) { throw new RuntimeException('Cannot safely expand users role constraint'); }
        $objects=$this->all("SELECT sql FROM sqlite_master WHERE tbl_name='users' AND type IN ('index','trigger') AND sql IS NOT NULL");
        $before=$this->all('PRAGMA foreign_key_check');
        $sequence=(int)($this->one("SELECT seq FROM sqlite_sequence WHERE name='users'")['seq']??0);
        $this->db->exec('PRAGMA foreign_keys=OFF');
        try {
            $this->transaction(function() use($updated,$objects,$before,$sequence) {
                $this->db->exec(preg_replace('/^CREATE TABLE (?:"users"|users)/i','CREATE TABLE users_role_migration',$updated));
                $columns=implode(',',array_map(fn($r)=>'"'.$r['name'].'"',$this->all('PRAGMA table_info(users)')));
                $this->db->exec('INSERT INTO users_role_migration('.$columns.') SELECT '.$columns.' FROM users');
                $this->db->exec('DROP TABLE users');
                $this->db->exec('ALTER TABLE users_role_migration RENAME TO users');
                $this->exec("UPDATE sqlite_sequence SET seq=MAX(seq,?) WHERE name='users'",[$sequence]);
                foreach($objects as $object){$this->db->exec($object['sql']);}
                if ($this->all('PRAGMA foreign_key_check')!==$before) { throw new RuntimeException('Role migration changed foreign key integrity'); }
            });
        } finally { $this->db->exec('PRAGMA foreign_keys=ON'); }
    }
    public function migrateAnnualOfferAmounts(): void
    {
        foreach(['platform_invoices','platform_renewal_orders'] as $table){
            $sql=$this->one("SELECT sql FROM sqlite_master WHERE type='table' AND name=?",[$table])['sql']??'';
            if(!str_contains($sql,'amount_satang=99000') && !str_contains($sql,'amount_satang IN (39900,99000)')){continue;}
            $objects=$this->all("SELECT sql FROM sqlite_master WHERE tbl_name=? AND type IN ('index','trigger') AND sql IS NOT NULL",[$table]);
            $before=$this->all('PRAGMA foreign_key_check');
            $sequence=(int)($this->one('SELECT seq FROM sqlite_sequence WHERE name=?',[$table])['seq']??0);
            $replacement=str_replace(['amount_satang=99000','amount_satang IN (39900,99000)'],'amount_satang>0',$sql);
            $replacement=preg_replace('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?["`]?'.preg_quote($table,'/').'["`]?/i','CREATE TABLE '.$table.'_offer_migration',$replacement,1);
            $this->db->exec('PRAGMA foreign_keys=OFF');
            try{$this->transaction(function()use($table,$replacement,$objects,$sequence,$before){
                $this->db->exec($replacement);
                $this->db->exec('INSERT INTO '.$table.'_offer_migration SELECT * FROM '.$table);
                $this->db->exec('DROP TABLE '.$table);
                $this->db->exec('ALTER TABLE '.$table.'_offer_migration RENAME TO '.$table);
                $this->exec('UPDATE sqlite_sequence SET seq=MAX(seq,?) WHERE name=?',[$sequence,$table]);
                foreach($objects as $object){$this->db->exec($object['sql']);}
                if($this->all('PRAGMA foreign_key_check')!==$before){throw new RuntimeException('Annual offer migration changed foreign key integrity');}
            });}finally{$this->db->exec('PRAGMA foreign_keys=ON');}
        }
    }
    public function migrateProductPV(): void
    {
        foreach(['products','order_items'] as $table){
            if(!in_array('pv',array_column($this->all('PRAGMA table_info('.$table.')'),'name'),true)){
                $this->db->exec('ALTER TABLE '.$table.' ADD COLUMN pv INTEGER NOT NULL DEFAULT 0 CHECK(pv>=0)');
            }
        }
    }
}
