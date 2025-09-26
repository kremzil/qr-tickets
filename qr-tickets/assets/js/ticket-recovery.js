(function (window, document) {
    'use strict';

    if (!window || !document) {
        return;
    }

    var config = window.QRTicketsRecovery;

    if (!config || !config.endpoint || !config.cookie) {
        return;
    }

    function readCookie(name) {
        var pattern = new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\/+^])/g, '\\$1') + '=([^;]*)');
        var match = document.cookie.match(pattern);

        if (!match) {
            return null;
        }

        try {
            return decodeURIComponent(match[1]);
        } catch (error) {
            return null;
        }
    }

    function writeCookie(name, value, days) {
        var expires = '';

        if (typeof days === 'number') {
            var date = new Date();
            date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
            expires = '; expires=' + date.toUTCString();
        }

        var secure = window.location.protocol === 'https:' ? '; secure' : '';

        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/' + secure + '; samesite=Lax';
    }

    function getStoredTokens() {
        var raw = readCookie(config.cookie);

        if (!raw) {
            return [];
        }

        try {
            var parsed = JSON.parse(raw);

            if (Array.isArray(parsed)) {
                return parsed.filter(function (entry) {
                    return entry && typeof entry === 'object' && entry.token;
                });
            }
        } catch (error) {
            // ignore
        }

        return [];
    }

    function updateCookieTokens(tokens) {
        try {
            writeCookie(config.cookie, JSON.stringify(tokens || []), 30);
        } catch (error) {
            // ignore
        }
    }

    function formatValidUntil(timestamp) {
        if (!timestamp) {
            return '';
        }

        try {
            var date = new Date(timestamp * 1000);
            return date.toLocaleString();
        } catch (error) {
            return '';
        }
    }

    function ensureContainer() {
        var existing = document.querySelector('[data-ticket-active]');

        if (existing) {
            return existing;
        }

        var banner = document.createElement('div');
        banner.className = 'qr-ticket-active';
        banner.setAttribute('data-ticket-active', '');

        function appendBanner() {
            if (banner.isConnected || !document.body) {
                return;
            }

            document.body.appendChild(banner);
        }

        if (document.body) {
            appendBanner();
        } else {
            document.addEventListener('DOMContentLoaded', appendBanner, { once: true });
        }

        return banner;
    }

    function renderActiveTicket(info) {
        if (!info) {
            return;
        }

        var container = ensureContainer();

        if (!container) {
            return;
        }

        var heading = config.i18n && config.i18n.heading ? config.i18n.heading : 'Active ticket';
        var openLabel = config.i18n && config.i18n.openLabel ? config.i18n.openLabel : 'Open ticket';
        var validUntil = formatValidUntil(info.valid_to);

        container.innerHTML = '';

        var title = document.createElement('strong');
        title.className = 'qr-ticket-active__title';
        title.textContent = info.title || heading;

        var status = document.createElement('span');
        status.className = 'qr-ticket-active__status';
        status.textContent = info.status ? String(info.status).toUpperCase() : '';

        var meta = document.createElement('div');
        meta.className = 'qr-ticket-active__meta';

        if (validUntil) {
            var metaItem = document.createElement('span');
            metaItem.textContent = validUntil;
            meta.appendChild(metaItem);
        }

        var link = document.createElement('a');
        link.className = 'qr-ticket-active__action';
        link.href = info.permalink;
        link.textContent = openLabel;

        container.appendChild(title);

        if (info.status) {
            container.appendChild(status);
        }

        if (meta.childNodes.length) {
            container.appendChild(meta);
        }

        container.appendChild(link);
        container.hidden = false;
    }

    function removeToken(token) {
        if (!token) {
            return;
        }

        var tokens = getStoredTokens();
        var filtered = tokens.filter(function (entry) {
            return entry.token !== token;
        });

        if (filtered.length !== tokens.length) {
            updateCookieTokens(filtered);
        }
    }

    var tokens = getStoredTokens();

    if (!tokens.length) {
        return;
    }

    tokens.sort(function (a, b) {
        return (a.updated || 0) - (b.updated || 0);
    });

    var current = tokens[tokens.length - 1];

    if (!current || !current.token) {
        return;
    }

    fetch(config.endpoint + '?token=' + encodeURIComponent(current.token), {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    }).then(function (response) {
        if (!response.ok) {
            throw response;
        }

        return response.json();
    }).then(function (data) {
        if (!data || !data.permalink) {
            throw new Error('Invalid payload');
        }

        renderActiveTicket(data);
    }).catch(function (error) {
        if (error && typeof error.status === 'number' && (error.status === 404 || error.status === 410)) {
            removeToken(current.token);
        }
    });
})(window, document);
