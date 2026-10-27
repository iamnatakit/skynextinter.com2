<?php

namespace App\Commands;

use App\Libraries\Platform\Store;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class PlatformInstall extends BaseCommand
{
    protected $group = 'Platform';
    protected $name = 'platform:install';
    protected $description = 'Create the platform database and first administrator; import captured products.';
    public function run(array $params)
    {
        $store = new Store(); $store->install();
        if (!$store->one("SELECT id FROM users WHERE role='admin'")) {
            $email = $params[0] ?? 'admin@skynext.local';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { CLI::error('Invalid administrator email'); return; }
            $password = bin2hex(random_bytes(10)) . '!Aa';
            $store->insert('users', ['name' => 'ผู้ดูแลระบบ', 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'admin', 'referral_code' => \App\Libraries\Platform\Affiliate::nextCode($store), 'created_at' => Store::now()]);
            CLI::write('Administrator: ' . $email);
            CLI::write('Initial password (shown once): ' . $password);
            CLI::write('Change the password in /account after signing in.');
        }
        $catalog = APPPATH . 'Database/catalog.json';
        if (is_file($catalog)) {
            $count = 0;
            $store->transaction(function () use ($store, $catalog, &$count) {
                foreach (json_decode(file_get_contents($catalog), true, 512, JSON_THROW_ON_ERROR) as $p) {
                    if ($store->one('SELECT id FROM products WHERE sku=?', [$p['sku']])) { continue; }
                    $p['price_satang'] = Store::amount($p['price']); unset($p['price']);
                    $store->insert('products', $p + ['active' => 1, 'stock' => 0, 'created_at' => Store::now()]); $count++;
                }
            });
            CLI::write('Imported products: ' . $count . '. Set actual stock in /admin/products.');
        }
        CLI::write('Platform ready: /admin and /shop');
    }
}
