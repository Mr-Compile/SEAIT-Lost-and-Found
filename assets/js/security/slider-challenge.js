(function (window, document) {
    'use strict';

    // Challenge UX inspired by github.com/adrsch/slider-captcha (adapted for this PHP app).
    function toFormBody(payload) {
        var params = new URLSearchParams();
        Object.keys(payload).forEach(function (key) {
            params.append(key, payload[key]);
        });
        return params;
    }

    function clamp(value, minValue, maxValue) {
        return Math.max(minValue, Math.min(maxValue, value));
    }

    function toNumber(value, fallbackValue) {
        var parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallbackValue;
    }

    function FindItClaimSliderChallenge(options) {
        this.options = Object.assign({
            endpoint: 'api/security/slider_challenge_api.php',
            modalId: 'claimSliderModal',
            panelId: 'claimSliderPanel',
            closeBtnId: 'claimSliderCloseBtn',
            retryBtnId: 'claimSliderRetryBtn',
            verifyBtnId: 'claimSliderVerifyBtn',
            statusId: 'claimSliderStatus',
            itemLabelId: 'claimSliderItemName',
            stageId: 'claimSliderStage',
            backgroundCanvasId: 'claimSliderBackgroundCanvas',
            pieceCanvasId: 'claimSliderPieceCanvas',
            trackId: 'claimSliderTrack',
            fillId: 'claimSliderFill',
            handleId: 'claimSliderHandle'
        }, options || {});

        this.elements = {
            modal: document.getElementById(this.options.modalId),
            panel: document.getElementById(this.options.panelId),
            closeBtn: document.getElementById(this.options.closeBtnId),
            retryBtn: document.getElementById(this.options.retryBtnId),
            verifyBtn: document.getElementById(this.options.verifyBtnId),
            status: document.getElementById(this.options.statusId),
            itemLabel: document.getElementById(this.options.itemLabelId),
            stage: document.getElementById(this.options.stageId),
            backgroundCanvas: document.getElementById(this.options.backgroundCanvasId),
            pieceCanvas: document.getElementById(this.options.pieceCanvasId),
            track: document.getElementById(this.options.trackId),
            fill: document.getElementById(this.options.fillId),
            handle: document.getElementById(this.options.handleId)
        };

        this.active = false;
        this.pendingPromise = null;
        this.challengeToken = '';
        this.itemId = 0;
        this.matchId = 0;

        this.backgroundCanvasSource = null;
        this.challenge = null;
        this.position = { x: 0, y: 0 };
        this.trailX = [];
        this.trailY = [];
        this.readyToVerify = false;

        this.dragState = {
            dragging: false,
            source: '',
            pointerId: null,
            startClientX: 0,
            startClientY: 0,
            startX: 0,
            startY: 0
        };

        this.bindEvents();
    }

    FindItClaimSliderChallenge.prototype.bindEvents = function () {
        var self = this;
        var el = this.elements;

        if (!el.modal || !el.backgroundCanvas || !el.pieceCanvas || !el.handle || !el.verifyBtn || !el.track) {
            return;
        }

        var beginDrag = function (event, source) {
            if (!self.active || !self.challengeToken || !self.challenge) {
                return;
            }

            event.preventDefault();
            self.dragState.dragging = true;
            self.dragState.source = source;
            self.dragState.pointerId = event.pointerId;
            self.dragState.startClientX = event.clientX;
            self.dragState.startClientY = event.clientY;
            self.dragState.startX = self.position.x;
            self.dragState.startY = self.position.y;

            if (event.target && event.target.setPointerCapture) {
                try {
                    event.target.setPointerCapture(event.pointerId);
                } catch (err) {
                    // Ignore pointer capture errors from unsupported browsers.
                }
            }

            self.setStatus('Drag the piece diagonally until it fits the slot.', '');
        };

        el.handle.addEventListener('pointerdown', function (event) {
            beginDrag(event, 'handle');
        });

        el.pieceCanvas.addEventListener('pointerdown', function (event) {
            beginDrag(event, 'piece');
        });

        window.addEventListener('pointermove', function (event) {
            self.onPointerMove(event);
        });

        window.addEventListener('pointerup', function (event) {
            self.onPointerUp(event);
        });

        el.verifyBtn.addEventListener('click', function () {
            self.verifyCurrentChallenge();
        });

        if (el.retryBtn) {
            el.retryBtn.addEventListener('click', function () {
                self.issueChallenge();
            });
        }

        if (el.closeBtn) {
            el.closeBtn.addEventListener('click', function () {
                self.cancel('cancelled');
            });
        }

        el.modal.addEventListener('click', function (event) {
            if (event.target === el.modal) {
                self.cancel('cancelled');
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && self.active) {
                self.cancel('cancelled');
            }
        });
    };

    FindItClaimSliderChallenge.prototype.puzzleBounds = function () {
        if (!this.challenge) {
            return { minX: 10, maxX: 240, minY: 10, maxY: 100 };
        }

        return {
            minX: 10,
            maxX: this.challenge.canvasWidth - this.challenge.pieceSize - 10,
            minY: 10,
            maxY: this.challenge.canvasHeight - this.challenge.pieceSize - 10
        };
    };

    FindItClaimSliderChallenge.prototype.onPointerMove = function (event) {
        if (!this.dragState.dragging) {
            return;
        }

        if (this.dragState.pointerId !== null && event.pointerId !== this.dragState.pointerId) {
            return;
        }

        var bounds = this.puzzleBounds();
        var dx = event.clientX - this.dragState.startClientX;
        var dy = event.clientY - this.dragState.startClientY;

        var nextX = this.dragState.startX + dx;
        var nextY = this.dragState.source === 'handle'
            ? this.dragState.startY + (dy * 0.45)
            : this.dragState.startY + dy;

        nextX = clamp(nextX, bounds.minX, bounds.maxX);
        nextY = clamp(nextY, bounds.minY, bounds.maxY);

        this.setPiecePosition(nextX, nextY, true);
    };

    FindItClaimSliderChallenge.prototype.onPointerUp = function (event) {
        if (!this.dragState.dragging) {
            return;
        }

        if (this.dragState.pointerId !== null && event.pointerId !== this.dragState.pointerId) {
            return;
        }

        this.dragState.dragging = false;
        this.dragState.pointerId = null;
        this.readyToVerify = this.trailX.length >= 10;
        this.updateButtons();

        if (this.readyToVerify) {
            this.setStatus('Looks good. Click verify to continue.', '');
        }
    };

    FindItClaimSliderChallenge.prototype.show = function () {
        if (!this.elements.modal) {
            return;
        }

        this.elements.modal.classList.remove('hidden');
        this.elements.modal.classList.add('flex');
    };

    FindItClaimSliderChallenge.prototype.hide = function () {
        if (!this.elements.modal) {
            return;
        }

        this.elements.modal.classList.add('hidden');
        this.elements.modal.classList.remove('flex');
    };

    FindItClaimSliderChallenge.prototype.setStatus = function (message, variant) {
        if (!this.elements.status) {
            return;
        }

        this.elements.status.textContent = String(message || '');
        this.elements.status.classList.remove('success', 'error');

        if (variant === 'success' || variant === 'error') {
            this.elements.status.classList.add(variant);
        }
    };

    FindItClaimSliderChallenge.prototype.updateButtons = function () {
        if (this.elements.verifyBtn) {
            this.elements.verifyBtn.disabled = !this.readyToVerify || !this.challengeToken;
        }
    };

    FindItClaimSliderChallenge.prototype.open = function (context) {
        var self = this;

        if (this.active) {
            return Promise.reject(new Error('A slider challenge is already active.'));
        }

        this.active = true;
        this.challengeToken = '';
        this.itemId = toNumber(context && context.itemId, 0);
        this.matchId = toNumber(context && context.matchId, 0);

        if (this.elements.itemLabel) {
            this.elements.itemLabel.textContent = context && context.itemName ? String(context.itemName) : 'this item';
        }

        this.show();
        this.setStatus('Preparing puzzle challenge...', '');

        this.pendingPromise = {};
        var promise = new Promise(function (resolve, reject) {
            self.pendingPromise.resolve = resolve;
            self.pendingPromise.reject = reject;
        });

        this.issueChallenge();
        return promise;
    };

    FindItClaimSliderChallenge.prototype.finish = function () {
        this.hide();
        this.active = false;
        this.challengeToken = '';
        this.itemId = 0;
        this.matchId = 0;
        this.pendingPromise = null;
        this.dragState.dragging = false;
    };

    FindItClaimSliderChallenge.prototype.resolve = function () {
        if (this.pendingPromise && this.pendingPromise.resolve) {
            this.pendingPromise.resolve(true);
        }
        this.finish();
    };

    FindItClaimSliderChallenge.prototype.cancel = function (message) {
        if (this.pendingPromise && this.pendingPromise.reject) {
            this.pendingPromise.reject(new Error(message || 'cancelled'));
        }
        this.finish();
    };

    FindItClaimSliderChallenge.prototype.issueChallenge = function () {
        var self = this;

        this.setStatus('Preparing puzzle challenge...', '');
        this.challengeToken = '';
        this.readyToVerify = false;
        this.updateButtons();

        fetch(this.options.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: toFormBody({
                action: 'issue',
                item_id: String(this.itemId),
                match_id: String(this.matchId)
            })
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.success || !data.token) {
                    throw new Error((data && data.message) || 'Unable to create slider challenge.');
                }

                self.challengeToken = String(data.token);
                self.challenge = {
                    canvasWidth: toNumber(data.canvas_width, 320),
                    canvasHeight: toNumber(data.canvas_height, 170),
                    pieceSize: toNumber(data.piece_size, 52),
                    startX: toNumber(data.start_x, 14),
                    startY: toNumber(data.start_y, 40),
                    targetX: toNumber(data.target_x, 180),
                    targetY: toNumber(data.target_y, 50),
                    backgroundImageUrl: data.background_image_url ? String(data.background_image_url) : ''
                };

                return self.prepareBackgroundSource(
                    self.challenge.backgroundImageUrl,
                    self.challenge.canvasWidth,
                    self.challenge.canvasHeight
                );
            })
            .then(function () {
                self.resetPuzzlePosition();
                self.renderPuzzleScene();
                self.setStatus('Drag the piece diagonally to the slot, then click verify.', '');
            })
            .catch(function (error) {
                self.setStatus(error.message || 'Challenge request failed.', 'error');
            });
    };

    FindItClaimSliderChallenge.prototype.resetPuzzlePosition = function () {
        if (!this.challenge) {
            return;
        }

        this.trailX = [];
        this.trailY = [];
        this.readyToVerify = false;
        this.setPiecePosition(this.challenge.startX, this.challenge.startY, true);
        this.updateButtons();
    };

    FindItClaimSliderChallenge.prototype.setPiecePosition = function (x, y, appendTrail) {
        this.position.x = x;
        this.position.y = y;

        if (appendTrail) {
            this.trailX.push(Number(x.toFixed(2)));
            this.trailY.push(Number(y.toFixed(2)));

            if (this.trailX.length > 80) {
                this.trailX.shift();
                this.trailY.shift();
            }
        }

        this.renderPiecePosition();
        this.renderTrack();
    };

    FindItClaimSliderChallenge.prototype.renderTrack = function () {
        if (!this.challenge || !this.elements.track || !this.elements.fill || !this.elements.handle) {
            return;
        }

        var bounds = this.puzzleBounds();
        var range = Math.max(1, bounds.maxX - bounds.minX);
        var progress = clamp((this.position.x - bounds.minX) / range, 0, 1);
        this.elements.track.setAttribute('aria-valuenow', String(Math.round(progress * 100)));

        this.elements.fill.style.width = String((progress * 100).toFixed(2)) + '%';

        var trackWidth = this.elements.track.clientWidth;
        var handleWidth = this.elements.handle.offsetWidth || 44;
        var handleLeft = progress * Math.max(0, (trackWidth - handleWidth));
        this.elements.handle.style.left = String(handleLeft.toFixed(2)) + 'px';
    };

    FindItClaimSliderChallenge.prototype.renderPiecePosition = function () {
        if (!this.elements.pieceCanvas) {
            return;
        }

        this.elements.pieceCanvas.style.transform =
            'translate(' + String(this.position.x.toFixed(2)) + 'px, ' + String(this.position.y.toFixed(2)) + 'px)';
    };

    FindItClaimSliderChallenge.prototype.prepareBackgroundSource = function (imageUrl, width, height) {
        var offscreen = document.createElement('canvas');
        offscreen.width = width;
        offscreen.height = height;
        var ctx = offscreen.getContext('2d');

        var self = this;

        if (!imageUrl) {
            this.drawFallbackBackground(ctx, width, height);
            this.backgroundCanvasSource = offscreen;
            return Promise.resolve();
        }

        return new Promise(function (resolve) {
            var img = new Image();
            img.decoding = 'async';

            img.onload = function () {
                var sourceWidth = img.naturalWidth || img.width || width;
                var sourceHeight = img.naturalHeight || img.height || height;
                var scale = Math.max(width / sourceWidth, height / sourceHeight);
                var drawWidth = sourceWidth * scale;
                var drawHeight = sourceHeight * scale;
                var offsetX = (width - drawWidth) / 2;
                var offsetY = (height - drawHeight) / 2;

                ctx.clearRect(0, 0, width, height);
                ctx.drawImage(img, offsetX, offsetY, drawWidth, drawHeight);
                self.overlayTexture(ctx, width, height);
                self.backgroundCanvasSource = offscreen;
                resolve();
            };

            img.onerror = function () {
                self.drawFallbackBackground(ctx, width, height);
                self.backgroundCanvasSource = offscreen;
                resolve();
            };

            img.src = imageUrl;
        });
    };

    FindItClaimSliderChallenge.prototype.overlayTexture = function (ctx, width, height) {
        ctx.save();
        ctx.fillStyle = 'rgba(9, 15, 24, 0.26)';
        ctx.fillRect(0, 0, width, height);

        for (var i = 0; i < 22; i++) {
            var x = Math.random() * width;
            var y = Math.random() * height;
            var radius = 2 + (Math.random() * 6);
            ctx.beginPath();
            ctx.fillStyle = i % 2 === 0 ? 'rgba(255, 123, 44, 0.18)' : 'rgba(56, 189, 248, 0.14)';
            ctx.arc(x, y, radius, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.restore();
    };

    FindItClaimSliderChallenge.prototype.drawFallbackBackground = function (ctx, width, height) {
        var gradient = ctx.createLinearGradient(0, 0, width, height);
        gradient.addColorStop(0, '#1c2a3f');
        gradient.addColorStop(0.55, '#1f3a53');
        gradient.addColorStop(1, '#102033');

        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, width, height);

        for (var i = 0; i < 24; i++) {
            ctx.fillStyle = i % 2 === 0 ? 'rgba(255, 123, 44, 0.18)' : 'rgba(56, 189, 248, 0.16)';
            ctx.beginPath();
            ctx.arc(Math.random() * width, Math.random() * height, 5 + (Math.random() * 18), 0, Math.PI * 2);
            ctx.fill();
        }
    };

    FindItClaimSliderChallenge.prototype.drawPuzzlePath = function (ctx, x, y, size) {
        var notch = size * 0.18;

        ctx.beginPath();
        ctx.moveTo(x, y);
        ctx.lineTo(x + (size * 0.3), y);
        ctx.quadraticCurveTo(x + (size * 0.5), y + notch, x + (size * 0.7), y);
        ctx.lineTo(x + size, y);

        ctx.lineTo(x + size, y + (size * 0.3));
        ctx.quadraticCurveTo(x + size - notch, y + (size * 0.5), x + size, y + (size * 0.7));
        ctx.lineTo(x + size, y + size);

        ctx.lineTo(x + (size * 0.7), y + size);
        ctx.quadraticCurveTo(x + (size * 0.5), y + size - notch, x + (size * 0.3), y + size);
        ctx.lineTo(x, y + size);

        ctx.lineTo(x, y + (size * 0.7));
        ctx.quadraticCurveTo(x + notch, y + (size * 0.5), x, y + (size * 0.3));
        ctx.closePath();
    };

    FindItClaimSliderChallenge.prototype.renderPuzzleScene = function () {
        if (!this.challenge || !this.elements.backgroundCanvas || !this.elements.pieceCanvas || !this.backgroundCanvasSource) {
            return;
        }

        var backgroundCanvas = this.elements.backgroundCanvas;
        var bgCtx = backgroundCanvas.getContext('2d');
        var challenge = this.challenge;

        if (this.elements.stage) {
            this.elements.stage.style.width = String(challenge.canvasWidth) + 'px';
            this.elements.stage.style.height = String(challenge.canvasHeight) + 'px';
            this.elements.stage.style.maxWidth = '100%';
        }

        backgroundCanvas.width = challenge.canvasWidth;
        backgroundCanvas.height = challenge.canvasHeight;

        bgCtx.clearRect(0, 0, challenge.canvasWidth, challenge.canvasHeight);
        bgCtx.drawImage(this.backgroundCanvasSource, 0, 0, challenge.canvasWidth, challenge.canvasHeight);

        bgCtx.save();
        this.drawPuzzlePath(bgCtx, challenge.targetX, challenge.targetY, challenge.pieceSize);
        bgCtx.fillStyle = 'rgba(10, 20, 30, 0.42)';
        bgCtx.fill();
        bgCtx.setLineDash([6, 4]);
        bgCtx.strokeStyle = 'rgba(255, 183, 122, 0.9)';
        bgCtx.lineWidth = 2;
        bgCtx.stroke();
        bgCtx.restore();

        var pieceCanvas = this.elements.pieceCanvas;
        var pieceCtx = pieceCanvas.getContext('2d');
        var padding = 4;

        pieceCanvas.width = challenge.pieceSize + (padding * 2);
        pieceCanvas.height = challenge.pieceSize + (padding * 2);

        pieceCtx.clearRect(0, 0, pieceCanvas.width, pieceCanvas.height);
        pieceCtx.save();
        this.drawPuzzlePath(pieceCtx, padding, padding, challenge.pieceSize);
        pieceCtx.clip();
        pieceCtx.drawImage(
            this.backgroundCanvasSource,
            challenge.targetX,
            challenge.targetY,
            challenge.pieceSize,
            challenge.pieceSize,
            padding,
            padding,
            challenge.pieceSize,
            challenge.pieceSize
        );
        pieceCtx.restore();

        pieceCtx.save();
        this.drawPuzzlePath(pieceCtx, padding, padding, challenge.pieceSize);
        pieceCtx.strokeStyle = 'rgba(255, 255, 255, 0.85)';
        pieceCtx.lineWidth = 2;
        pieceCtx.shadowColor = 'rgba(0, 0, 0, 0.45)';
        pieceCtx.shadowBlur = 8;
        pieceCtx.stroke();
        pieceCtx.restore();

        this.renderPiecePosition();
        this.renderTrack();
    };

    FindItClaimSliderChallenge.prototype.verifyCurrentChallenge = function () {
        var self = this;

        if (!this.challengeToken || !this.challenge) {
            this.setStatus('Challenge is not ready. Please retry.', 'error');
            return;
        }

        if (!this.readyToVerify) {
            this.setStatus('Drag the piece first before verifying.', 'error');
            return;
        }

        this.setStatus('Verifying puzzle alignment...', '');
        if (this.elements.verifyBtn) {
            this.elements.verifyBtn.disabled = true;
        }

        fetch(this.options.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: toFormBody({
                action: 'verify',
                token: this.challengeToken,
                position_x: String(this.position.x),
                position_y: String(this.position.y),
                trail_x: JSON.stringify(this.trailX.slice(-80)),
                trail_y: JSON.stringify(this.trailY.slice(-80)),
                item_id: String(this.itemId),
                match_id: String(this.matchId)
            })
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'Verification failed.');
                }

                self.setStatus(data.message || 'Security check passed.', 'success');
                window.setTimeout(function () {
                    self.resolve();
                }, 220);
            })
            .catch(function (error) {
                self.setStatus(error.message || 'Verification failed.', 'error');
                self.updateButtons();
            });
    };

    window.FindItClaimSliderChallenge = FindItClaimSliderChallenge;
})(window, document);
