(function (window, document) {
    'use strict';

    if (window.__FINDIT_UNIFIED_LOADER_READY__) {
        return;
    }
    window.__FINDIT_UNIFIED_LOADER_READY__ = true;

    var loaderState = {
        locked: false
    };

    function getLoaderConfig() {
        return window.__FINDIT_LOADING__ || {};
    }

    function parseNumber(value, fallbackValue) {
        var parsed = Number(value);
        if (!Number.isFinite(parsed)) {
            return fallbackValue;
        }
        return parsed;
    }

    function ensureLoadingOverlay() {
        var existing = document.getElementById('loadingOverlay');
        if (existing) {
            return existing;
        }

        if (!document.body) {
            return null;
        }

        var config = getLoaderConfig();

        var overlay = document.createElement('div');
        overlay.id = 'loadingOverlay';
        overlay.className = 'loading-overlay';
        overlay.setAttribute('aria-live', 'polite');
        overlay.setAttribute('role', 'status');

        var shell = document.createElement('div');
        shell.className = 'loading-overlay-shell';

        if (typeof config.logoSrc === 'string' && config.logoSrc.trim() !== '') {
            var logo = document.createElement('img');
            logo.src = config.logoSrc;
            logo.alt = 'Loading';
            logo.className = 'loading-logo';
            shell.appendChild(logo);
        } else {
            var spinner = document.createElement('span');
            spinner.className = 'loading-logo-fallback';
            spinner.setAttribute('aria-hidden', 'true');
            shell.appendChild(spinner);
        }

        var loadingText = document.createElement('p');
        loadingText.id = 'loadingText';
        loadingText.textContent = 'Loading...';
        shell.appendChild(loadingText);

        overlay.appendChild(shell);
        document.body.appendChild(overlay);

        return overlay;
    }

    function showLoading(message) {
        var overlay = ensureLoadingOverlay();
        if (!overlay) {
            return;
        }

        var label = document.getElementById('loadingText');
        if (label) {
            var text = typeof message === 'string' && message.trim() !== ''
                ? message
                : (getLoaderConfig().defaultMessage || 'Loading...');
            label.textContent = text;
        }

        overlay.classList.add('active');
    }

    function hideLoading() {
        var overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.classList.remove('active');
        }
        loaderState.locked = false;
    }

    function normalizeToastType(value) {
        if (typeof value === 'string') {
            var lowered = value.toLowerCase();
            if (lowered === 'error' || lowered === 'danger' || lowered === 'warning' || lowered === 'failed' || lowered === 'fail') {
                return false;
            }
            return true;
        }

        return Boolean(value);
    }

    function showToast(message, success) {
        if (!document.body) {
            return;
        }

        var text = String(message || 'Notification');
        var isSuccess = normalizeToastType(success);

        var toast = document.createElement('div');
        toast.className = 'toast-notification ' + (isSuccess ? 'toast-success' : 'toast-error');

        var content = document.createElement('div');
        content.className = 'toast-body';

        var icon = document.createElement('span');
        icon.className = 'toast-icon';
        icon.textContent = isSuccess ? '✓' : '!';

        var label = document.createElement('span');
        label.className = 'toast-text';
        label.textContent = text;

        content.appendChild(icon);
        content.appendChild(label);
        toast.appendChild(content);
        document.body.appendChild(toast);

        window.setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-6px)';

            window.setTimeout(function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 3000);
    }

    function navigateWithLoading(targetUrl, message, delayMs) {
        if (loaderState.locked) {
            return;
        }

        loaderState.locked = true;
        showLoading(message || getLoaderConfig().defaultMessage || 'Loading...');

        var fallbackDelay = parseNumber(getLoaderConfig().manualNavigationDelayMs, 3000);
        var finalDelay = Math.max(0, parseNumber(delayMs, fallbackDelay));

        window.setTimeout(function () {
            window.location.href = targetUrl;
        }, finalDelay);
    }

    function hasDataFlag(element, keyName) {
        if (!element || !element.dataset) {
            return false;
        }

        var value = element.dataset[keyName];
        if (typeof value !== 'string') {
            return false;
        }

        var lowered = value.toLowerCase();
        return lowered === '1' || lowered === 'true' || lowered === 'yes' || lowered === 'on';
    }

    function shouldIgnoreAnchor(anchor, event) {
        if (!anchor) {
            return true;
        }

        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return true;
        }

        if (anchor.hasAttribute('download')) {
            return true;
        }

        var target = (anchor.getAttribute('target') || '').toLowerCase();
        if (target === '_blank') {
            return true;
        }

        if (anchor.classList && anchor.classList.contains('no-loader')) {
            return true;
        }

        if (hasDataFlag(anchor, 'noLoader') || hasDataFlag(anchor, 'loadingIgnore')) {
            return true;
        }

        var rawHref = (anchor.getAttribute('href') || '').trim();
        if (!rawHref || rawHref === '#' || rawHref.indexOf('javascript:') === 0 || rawHref.indexOf('mailto:') === 0 || rawHref.indexOf('tel:') === 0) {
            return true;
        }

        return false;
    }

    function onAnchorClick(event) {
        var anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (shouldIgnoreAnchor(anchor, event)) {
            return;
        }

        var targetUrl;
        try {
            targetUrl = new URL(anchor.href, window.location.href);
        } catch (err) {
            return;
        }

        if (targetUrl.origin !== window.location.origin) {
            return;
        }

        var samePath = targetUrl.pathname === window.location.pathname && targetUrl.search === window.location.search;
        if (samePath && targetUrl.hash !== '') {
            return;
        }

        event.preventDefault();

        var defaultMessage = /\/logout\.php$/i.test(targetUrl.pathname)
            ? 'Signing out...'
            : (getLoaderConfig().defaultMessage || 'Loading...');

        var anchorMessage = anchor.dataset && anchor.dataset.loadingMessage ? anchor.dataset.loadingMessage : defaultMessage;
        var defaultDelay = parseNumber(getLoaderConfig().autoNavigationDelayMs, 120);
        var anchorDelay = anchor.dataset && anchor.dataset.loadingDelay ? anchor.dataset.loadingDelay : defaultDelay;

        navigateWithLoading(targetUrl.href, anchorMessage, anchorDelay);
    }

    function shouldIgnoreForm(form, event) {
        if (!form || event.defaultPrevented) {
            return true;
        }

        if (hasDataFlag(form, 'noLoader') || hasDataFlag(form, 'loadingIgnore')) {
            return true;
        }

        var formTarget = (form.getAttribute('target') || '').toLowerCase();
        if (formTarget === '_blank') {
            return true;
        }

        var method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method === 'dialog') {
            return true;
        }

        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            return true;
        }

        return false;
    }

    function onFormSubmit(event) {
        var form = event.target;
        if (shouldIgnoreForm(form, event)) {
            return;
        }

        var submitMessage = (form.dataset && form.dataset.loadingMessage)
            ? form.dataset.loadingMessage
            : 'Submitting...';

        showLoading(submitMessage);
    }

    function initialize() {
        ensureLoadingOverlay();
    }

    document.addEventListener('click', onAnchorClick, false);
    document.addEventListener('submit', onFormSubmit, false);
    window.addEventListener('pageshow', hideLoading);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

    window.showLoading = showLoading;
    window.hideLoading = hideLoading;
    window.navigateWithLoading = navigateWithLoading;
    window.showToast = showToast;
})(window, document);
