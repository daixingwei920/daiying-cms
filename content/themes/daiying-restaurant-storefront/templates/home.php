<?php

declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;
use Cms\Core\Security\CsrfToken;

/** @var TemplateContext $context */
$e = static fn (mixed $value): string => $context->e((string) $value);
$setting = static fn (string $key, mixed $default = ''): mixed => $context->setting($key, $default);
$color = static function (mixed $value, string $fallback): string {
    $value = (string) $value;
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $fallback;
};
$choice = static function (mixed $value, string $fallback, array $allowed): string {
    $value = (string) $value;
    return in_array($value, $allowed, true) ? $value : $fallback;
};
$bool = static fn (string $key, bool $default): string => filter_var($setting($key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default ? 'true' : 'false';
$radius = max(0, min(16, (int) $setting('corner_radius', 8)));
$ratio = $choice($setting('image_ratio', '4 / 3'), '4 / 3', ['1 / 1', '4 / 3', '16 / 10']);
$primary = $color($setting('primary_color', '#146c43'), '#146c43');
$secondary = $color($setting('secondary_color', '#c2410c'), '#c2410c');
$accent = $color($setting('accent_color', '#f2b705'), '#f2b705');
$restaurantName = (string) $setting('restaurant_name', '示例餐馆 / Sample Restaurant');
$seoAddress = trim((string) $setting('seo_address', ''));
$seoPhone = trim((string) $setting('seo_phone', ''));
$seoHours = trim((string) $setting('seo_hours', ''));
$canonical = trim((string) $setting('canonical_url', ''));
$canonical = filter_var($canonical, FILTER_VALIDATE_URL) && str_starts_with($canonical, 'https://') ? $canonical : '';
$siteLogoUrl = (string) $context->get('site_logo_url', '');
$logoUrl = $siteLogoUrl !== '' ? $siteLogoUrl : (string) $setting('logo_url', '');
$storefront = (string) $setting('storefront_image_url', '');
$desktopFocus = preg_match('/^\d{1,3}% \d{1,3}%$/', (string) $setting('desktop_focal_point', '50% 50%')) ? (string) $setting('desktop_focal_point', '50% 50%') : '50% 50%';
$mobileFocus = preg_match('/^\d{1,3}% \d{1,3}%$/', (string) $setting('mobile_focal_point', '50% 45%')) ? (string) $setting('mobile_focal_point', '50% 45%') : '50% 45%';
?>
<!doctype html>
<html lang="en-US">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($restaurantName) ?></title>
    <meta name="description" content="<?= $e('Menu and pickup ordering at ' . $restaurantName) ?>" data-seo-description>
    <?php if ($canonical !== ''): ?><link rel="canonical" href="<?= $e($canonical) ?>" data-seo-canonical><?php endif; ?>
    <meta property="og:type" content="restaurant">
    <meta property="og:title" content="<?= $e($restaurantName) ?>" data-og-title>
    <meta property="og:description" content="<?= $e('Menu and pickup ordering at ' . $restaurantName) ?>" data-og-description>
    <?php if ($canonical !== ''): ?><meta property="og:url" content="<?= $e($canonical) ?>" data-og-url><?php endif; ?>
    <?php if ($canonical !== '' && $seoAddress !== ''): ?>
    <script type="application/ld+json" data-restaurant-schema><?= json_encode(array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Restaurant',
        'name' => $restaurantName,
        'address' => $seoAddress,
        'telephone' => $seoPhone,
        'openingHours' => $seoHours,
        'url' => $canonical,
        'hasMenu' => $canonical . '#menu',
    ]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $e($context->asset('style.css')) ?>">
    <style>
        :root {
            --rs-primary: <?= $e($primary) ?>;
            --rs-secondary: <?= $e($secondary) ?>;
            --rs-accent: <?= $e($accent) ?>;
            --rs-radius: <?= $radius ?>px;
            --rs-image-ratio: <?= $e($ratio) ?>;
            --rs-hero-focus-desktop: <?= $e($desktopFocus) ?>;
            --rs-hero-focus-mobile: <?= $e($mobileFocus) ?>;
        }
    </style>
</head>
<body
    data-restaurant-app
    data-storefront-api="<?= $e($context->url('/restaurant/api/storefront')) ?>"
    data-menu-api="<?= $e($context->url('/restaurant/api/menu')) ?>"
    data-cart-api="<?= $e($context->url('/restaurant/api/cart')) ?>"
    data-cart-add-api="<?= $e($context->url('/restaurant/api/cart/add')) ?>"
    data-cart-remove-api="<?= $e($context->url('/restaurant/api/cart/remove')) ?>"
    data-cart-update-api="<?= $e($context->url('/restaurant/api/cart/update')) ?>"
    data-fallback-name="<?= $e($restaurantName) ?>"
    data-fallback-logo="<?= $e($logoUrl) ?>"
    data-site-logo="<?= $e($siteLogoUrl) ?>"
    data-fallback-storefront="<?= $e($storefront) ?>"
    data-show-favorites="<?= $bool('show_house_favorites', true) ?>"
    data-show-menu="<?= $bool('show_menu', true) ?>"
    data-show-gallery="<?= $bool('show_gallery', true) ?>"
    data-show-about="<?= $bool('show_about', true) ?>"
    data-show-location="<?= $bool('show_location', true) ?>"
    data-hero-style="<?= $e($choice($setting('hero_style', 'storefront'), 'storefront', ['storefront', 'compact'])) ?>"
    data-menu-card-style="<?= $e($choice($setting('menu_card_style', 'photo'), 'photo', ['photo', 'text'])) ?>"
>
<header class="rs-header">
    <a class="rs-brand" href="<?= $e($context->url('/')) ?>">
        <span class="rs-logo" data-logo><?= $logoUrl !== '' ? '<img src="' . $e($logoUrl) . '" alt="">' : $e(mb_substr($restaurantName, 0, 1)) ?></span>
        <span data-restaurant-name><?= $e($restaurantName) ?></span>
    </a>
    <nav class="rs-nav" aria-label="Primary" data-i18n-aria="nav.label">
        <a href="#menu" data-nav-section="menu" data-i18n="nav.menu">菜单</a>
        <a href="#favorites" data-nav-section="favorites" data-i18n="nav.favorites">招牌</a>
        <a href="#visit" data-nav-section="location" data-i18n="nav.visit">到店</a>
        <button type="button" data-open-cart data-order-entry data-i18n="nav.order" hidden>订单凭证</button>
    </nav>
    <div class="rs-actions" role="group" aria-label="Language" data-i18n-aria="language.label">
        <button type="button" data-lang="zh-CN" lang="zh-CN">中文</button>
        <button type="button" data-lang="en-US" lang="en-US">English</button>
        <button type="button" data-lang="es-US" lang="es-US">Español</button>
    </div>
    <button type="button" class="rs-header-cart" data-open-cart hidden><span data-i18n="cart.view">查看购物车</span> <span data-cart-count></span></button>
</header>
<main>
    <section class="rs-hero" id="top">
        <img data-hero-image alt="" fetchpriority="high" decoding="async" hidden>
        <p class="rs-photo-note" data-hero-placeholder data-i18n="hero.noPhoto">真实门头照片待上传</p>
        <div class="rs-hero-copy">
            <p class="rs-status" data-status>Open</p>
            <h1 data-hero-title><?= $e($restaurantName) ?></h1>
            <p data-hero-subtitle></p>
            <p class="rs-meta"><span data-hours></span><span data-address></span></p>
            <div class="rs-cta-row">
                <button type="button" data-service-mode="pickup" data-i18n="cta.pickup">自取下单</button>
                <button type="button" data-service-mode="delivery" data-i18n="cta.delivery">外卖配送</button>
                <a href="#menu" data-i18n="cta.menu">查看菜单</a>
            </div>
            <p class="rs-service-note" data-service-note></p>
            <p class="rs-online-notice" data-online-notice hidden role="status"></p>
        </div>
    </section>
    <section class="rs-strip" aria-label="Ordering mode">
        <button type="button" data-service-mode="pickup" data-i18n="mode.pickup">自取</button>
        <button type="button" data-service-mode="delivery" data-i18n="mode.delivery">配送</button>
        <form class="rs-search" role="search">
            <label class="sr-only" for="menu-search" data-i18n="search.label">搜索菜单</label>
            <input id="menu-search" type="search" data-search-input placeholder="搜索菜品">
        </form>
    </section>
    <section class="rs-section" id="favorites" data-section="favorites">
        <div class="rs-section-head">
            <div>
                <p data-i18n="section.featured.kicker">House Favorites</p>
                <h2 data-i18n="section.featured.title">招牌推荐</h2>
            </div>
        </div>
        <div class="rs-product-grid" data-featured-list></div>
    </section>
    <section class="rs-section" id="menu" data-section="menu">
        <div class="rs-section-head">
            <div>
                <p data-i18n="section.menu.kicker">Full Menu</p>
                <h2 data-i18n="section.menu.title">完整菜单</h2>
            </div>
        </div>
        <nav class="rs-category-bar" data-menu-section-list aria-label="Meal menus" data-i18n-aria="menuSection.label"></nav>
        <nav class="rs-category-bar" data-category-list aria-label="Menu categories" data-i18n-aria="category.label"></nav>
        <label class="rs-category-picker"><span data-i18n="category.label">菜品分类</span><select data-category-select></select></label>
        <p class="rs-search-count" data-search-count role="status" aria-live="polite"></p>
        <div class="rs-product-grid" data-menu-list></div>
    </section>
    <section class="rs-section" data-section="gallery">
        <div class="rs-section-head"><div><p data-i18n="section.gallery.kicker">Gallery</p><h2 data-i18n="section.gallery.title">门店与菜品</h2></div></div>
        <div class="rs-gallery" data-gallery></div>
    </section>
    <section class="rs-section rs-two-col" data-section="about">
        <div><p data-i18n="section.about.kicker">About</p><h2 data-about-title>关于我们</h2></div>
        <p data-about-body></p>
    </section>
    <section class="rs-section rs-two-col" id="visit" data-section="location">
        <div><p data-i18n="section.visit.kicker">Visit Us</p><h2 data-i18n="section.visit.title">地址与营业时间</h2></div>
        <div class="rs-location">
            <p><strong data-i18n="label.address">地址</strong><span data-address></span></p>
            <p><strong data-i18n="label.phone">电话</strong><a data-phone></a></p>
            <p><strong data-i18n="label.hours">今日营业</strong><span data-hours></span></p>
            <p><strong data-i18n="label.map">地图</strong><a data-map target="_blank" rel="noopener noreferrer" data-i18n="link.map">查看路线</a></p>
        </div>
    </section>
</main>
<footer class="rs-footer">
    <span data-restaurant-name><?= $e($restaurantName) ?></span>
    <span data-i18n="footer.powered">Powered by Daiying CMS Restaurant.</span>
</footer>
<div class="rs-sticky-cart" data-cart-bar hidden>
    <span data-cart-summary aria-live="polite"></span>
    <button type="button" data-open-cart data-i18n="cart.view">查看购物车</button>
</div>
<dialog class="rs-dialog" data-product-dialog aria-labelledby="product-title">
    <form method="dialog" class="rs-dialog-close"><button type="submit" aria-label="Close" data-i18n-aria="button.close">×</button></form>
    <div data-product-detail></div>
    <form data-product-form>
        <div data-modifier-groups></div>
        <label><span data-i18n="product.quantity">数量</span><input name="quantity" type="number" min="1" max="99" value="1" required></label>
        <label><span data-i18n="product.notes">备注</span><textarea name="notes" maxlength="500" aria-describedby="product-notes-hint"></textarea></label>
        <p id="product-notes-hint" data-i18n="product.notesHint">额外变更需门店确认，不自动减价；过敏需求请直接联系门店。</p>
        <button type="submit" data-i18n="button.add">加入餐车</button>
    </form>
</dialog>
<dialog class="rs-dialog" data-cart-dialog aria-labelledby="cart-title">
    <form method="dialog" class="rs-dialog-close"><button type="submit" aria-label="Close" data-i18n-aria="button.close">×</button></form>
    <h2 id="cart-title" data-i18n="cart.title">购物车</h2>
    <section class="rs-order-receipt" data-order-receipt hidden aria-label="Order receipt" data-i18n-aria="receipt.title" aria-live="polite"></section>
    <div data-cart-items></div>
    <div data-cart-pricing aria-live="polite"></div>
    <p><span data-pricing-total-label data-i18n="cart.total">总计</span> <strong data-cart-total></strong></p>
    <form method="post" action="<?= $e($context->url('/restaurant/checkout')) ?>" data-checkout-form>
        <?= CsrfToken::field() ?>
        <input type="hidden" name="token" data-cart-token>
        <input type="hidden" name="service_mode" data-checkout-mode value="pickup">
        <input type="hidden" name="expected_total_minor" data-checkout-total>
        <label data-preorder-field hidden><span data-i18n="checkout.time">预约时间（留空表示尽快）</span><input name="fulfillment_at" type="datetime-local" data-fulfillment-time></label>
        <p data-preorder-note></p>
        <label><span data-i18n="checkout.name">姓名</span><input name="customer_name" required autocomplete="name"></label>
        <label><span data-i18n="checkout.phone">电话</span><input name="customer_phone" required autocomplete="tel"></label>
        <label><span data-i18n="checkout.email">邮箱</span><input name="customer_email" type="email" autocomplete="email"></label>
        <div data-delivery-address hidden>
            <label><span data-i18n="checkout.address">配送地址</span><input name="delivery_address" autocomplete="street-address"></label>
            <label><span data-i18n="checkout.postal">配送邮编</span><input name="delivery_postal_code" autocomplete="postal-code"></label>
            <p data-delivery-fee></p>
        </div>
        <label><span data-i18n="checkout.payment">支付方式</span><select name="provider_id" data-payment-provider required></select></label>
        <button type="submit" data-i18n="cart.checkout">去结算</button>
    </form>
</dialog>
<p class="rs-live sr-only" role="status" aria-live="polite" data-live></p>
<template id="rs-product-card">
    <article class="rs-product-card">
        <figure><img alt="" loading="lazy"><figcaption></figcaption></figure>
        <div class="rs-product-body">
            <div class="rs-product-top"><h3></h3><span class="rs-price"></span></div>
            <p class="rs-desc"></p>
            <div class="rs-tags"></div>
            <details class="rs-card-notes"><summary><span>备注</span></summary><textarea data-card-notes rows="2" maxlength="500"></textarea></details>
            <button type="button" class="rs-add"></button>
        </div>
    </article>
</template>
<script src="<?= $e($context->asset('storefront.js')) ?>" defer></script>
</body>
</html>
