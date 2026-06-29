/*
 * Elfbar Theme — Entry
 * ==================================================
 * 18+ Altersverifikation (Jugendschutz / § 10 JuSchG).
 * Vanilla JS, kein Framework. Läuft auf DOMContentLoaded.
 */

(function () {
    'use strict';

    var STORAGE_KEY = 'elfbar_age_verified';
    var COOKIE_DAYS = 30;
    var SCROLL_LOCK_CLASS = 'elfbar-age-gate--open';

    function readCookie(name) {
        var match = document.cookie.match(
            new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)')
        );
        return match ? decodeURIComponent(match[1]) : null;
    }

    function writeCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
            expires = '; expires=' + date.toUTCString();
        }
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie =
            name + '=' + encodeURIComponent(value) +
            expires + '; path=/; SameSite=Lax' + secure;
    }

    function isVerified() {
        if (readCookie(STORAGE_KEY) === '1') {
            return true;
        }
        try {
            return window.localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function persistVerified() {
        writeCookie(STORAGE_KEY, '1', COOKIE_DAYS);
        try {
            window.localStorage.setItem(STORAGE_KEY, '1');
        } catch (e) {
            /* localStorage nicht verfügbar — Cookie genügt */
        }
    }

    function lockScroll() {
        document.documentElement.classList.add(SCROLL_LOCK_CLASS);
        document.body.classList.add(SCROLL_LOCK_CLASS);
    }

    function unlockScroll() {
        document.documentElement.classList.remove(SCROLL_LOCK_CLASS);
        document.body.classList.remove(SCROLL_LOCK_CLASS);
    }

    function initAgeGate() {
        var gate = document.querySelector('[data-elfbar-age-gate]');
        if (!gate) {
            return;
        }

        // Bereits verifiziert → Gate gar nicht erst zeigen (kein Flackern).
        if (isVerified()) {
            if (gate.parentNode) {
                gate.parentNode.removeChild(gate);
            }
            return;
        }

        var confirmBtn = gate.querySelector('[data-elfbar-age-confirm]');
        var denyBtn = gate.querySelector('[data-elfbar-age-deny]');
        var blocked = gate.querySelector('[data-elfbar-age-blocked]');
        var redirectUrl = gate.getAttribute('data-redirect-url') || 'https://www.google.com';

        // Anzeigen + Scroll sperren.
        gate.removeAttribute('hidden');
        lockScroll();

        // Fokus für Tastatur-/Screenreader-Nutzer.
        if (confirmBtn) {
            confirmBtn.focus();
        }

        // Fokus innerhalb des Dialogs halten (einfacher Trap).
        gate.addEventListener('keydown', function (event) {
            if (event.key === 'Tab') {
                var focusable = gate.querySelectorAll('button:not([disabled])');
                if (!focusable.length) {
                    return;
                }
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                persistVerified();
                gate.setAttribute('hidden', '');
                unlockScroll();
                if (gate.parentNode) {
                    gate.parentNode.removeChild(gate);
                }
            });
        }

        if (denyBtn) {
            denyBtn.addEventListener('click', function () {
                if (blocked) {
                    blocked.removeAttribute('hidden');
                }
                if (confirmBtn) {
                    confirmBtn.disabled = true;
                }
                denyBtn.disabled = true;
                window.setTimeout(function () {
                    window.location.replace(redirectUrl);
                }, 1800);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAgeGate);
    } else {
        initAgeGate();
    }

    // =================================================================
    // Deals des Tages — Countdown
    // =================================================================
    function initCountdowns() {
        var deals = document.querySelectorAll('[data-elfbar-countdown]');
        if (!deals.length) { return; }

        deals.forEach(function (el) {
            var hours = parseInt(el.getAttribute('data-end-hours'), 10) || 24;
            var end = new Date().getTime() + hours * 60 * 60 * 1000;
            var d = el.querySelector('[data-d]');
            var h = el.querySelector('[data-h]');
            var m = el.querySelector('[data-m]');
            var s = el.querySelector('[data-s]');

            function pad(n) { return (n < 10 ? '0' : '') + n; }

            function tick() {
                var diff = end - new Date().getTime();
                if (diff < 0) { diff = 0; }
                var days = Math.floor(diff / (1000 * 60 * 60 * 24));
                var hrs = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                var min = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                var sec = Math.floor((diff % (1000 * 60)) / 1000);
                if (d) { d.textContent = pad(days); }
                if (h) { h.textContent = pad(hrs); }
                if (m) { m.textContent = pad(min); }
                if (s) { s.textContent = pad(sec); }
            }
            tick();
            window.setInterval(tick, 1000);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCountdowns);
    } else {
        initCountdowns();
    }

    // =================================================================
    // Ürün kart slider (Neuheiten) — prev/next ok butonları
    // =================================================================
    function initCardSliders() {
        var sliders = document.querySelectorAll('[data-elfbar-cardslider]');
        if (!sliders.length) { return; }

        sliders.forEach(function (slider) {
            // Aynı section-head içindeki butonları bul (önceki kardeş)
            var head = slider.previousElementSibling;
            if (!head) { return; }
            var prev = head.querySelector('[data-slide="prev"]');
            var next = head.querySelector('[data-slide="next"]');

            function step() {
                var card = slider.querySelector('.elfbar-pcard');
                var w = card ? card.getBoundingClientRect().width + 16 : 240;
                return w * 2; // her tıklamada 2 kart kaydır
            }
            if (prev) { prev.addEventListener('click', function () { slider.scrollLeft -= step(); }); }
            if (next) { next.addEventListener('click', function () { slider.scrollLeft += step(); }); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCardSliders);
    } else {
        initCardSliders();
    }

    // =================================================================
    // Menge stepper (kategori liste satırı) — -/+ butonları
    // =================================================================
    function initQtySteppers() {
        // Event delegation: listing AJAX ile yeniden yüklense de çalışır
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-qty]') : null;
            if (!btn) { return; }
            var wrap = btn.closest('[data-elfbar-qty]');
            if (!wrap) { return; }
            var input = wrap.querySelector('.elfbar-qty__input');
            if (!input) { return; }

            var step = parseFloat(input.getAttribute('step')) || 1;
            var min = parseFloat(input.getAttribute('min')) || 1;
            var max = input.getAttribute('max') ? parseFloat(input.getAttribute('max')) : null;
            var val = parseFloat(input.value) || min;

            if (btn.getAttribute('data-qty') === 'plus') {
                val += step;
                if (max !== null && val > max) { val = max; }
            } else {
                val -= step;
                if (val < min) { val = min; }
            }
            input.value = val;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }
    initQtySteppers();

    // =================================================================
    // Kategori sayfası: filtre panelini sol sidebar'a taşı
    // =================================================================
    function initFilterToSidebar() {
        var slot = document.querySelector('[data-elfbar-filter-slot]');
        if (!slot) { return; }
        var filterBlock = document.querySelector('.cms-block-sidebar-filter');
        if (!filterBlock) { return; }
        // Filtre bloğunu sidebar slotuna taşı
        slot.appendChild(filterBlock);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFilterToSidebar);
    } else {
        initFilterToSidebar();
    }

    // =================================================================
    // Drag-scroll (mouse ile tutup kaydırma) — marka ikonları şeridi
    // =================================================================
    function initDragScroll() {
        var els = document.querySelectorAll('[data-elfbar-dragscroll]');
        els.forEach(function (el) {
            var isDown = false, startX = 0, startScroll = 0, moved = false;

            el.addEventListener('mousedown', function (e) {
                isDown = true; moved = false;
                el.classList.add('is-dragging');
                startX = e.pageX - el.offsetLeft;
                startScroll = el.scrollLeft;
            });
            el.addEventListener('mouseleave', function () { isDown = false; el.classList.remove('is-dragging'); });
            el.addEventListener('mouseup', function () { isDown = false; el.classList.remove('is-dragging'); });
            el.addEventListener('mousemove', function (e) {
                if (!isDown) { return; }
                e.preventDefault();
                var x = e.pageX - el.offsetLeft;
                var walk = x - startX;
                if (Math.abs(walk) > 5) { moved = true; }
                el.scrollLeft = startScroll - walk;
            });
            // Sürükleme sırasında linke tıklamayı engelle (yanlışlıkla gitmesin)
            el.addEventListener('click', function (e) {
                if (moved) { e.preventDefault(); }
            }, true);
        });
    }
    initDragScroll();

    // =================================================================
    // Arama popup: backdrop'a tıklayınca + ESC ile kapat
    // =================================================================
    // =================================================================
    // Kategori sayfası: mobil BOTTOM-SHEET (Kategorien + Filter)
    // Buton → ilgili sheet alttan açılır (mobil app hissi). Backdrop/✕/ESC kapatır.
    // =================================================================
    function initCatSheets() {
        var backdrop = document.querySelector('[data-elfbar-sheet-backdrop]');
        var openBtns = document.querySelectorAll('[data-elfbar-sheet-open]');
        if (!openBtns.length) { return; }

        function sheetFor(name) { return document.querySelector('[data-elfbar-sheet="' + name + '"]'); }

        function openSheet(name) {
            var sheet = sheetFor(name);
            if (!sheet) { return; }
            // diğer açık sheet'i kapat
            document.querySelectorAll('[data-elfbar-sheet].is-open').forEach(function (s) { s.classList.remove('is-open'); });
            sheet.classList.add('is-open');
            if (backdrop) { backdrop.hidden = false; }
            // bir frame sonra .is-shown ekle (geçiş animasyonu için)
            window.requestAnimationFrame(function () {
                if (backdrop) { backdrop.classList.add('is-shown'); }
            });
            document.documentElement.classList.add('elfbar-sheet-open');
        }

        function closeSheets() {
            document.querySelectorAll('[data-elfbar-sheet].is-open').forEach(function (s) { s.classList.remove('is-open'); });
            if (backdrop) {
                backdrop.classList.remove('is-shown');
                window.setTimeout(function () { backdrop.hidden = true; }, 250);
            }
            document.documentElement.classList.remove('elfbar-sheet-open');
        }

        openBtns.forEach(function (btn) {
            btn.addEventListener('click', function () { openSheet(btn.getAttribute('data-elfbar-sheet-open')); });
        });
        document.querySelectorAll('[data-elfbar-sheet-close]').forEach(function (c) {
            c.addEventListener('click', closeSheets);
        });
        if (backdrop) { backdrop.addEventListener('click', closeSheets); }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSheets(); } });
    }
    initCatSheets();

    // =================================================================
    // B2B CANLI SEPET — satırdaki +/− doğrudan gerçek sepete yazar
    // (+ ekler, − çıkarır). Hızlı tıklamalar debounce ile birleşir.
    // Alttaki sticky bar gerçek sepet özetini gösterir → "Zur Kasse".
    // =================================================================
    function initLiveCart() {
        var bar = document.querySelector('[data-elfbar-cartbar]');
        var elProducts = bar ? bar.querySelector('[data-cart-products]') : null;
        var elUnits = bar ? bar.querySelector('[data-cart-units]') : null;
        var elTotal = bar ? bar.querySelector('[data-cart-total]') : null;

        // sadece kategori listesinde stepper varsa çalış
        if (!document.querySelector('[data-elfbar-cartqty]') && !bar) { return; }

        function formatPrice(value) {
            try { return value.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }); }
            catch (e) { return value.toFixed(2) + ' €'; }
        }

        // Header'daki sepet widget'ını (ikon + fiyat + adet rozeti) Shopware'in
        // kendi frontend.checkout.info route'undan güncel HTML çekip yeniler.
        // Native CartWidgetPlugin'in yaptığının aynısı → fiyat/rozet anında değişir.
        function refreshHeaderCart(isEmpty) {
            var widget = document.querySelector('[data-cart-widget]');
            if (!widget) { return; }
            // Sepet BOŞSA Shopware /checkout/info 204 (boş) döner → eski HTML
            // ekranda kalıyordu (rozet "1", fiyat eski). O yüzden boşken HTML
            // çekmek yerine widget'ı elle sıfır sepet haline getir.
            if (isEmpty) { renderEmptyHeaderCart(widget); return; }
            var url = (window.router && window.router['frontend.checkout.info']) || '/checkout/info';
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.status === 204 ? '' : r.text(); })
                .then(function (html) {
                    if (html && html.length) { widget.innerHTML = html; }
                    else { renderEmptyHeaderCart(widget); } // 204 = boş → sıfırla
                })
                .catch(function () {});
        }

        // Header sepet widget'ını "boş sepet" görünümüne çevir (fiyat 0,00 €, rozet gizli)
        function renderEmptyHeaderCart(widget) {
            var price = widget.querySelector('.header-cart-total, [data-cart-widget-total]');
            if (price) { price.textContent = formatPrice(0); }
            var badge = widget.querySelector('.elfbar-cart-count');
            if (badge) { badge.textContent = '0'; badge.hidden = true; badge.style.display = 'none'; }
        }

        // bekleyen hedef adetler (debounce sırasında): { productId: qty }
        var pending = {};
        var timers = {};
        // o anki sepet durumu (productId → qty), cart.json'dan
        var cartState = {};

        function setRowQty(id, qty) {
            document.querySelectorAll('[data-elfbar-cartqty][data-product-id="' + id + '"]').forEach(function (w) {
                var inp = w.querySelector('.elfbar-qty__input');
                if (inp) { inp.value = qty; }
                w.classList.toggle('is-in-cart', qty > 0);
            });
            var row = document.querySelector('[data-elfbar-cart-row][data-product-id="' + id + '"]');
            if (row) { row.classList.toggle('is-bulk-selected', qty > 0); }
        }

        // cart.json oku → state + bar + tüm stepper'ları senkronla
        function syncFromCart() {
            return fetch('/checkout/cart.json', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (cart) {
                    cartState = {};
                    var products = 0, units = 0;
                    (cart.lineItems || []).forEach(function (li) {
                        if (li.type && li.type !== 'product') { return; }
                        var pid = li.referencedId || li.id;
                        cartState[pid] = li.quantity;
                        products += 1; units += li.quantity;
                    });
                    // stepper'ları güncelle (pending olmayanları)
                    document.querySelectorAll('[data-elfbar-cartqty]').forEach(function (w) {
                        var id = w.getAttribute('data-product-id');
                        if (pending[id] !== undefined) { return; } // kullanıcı hâlâ değiştiriyor
                        setRowQty(id, cartState[id] || 0);
                    });
                    // bar
                    var total = cart.price ? cart.price.totalPrice : 0;
                    if (elProducts) { elProducts.textContent = products; }
                    if (elUnits) { elUnits.textContent = units; }
                    if (elTotal) { elTotal.textContent = total > 0 ? formatPrice(total) : ''; }
                    if (bar) {
                        if (products > 0) { bar.hidden = false; bar.classList.add('is-visible'); document.body.classList.add('has-elfbar-bulkbar'); }
                        else { bar.hidden = true; bar.classList.remove('is-visible'); document.body.classList.remove('has-elfbar-bulkbar'); }
                    }
                    // header sepet ikonu (fiyat + adet rozeti) ANINDA güncellensin
                    // sepet boşsa (units 0) widget elle sıfırlanır (/checkout/info 204 sorunu)
                    refreshHeaderCart(units <= 0);
                    return cart;
                })
                .catch(function () {});
        }

        // sunucuya hedef adedi yaz (add / change-quantity / delete)
        function commit(id) {
            var target = pending[id];
            delete pending[id];
            var current = cartState[id] || 0;
            var headers = { 'X-Requested-With': 'XMLHttpRequest' };
            var req;

            if (target <= 0) {
                // sepetten çıkar
                if (current <= 0) { return syncFromCart(); }
                req = fetch('/checkout/line-item/delete/' + id, { method: 'POST', headers: headers, credentials: 'same-origin' });
            } else if (current <= 0) {
                // ilk kez ekle
                var fd = new FormData();
                fd.append('lineItems[' + id + '][id]', id);
                fd.append('lineItems[' + id + '][referencedId]', id);
                fd.append('lineItems[' + id + '][type]', 'product');
                fd.append('lineItems[' + id + '][stackable]', '1');
                fd.append('lineItems[' + id + '][removable]', '1');
                fd.append('lineItems[' + id + '][quantity]', target);
                req = fetch('/checkout/line-item/add', { method: 'POST', headers: headers, body: fd, credentials: 'same-origin' });
            } else {
                // adet değiştir
                var fd2 = new FormData();
                fd2.append('quantity', target);
                req = fetch('/checkout/line-item/change-quantity/' + id, { method: 'POST', headers: headers, body: fd2, credentials: 'same-origin' });
            }
            return req.then(function () { return syncFromCart(); }).catch(function () { return syncFromCart(); });
        }

        // +/− tıklama (event delegation → AJAX listing sonrası da çalışır)
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-cartqty]') : null;
            if (!btn) { return; }
            var wrap = btn.closest('[data-elfbar-cartqty]');
            if (!wrap) { return; }
            var id = wrap.getAttribute('data-product-id');
            var min = parseInt(wrap.getAttribute('data-min'), 10) || 1;
            var step = parseInt(wrap.getAttribute('data-step'), 10) || 1;
            var max = wrap.getAttribute('data-max') ? parseInt(wrap.getAttribute('data-max'), 10) : null;
            var input = wrap.querySelector('.elfbar-qty__input');
            var cur = pending[id] !== undefined ? pending[id] : (parseInt(input.value, 10) || 0);
            var next;

            if (btn.getAttribute('data-cartqty') === 'plus') {
                next = cur <= 0 ? min : cur + step;        // 0'dan minPurchase'a sıçra
                if (max !== null && next > max) { next = max; }
            } else {
                next = cur - step;
                if (next < min) { next = 0; }              // min altına inerse sepetten çıkar
            }
            pending[id] = next;
            // anında görsel geri bildirim
            if (input) { input.value = next; }
            wrap.classList.toggle('is-in-cart', next > 0);
            wrap.classList.add('is-busy');
            var row = wrap.closest('[data-elfbar-cart-row]');
            if (row) { row.classList.toggle('is-bulk-selected', next > 0); }

            // debounce: hızlı tıklamalar birleşsin (~500ms)
            if (timers[id]) { window.clearTimeout(timers[id]); }
            timers[id] = window.setTimeout(function () {
                commit(id).then(function () { wrap.classList.remove('is-busy'); });
            }, 500);
        });

        // adet input'a ELLE yazma (örn. 10 → tek seferde): blur/Enter ile sepete yaz
        function applyTypedQty(input) {
            var wrap = input.closest('[data-elfbar-cartqty]');
            if (!wrap) { return; }
            var id = wrap.getAttribute('data-product-id');
            var min = parseInt(wrap.getAttribute('data-min'), 10) || 1;
            var step = parseInt(wrap.getAttribute('data-step'), 10) || 1;
            var max = wrap.getAttribute('data-max') ? parseInt(wrap.getAttribute('data-max'), 10) : null;
            var raw = parseInt(input.value, 10);
            if (isNaN(raw) || raw < 0) { raw = 0; }
            var next = raw;
            if (next > 0) {
                if (next < min) { next = min; }                 // min altı → minPurchase
                if (max !== null && next > max) { next = max; } // max üstü → maxPurchase
                // step'e yuvarla (yukarı), purchaseSteps varsa
                if (step > 1 && (next - min) % step !== 0) {
                    next = min + Math.ceil((next - min) / step) * step;
                    if (max !== null && next > max) { next = max; }
                }
            }
            input.value = next;
            pending[id] = next;
            wrap.classList.toggle('is-in-cart', next > 0);
            wrap.classList.add('is-busy');
            var row = wrap.closest('[data-elfbar-cart-row]');
            if (row) { row.classList.toggle('is-bulk-selected', next > 0); }
            if (timers[id]) { window.clearTimeout(timers[id]); }
            commit(id).then(function () { wrap.classList.remove('is-busy'); });
        }
        document.addEventListener('change', function (e) {
            var inp = e.target.classList && e.target.classList.contains('elfbar-qty__input') ? e.target : null;
            if (!inp || inp.hasAttribute('readonly')) { return; }
            if (!inp.closest('[data-elfbar-cartqty]')) { return; }
            applyTypedQty(inp);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') { return; }
            var inp = e.target.classList && e.target.classList.contains('elfbar-qty__input') ? e.target : null;
            if (!inp || !inp.closest('[data-elfbar-cartqty]')) { return; }
            e.preventDefault();
            inp.blur(); // change tetiklenir
        });

        // ilk yükleme + listing AJAX (filtre/sayfalama) sonrası senkronla
        syncFromCart();
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('.cms-element-product-listing .pagination, .cms-element-product-listing .filter-panel, .sorting')) {
                window.setTimeout(syncFromCart, 800);
            }
        });
    }
    initLiveCart();

    function initSearchOverlay() {
        var overlay = document.querySelector('[data-elfbar-search-overlay]');
        if (!overlay) { return; }

        function closeOverlay() {
            // Bootstrap collapse'ı kapat: toggle butonuna tıkla (state senkron kalsın)
            var toggle = document.querySelector('.js-search-toggle-btn');
            if (overlay.classList.contains('show')) {
                if (toggle) { toggle.click(); }
                else { overlay.classList.remove('show'); }
            }
        }

        // backdrop (overlay'in kendisi, inner değil) tıklanınca kapat
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { closeOverlay(); }
        });
        // ESC ile kapat + açılınca input'a odaklan
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeOverlay(); }
        });
        // açıldığında arama input'una odaklan
        overlay.addEventListener('shown.bs.collapse', function () {
            var inp = overlay.querySelector('input[type="search"], .header-search-input');
            if (inp) { inp.focus(); }
        });
    }
    initSearchOverlay();

    // Schnellbestellung: "Weitere Zeile" → yeni SKU+adet satırı ekle
    function initQuickOrder() {
        var addBtn = document.querySelector('[data-elfbar-quick-addrow]');
        var rows = document.querySelector('[data-elfbar-quick-rows]');
        if (!addBtn || !rows) { return; }
        addBtn.addEventListener('click', function () {
            var idx = rows.querySelectorAll('.elfbar-quick__row').length;
            var row = document.createElement('div');
            row.className = 'elfbar-quick__row';
            row.innerHTML =
                '<input type="text" name="lines[' + idx + '][sku]" class="form-control elfbar-quick__sku" placeholder="z. B. SW-12345" autocomplete="off">' +
                '<input type="number" name="lines[' + idx + '][qty]" class="form-control elfbar-quick__qty" min="1" value="1" inputmode="numeric">';
            rows.appendChild(row);
            var first = row.querySelector('input');
            if (first) { first.focus(); }
        });
    }
    initQuickOrder();

    // ÜRÜN DETAY MOBİL: başlık (product-heading) görselin ÜSTünde geliyordu.
    // Kullanıcı sırası: breadcrumb → görsel → başlık → buybox. CMS blokları
    // farklı container'larda olduğu için CSS order yetmiyor → mobilde başlık
    // bloğunu görselin (media) hemen ardına DOM'da taşı. Desktop'ta dokunma.
    function initProductDetailMobileOrder() {
        if (!document.body.classList.contains('is-ctl-product')) { return; }
        var mq = window.matchMedia('(max-width: 767.98px)');
        var heading = document.querySelector('.cms-block-product-heading');
        var media = document.querySelector('.product-detail-media');
        if (!heading || !media) { return; }
        var originalParent = heading.parentNode;
        var originalNext = heading.nextElementSibling;

        function apply() {
            if (mq.matches) {
                // başlığı görselin hemen ardına taşı (görsel altında)
                if (media.nextElementSibling !== heading) {
                    media.parentNode.insertBefore(heading, media.nextElementSibling);
                }
            } else {
                // desktop: orijinal yerine geri koy
                if (heading.parentNode !== originalParent) {
                    if (originalNext) { originalParent.insertBefore(heading, originalNext); }
                    else { originalParent.appendChild(heading); }
                }
            }
        }
        apply();
        mq.addEventListener ? mq.addEventListener('change', apply) : mq.addListener(apply);
    }
    initProductDetailMobileOrder();

    /* ---- FAQ Accordion (içerik sayfalarındaki .elfbar-faq__item aç/kapa) ---- */
    function initFaqAccordion() {
        // NOT: Shopware CMS sanitizer data-* attribute'larını siler → class selektörü
        var items = document.querySelectorAll('.elfbar-faq__item');
        if (!items.length) { return; }
        items.forEach(function (item) {
            var q = item.querySelector('.elfbar-faq__q');
            if (!q) { return; }
            q.setAttribute('role', 'button');
            q.setAttribute('tabindex', '0');
            function toggle() { item.classList.toggle('is-open'); }
            q.addEventListener('click', toggle);
            q.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
            });
        });
    }
    document.addEventListener('DOMContentLoaded', initFaqAccordion);
})();
