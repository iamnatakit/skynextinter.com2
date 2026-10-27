(() => {
  'use strict';

  const $ = window.jQuery;
  if (!$) return;

  let catalogRequest = null;
  let noticeTimer = null;

  const showNotice = (message, isError = false) => {
    let $notice = $('#sn-ajax-notice');
    if (!$notice.length) {
      $notice = $('<div>', {
        id: 'sn-ajax-notice',
        class: 'hidden fixed bottom-5 right-5 z-50 max-w-sm rounded-lg bg-brand-forest px-5 py-3 text-white shadow-xl transition duration-200',
        role: 'status',
        'aria-live': 'polite'
      }).appendTo(document.body);
    }

    $notice
      .toggleClass('bg-red-700', isError)
      .toggleClass('bg-brand-forest', !isError)
      .text(message)
      .removeClass('hidden');

    window.clearTimeout(noticeTimer);
    noticeTimer = window.setTimeout(() => $notice.addClass('hidden'), 4000);
  };

  const syncCsrf = (data) => {
    if (!data || !data.csrf || !data.csrf_name) return;
    $('input[type="hidden"]').each(function () {
      if (this.name === data.csrf_name) this.value = data.csrf;
    });
  };

  const updateCartCount = (count) => {
    $('.top-links .count').text(count);

    const $cartLink = $('.sn-cart-pill');
    if (!$cartLink.length) return;

    let $badge = $cartLink.find('.sn-cart-count');
    if (count > 0) {
      if (!$badge.length) $badge = $('<span>', { class: 'sn-cart-count' }).appendTo($cartLink);
      $badge.text(count);
    } else {
      $badge.remove();
    }
  };

  const catalogUrl = (form) => {
    const url = new URL(form.action, window.location.href);
    new URLSearchParams($(form).serialize()).forEach((value, key) => {
      if (value) url.searchParams.set(key, value);
      else url.searchParams.delete(key);
    });
    return url;
  };

  const loadCatalog = (url, addHistory) => {
    if (!$('#sn-catalog-results').length) return;
    if (catalogRequest) catalogRequest.abort();

    $('#sn-catalog-results').attr('aria-busy', 'true');
    const request = $.ajax({
      url: url.href,
      method: 'GET',
      dataType: 'html',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .done((html) => {
        const $page = $('<div>').append($.parseHTML(html, document, false));
        const $nextResults = $page.find('#sn-catalog-results');
        const $nextCount = $page.find('#sn-catalog-count');

        if (!$nextResults.length || !$nextCount.length) {
          showNotice('ไม่สามารถแสดงผลการค้นหาได้ กรุณาลองใหม่', true);
          return;
        }

        $('#sn-catalog-results').replaceWith($nextResults);
        $('#sn-catalog-count').text($nextCount.text());
        if (addHistory) window.history.pushState({ catalog: true }, '', url.href);
      })
      .fail((xhr, status) => {
        if (status !== 'abort') showNotice('ค้นหาสินค้าไม่สำเร็จ กรุณาลองใหม่', true);
      })
      .always(() => {
        if (catalogRequest === request) {
          $('#sn-catalog-results').attr('aria-busy', 'false');
          catalogRequest = null;
        }
      });
    catalogRequest = request;
  };

  $(document).on('submit', '[data-sn-cart]', function (event) {
    event.preventDefault();
    const $form = $(this);
    const $button = $form.find('button[type="submit"], button').first();
    $button.prop('disabled', true);

    $.ajax({
      url: this.action,
      method: 'POST',
      data: $form.serialize(),
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .done((data) => {
        syncCsrf(data);
        updateCartCount(data.cart_count);
        showNotice(data.message || 'เพิ่มสินค้าในตะกร้าแล้ว');
      })
      .fail((xhr) => {
        syncCsrf(xhr.responseJSON);
        showNotice(xhr.responseJSON?.message || 'เพิ่มสินค้าในตะกร้าไม่สำเร็จ กรุณาลองใหม่', true);
      })
      .always(() => $button.prop('disabled', false));
  });

  $(document).on('submit', '[data-sn-catalog-search]', function (event) {
    event.preventDefault();
    loadCatalog(catalogUrl(this), true);
  });

  $(document).on('click', '[data-sn-catalog-page]', function (event) {
    event.preventDefault();
    loadCatalog(new URL(this.href, window.location.href), true);
  });

  $(window).on('popstate', () => {
    if ($('#sn-catalog-results').length) loadCatalog(new URL(window.location.href), false);
  });
})();
