(function (window, document) {
    'use strict';
    if (!window || !document) return;

    /* -------------------- constants -------------------- */
    var DB_NAME = 'qrTickets';
    var STORE_NAME = 'tickets';
    var DB_VERSION = 1;

    var state = { saving: false, saved: false, ticketId: null };

    var dbPromise = null;
    var containerRef = null;
    var saveButtonRef = null;
    var feedbackElRef = null;
    var saveInitDone = false;
    var ticketCheckTimer = null;

    /* -------------------- helpers (data/i18n) -------------------- */
    function getLocalizedData() { return window.QRTicketsTicket || {}; }
    function getTicketData()    { return (getLocalizedData().ticket) || null; }
    function getTicketId() {
        var t = getTicketData(); if (!t) return null;
        return t.id || (t.structured && t.structured.id) || null;
    }
    function getCacheName() {
        var d = getLocalizedData();
        return (d.cache && d.cache.qr) || 'qr-tickets-qr';
    }
    function getI18n() { return getLocalizedData().i18n || {}; }
    function translate(key, fallback) {
        var v = getI18n()[key];
        return (typeof v === 'string') ? v : (fallback || '');
    }

    /* -------------------- PWA install prompt (desktop/Android) -------------------- */
    var installButtons = new Set();
    var installOptions = new WeakMap();
    var installListeners = [];
    var installStore = window.__pwaInstall;

    function notifyInstallListeners() {
        var prompt = installStore.get();
        installListeners.slice().forEach(function (listener) {
            try { listener(prompt); } catch(e) {}
        });
    }

    if (!installStore) {
        var deferredPrompt = null;
        installStore = {
            get: function(){ return deferredPrompt; },
            set: function(e){ deferredPrompt = e; notifyInstallListeners(); },
            clear: function(){ deferredPrompt = null; notifyInstallListeners(); },
            subscribe: function (listener) {
                if (typeof listener !== 'function') return function(){};
                installListeners.push(listener);
                if (deferredPrompt) { try { listener(deferredPrompt); } catch(e) {} }
                return function(){ installListeners = installListeners.filter(function(fn){ return fn !== listener; }); };
            }
        };
        window.__pwaInstall = installStore;
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        // iOS этого события не имеет — там показ инструкции/баннера, а не prompt. :contentReference[oaicite:1]{index=1}
        event.preventDefault();
        installStore.set(event);
        document.dispatchEvent(new CustomEvent('pwa:install-ready'));
    });

    function registerInstallButton(button) {
        if (!(button instanceof window.HTMLElement)) return;
        if (installButtons.has(button)) return;
        installButtons.add(button);

        var requiresSaveAttr = button.getAttribute('data-requires-save');
        installOptions.set(button, { requireSaved: (requiresSaveAttr === 'false') ? false : true });

        button.hidden = true; button.disabled = true;
        button.addEventListener('click', function () {
            var promptEvent = installStore.get(); if (!promptEvent) return;
            button.disabled = true;
            try {
                promptEvent.prompt();
                var userChoice = promptEvent.userChoice;
                if (userChoice && typeof userChoice.then === 'function') {
                    userChoice.then(function (choice) {
                        installStore.clear();
                        if (!choice || choice.outcome !== 'accepted') button.disabled = false;
                        updateInstallButtons();
                    }).catch(function (e) {
                        console.warn('[QR Tickets] install prompt error:', e);
                        installStore.clear(); button.disabled = false; updateInstallButtons();
                    });
                } else {
                    installStore.clear(); updateInstallButtons();
                }
            } catch (e) {
                console.warn('[QR Tickets] install prompt threw:', e);
                installStore.clear(); button.disabled = false; updateInstallButtons();
            }
        });
    }

    function updateInstallButtons() {
        var deferred = installStore.get();
        installButtons.forEach(function (button) {
            if (!button.isConnected) { installButtons.delete(button); installOptions.delete(button); return; }
            var opts = installOptions.get(button) || {};
            var requireSaved = opts.requireSaved !== false;
            var shouldShow = !!deferred && (!requireSaved || state.saved);
            button.hidden = !shouldShow;
            button.disabled = !deferred;
        });
    }

    function wireInstallButtons(root) {
        var scope = root || document; if (!scope || !scope.querySelectorAll) return;
        var candidates = [];
        if (scope.matches && scope.matches('[data-ticket-install]')) candidates.push(scope);
        var nested = scope.querySelectorAll('[data-ticket-install]');
        if (nested && nested.length) candidates = candidates.concat(Array.prototype.slice.call(nested));
        candidates.forEach(registerInstallButton);
        updateInstallButtons();
    }

    installStore.subscribe(updateInstallButtons);
    document.addEventListener('pwa:install-ready', updateInstallButtons);
    wireInstallButtons(document);

    /* -------------------- IndexedDB -------------------- */
    function openDatabase() {
        return new Promise(function (resolve, reject) {
            var request = window.indexedDB.open(DB_NAME, DB_VERSION);
            request.onupgradeneeded = function (event) {
                var db = event.target.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) db.createObjectStore(STORE_NAME);
            };
            request.onsuccess = function (event) {
                var db = event.target.result;
                db.onversionchange = function () { db.close(); };
                resolve(db);
            };
            request.onerror = function () { reject(request.error || new Error('Failed to open IndexedDB')); };
        });
    }
    function getDb() { if (!dbPromise) dbPromise = openDatabase(); return dbPromise; }

    function getTicketRecord(id) {
        if (!id || !('indexedDB' in window)) return Promise.resolve(null);
        return getDb().then(function (db) {
            return new Promise(function (resolve) {
                var tx = db.transaction(STORE_NAME, 'readonly');
                var store = tx.objectStore(STORE_NAME);
                var request = store.get('ticket:' + id);
                request.onsuccess = function () { resolve(request.result || null); };
                request.onerror  = function () { resolve(null); };
            });
        }).catch(function () { return null; });
    }

    function putTicketRecord(id, value) {
        if (!id || !('indexedDB' in window)) return Promise.reject(new Error('Missing ticket id or IndexedDB unsupported'));
        return getDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_NAME, 'readwrite');
                var store = tx.objectStore(STORE_NAME);
                tx.oncomplete = function () { resolve(value); };
                tx.onabort = tx.onerror = function () { reject(tx.error || new Error('Transaction failed')); };
                store.put(value, 'ticket:' + id);
            });
        });
    }

    /* -------------------- Cache helpers -------------------- */
    function requestPersistence() {
        if (navigator.storage && navigator.storage.persist) {
            return navigator.storage.persist().catch(function(){ return false; });
        }
        return Promise.resolve(false);
    }

    function cacheQrAsset(id, qrUrl) {
        if (!qrUrl) return Promise.resolve(null);
        var isDataUrl = qrUrl.indexOf('data:') === 0;
        if (!('caches' in window)) return Promise.resolve(isDataUrl ? { inline: qrUrl } : null);

        var cacheName = getCacheName();
        var cacheKey = new URL('/qr-tickets/offline/' + encodeURIComponent(id) + '/qr.png', window.location.origin).toString();

        return caches.open(cacheName).then(function (cache) {
            return fetch(qrUrl, isDataUrl ? {} : { credentials: 'include' }).then(function (res) {
                var ok = res && (res.ok || res.type === 'opaque');
                if (!ok) {
                    if (isDataUrl) return { inline: qrUrl };
                    throw new Error('QR fetch failed ' + (res ? res.status : 'unknown'));
                }
                return cache.put(cacheKey, res.clone()).then(function () {
                    return { cacheName: cacheName, cacheKey: cacheKey, originalUrl: qrUrl };
                });
            }).catch(function () { return isDataUrl ? { inline: qrUrl } : null; });
        }).catch(function () { return isDataUrl ? { inline: qrUrl } : null; });
    }

    function setFeedback(message, isError) {
        if (!feedbackElRef) return;
        feedbackElRef.textContent = message || '';
        feedbackElRef.classList.toggle('is-error', !!isError);
        feedbackElRef.classList.toggle('is-success', !isError && !!message);
    }

    /* -------------------- Talk to Service Worker -------------------- */
    function warmCurrentTicketPage() {
        // Автокэш: попросим SW положить текущую страницу билета в кэш HTML.
        if (!('serviceWorker' in navigator)) return;
        if (!/^\/ticket\//.test(location.pathname)) return;

        navigator.serviceWorker.ready.then(function (reg) {
            var sw = (reg && reg.active) || navigator.serviceWorker.controller;
            if (!sw || !sw.postMessage) return;
            // Сообщение обрабатывается в SW в message-слушателе (CACHE_TICKET_PAGE).
            sw.postMessage({ type: 'CACHE_TICKET_PAGE', url: location.href });
        }).catch(function(){});
    }

    function sendToServiceWorker(record) {
        if (!('serviceWorker' in navigator)) return;
        var message = { type: 'SAVE_TICKET', payload: {
            ticketId: record.ticketId, data: record.data, qr: record.qr, savedAt: record.savedAt
        }};
        var deliver = function (worker) { try { worker && worker.postMessage && worker.postMessage(message); } catch(e){} };
        if (navigator.serviceWorker.controller) { deliver(navigator.serviceWorker.controller); return; }
        navigator.serviceWorker.ready.then(function (reg) { if (reg && reg.active) deliver(reg.active); }).catch(function(){});
    }

    /* -------------------- Save flow -------------------- */
    function markSaved(message) {
        state.saved = true;
        setFeedback(message || '', false);

        if (saveButtonRef) {
            saveButtonRef.disabled = true;
            saveButtonRef.setAttribute('aria-disabled', 'true');
        }
        if (containerRef) {
            containerRef.classList.add('is-saved');
            window.setTimeout(function(){ containerRef.hidden = true; }, 1500);
        }
        updateInstallButtons();

        // Автокэш билета сразу после сохранения
        warmCurrentTicketPage();
    }

    function handleSaveClick() {
        if (state.saving || state.saved) return;

        var ticketId = getTicketId();
        var ticketData = getTicketData();
        if (!ticketId || !ticketData) {
            setFeedback(translate('saveError', 'Unable to save ticket. Please try again.'), true);
            return;
        }

        state.ticketId = ticketId;
        state.saving = true;
        if (saveButtonRef) { saveButtonRef.disabled = true; saveButtonRef.setAttribute('aria-disabled', 'true'); }
        setFeedback(translate('saving', 'Saving...'), false);

        requestPersistence()
          .then(function () {
              var qrUrl = ticketData.qrUrl || (ticketData.structured && ticketData.structured.qrUrl);
              return cacheQrAsset(ticketId, qrUrl);
          })
          .then(function (qrResource) {
              var structuredData = {};
              if (ticketData.structured && typeof ticketData.structured === 'object') {
                  try { structuredData = JSON.parse(JSON.stringify(ticketData.structured)); }
                  catch (e) { structuredData = Object.assign({}, ticketData.structured); }
              }
              var record = { ticketId: ticketId, data: structuredData, qr: qrResource, savedAt: Date.now() };
              return putTicketRecord(ticketId, record).then(function(){ sendToServiceWorker(record); return record; });
          })
          .then(function () { markSaved(translate('saveSuccess', 'Ticket saved for offline use.')); })
          .catch(function (e) {
              console.warn('[QR Tickets] Failed to save ticket:', e);
              setFeedback(translate('saveError', 'Unable to save ticket. Please try again.'), true);
              if (saveButtonRef) { saveButtonRef.disabled = false; saveButtonRef.removeAttribute('aria-disabled'); }
          })
          .then(function () { state.saving = false; });
    }

    /* -------------------- UI wiring -------------------- */
    function showContainer(container) { if (!container) return; container.hidden = false; setFeedback('', false); }

    function initSaveUI(container) {
        if (saveInitDone) return;
        var ticketId = getTicketId(); if (!container || !ticketId) return;

        saveInitDone = true;
        state.ticketId = ticketId;
        containerRef = container;
        saveButtonRef = container.querySelector('[data-ticket-save-trigger]');
        feedbackElRef = container.querySelector('[data-ticket-save-feedback]');

        wireInstallButtons(container);

        if (!saveButtonRef) { container.hidden = true; return; }

        container.hidden = true;
        saveButtonRef.disabled = false;
        saveButtonRef.addEventListener('click', handleSaveClick);

        if (!('indexedDB' in window)) {
            if (feedbackElRef) {
                feedbackElRef.textContent = translate('storageMissing', 'Offline storage is not supported in this browser.');
                feedbackElRef.classList.add('is-error');
            }
            container.hidden = true;
            console.warn('[QR Tickets] IndexedDB unavailable; offline save skipped.');
            return;
        }

        getTicketRecord(ticketId).then(function (record) {
            if (record) {
                state.saved = true;
                setFeedback(translate('alreadySaved', 'Ticket already saved on this device.'), false);
                container.hidden = true;
                updateInstallButtons();
                // Страница уже когда-то сохранялась → всё равно прогреем кэш на всякий
                warmCurrentTicketPage();
                return;
            }
            showContainer(container);
        }).catch(function () {
            showContainer(container);
        });
    }

    function scheduleTicketCheck() {
        if (ticketCheckTimer) return;
        var attempts = 0;
        ticketCheckTimer = window.setInterval(function () {
            attempts++;
            if (getTicketId()) {
                window.clearInterval(ticketCheckTimer);
                ticketCheckTimer = null;
                attemptInitSave();
                return;
            }
            if (attempts > 50) { window.clearInterval(ticketCheckTimer); ticketCheckTimer = null; }
        }, 100);
    }

    function attemptInitSave() {
        if (saveInitDone) return;
        var container = document.querySelector('[data-ticket-save]');
        if (!container) return;
        if (!getTicketId()) { scheduleTicketCheck(); return; }
        initSaveUI(container);
    }

    function evaluateSavedState() {
        var ticketId = getTicketId();
        if (!ticketId) { scheduleTicketCheck(); return; }
        state.ticketId = ticketId;
        getTicketRecord(ticketId).then(function (record) {
            if (record) { state.saved = true; updateInstallButtons(); }
        }).catch(function(){});
    }

    // Наблюдатель: появление блока с кнопками
    if (window.MutationObserver) {
        var domObserver = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.prototype.slice.call(mutation.addedNodes).forEach(function (node) {
                    if (!(node instanceof window.HTMLElement)) return;
                    wireInstallButtons(node);
                    if (!saveInitDone) {
                        if (node.matches && node.matches('[data-ticket-save]')) {
                            attemptInitSave();
                        } else if (node.querySelector) {
                            var target = node.querySelector('[data-ticket-save]');
                            if (target) initSaveUI(target);
                        }
                    }
                });
            });
        });
        domObserver.observe(document.documentElement, { childList: true, subtree: true });
    }

    // Прямая привязка клика по кнопке сохранения (без глобального делегирования)
    document.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest && event.target.closest('[data-ticket-save-trigger]');
        if (!trigger) return;
        if (!saveInitDone) {
            var scopedContainer = trigger.closest('[data-ticket-save]');
            if (scopedContainer) initSaveUI(scopedContainer); else attemptInitSave();
        }
        if (!saveButtonRef || trigger !== saveButtonRef) saveButtonRef = trigger;
        event.preventDefault();
        handleSaveClick();
    });

    /* -------------------- bootstrap -------------------- */
    attemptInitSave();
    evaluateSavedState();

    // 🔥 Автокэш билета при самом первом посещении страницы /ticket/…
    // (берём готового активного SW и отправляем ему postMessage)
    warmCurrentTicketPage();

    updateInstallButtons();
})(window, document);
