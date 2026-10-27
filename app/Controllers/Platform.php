<?php
namespace App\Controllers;

use App\Libraries\Platform\{Store, Commerce, Access, Bot, Gateways};
use CodeIgniter\Exceptions\PageNotFoundException;
use RuntimeException;
use Throwable;

class Platform extends BaseController
{
    protected Store $store;
    protected ?array $user = null;
    protected $helpers = ['form','url','platform'];

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->store = new Store();
        if ($this->store->installed()) { $this->user = $this->store->one('SELECT * FROM users WHERE id=? AND active=1', [(int)session('user_id')]); }
        if ($this->user && (int)$this->user['auth_version'] !== (int)session('auth_version')) { $this->user=null; session()->remove('user_id'); }
        $response->setHeader('Cache-Control','no-store')->setHeader('X-Content-Type-Options','nosniff')->setHeader('X-Frame-Options','SAMEORIGIN');
    }
    protected function page(string $name, array $data = []): string
    { return view('platform/layout', ['content' => view('platform/' . $name, $data + ['user' => $this->user]), 'user' => $this->user, 'title' => $data['title'] ?? 'SkyNext Commerce', 'indexable'=>$data['indexable']??false, 'description'=>$data['description']??'', 'store' => $this->store]); }
    protected function post(): bool { return $this->request->getMethod() === 'POST'; }
    protected function input(string $key, string $default = ''): string { return trim((string)($this->request->getPost($key) ?? $default)); }
    protected function fail(Throwable $e, string $path)
    {
        if ($e instanceof RuntimeException && !$e instanceof \PDOException) { $message = $e->getMessage(); }
        else { log_message('error', 'Platform operation failed: {type}', ['type' => get_class($e)]); $message = 'ทำรายการไม่สำเร็จ กรุณาตรวจข้อมูลและลองใหม่'; }
        return redirect()->to(site_url($path))->with('error', $message);
    }
    public function auth(string $mode = 'login')
    {
        if (!$this->store->installed()) { return $this->response->setStatusCode(503)->setBody('Run php spark platform:install'); }
        if ($this->post()) {
            try {
                $this->store->rate('auth:' . $this->request->getIPAddress(), 15, 900);
                if ($mode === 'register') {
                    if ($this->input('consent') !== '1') { throw new RuntimeException('กรุณายอมรับการใช้ข้อมูลเพื่อจัดการบัญชีและคำสั่งซื้อ'); }
                    $ref=$this->registrationReferral();
                    $id = (new Commerce($this->store))->register($this->input('name'), $this->input('email'), (string)$this->request->getPost('password'), $ref, $this->input('phone'), ['province'=>$this->input('province'),'membership_kind'=>$this->input('membership_kind','new'),'skynext_member_code'=>$this->input('skynext_member_code'),'annual_opt_in'=>$this->input('annual_opt_in')]);
                    session()->remove('referral');
                } else {
                    $u = $this->store->one('SELECT * FROM users WHERE email=? AND active=1', [$this->input('email')]);
                    $valid = password_verify((string)$this->request->getPost('password'), $u['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuN7fM0bGkYnPBMR3oc8LGGJ7cU2eShGe');
                    if (!$u || !$valid) { throw new RuntimeException('อีเมลหรือรหัสผ่านไม่ถูกต้อง'); }
                    $id = (int)$u['id'];
                }
                session()->regenerate(true); session()->set('user_id', $id);
                $this->store->audit($id, $mode, 'user', $id);
                $u = $this->store->one('SELECT role,auth_version FROM users WHERE id=?', [$id]);
                session()->set('auth_version',(int)$u['auth_version']);
                $destination=session('annual_website_intent')==='1' && $u['role']!=='member'?'platform/billing':($u['role']==='admin'?'admin':'workspace');
                session()->remove('annual_website_intent');
                return redirect()->to(site_url($destination))->deleteCookie('sn_ref');
            } catch (Throwable $e) { return $this->fail($e, $mode); }
        }
        return $this->page('auth', ['mode' => $mode, 'registrationReferral'=>$mode==='register'?$this->registrationReferral():'', 'title' => $mode === 'register' ? 'สมัครสมาชิก' : 'เข้าสู่ระบบ']);
    }
    private function registrationReferral(): string
    {
        $ref='';$context=session('referral');
        if(is_array($context) && (int)($context['expires']??0)>=time()){$ref=(string)($context['code']??'');}
        $token=(string)$this->request->getCookie('sn_ref');
        if(preg_match('/^[a-f0-9]{48}$/D',$token)){
            $saved=$this->store->one('SELECT u.referral_code FROM referral_tokens t JOIN users u ON u.id=t.sponsor_id WHERE t.token_hash=? AND t.expires>=? AND u.active=1',[hash('sha256',$token),time()]);
            if($saved){$ref=$saved['referral_code'];}
        }
        return $ref;
    }
    public function logout() { session()->destroy(); return redirect()->to(site_url('login')); }
    public function annualWebsite()
    {
        if($this->user){return redirect()->to(site_url('platform/billing'));}
        session()->set('annual_website_intent','1');
        return redirect()->to(site_url('register'))->with('success','สมัครบัญชีเพื่อใช้แพ็กเกจเว็บไซต์ 990 บาท/ปี หากมีบัญชีแล้วให้เข้าสู่ระบบ');
    }
    public function catalog(?string $id = null)
    {
        $q = mb_substr((string)$this->request->getGet('q'), 0, 120);
        $category = mb_substr((string)$this->request->getGet('category'), 0, 120);
        $page = max(1,(int)$this->request->getGet('page')); $offset = ($page - 1) * 24;
        if ($id !== null) {
            $p = $this->store->one('SELECT * FROM products WHERE id=? AND active=1', [(int)$id]);
            if (!$p) { throw PageNotFoundException::forPageNotFound(); }
            $images = $this->store->tableExists('product_images')
                ? $this->store->all('SELECT image,alt FROM product_images WHERE product_id=? ORDER BY sort_order,id', [(int)$id]) : [];
            return $this->page('product', ['product' => $p, 'images' => $images, 'title' => $p['name']]);
        }
        $params = ['%' . $q . '%', '%' . $q . '%', $category, $category, $category . ' / %'];
        $where = " WHERE active=1 AND (name LIKE ? OR description LIKE ?) AND (?='' OR category=? OR category LIKE ?)";
        $products = $this->store->all('SELECT * FROM products' . $where . ' ORDER BY id LIMIT 24 OFFSET ' . $offset, $params);
        $total = $this->store->one('SELECT COUNT(*) n FROM products' . $where, $params)['n'];
        return $this->page('catalog', ['products' => $products, 'categories' => $this->store->all("SELECT DISTINCT category FROM products WHERE active=1 ORDER BY category"), 'q' => $q, 'category' => $category, 'page' => $page, 'total' => $total, 'title' => 'สินค้าของเรา']);
    }
    public function favorites(?string $id = null)
    {
        $ids=array_values(array_unique(array_map('intval',session('favorites')??[])));
        if($this->post() && $id!==null){
            $product=$this->store->one('SELECT id FROM products WHERE id=? AND active=1',[(int)$id]);
            if(!$product){throw PageNotFoundException::forPageNotFound();}
            if(in_array((int)$id,$ids,true)){$ids=array_values(array_diff($ids,[(int)$id]));}
            elseif(count($ids)<100){$ids[]=(int)$id;}
            session()->set('favorites',$ids);
            return redirect()->to(site_url($this->input('return')==='favorites'?'favorites':'shop'));
        }
        $products=$ids?$this->store->all('SELECT * FROM products WHERE active=1 AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id',$ids):[];
        return $this->page('catalog',['products'=>$products,'categories'=>[],'q'=>'','category'=>'','page'=>1,'total'=>count($products),'favorites'=>true,'title'=>'รายการโปรด']);
    }
    public function cart()
    {
        $cart = session('cart') ?? [];
        if ($this->post()) {
            try {
                $id = (int)$this->input('product_id'); $quantity = filter_var($this->input('quantity'), FILTER_VALIDATE_INT);
                $p = $this->store->one('SELECT * FROM products WHERE id=?', [$id]);
                if (!$p || $quantity === false || $quantity < 0 || $quantity > 100) { throw new RuntimeException('ข้อมูลสินค้าไม่ถูกต้อง'); }
                if ($this->input('mode') === 'add') { $quantity += (int)($cart[$id] ?? 0); }
                if ($quantity > 0 && !$p['active']) { throw new RuntimeException('สินค้านี้ปิดขายแล้ว กรุณาลบออกจากตะกร้า'); }
                if ($quantity > min(100,$p['stock'])) { throw new RuntimeException('สินค้าในสต็อกไม่เพียงพอ'); }
                if ($quantity === 0) { unset($cart[$id]); } else { $cart[$id] = $quantity; }
                if (count($cart) > 50) { throw new RuntimeException('ตะกร้ามีสินค้ามากเกินไป'); }
                session()->set('cart', $cart);
                if ($this->request->isAJAX()) {
                    return $this->response->setJSON([
                        'status' => 'ok',
                        'message' => 'อัปเดตตะกร้าแล้ว',
                        'cart_count' => array_sum($cart),
                        'csrf' => csrf_hash(),
                        'csrf_name' => csrf_token(),
                    ]);
                }
                return redirect()->to(site_url('cart'))->with('success','อัปเดตตะกร้าแล้ว');
            } catch (Throwable $e) {
                if ($this->request->isAJAX()) {
                    return $this->response->setStatusCode(422)->setJSON([
                        'status' => 'error',
                        'message' => $e->getMessage(),
                        'csrf' => csrf_hash(),
                        'csrf_name' => csrf_token(),
                    ]);
                }
                return $this->fail($e,'cart');
            }
        }
        $items = [];
        foreach ($cart as $id => $qty) {
            $p = $this->store->one('SELECT * FROM products WHERE id=?', [$id]);
            if ($p) { $p=\App\Libraries\Platform\ProductPricing::apply($p,\App\Libraries\Platform\ProductPricing::eligible($this->user)); $p['quantity'] = $qty; $items[] = $p; }
        }
        return $this->page('cart', ['items' => $items, 'title' => 'ตะกร้าสินค้า']);
    }
    public function checkout()
    {
        $key = session('checkout_key');
        if (!$key) { $key = bin2hex(random_bytes(16)); session()->set('checkout_key', $key); }
        if ($this->post()) {
            try {
                $id = (new Commerce($this->store))->checkout((int)$this->user['id'], session('cart') ?? [], $this->input('coupon'), ['recipient' => $this->input('recipient'), 'phone' => $this->input('phone'), 'address' => $this->input('address')], $this->input('idempotency_key'));
                session()->remove(['cart','checkout_key']);
                return redirect()->to(site_url('orders/' . $id))->with('success','สั่งซื้อสำเร็จ กรุณาชำระเงิน');
            } catch (Throwable $e) { return $this->fail($e,'checkout'); }
        }
        try { $quote = (new Commerce($this->store))->quote(session('cart') ?? [],'',(int)$this->user['id']); }
        catch (Throwable $e) { return $this->fail($e,'cart'); }
        return $this->page('checkout', ['quote' => $quote, 'key' => $key, 'title' => 'ยืนยันคำสั่งซื้อ']);
    }
    public function paymentPortal()
    {
        $this->response->setHeader('Cache-Control','private, no-store');
        $orders=$this->user?$this->store->all('SELECT id,number,total_satang,status,created_at FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 50',[$this->user['id']]):[];
        return $this->page('payment_portal',['title'=>'แจ้งชำระเงิน','orders'=>$orders]);
    }
    public function order(string $id)
    {
        $o = $this->store->one('SELECT * FROM orders WHERE id=? AND user_id=?', [(int)$id, $this->user['id']]);
        if (!$o) { throw PageNotFoundException::forPageNotFound(); }
        $o['payment_token']=$this->store->one('SELECT token,issued_at,revoked_at FROM payment_tokens WHERE order_id=?',[$o['id']]);
        $this->response->setHeader('Cache-Control','private, no-store');
        return $this->page('order', ['order' => $o, 'items' => $this->store->all('SELECT * FROM order_items WHERE order_id=?', [$o['id']]), 'payments' => $this->store->all('SELECT * FROM payments WHERE order_id=?', [$o['id']]), 'bank' => $this->store->setting('bank_details'), 'gateway' => $this->store->all('SELECT * FROM gateway_attempts WHERE order_id=? ORDER BY id DESC',[$o['id']]), 'title' => 'คำสั่งซื้อ ' . $o['number']]);
    }
    public function payment(string $id)
    {
        $filename = null;
        try {
            $this->store->rate('payment:' . $this->user['id'], 10, 3600);
            $file = $this->request->getFile('slip');
            if (!$file || !$file->isValid() || $file->getSize() > 5 * 1024 * 1024) { throw new RuntimeException('อัปโหลดรูปหลักฐาน JPG, PNG หรือ WebP ไม่เกิน 5 MB'); }
            $mime = $file->getMimeType(); $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
            if (!$ext || !getimagesize($file->getTempName())) { throw new RuntimeException('ไฟล์รูปภาพไม่ถูกต้อง'); }
            $reference = $this->input('reference');
            if($reference==='' && \App\Libraries\Platform\SlipPayments::enabled()){$reference='auto-verification';}
            if (strlen($reference)<3 || strlen($reference)>160) { throw new RuntimeException('กรุณาระบุเลขอ้างอิงการโอน'); }
            $hash = hash_file('sha256', $file->getTempName());
            $filename = bin2hex(random_bytes(16)) . '.' . $ext;
            $file->move(WRITEPATH . 'slips', $filename);
            $payment=(new Commerce($this->store))->submitPayment((int)$id, (int)$this->user['id'], ['amount_satang' => Store::amount($this->input('amount')), 'reference' => $reference, 'slip' => $filename, 'slip_hash' => $hash, 'mime' => $mime]);
            $filename=null; // Submission committed: retain evidence even when the external verifier is unavailable.
            $result=(new \App\Libraries\Platform\SlipPayments($this->store))->check($payment);
            return redirect()->to(site_url('orders/' . (int)$id))->with('success',$result==='verified'?'ตรวจสลิปและยืนยันการชำระเงินสำเร็จ':'รับหลักฐานแล้ว รอเจ้าหน้าที่ตรวจสอบกับรายการธนาคาร');
        } catch (Throwable $e) { if ($filename && is_file(WRITEPATH . 'slips/' . $filename)) { unlink(WRITEPATH . 'slips/' . $filename); } return $this->fail($e,'orders/' . (int)$id); }
    }
    public function cancelOrder(string $id)
    {
        try{
            $o=$this->store->one('SELECT * FROM orders WHERE id=? AND user_id=?',[(int)$id,$this->user['id']]);
            if(!$o || !in_array($o['status'],['awaiting_payment','payment_review'])){throw new RuntimeException('ไม่สามารถยกเลิกคำสั่งซื้อในสถานะนี้ได้');}
            (new Commerce($this->store))->transition($o['id'],$this->user['id'],'cancelled','Customer cancellation');
            return redirect()->to(site_url('orders/'.(int)$id))->with('success','ยกเลิกคำสั่งซื้อแล้ว');
        }catch(Throwable $e){return $this->fail($e,'orders/'.(int)$id);}
    }
    public function account()
    {
        if ($this->post()) {
            try {
                $name = $this->input('name'); $phone = $this->input('phone');
                if (mb_strlen($name)<2 || mb_strlen($name)>120 || strlen($phone)>40) { throw new RuntimeException('ชื่อหรือเบอร์โทรไม่ถูกต้อง'); }
                $password = (string)$this->request->getPost('new_password');
                if ($password !== '') {
                    if (!password_verify((string)$this->request->getPost('current_password'), $this->user['password_hash']) || strlen($password)<12 || strlen($password)>200) { throw new RuntimeException('รหัสผ่านเดิมไม่ถูกต้อง หรือรหัสใหม่สั้นกว่า 12 ตัวอักษร'); }
                    $this->store->exec('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?', [password_hash($password,PASSWORD_DEFAULT),$this->user['id']]);
                    session()->set('auth_version',(int)$this->user['auth_version']+1);
                    session()->regenerate(true);
                }
                $this->store->exec('UPDATE users SET name=?,phone=? WHERE id=?',[$name,$phone,$this->user['id']]);
                $this->store->audit($this->user['id'],'profile_updated','user',$this->user['id']);
                return redirect()->to(site_url('account'))->with('success','บันทึกข้อมูลแล้ว');
            } catch (Throwable $e) { return $this->fail($e,'account'); }
        }
        if($this->user['role']==='member'){return $this->page('customer_account',['title'=>'โปรไฟล์และคำสั่งซื้อของฉัน','orders'=>$this->store->all('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 100',[$this->user['id']])]);}
        $network = $this->store->all('WITH RECURSIVE network(id,name,sponsor_id,depth) AS (SELECT id,name,sponsor_id,1 FROM users WHERE sponsor_id=? UNION ALL SELECT u.id,u.name,u.sponsor_id,n.depth+1 FROM users u JOIN network n ON u.sponsor_id=n.id WHERE n.depth<30) SELECT * FROM network ORDER BY depth,id LIMIT 500',[$this->user['id']]);
        return $this->page('account',['orders'=>$this->store->all('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 100',[$this->user['id']]),'network'=>$network,'title'=>'บัญชีและ Affiliate ของฉัน']);
    }
    public function admin(string $module = 'dashboard')
    {
        if (!$this->user || empty($this->user['active'])) {
            return redirect()->to(site_url('login'));
        }
        $allowed = Access::getMenusForUser($this->user, $this->store);
        if (empty($allowed)) {
            return redirect()->to(site_url('account'))->with('error', 'บัญชีของคุณยังไม่มีสิทธิ์เข้าถึงโมดูลหลังบ้าน');
        }
        if (!Access::can($this->user, $module, $this->store)) {
            return redirect()->to(site_url('admin/' . $allowed[0]))->with('error', 'ไม่มีสิทธิ์เข้าถึงเมนู ' . (Access::MODULES[$module] ?? $module));
        }
        $data = (new \App\Libraries\Platform\Admin($this->store))->data(
            $module,
            (int)$this->request->getGet('edit'),
            mb_substr((string)$this->request->getGet('q'), 0, 120),
            max(1, (int)$this->request->getGet('page')),
            $this->user
        );
        return $this->page('admin', $data + ['module'=>$module, 'title'=>Access::MODULES[$module] ?? $module, 'store'=>$this->store]);
    }
    public function adminAction(string $module, string $action, string $id = '0')
    {
        if (!$this->user || !Access::can($this->user, $module, $this->store)) {
            return $this->response->setStatusCode(403)->setBody('ไม่มีสิทธิ์ดำเนินการในส่วนนี้');
        }
        $newImage=null;
        try {
            $input=$this->request->getPost();
            $photo=in_array($module,['products','blog'])?$this->request->getFile('photo'):null;
            if ($photo && $photo->getError() !== UPLOAD_ERR_NO_FILE) {
                $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$photo->getMimeType()]??null;
                if(!$photo->isValid()||!$ext||$photo->getSize()>5*1024*1024||!getimagesize($photo->getTempName())){throw new RuntimeException('ใช้ภาพ JPG, PNG หรือ WebP ไม่เกิน 5 MB');}
                $newImage=bin2hex(random_bytes(16)).'.'.$ext;
                $photo->move(FCPATH.'platform/uploads',$newImage);$input[$module==='blog'?'cover_image':'image']='platform/uploads/'.$newImage;
            }
            $result = (new \App\Libraries\Platform\Admin($this->store))->save($module,$action,(int)$id,$input,$this->user);
            if ($this->request->isAJAX() || $action === 'test' || str_contains((string)$this->request->getHeaderLine('Accept'), 'application/json')) {
                return $this->response->setJSON(['status'=>'ok','message'=>'ดำเนินการเรียบร้อย','data'=>$result,'csrf'=>csrf_hash(),'csrf_name'=>csrf_token()]);
            }
            return redirect()->to(site_url('admin/'.$module))->with('success','บันทึกรายการแล้ว');
        } catch (Throwable $e) { 
            if($newImage&&is_file(FCPATH.'platform/uploads/'.$newImage)){unlink(FCPATH.'platform/uploads/'.$newImage);}
            if ($this->request->isAJAX() || $action === 'test' || str_contains((string)$this->request->getHeaderLine('Accept'), 'application/json')) {
                return $this->response->setStatusCode(400)->setJSON(['status'=>'error','message'=>$e->getMessage(),'csrf'=>csrf_hash(),'csrf_name'=>csrf_token()]);
            }
            return $this->fail($e,'admin/'.$module); 
        }
    }
    public function slip(string $id)
    {
        $p = $this->store->one('SELECT * FROM payments WHERE id=?',[(int)$id]);
        if (!$p || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D',$p['slip'])) { throw PageNotFoundException::forPageNotFound(); }
        return $this->response->setContentType($p['mime'])->setHeader('Content-Disposition','inline')->setBody(file_get_contents(WRITEPATH.'slips/'.$p['slip']));
    }
    public function gateway(string $id, string $provider)
    {
        try {
            $result = (new Gateways($this->store))->start((int)$id, (int)$this->user['id'], $provider);
            if ($provider === 'stripe') { return redirect()->to($result['url']); }
            return redirect()->to(site_url('orders/'.(int)$id));
        } catch (Throwable $e) { return $this->fail($e,'orders/'.(int)$id); }
    }
}
