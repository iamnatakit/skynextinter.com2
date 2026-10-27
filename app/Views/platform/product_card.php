<?php
helper(['form', 'platform']);
$detailUrl = site_url('shop/product/' . $product['id']);
$annualWebsite=\App\Libraries\Platform\AnnualWebsitePackage::matches($product);
$isInquiry = !empty($product['inquiry_only']);
$isFavorite = in_array((int)$product['id'], session('favorites') ?? [], true);
$publicPricing=\App\Libraries\Platform\ProductPricing::apply($product,false);
?>
<article class="sn-product-card">
    <div class="sn-product-card-visual">
        <a class="sn-product-media" href="<?= $detailUrl ?>" aria-label="<?= esc($product['name'], 'attr') ?>">
            <?php if ($product['image']): ?>
            <img src="<?= base_url($product['image']) ?>" alt="<?= esc($product['name'], 'attr') ?>" loading="lazy" width="500" height="600">
            <?php else: ?><span>ไม่มีภาพสินค้า</span><?php endif ?>
        </a>
        <?php if($publicPricing['public_discount_satang']>0): ?><span class="sn-product-sale-flag">ลด <?= esc(\App\Libraries\Platform\ProductPricing::discountLabel($product)) ?></span><?php endif ?>
        <form class="sn-product-favorite" method="post" action="<?= site_url('favorites/' . $product['id']) ?>">
            <?= csrf_field() ?><input type="hidden" name="return" value="<?= !empty($favorites) ? 'favorites' : 'shop' ?>">
            <button type="submit" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>" aria-label="<?= esc(($isFavorite ? 'ลบจากรายการโปรด ' : 'เพิ่มในรายการโปรด ') . $product['name'], 'attr') ?>">
                <svg viewBox="0 0 24 24" fill="<?= $isFavorite ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg>
            </button>
        </form>
    </div>
    <div class="sn-product-card-body">
        <span class="sn-product-category"><?= esc(p_category($product['category'])) ?></span>
        <h3 class="sn-product-card-title"><a href="<?= $detailUrl ?>"><?= esc($product['name']) ?></a></h3>
        <p class="sn-product-card-summary"><?= esc($product['short_description'] ?? '') ?></p>
        <div class="sn-product-card-footer">
            <?= view('platform/product_price', ['product' => $product, 'pricingMember'=>\App\Libraries\Platform\ProductPricing::eligible($user??null)]) ?>
            <?= view('platform/product_pv', ['product' => $product]) ?>
            <p class="sn-product-status"><?= $annualWebsite ? 'บริการรายปี · ไม่มีค่าจัดส่ง' : ($isInquiry ? 'ติดต่อเพื่อสอบถามข้อมูลสินค้า' : ($product['stock'] > 0 ? 'พร้อมส่ง ' . $product['stock'] . ' ชิ้น' : 'รอยืนยันสต็อก')) ?></p>
            <div class="sn-product-card-actions">
                <a class="sn-product-button sn-product-button-secondary" href="<?= $detailUrl ?>">ดูรายละเอียด</a>
                <?php if ($annualWebsite): ?><a class="sn-product-button" href="<?= site_url('website-package/apply') ?>">สมัครใช้บริการเว็บไซต์</a>
                <?php elseif ($isInquiry): ?>
                <a class="sn-product-button" href="<?= p_affiliate_contact_url() ?>"><?= esc(($product['inquiry_label'] ?? '') ?: 'สอบถามสูตรและการใช้งาน') ?></a>
                <?php elseif ($product['stock'] > 0): ?>
                <form method="post" action="<?= site_url('cart') ?>" data-sn-cart>
                    <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= $product['id'] ?>"><input type="hidden" name="quantity" value="1"><input type="hidden" name="mode" value="add"><button class="sn-product-button">ใส่ตะกร้า</button>
                </form>
                <?php endif ?>
            </div>
        </div>
    </div>
</article>
