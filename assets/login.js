(function () {
    'use strict';

    var cfg = window.__OAUTHLOGIN__;
    if (!cfg || !cfg.providers || !cfg.providers.length) {
        return;
    }

    function buildIcon(provider) {
        var box = document.createElement('span');
        box.className = 'oauth-login-icon';

        if (provider.svg) {
            box.innerHTML = provider.svg;
            return box;
        }

        if (provider.icon) {
            var img = document.createElement('img');
            img.src = provider.icon;
            img.alt = '';
            img.decoding = 'async';
            img.onerror = function () {
                box.style.display = 'none';
            };
            box.appendChild(img);
            return box;
        }

        return null;
    }

    function build() {
        var card = document.querySelector('.typecho-login');
        if (!card || card.querySelector('.oauth-login')) {
            return;
        }

        var form = card.querySelector('form[name="login"]') || card.querySelector('form');
        var moreLink = card.querySelector('.more-link');

        var wrap = document.createElement('div');
        wrap.className = 'oauth-login';

        var divider = document.createElement('div');
        divider.className = 'oauth-login-divider';
        var dividerText = document.createElement('span');
        dividerText.textContent = '或使用第三方账号登录';
        divider.appendChild(dividerText);
        wrap.appendChild(divider);

        var list = document.createElement('div');
        list.className = 'oauth-login-list';

        cfg.providers.forEach(function (provider) {
            var link = document.createElement('a');
            link.className = 'oauth-login-btn';
            link.href = provider.href;
            link.rel = 'nofollow';
            link.setAttribute('data-provider', provider.key);

            var icon = buildIcon(provider);
            if (icon) {
                link.appendChild(icon);
            }

            var text = document.createElement('span');
            text.className = 'oauth-login-text';
            text.textContent = provider.label;
            link.appendChild(text);

            list.appendChild(link);
        });

        wrap.appendChild(list);

        if (moreLink && moreLink.parentNode === card) {
            card.insertBefore(wrap, moreLink);
        } else if (form && form.parentNode === card) {
            card.insertBefore(wrap, form.nextSibling);
        } else {
            card.appendChild(wrap);
        }
    }

    function readError() {
        var match = /[?&]oauth_error=([^&]*)/.exec(window.location.search);
        if (!match) {
            return '';
        }

        try {
            return decodeURIComponent(match[1].replace(/\+/g, ' '));
        } catch (e) {
            return '';
        }
    }

    function showError() {
        var message = readError();
        if (!message) {
            return;
        }

        var card = document.querySelector('.typecho-login');
        if (!card) {
            return;
        }

        var notice = document.createElement('div');
        notice.className = 'oauth-notice';
        notice.setAttribute('role', 'alert');
        notice.textContent = message;

        var form = card.querySelector('form');
        if (form && form.parentNode === card) {
            card.insertBefore(notice, form);
        } else {
            card.insertBefore(notice, card.firstChild);
        }

        if (window.history && window.history.replaceState) {
            try {
                window.history.replaceState(null, document.title, window.location.pathname);
            } catch (e) {
                // ignore
            }
        }
    }

    function init() {
        build();
        showError();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
