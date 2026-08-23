(function () {
    'use strict';

    if (window.__finditSessionTimeoutInitialized) {
        return;
    }
    window.__finditSessionTimeoutInitialized = true;

    var cfg = window.__SESSION_TIMEOUT_CONFIG__ || {};
    var timeoutSeconds = Number(cfg.timeoutSeconds || 180);
    if (!Number.isFinite(timeoutSeconds) || timeoutSeconds < 60) {
        timeoutSeconds = 180;
    }

    var warningSeconds = Number(cfg.warningSeconds || 60);
    if (!Number.isFinite(warningSeconds) || warningSeconds < 15) {
        warningSeconds = 60;
    }
    if (warningSeconds >= timeoutSeconds) {
        warningSeconds = Math.max(15, timeoutSeconds - 10);
    }

    var logoutUrl = String(cfg.logoutUrl || 'logout.php');
    var pingUrl = String(cfg.pingUrl || '');

    var timeoutMs = timeoutSeconds * 1000;
    var warningMs = warningSeconds * 1000;

    var lastActivityAt = Date.now();
    var lastMouseMoveAt = 0;
    var lastHeartbeatAt = Date.now();
    var alreadyLoggingOut = false;
    var warningVisible = false;

    var overlayEl = null;
    var headEl = null;
    var titleEl = null;
    var eyebrowEl = null;
    var bodyEl = null;
    var countdownEl = null;
    var iconWrapEl = null;
    var warningIconEl = null;
    var expiredIconEl = null;
    var stayBtnEl = null;
    var stayLabelEl = null;
    var leaveBtnEl = null;
    var leaveLabelEl = null;

    function buildLogoutUrl() {
        if (logoutUrl.indexOf('reason=session_timeout') !== -1) {
            return logoutUrl;
        }
        return logoutUrl + (logoutUrl.indexOf('?') === -1 ? '?' : '&') + 'reason=session_timeout';
    }

    function injectModal() {
        if (overlayEl) {
            return;
        }

        var styleEl = document.createElement('style');
        styleEl.textContent = '' +
            '.fit-session-overlay{' +
            'position:fixed;inset:0;display:none;align-items:center;justify-content:center;' +
            'background:radial-gradient(circle at 12% 16%,rgba(56,189,248,0.12),transparent 28%),' +
            'radial-gradient(circle at 88% 84%,rgba(255,91,31,0.22),transparent 32%),rgba(3,7,16,0.86);' +
            'backdrop-filter:blur(6px);z-index:2100;padding:18px;}' +
            '.fit-session-modal{' +
            'width:100%;max-width:470px;background:linear-gradient(160deg,#131c2a,#0f1724 58%,#0d1520);' +
            'border:1px solid rgba(109,130,167,0.35);border-radius:18px;overflow:hidden;' +
            'box-shadow:0 26px 52px rgba(0,0,0,0.58),0 1px 0 rgba(255,255,255,0.04) inset;' +
            'font-family:"Plus Jakarta Sans",Manrope,"Segoe UI",sans-serif;transform:translateY(8px);' +
            'animation:fitSessionRise .2s ease-out forwards;}' +
            '.fit-session-head{' +
            'padding:18px 20px;background:linear-gradient(132deg,#2a3b56,#1d2a3d,#121b28);color:#ecf2ff;' +
            'border-bottom:1px solid rgba(156,180,214,0.2);}' +
            '.fit-session-head.fit-expired{background:linear-gradient(132deg,#7f1d1d,#991b1b,#7f1d1d);}' +
            '.fit-session-head-content{display:flex;align-items:center;gap:14px;}' +
            '.fit-session-icon-wrap{' +
            'width:46px;height:46px;flex:0 0 46px;border-radius:14px;display:grid;place-items:center;' +
            'border:1px solid rgba(255,255,255,0.25);background:rgba(10,14,22,0.44);}' +
            '.fit-session-icon-wrap.fit-expired{background:rgba(127,29,29,0.25);border-color:rgba(254,202,202,0.42);}' +
            '.fit-session-icon{width:24px;height:24px;display:block;}' +
            '.fit-session-icon-warning{color:#ffd7a8;filter:drop-shadow(0 0 9px rgba(255,205,141,0.35));}' +
            '.fit-session-icon-expired{color:#fecaca;filter:drop-shadow(0 0 10px rgba(248,113,113,0.4));}' +
            '.fit-session-head-copy{min-width:0;}' +
            '.fit-session-eyebrow{' +
            'margin:0 0 3px;font-size:0.67rem;letter-spacing:0.1em;text-transform:uppercase;' +
            'font-weight:800;color:#b7c6df;}' +
            '.fit-session-title{margin:0;font-size:1.16rem;font-weight:800;letter-spacing:-0.01em;line-height:1.25;}' +
            '.fit-session-body{padding:20px 20px 18px;color:#dbe5f7;}' +
            '.fit-session-desc{margin:0;font-size:0.95rem;line-height:1.55;color:#d9e4f8;}' +
            '.fit-session-count-wrap{' +
            'margin-top:12px;display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:11px;' +
            'background:rgba(17,28,43,0.72);border:1px solid rgba(134,162,207,0.33);}' +
            '.fit-session-pulse{' +
            'width:10px;height:10px;border-radius:999px;background:#ff8a3d;box-shadow:0 0 0 0 rgba(255,138,61,0.75);' +
            'animation:fitSessionPulse 1.35s ease-out infinite;}' +
            '.fit-session-count{margin:0;font-size:0.9rem;color:#ffd8be;font-weight:800;letter-spacing:0.01em;}' +
            '.fit-session-note{margin:12px 0 0;font-size:0.8rem;color:#9eb1ce;line-height:1.45;}' +
            '.fit-session-actions{display:flex;gap:10px;margin-top:18px;}' +
            '.fit-session-btn{' +
            'flex:1;border:none;border-radius:11px;padding:11px 14px;font-size:0.86rem;font-weight:800;cursor:pointer;' +
            'display:inline-flex;align-items:center;justify-content:center;gap:8px;line-height:1.2;' +
            'transition:transform .14s ease,box-shadow .2s ease,filter .2s ease;}' +
            '.fit-session-btn svg{width:16px;height:16px;flex:0 0 16px;}' +
            '.fit-session-btn:hover{transform:translateY(-1px);}' +
            '.fit-session-btn:focus-visible{outline:2px solid rgba(255,255,255,0.6);outline-offset:1px;}' +
            '.fit-session-stay{' +
            'background:linear-gradient(132deg,#ff8c3b,#ff5b1f);color:#fff;' +
            'box-shadow:0 10px 24px rgba(255,91,31,0.34);}' +
            '.fit-session-stay:hover{filter:brightness(1.05);box-shadow:0 13px 28px rgba(255,91,31,0.45);}' +
            '.fit-session-leave{' +
            'background:linear-gradient(132deg,#1e293b,#0f172a);color:#d5deee;border:1px solid #334155;' +
            'box-shadow:0 6px 16px rgba(2,6,23,0.55);}' +
            '.fit-session-overlay.fit-expired-state .fit-session-leave{' +
            'background:linear-gradient(132deg,#7f1d1d,#b91c1c);border-color:#fecaca;color:#fff;' +
            'box-shadow:0 10px 22px rgba(185,28,28,0.35);}' +
            '@keyframes fitSessionPulse{' +
            '0%{box-shadow:0 0 0 0 rgba(255,138,61,0.72);}70%{box-shadow:0 0 0 8px rgba(255,138,61,0);}' +
            '100%{box-shadow:0 0 0 0 rgba(255,138,61,0);}}' +
            '@keyframes fitSessionRise{' +
            '0%{opacity:0;transform:translateY(16px) scale(.98);}100%{opacity:1;transform:translateY(0) scale(1);}}' +
            '@media (max-width:520px){' +
            '.fit-session-modal{max-width:100%;border-radius:15px;}' +
            '.fit-session-head,.fit-session-body{padding-left:16px;padding-right:16px;}' +
            '.fit-session-actions{flex-direction:column;}' +
            '.fit-session-btn{width:100%;}' +
            '}' +
            '@media (prefers-reduced-motion:reduce){' +
            '.fit-session-modal,.fit-session-pulse,.fit-session-btn{animation:none;transition:none;}' +
            '}';
        document.head.appendChild(styleEl);

        overlayEl = document.createElement('div');
        overlayEl.className = 'fit-session-overlay';
        overlayEl.innerHTML = '' +
            '<div class="fit-session-modal" role="dialog" aria-modal="true" aria-labelledby="fitSessionTitle">' +
            '  <div class="fit-session-head" id="fitSessionHead">' +
            '    <div class="fit-session-head-content">' +
            '      <div id="fitSessionIconWrap" class="fit-session-icon-wrap">' +
            '        <svg id="fitSessionIconWarning" class="fit-session-icon fit-session-icon-warning" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
            '          <path d="M12 8v4" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"/>' +
            '          <circle cx="12" cy="16.6" r="1.2" fill="currentColor"/>' +
            '          <path d="M10.16 3.95a2.1 2.1 0 0 1 3.68 0l8 14A2.1 2.1 0 0 1 20 21H4a2.1 2.1 0 0 1-1.84-3.05l8-14Z" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/>' +
            '        </svg>' +
            '        <svg id="fitSessionIconExpired" class="fit-session-icon fit-session-icon-expired" viewBox="0 0 24 24" fill="none" aria-hidden="true" style="display:none">' +
            '          <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/>' +
            '          <path d="M8.5 8.5l7 7m0-7-7 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>' +
            '        </svg>' +
            '      </div>' +
            '      <div class="fit-session-head-copy">' +
            '        <p id="fitSessionEyebrow" class="fit-session-eyebrow">Security notice</p>' +
            '        <h3 id="fitSessionTitle" class="fit-session-title">Are you still there?</h3>' +
            '      </div>' +
            '    </div>' +
            '  </div>' +
            '  <div class="fit-session-body">' +
            '    <p id="fitSessionBody" class="fit-session-desc">For your security, this session will expire due to inactivity.</p>' +
            '    <div id="fitSessionCountdownWrap" class="fit-session-count-wrap">' +
            '      <span class="fit-session-pulse" aria-hidden="true"></span>' +
            '      <p id="fitSessionCountdown" class="fit-session-count">Auto logout in 60s</p>' +
            '    </div>' +
            '    <p class="fit-session-note">Click "I\'m still here" to continue securely.</p>' +
            '    <div class="fit-session-actions">' +
            '      <button id="fitSessionStayBtn" class="fit-session-btn fit-session-stay">' +
            '        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
            '          <path d="M12 2a10 10 0 1 0 10 10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
            '          <path d="M22 4v6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
            '        </svg>' +
            '        <span id="fitSessionStayLabel">I\'m still here</span>' +
            '      </button>' +
            '      <button id="fitSessionLeaveBtn" class="fit-session-btn fit-session-leave">' +
            '        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
            '          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
            '          <path d="M16 17l5-5-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
            '          <path d="M21 12H9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
            '        </svg>' +
            '        <span id="fitSessionLeaveLabel">Logout now</span>' +
            '      </button>' +
            '    </div>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(overlayEl);

        headEl = document.getElementById('fitSessionHead');
        titleEl = document.getElementById('fitSessionTitle');
        eyebrowEl = document.getElementById('fitSessionEyebrow');
        bodyEl = document.getElementById('fitSessionBody');
        countdownEl = document.getElementById('fitSessionCountdown');
        iconWrapEl = document.getElementById('fitSessionIconWrap');
        warningIconEl = document.getElementById('fitSessionIconWarning');
        expiredIconEl = document.getElementById('fitSessionIconExpired');
        stayBtnEl = document.getElementById('fitSessionStayBtn');
        stayLabelEl = document.getElementById('fitSessionStayLabel');
        leaveBtnEl = document.getElementById('fitSessionLeaveBtn');
        leaveLabelEl = document.getElementById('fitSessionLeaveLabel');

        stayBtnEl.addEventListener('click', function () {
            if (alreadyLoggingOut) {
                return;
            }
            warningVisible = false;
            overlayEl.style.display = 'none';
            registerActivity(true, 'confirm');
            sendHeartbeat(true);
        });

        leaveBtnEl.addEventListener('click', function () {
            triggerLogout();
        });
    }

    function formatSeconds(value) {
        var seconds = Math.max(0, Math.floor(value));
        return String(seconds);
    }

    function showWarning(remainingMs) {
        injectModal();
        warningVisible = true;
        overlayEl.style.display = 'flex';
        overlayEl.classList.remove('fit-expired-state');
        headEl.classList.remove('fit-expired');
        iconWrapEl.classList.remove('fit-expired');
        warningIconEl.style.display = 'block';
        expiredIconEl.style.display = 'none';
        eyebrowEl.textContent = 'Security notice';
        titleEl.textContent = 'Are you still there?';
        bodyEl.textContent = 'For your security, this session will expire due to inactivity.';
        countdownEl.parentElement.style.display = 'flex';
        countdownEl.textContent = 'Auto logout in ' + formatSeconds(remainingMs / 1000) + 's';
        stayBtnEl.style.display = 'inline-flex';
        stayLabelEl.textContent = 'I\'m still here';
        leaveLabelEl.textContent = 'Logout now';
    }

    function showExpiredState() {
        injectModal();
        warningVisible = true;
        overlayEl.style.display = 'flex';
        overlayEl.classList.add('fit-expired-state');
        headEl.classList.add('fit-expired');
        iconWrapEl.classList.add('fit-expired');
        warningIconEl.style.display = 'none';
        expiredIconEl.style.display = 'block';
        eyebrowEl.textContent = 'Timed out';
        titleEl.textContent = 'Session expired';
        bodyEl.textContent = 'Your session expired due to inactivity. Redirecting to login...';
        countdownEl.parentElement.style.display = 'none';
        stayBtnEl.style.display = 'none';
        leaveLabelEl.textContent = 'Go to login';
    }

    function sendHeartbeat(force) {
        if (!pingUrl || alreadyLoggingOut) {
            return;
        }

        var now = Date.now();
        if (!force && (now - lastHeartbeatAt) < 45000) {
            return;
        }

        lastHeartbeatAt = now;

        fetch(pingUrl, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('heartbeat_failed');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    if (data && data.reason === 'session_timeout') {
                        triggerLogout();
                    }
                }
            })
            .catch(function () {
                // Silent fail: server-side timeout still enforces security.
            });
    }

    function triggerLogout() {
        if (alreadyLoggingOut) {
            return;
        }
        alreadyLoggingOut = true;
        showExpiredState();
        setTimeout(function () {
            window.location.href = buildLogoutUrl();
        }, 1200);
    }

    function registerActivity(force, source) {
        if (warningVisible && source !== 'confirm') {
            return;
        }

        var now = Date.now();
        if (force) {
            lastActivityAt = now;
            sendHeartbeat(false);
            return;
        }

        // Avoid excessive churn from high-frequency events like mousemove.
        if (now - lastMouseMoveAt < 800) {
            return;
        }
        lastMouseMoveAt = now;
        lastActivityAt = now;
        sendHeartbeat(false);
    }

    function addActivityListener(eventName, force) {
        document.addEventListener(eventName, function () {
            registerActivity(!!force, eventName);
        }, { passive: true });
    }

    addActivityListener('mousemove', false);
    addActivityListener('mousedown', true);
    addActivityListener('click', true);
    addActivityListener('keydown', true);
    addActivityListener('scroll', false);
    addActivityListener('touchstart', true);

    window.addEventListener('focus', function () {
        registerActivity(true, 'focus');
    });

    setInterval(function () {
        var idleFor = Date.now() - lastActivityAt;
        var remainingMs = timeoutMs - idleFor;

        if (remainingMs <= 0) {
            triggerLogout();
            return;
        }

        if (remainingMs <= warningMs) {
            showWarning(remainingMs);
            return;
        }

        if (warningVisible && overlayEl) {
            warningVisible = false;
            overlayEl.style.display = 'none';
        }
    }, 1000);

    document.addEventListener('DOMContentLoaded', function () {
        injectModal();
    });
})();
