(() => {
    const BREAKPOINT_SAFETY_PX = 2;
    const DRAG_SCROLL_THRESHOLD_PX = 4;
    const PROCESSING_QUERY_PARAM = '__bpiProcessing';
    const PREVIEW_WIDTH_SETTLE_TIMEOUT_MS = 800;
    const PREVIEW_WIDTH_SETTLE_TOLERANCE_PX = 2;
    const PREVIEW_FRAME_TAG = 'ifr' + 'ame';
    const IMAGE_WAIT_SOFT_DEADLINE_MS = 4000;
    const IMAGE_WAIT_POLL_MS = 250;
    const CARD_UPDATE_STATUS_CLEAR_DELAY_MS = 1800;

    const bpiProcessingManifest = window.bpiProcessingManifest || {};
    const ENTRY_URL_ACTION = 'craft-breakpoint-images/default/entry-url';
    const RENDER_RESULT_REVIEW_ACTION = 'craft-breakpoint-images/transforms/render-result-review';
    const DATASTAR_FETCH_EVENT = 'datastar-fetch';
    const DATASTAR_PATCH_ELEMENTS_EVENT = 'datastar-patch-elements';
    const DATASTAR_PATCH_SIGNALS_EVENT = 'datastar-patch-signals';
    const DATASTAR_SIGNAL_PATCH_EVENT = 'datastar-signal-patch';

    const elements = {
        page: document.querySelector('.bpi-transforms-page'),
        sourceEntry: document.getElementById('bpi-source-entry'),
        status: document.getElementById('bpi-status'),
        framePane: document.getElementById('bpi-frame-pane'),
        wrapper: document.getElementById('bpi-frame-wrapper'),
        warnings: document.getElementById('bpi-warnings'),
        visualResults: document.getElementById('bpi-visual-results'),
        btnOpenPreview: document.getElementById('bpi-open-preview'),
        btnRun: document.getElementById('bpi-run-processing'),
        btnStop: document.getElementById('bpi-stop-processing'),
        btnClosePreview: document.getElementById('bpi-close-preview'),
        btnCopy: document.getElementById('bpi-copy-output')
    };

    if (!elements.sourceEntry || !elements.wrapper) {
        return;
    }

    const state = {
        previewFrame: null,
        previewUrl: null,
        lastResult: null,
        runCount: 0,
        busy: false,
        stopRequested: false,
        waitSoftLimitReached: false,
        previewVisible: false,
        previewHeightSyncRaf: null,
        sourceSyncRaf: null,
        selectedEntryId: null,
        dragScrollSuppressClick: false,
        updateStatusResetTimersByTransform: {},
        pendingTransformUpdates: new Set(),
        dragScroll: {
            active: false,
            moved: false,
            pointerId: null,
            grid: null,
            startX: 0,
            startScrollLeft: 0
        }
    };

    function setStatus(message) {
        if (elements.status) {
            elements.status.textContent = message;
        }
    }

    function setProcessingState(isProcessing) {
        if (!elements.page) {
            return;
        }

        elements.page.classList.toggle('is-processing', Boolean(isProcessing));
    }

    function updateCopyButtonVisibility() {
        if (!elements.btnCopy) {
            return;
        }

        const hasResult = state.lastResult !== null;
        elements.btnCopy.hidden = !hasResult;
        elements.btnCopy.disabled = !hasResult;
        elements.btnCopy.setAttribute('aria-hidden', hasResult ? 'false' : 'true');
    }

    function setStopButtonVisibility(isVisible) {
        if (!elements.btnStop) {
            return;
        }

        const shouldShow = Boolean(isVisible) && state.waitSoftLimitReached === true;
        elements.btnStop.hidden = !shouldShow;
        elements.btnStop.disabled = !shouldShow;
        elements.btnStop.style.display = shouldShow ? '' : 'none';
        elements.btnStop.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
    }

    function getConfiguredBreakpoints() {
        const rawBreakpoints = Array.isArray(bpiProcessingManifest?.breakpointValues)
            ? bpiProcessingManifest.breakpointValues
            : [];

        return rawBreakpoints
            .map((entry) => parseInt(String(entry), 10))
            .filter((bp) => Number.isFinite(bp) && bp > 0)
            .sort((a, b) => a - b);
    }

    function getFirstBreakpointMeasurementWidth() {
        const breakpoints = getConfiguredBreakpoints();
        if (!breakpoints.length) {
            return null;
        }

        return getMeasurementWidthForBreakpoint(breakpoints[0]);
    }

    function setButtonsDisabled(disabled) {
        const hasSourceSelection = getSelectedEntryId() !== null;
        const runShouldDisable = disabled || !hasSourceSelection;
        const openShouldDisable = disabled || state.previewVisible || !hasSourceSelection;

        if (elements.btnRun) {
            elements.btnRun.disabled = runShouldDisable;
            elements.btnRun.classList.toggle('disabled', runShouldDisable);
        }
        if (elements.btnOpenPreview) {
            elements.btnOpenPreview.disabled = openShouldDisable;
            elements.btnOpenPreview.classList.toggle('disabled', openShouldDisable);
        }
    }

    function setPreviewVisibility(isVisible) {
        state.previewVisible = Boolean(isVisible);

        if (elements.framePane) {
            elements.framePane.classList.toggle('is-visible', state.previewVisible);
            elements.framePane.classList.toggle('is-hidden', !state.previewVisible);
        }

        setButtonsDisabled(state.busy);
    }

    function scheduleSourceControlSync() {
        if (state.sourceSyncRaf !== null) {
            window.cancelAnimationFrame(state.sourceSyncRaf);
        }

        state.sourceSyncRaf = window.requestAnimationFrame(() => {
            state.sourceSyncRaf = null;
            const selectedEntryId = getSelectedEntryId();
            const sourceChanged = selectedEntryId !== state.selectedEntryId;
            state.selectedEntryId = selectedEntryId;

            setButtonsDisabled(state.busy);

            if (!state.busy && sourceChanged) {
                if (selectedEntryId) {
                    setStatus('Source selected. Run Processing next.');
                } else {
                    setStatus('Select a source entry.');
                }
            }
        });
    }

    function bindSourceSelectionSync() {
        if (!elements.sourceEntry) {
            return;
        }

        const observer = new MutationObserver(() => {
            scheduleSourceControlSync();
        });

        observer.observe(elements.sourceEntry, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['value']
        });

        elements.sourceEntry.addEventListener('change', scheduleSourceControlSync);
        elements.sourceEntry.addEventListener('click', () => {
            window.setTimeout(scheduleSourceControlSync, 0);
        });
    }

    function normalizeUrl(rawUrl) {
        const input = String(rawUrl || '').trim();
        if (!input) {
            throw new Error('Source entry URL is required.');
        }
        return new URL(input, window.location.origin).toString();
    }

    function parseEntryId(rawValue) {
        const input = String(rawValue || '').trim();
        if (!input) {
            return null;
        }

        if (!/^\d+$/.test(input)) {
            return null;
        }

        const parsed = parseInt(input, 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    }

    function getSelectedEntryId() {
        if (!elements.sourceEntry) {
            return null;
        }

        const selectedElement = elements.sourceEntry.querySelector('.elements .element[data-id]');
        if (selectedElement) {
            const selectedId = parseEntryId(selectedElement.getAttribute('data-id'));
            if (selectedId !== null) {
                return selectedId;
            }
        }

        const hiddenInputs = Array.from(elements.sourceEntry.querySelectorAll('input[type="hidden"][name="bpi-source-entry-id"]'));
        for (let index = hiddenInputs.length - 1; index >= 0; index -= 1) {
            const value = parseEntryId(hiddenInputs[index].value);
            if (value !== null) {
                return value;
            }
        }

        return null;
    }

    async function resolveSelectedEntryUrl() {
        const entryId = getSelectedEntryId();
        if (!entryId) {
            throw new Error('Select a source entry first.');
        }

        if (typeof Craft === 'undefined' || typeof Craft.sendActionRequest !== 'function') {
            throw new Error('Craft action request API is unavailable.');
        }

        const response = await Craft.sendActionRequest('POST', ENTRY_URL_ACTION, {
            data: {
                entryId,
            },
        });

        return normalizeUrl(response?.data?.url || '');
    }

    function getOrCreatePreviewFrame() {
        if (!state.previewFrame) {
            const previewFrame = document.createElement(PREVIEW_FRAME_TAG);
            previewFrame.id = 'bpi-processing-preview';
            previewFrame.className = 'bpi-frame';
            previewFrame.setAttribute('data-role', 'processing-preview');
            previewFrame.src = 'about:blank';
            elements.wrapper.appendChild(previewFrame);
            state.previewFrame = previewFrame;
        }

        return state.previewFrame;
    }

    async function ensurePreviewFrame(url, forceReload = false) {
        const previewFrame = getOrCreatePreviewFrame();
        const normalizedUrl = normalizeUrl(url);

        const shouldReload = forceReload || state.previewUrl !== normalizedUrl;
        if (!shouldReload) {
            try {
                ensurePreviewScrollbarStyles();
            } catch (_error) {
                // Ignore style injection errors for reused preview frame states.
            }
            return previewFrame;
        }

        await new Promise((resolve, reject) => {
            const timeout = window.setTimeout(() => {
                reject(new Error('Preview load timed out.'));
            }, 15000);

            const onLoad = () => {
                window.clearTimeout(timeout);
                resolve();
            };

            const onError = () => {
                window.clearTimeout(timeout);
                reject(new Error('Preview failed to load.'));
            };

            previewFrame.addEventListener('load', onLoad, { once: true });
            previewFrame.addEventListener('error', onError, { once: true });

            const requestUrl = new URL(normalizedUrl);
            requestUrl.searchParams.set(PROCESSING_QUERY_PARAM, '1');
            if (forceReload) {
                requestUrl.searchParams.set('__bpiReload', String(Date.now()));
            }

            previewFrame.src = requestUrl.toString();
        });

        state.previewUrl = normalizedUrl;
        ensurePreviewScrollbarStyles();

        return previewFrame;
    }

    async function setPreviewWidth(width) {
        if (!state.previewFrame) {
            return;
        }

        state.previewFrame.style.width = `${width}px`;
        await new Promise((resolve) => requestAnimationFrame(resolve));
        await new Promise((resolve) => requestAnimationFrame(resolve));
        await waitForPreviewWidthSettle(width);
    }

    function getPreviewViewportWidth() {
        if (!state.previewFrame) {
            return null;
        }

        const frameRect = state.previewFrame.getBoundingClientRect();
        const frameWidth = Number.isFinite(frameRect.width) ? Math.round(frameRect.width) : null;

        const frameDocument = state.previewFrame.contentDocument || state.previewFrame.contentWindow?.document;
        const viewportWidth = frameDocument?.documentElement?.clientWidth;
        const normalizedViewportWidth = Number.isFinite(viewportWidth) ? Math.round(viewportWidth) : null;

        return normalizedViewportWidth ?? frameWidth;
    }

    async function waitForPreviewWidthSettle(targetWidth) {
        const startedAt = Date.now();
        const normalizedTargetWidth = Math.max(1, Math.round(Number(targetWidth) || 1));

        while ((Date.now() - startedAt) < PREVIEW_WIDTH_SETTLE_TIMEOUT_MS) {
            const viewportWidth = getPreviewViewportWidth();
            if (viewportWidth !== null && Math.abs(viewportWidth - normalizedTargetWidth) <= PREVIEW_WIDTH_SETTLE_TOLERANCE_PX) {
                return;
            }

            await new Promise((resolve) => requestAnimationFrame(resolve));
        }

        // Keep processing resilient if a browser refuses to report exact viewport width.
        const finalViewportWidth = getPreviewViewportWidth();
        console.debug('[BPI] Preview width settle timeout', {
            targetWidth: normalizedTargetWidth,
            viewportWidth: finalViewportWidth,
        });
    }

    function getMeasurementWidthForBreakpoint(breakpoint) {
        const parsed = parseInt(String(breakpoint), 10);
        if (!Number.isFinite(parsed) || parsed <= 1) {
            return 1;
        }

        // Measure at the true frontend breakpoint size (inside the target range).
        // Oversized media ranges are only a guard against accidental source rollover.
        return Math.max(1, parsed - BREAKPOINT_SAFETY_PX);
    }

    function getFrameDocument() {
        const frameDocument = state.previewFrame?.contentDocument || state.previewFrame?.contentWindow?.document;
        if (!frameDocument) {
            throw new Error('Preview document is not accessible. Ensure same-origin URL.');
        }

        return frameDocument;
    }

    function ensurePreviewScrollbarStyles() {
        const frameDocument = getFrameDocument();
        const styleId = 'bpi-processing-scrollbar-style';
        if (frameDocument.getElementById(styleId)) {
            return;
        }

        const style = frameDocument.createElement('style');
        style.id = styleId;
        style.textContent = `
            html, body {
                scrollbar-width: none !important;
                -ms-overflow-style: none !important;
            }

            html::-webkit-scrollbar,
            body::-webkit-scrollbar {
                width: 0 !important;
                height: 0 !important;
                display: none !important;
            }
        `;

        const target = frameDocument.head || frameDocument.documentElement || frameDocument.body;
        target?.appendChild(style);
    }

    async function waitForImagesToSettle({
        softDeadlineMs = IMAGE_WAIT_SOFT_DEADLINE_MS,
        pollMs = IMAGE_WAIT_POLL_MS,
        shouldStop = () => false,
        onSoftDeadline = null,
        onWaitingTick = null,
    } = {}) {
        const frameDocument = getFrameDocument();
        const isActiveWaitCandidate = (img) => {
            if (img.complete) {
                return false;
            }

            if (img.currentSrc && !img.currentSrc.startsWith('data:image')) {
                return true;
            }

            return img.loading !== 'lazy';
        };

        const getPendingImages = () => Array.from(frameDocument.querySelectorAll('picture[data-transform] img'))
            .filter(isActiveWaitCandidate);

        const startedAt = Date.now();
        let softDeadlineReached = false;
        let lastTickAt = 0;

        if (!frameDocument.querySelector('picture[data-transform] img')) {
            await new Promise((resolve) => requestAnimationFrame(resolve));
            return {
                aborted: false,
                timedOut: false,
                waitedMs: 0,
                pendingCount: 0,
            };
        }

        if (getPendingImages().length < 1) {
            await new Promise((resolve) => requestAnimationFrame(resolve));
            return {
                aborted: false,
                timedOut: false,
                waitedMs: 0,
                pendingCount: 0,
            };
        }

        while (true) {
            if (shouldStop()) {
                const pendingNow = getPendingImages();
                return {
                    aborted: true,
                    timedOut: softDeadlineReached,
                    waitedMs: Date.now() - startedAt,
                    pendingCount: pendingNow.length,
                };
            }

            const pending = getPendingImages();
            if (!pending.length) {
                break;
            }

            const waitedMs = Date.now() - startedAt;
            if (!softDeadlineReached && waitedMs >= softDeadlineMs) {
                softDeadlineReached = true;
                if (typeof onSoftDeadline === 'function') {
                    onSoftDeadline({
                        waitedMs,
                        pendingCount: pending.length,
                    });
                }
                lastTickAt = waitedMs;
            }

            if (softDeadlineReached && typeof onWaitingTick === 'function' && (waitedMs - lastTickAt) >= 1000) {
                onWaitingTick({
                    waitedMs,
                    pendingCount: pending.length,
                });
                lastTickAt = waitedMs;
            }

            await new Promise((resolve) => window.setTimeout(resolve, pollMs));
        }

        await new Promise((resolve) => requestAnimationFrame(resolve));
        await new Promise((resolve) => requestAnimationFrame(resolve));

        return {
            aborted: false,
            timedOut: softDeadlineReached,
            waitedMs: Date.now() - startedAt,
            pendingCount: 0,
        };
    }

    function getPictureLoadKey(picture, index) {
        return picture?.getAttribute('data-picture-id')
            || picture?.getAttribute('data-asset-id')
            || `unknown-${index}`;
    }

    function getPrimarySourceForBreakpoint(picture, breakpoint) {
        return picture?.querySelector(`source.bpi_first-source-set[data-bp-size="${breakpoint}"]`)
            || picture?.querySelector(`source[data-bp-size="${breakpoint}"]`)
            || null;
    }

    function isTransparentPixelSrcset(srcset) {
        const normalized = String(srcset || '').trim();
        return normalized.startsWith('data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
    }

    async function preloadBreakpointSources(breakpoint, timeoutMs = 5000) {
        const frameDocument = getFrameDocument();
        const pictures = Array.from(frameDocument.querySelectorAll('picture[data-transform]'));
        const loadStates = new Map();

        const waiters = pictures.map((picture, index) => new Promise((resolve) => {
            const key = getPictureLoadKey(picture, index);
            const source = getPrimarySourceForBreakpoint(picture, breakpoint);

            if (!source) {
                loadStates.set(key, false);
                resolve();
                return;
            }

            const enabled = source.getAttribute('data-bp-enabled') !== 'false';
            if (!enabled) {
                loadStates.set(key, true);
                resolve();
                return;
            }

            const srcset = String(source.getAttribute('srcset') || '').trim();
            if (!srcset || isTransparentPixelSrcset(srcset)) {
                loadStates.set(key, true);
                resolve();
                return;
            }

            const probe = new Image();
            let done = false;

            const finish = (ok) => {
                if (done) {
                    return;
                }

                done = true;
                probe.removeEventListener('load', onLoad);
                probe.removeEventListener('error', onError);
                loadStates.set(key, ok);
                resolve();
            };

            const onLoad = () => finish(true);
            const onError = () => finish(false);

            probe.addEventListener('load', onLoad);
            probe.addEventListener('error', onError);

            const sizes = source.getAttribute('sizes');
            if (sizes) {
                probe.sizes = sizes;
            }

            probe.srcset = srcset;

            window.setTimeout(() => finish(false), timeoutMs);
        }));

        await Promise.all(waiters);
        await new Promise((resolve) => requestAnimationFrame(resolve));
        await new Promise((resolve) => requestAnimationFrame(resolve));

        return loadStates;
    }

    function extractRowsForBreakpoint(breakpoint, preloadStates = null) {
        const frameDocument = getFrameDocument();

        const images = Array.from(frameDocument.querySelectorAll('picture[data-transform] img'));

        return images.map((img, index) => {
            const picture = img.closest('picture');
            const source = getPrimarySourceForBreakpoint(picture, breakpoint);
            if (!source) {
                return null;
            }

            const assetId = img.getAttribute('data-asset-id') || picture?.getAttribute('data-asset-id') || `unknown-${index}`;
            const enabled = source?.getAttribute('data-bp-enabled') !== 'false';
            const preloadKey = getPictureLoadKey(picture, index);
            const preloadLoaded = preloadStates ? preloadStates.get(preloadKey) : undefined;
            const loadedFromElement = img.complete && (img.naturalWidth > 0 || img.naturalHeight > 0);

            return {
                assetId,
                transform: picture?.getAttribute('data-transform') || 'unknown',
                title: picture?.getAttribute('data-asset-title') || '',
                enabled,
                isVisible: img.offsetWidth > 0 || img.offsetHeight > 0,
                src: img.currentSrc || img.getAttribute('src') || '',
                loaded: enabled ? Boolean(preloadLoaded || loadedFromElement) : true,
                rendered: {
                    width: img.clientWidth || 0,
                    height: img.clientHeight || 0
                },
                intrinsic: {
                    width: img.naturalWidth || 0,
                    height: img.naturalHeight || 0
                },
                transformDimensions: {
                    width: toPositiveIntOrNull(source?.getAttribute('data-transform-width')),
                    height: toPositiveIntOrNull(source?.getAttribute('data-transform-height')),
                    autoDimension: source?.getAttribute('data-auto-dimension') || null
                }
            };
        }).filter((row) => row !== null);
    }

    function toPositiveIntOrNull(value) {
        const parsed = parseInt(String(value ?? '').trim(), 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    }

    function buildStructuredOutput(sourceUrl, breakpoints, rowsByBreakpoint, startedAt) {
        const assetsById = new Set();
        let unloadedImageCount = 0;

        Object.values(rowsByBreakpoint).forEach((rows) => {
            rows.forEach((row) => {
                assetsById.add(String(row.assetId || ''));

                if (!row.loaded) {
                    unloadedImageCount += 1;
                }
            });
        });

        const durationMs = Date.now() - startedAt;
        const rowCount = Object.values(rowsByBreakpoint).reduce((count, rows) => count + rows.length, 0);

        return {
            schemaVersion: 2,
            manifestSchemaVersion: bpiProcessingManifest?.schemaVersion || null,
            runId: `run-${Date.now()}`,
            sourceUrl,
            timestamp: new Date().toISOString(),
            breakpoints,
            rowsByBreakpoint,
            summary: {
                runs: state.runCount,
                breakpointCount: breakpoints.length,
                assetCount: Array.from(assetsById).filter((assetId) => assetId !== '').length,
                rowCount,
                warningCount: 0,
                unloadedImageCount,
                durationMs
            }
        };
    }

    function syncBreakpointPreviewHeights() {
        if (!elements.visualResults) {
            return;
        }

        const grids = Array.from(elements.visualResults.querySelectorAll('.bpi-breakpoint-grid'));
        grids.forEach((grid) => {
            const resultBlocks = Array.from(grid.querySelectorAll('.bpi_breakpoint-result'));
            if (!resultBlocks.length) {
                return;
            }

            resultBlocks.forEach((block) => {
                block.style.removeProperty('min-height');
            });

            const tallest = Math.max(
                0,
                ...resultBlocks.map((block) => Math.ceil(block.getBoundingClientRect().height || 0))
            );

            if (tallest < 1) {
                return;
            }

            resultBlocks.forEach((block) => {
                block.style.minHeight = `${tallest}px`;
            });
        });

        updateDragScrollability();
    }

    function updateDragScrollability() {
        if (!elements.visualResults) {
            return;
        }

        const grids = Array.from(elements.visualResults.querySelectorAll('.bpi-breakpoint-grid'));
        grids.forEach((grid) => {
            updateGridScrollAffordance(grid);
        });
    }

    function updateGridScrollAffordance(grid) {
        const maxScrollLeft = Math.max(0, grid.scrollWidth - grid.clientWidth);
        const isScrollable = maxScrollLeft > 1;
        const atStart = grid.scrollLeft <= 1;
        const atEnd = grid.scrollLeft >= (maxScrollLeft - 1);

        grid.classList.toggle('bpi-drag-scrollable', isScrollable);
        grid.classList.toggle('bpi-scroll-fade-active', isScrollable);
        grid.classList.toggle('bpi-scroll-fade-left', isScrollable && !atStart);
        grid.classList.toggle('bpi-scroll-fade-right', isScrollable && !atEnd);
    }

    function endDragScroll(pointerId = null) {
        const drag = state.dragScroll;
        if (!drag.active) {
            return;
        }

        if (pointerId !== null && drag.pointerId !== pointerId) {
            return;
        }

        if (drag.grid) {
            drag.grid.classList.remove('bpi-drag-scrolling');

            if (drag.pointerId !== null && drag.grid.hasPointerCapture?.(drag.pointerId)) {
                try {
                    drag.grid.releasePointerCapture(drag.pointerId);
                } catch (_error) {
                    // Ignore pointer capture release errors.
                }
            }
        }

        drag.active = false;
        drag.moved = false;
        drag.pointerId = null;
        drag.grid = null;
        drag.startX = 0;
        drag.startScrollLeft = 0;
    }

    function setupDragToScroll() {
        if (!elements.visualResults) {
            return;
        }

        const interactiveSelector = 'a, button, input, select, textarea, label, [role="button"], .btn';

        elements.visualResults.addEventListener('pointerdown', (event) => {
            if (event.pointerType !== 'mouse' || event.button !== 0) {
                return;
            }

            const grid = event.target.closest('.bpi-breakpoint-grid');
            if (!grid || !elements.visualResults.contains(grid)) {
                return;
            }

            if (!grid.classList.contains('bpi-drag-scrollable')) {
                return;
            }

            if (event.target.closest(interactiveSelector)) {
                return;
            }

            state.dragScroll.active = true;
            state.dragScroll.moved = false;
            state.dragScroll.pointerId = event.pointerId;
            state.dragScroll.grid = grid;
            state.dragScroll.startX = event.clientX;
            state.dragScroll.startScrollLeft = grid.scrollLeft;

            if (grid.setPointerCapture) {
                try {
                    grid.setPointerCapture(event.pointerId);
                } catch (_error) {
                    // Ignore pointer capture errors.
                }
            }
        });

        window.addEventListener('pointermove', (event) => {
            const drag = state.dragScroll;
            if (!drag.active || drag.pointerId !== event.pointerId || !drag.grid) {
                return;
            }

            const deltaX = event.clientX - drag.startX;
            if (!drag.moved && Math.abs(deltaX) < DRAG_SCROLL_THRESHOLD_PX) {
                return;
            }

            if (!drag.moved) {
                drag.moved = true;
                drag.grid.classList.add('bpi-drag-scrolling');
                state.dragScrollSuppressClick = true;
            }

            event.preventDefault();
            drag.grid.scrollLeft = drag.startScrollLeft - deltaX;
            updateGridScrollAffordance(drag.grid);
        }, { passive: false });

        window.addEventListener('pointerup', (event) => {
            endDragScroll(event.pointerId);
        });

        window.addEventListener('pointercancel', (event) => {
            endDragScroll(event.pointerId);
        });

        elements.visualResults.addEventListener('dragstart', (event) => {
            if (event.target.closest('.bpi_breakpoint-result-image')) {
                event.preventDefault();
            }
        });

        elements.visualResults.addEventListener('scroll', (event) => {
            const target = event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }

            const grid = target.closest('.bpi-breakpoint-grid');
            if (!grid || !elements.visualResults.contains(grid)) {
                return;
            }

            updateGridScrollAffordance(grid);
        }, true);

        elements.visualResults.addEventListener('click', (event) => {
            if (!state.dragScrollSuppressClick) {
                return;
            }

            if (event.target.closest('.bpi-breakpoint-grid')) {
                event.preventDefault();
                event.stopPropagation();
            }

            state.dragScrollSuppressClick = false;
        }, true);
    }

    function scheduleBreakpointPreviewHeightSync() {
        if (state.previewHeightSyncRaf !== null) {
            window.cancelAnimationFrame(state.previewHeightSyncRaf);
        }

        state.previewHeightSyncRaf = window.requestAnimationFrame(() => {
            state.previewHeightSyncRaf = null;
            syncBreakpointPreviewHeights();
        });
    }

    function collectReviewEditStateFromDom() {
        const editScopeByTransform = {};
        const editTabByTransform = {};

        if (!elements.visualResults) {
            return {
                editScopeByTransform,
                editTabByTransform,
            };
        }

        const cards = Array.from(elements.visualResults.querySelectorAll('.bpi-transform-card[data-transform]'));
        cards.forEach((card) => {
            const transformName = String(card.getAttribute('data-transform') || '').trim();
            if (!transformName) {
                return;
            }

            const rawScopeMode = String(card.getAttribute('data-scope-mode') || '').trim().toLowerCase();
            if (rawScopeMode === 'all') {
                editScopeByTransform[transformName] = {
                    mode: 'all',
                    breakpoint: null,
                };
            } else if (rawScopeMode === 'breakpoint') {
                const scopeBreakpoint = toPositiveIntOrNull(card.getAttribute('data-scope-breakpoint'));
                if (scopeBreakpoint !== null) {
                    editScopeByTransform[transformName] = {
                        mode: 'breakpoint',
                        breakpoint: scopeBreakpoint,
                    };
                }
            } else {
                editScopeByTransform[transformName] = {
                    mode: 'unset',
                    breakpoint: null,
                };
            }

            const activeTab = String(card.getAttribute('data-active-tab') || '').trim().toLowerCase();
            editTabByTransform[transformName] = (activeTab === 'ratio' || activeTab === 'settings')
                ? activeTab
                : 'dimensions';
        });

        return {
            editScopeByTransform,
            editTabByTransform,
        };
    }

    function clearUpdateStatusResetTimer(transformName) {
        const existingTimer = state.updateStatusResetTimersByTransform[transformName] || null;
        if (existingTimer !== null) {
            window.clearTimeout(existingTimer);
            delete state.updateStatusResetTimersByTransform[transformName];
        }
    }

    function findTransformCard(transformName) {
        if (!elements.visualResults || !transformName) {
            return null;
        }

        const cards = Array.from(elements.visualResults.querySelectorAll('.bpi-transform-card[data-transform]'));
        return cards.find((card) => (card.getAttribute('data-transform') || '') === transformName) || null;
    }

    function setTransformUpdateStatus(transformName, message, statusState) {
        const card = findTransformCard(transformName);
        if (!card) {
            return;
        }

        const statusElement = card.querySelector('[data-role="transform-update-status"]');
        if (!statusElement) {
            return;
        }

        statusElement.textContent = message;
        statusElement.setAttribute('data-state', statusState);
    }

    function scheduleTransformUpdateStatusClear(transformName, delayMs = CARD_UPDATE_STATUS_CLEAR_DELAY_MS) {
        clearUpdateStatusResetTimer(transformName);

        const timer = window.setTimeout(() => {
            setTransformUpdateStatus(transformName, '', 'idle');
            clearUpdateStatusResetTimer(transformName);
        }, delayMs);

        state.updateStatusResetTimersByTransform[transformName] = timer;
    }

    function removePendingTransformUpdate(transformName) {
        if (!transformName) {
            return;
        }

        state.pendingTransformUpdates.delete(transformName);
    }

    function finalizePendingTransformUpdatesFromServerStatus(serverStatus) {
        const status = serverStatus && typeof serverStatus === 'object' ? serverStatus : null;
        const kind = String(status?.kind || '').trim().toLowerCase();
        if (kind !== 'success' && kind !== 'error') {
            return;
        }

        const pendingTransforms = Array.from(state.pendingTransformUpdates);
        if (pendingTransforms.length < 1) {
            return;
        }

        state.pendingTransformUpdates.clear();

        if (kind === 'success') {
            pendingTransforms.forEach((transformName) => {
                setTransformUpdateStatus(transformName, 'Updated', 'success');
                scheduleTransformUpdateStatusClear(transformName);
            });

            if (state.lastResult) {
                void renderResultReview(state.lastResult).catch((error) => {
                    console.error(error);
                });
            }

            return;
        }

        pendingTransforms.forEach((transformName) => {
            setTransformUpdateStatus(transformName, 'Update failed', 'error');
            scheduleTransformUpdateStatusClear(transformName, 2600);
        });
    }

    function finalizeTransformUpdateFromServerStatus(transformName, serverStatus) {
        if (!transformName || !state.pendingTransformUpdates.has(transformName)) {
            return;
        }

        const status = serverStatus && typeof serverStatus === 'object' ? serverStatus : null;
        const kind = String(status?.kind || '').trim().toLowerCase();
        if (kind !== 'success' && kind !== 'error') {
            return;
        }

        removePendingTransformUpdate(transformName);

        if (kind === 'success') {
            setTransformUpdateStatus(transformName, 'Updated', 'success');
            scheduleTransformUpdateStatusClear(transformName);
            if (state.lastResult) {
                void renderResultReview(state.lastResult).catch((error) => {
                    console.error(error);
                });
            }
            return;
        }

        setTransformUpdateStatus(transformName, 'Update failed', 'error');
        scheduleTransformUpdateStatusClear(transformName, 2600);
    }

    function parseServerStatusFromPatchSignalsArgs(argsRaw) {
        const signalsRaw = argsRaw?.signals;
        if (typeof signalsRaw !== 'string' || signalsRaw.trim() === '') {
            return null;
        }

        try {
            const parsed = JSON.parse(signalsRaw);
            const serverStatus = parsed?.editor?.serverStatus;
            return serverStatus && typeof serverStatus === 'object' ? serverStatus : null;
        } catch (_error) {
            return null;
        }
    }

    function getDatastarUpdateAction(sourceElement) {
        const classList = sourceElement.classList;
        const isWidthInput = classList?.contains('bpi-transform-width-input');
        const isHeightInput = classList?.contains('bpi-transform-height-input');
        const isDimensionsApply = classList?.contains('bpi-transform-dimensions-apply');
        const isRatioApply = classList?.contains('bpi-transform-ratio-apply');
        const isSettingsApply = classList?.contains('bpi-transform-settings-apply');
        const isRenderedAction = classList?.contains('bpi-rendered-apply-single')
            || classList?.contains('bpi-rendered-apply-all');

        if (isRenderedAction) {
            return 'renderedValues';
        }

        if (isDimensionsApply) {
            return 'dimensions';
        }

        if (isRatioApply) {
            return 'ratio';
        }

        if (isSettingsApply) {
            return 'settings';
        }

        if (isWidthInput) {
            return 'width';
        }

        if (isHeightInput) {
            return 'height';
        }

        return null;
    }

    function setupDatastarCardUpdateStatus() {
        document.addEventListener(DATASTAR_SIGNAL_PATCH_EVENT, (event) => {
            const detail = event?.detail;
            const serverStatus = detail?.editor?.serverStatus;
            finalizePendingTransformUpdatesFromServerStatus(serverStatus);
        });

        document.addEventListener('datastar-fetch', (event) => {
            const detail = event?.detail || {};
            const sourceElement = detail.el;

            if (detail.type === DATASTAR_PATCH_SIGNALS_EVENT) {
                const serverStatus = parseServerStatusFromPatchSignalsArgs(detail.argsRaw);
                if (!serverStatus) {
                    return;
                }

                if (sourceElement && typeof sourceElement.closest === 'function') {
                    const card = sourceElement.closest('.bpi-transform-card');
                    const transformName = card?.getAttribute('data-transform') || '';
                    if (transformName) {
                        finalizeTransformUpdateFromServerStatus(transformName, serverStatus);
                        return;
                    }
                }

                finalizePendingTransformUpdatesFromServerStatus(serverStatus);
                return;
            }

            if (!sourceElement || typeof sourceElement.closest !== 'function') {
                return;
            }

            const action = getDatastarUpdateAction(sourceElement);
            if (!action) {
                return;
            }

            const card = sourceElement.closest('.bpi-transform-card');
            if (!card || !elements.visualResults || !elements.visualResults.contains(card)) {
                return;
            }

            const transformName = card.getAttribute('data-transform') || '';
            if (!transformName) {
                return;
            }

            if (detail.type === 'started') {
                state.pendingTransformUpdates.add(transformName);
                clearUpdateStatusResetTimer(transformName);
                setTransformUpdateStatus(transformName, 'Updating...', 'pending');
                return;
            }

            if (detail.type === 'finished') {
                if (state.pendingTransformUpdates.has(transformName)) {
                    setTransformUpdateStatus(transformName, 'Syncing...', 'pending');
                }
                return;
            }

            if (detail.type === 'error' || detail.type === 'retries-failed') {
                removePendingTransformUpdate(transformName);
                setTransformUpdateStatus(transformName, 'Update failed', 'error');
                scheduleTransformUpdateStatusClear(transformName, 2600);
            }
        });
    }

    function patchElementsWithDatastar(selector, html, mode = 'inner') {
        if (!selector || typeof html !== 'string') {
            return false;
        }

        try {
            document.dispatchEvent(new CustomEvent(DATASTAR_FETCH_EVENT, {
                detail: {
                    type: DATASTAR_PATCH_ELEMENTS_EVENT,
                    el: elements.page || document.documentElement,
                    argsRaw: {
                        selector,
                        mode,
                        elements: html,
                    },
                },
            }));

            return true;
        } catch (_error) {
            return false;
        }
    }

    function applyRenderedReviewPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        if (elements.warnings && typeof payload.warningsHtml === 'string') {
            const warningsPatched = patchElementsWithDatastar('#bpi-warnings', payload.warningsHtml, 'inner');
            if (!warningsPatched) {
                elements.warnings.innerHTML = payload.warningsHtml;
            }
        }

        if (elements.visualResults && typeof payload.visualResultsHtml === 'string') {
            const visualResultsPatched = patchElementsWithDatastar('#bpi-visual-results', payload.visualResultsHtml, 'inner');
            if (!visualResultsPatched) {
                elements.visualResults.innerHTML = payload.visualResultsHtml;
            }
            scheduleBreakpointPreviewHeightSync();
            window.setTimeout(scheduleBreakpointPreviewHeightSync, 120);

            const images = Array.from(elements.visualResults.querySelectorAll('.bpi_breakpoint-result-image'));
            images.forEach((image) => {
                if (image instanceof HTMLImageElement && image.complete) {
                    return;
                }

                image.addEventListener('load', scheduleBreakpointPreviewHeightSync, { once: true });
                image.addEventListener('error', scheduleBreakpointPreviewHeightSync, { once: true });
            });
        }

        const warningCount = Number(payload.warningCount);
        if (state.lastResult && state.lastResult.summary && Number.isFinite(warningCount) && warningCount >= 0) {
            state.lastResult.summary.warningCount = warningCount;
        }
    }

    async function renderResultReview(result) {
        if (!result || typeof result !== 'object') {
            return null;
        }

        if (typeof Craft === 'undefined' || typeof Craft.sendActionRequest !== 'function') {
            return null;
        }

        const {
            editScopeByTransform,
            editTabByTransform,
        } = collectReviewEditStateFromDom();

        const response = await Craft.sendActionRequest('POST', RENDER_RESULT_REVIEW_ACTION, {
            data: {
                result,
                editScopeByTransform,
                editTabByTransform,
            },
        });

        const payload = response?.data || null;
        applyRenderedReviewPayload(payload);
        return payload;
    }

    async function publishResult(result) {
        state.lastResult = result;
        updateCopyButtonVisibility();
        try {
            await renderResultReview(result);
        } catch (error) {
            // Keep measured output available even if backend review render fails.
            console.error(error);
        }

        document.dispatchEvent(new CustomEvent('bpi:processing-result', {
            detail: result
        }));
    }

    function buildWaitingStatusMessage(breakpoint, pendingCount, waitedMs = null) {
        const imageLabel = `${pendingCount} image${pendingCount === 1 ? '' : 's'}`;
        if (Number.isFinite(waitedMs) && waitedMs > 0) {
            const seconds = Math.ceil(waitedMs / 1000);
            return `Waiting. Probably on transforms. ${imageLabel} still pending at ${breakpoint}px (${seconds}s). Click Quit Waiting to stop.`;
        }

        return `Waiting. Probably on transforms. ${imageLabel} still pending at ${breakpoint}px. Click Quit Waiting to stop.`;
    }

    async function loadPreviewForSelectedEntry(successMessage) {
        setStatus('Loading entry...');
        const firstMeasurementWidth = getFirstBreakpointMeasurementWidth();
        if (firstMeasurementWidth !== null) {
            await setPreviewWidth(firstMeasurementWidth);
        }

        const sourceUrl = await resolveSelectedEntryUrl();
        await ensurePreviewFrame(sourceUrl, false);
        setStatus(successMessage);
    }

    async function runProcessing() {
        if (state.busy) {
            return;
        }

        const breakpoints = getConfiguredBreakpoints();

        if (!breakpoints.length) {
            setStatus('No configured breakpoints available. Check plugin settings.');
            return;
        }

        state.busy = true;
        state.stopRequested = false;
        state.waitSoftLimitReached = false;
        setProcessingState(true);
        setStopButtonVisibility(false);
        setButtonsDisabled(true);
        setStatus('Preparing preview...');

        const startedAt = Date.now();

        try {
            const sourceUrl = await resolveSelectedEntryUrl();
            getOrCreatePreviewFrame();
            await ensurePreviewFrame(sourceUrl, true);

            const rowsByBreakpoint = {};
            for (const breakpoint of breakpoints) {
                state.waitSoftLimitReached = false;
                setStopButtonVisibility(false);
                const measurementWidth = getMeasurementWidthForBreakpoint(breakpoint);
                setStatus(`Processing ${breakpoint}px...`);
                await setPreviewWidth(measurementWidth);
                const preloadStates = await preloadBreakpointSources(breakpoint);
                const waitResult = await waitForImagesToSettle({
                    shouldStop: () => state.stopRequested,
                    onSoftDeadline: ({ pendingCount }) => {
                        state.waitSoftLimitReached = true;
                        setStopButtonVisibility(true);
                        setStatus(buildWaitingStatusMessage(breakpoint, pendingCount));
                    },
                    onWaitingTick: ({ pendingCount, waitedMs }) => {
                        setStatus(buildWaitingStatusMessage(breakpoint, pendingCount, waitedMs));
                    },
                });

                if (waitResult.aborted) {
                    throw new Error('Processing stopped by user during image wait.');
                }

                state.waitSoftLimitReached = false;
                setStopButtonVisibility(false);
                rowsByBreakpoint[breakpoint] = extractRowsForBreakpoint(breakpoint, preloadStates);
            }

            state.runCount += 1;
            const result = buildStructuredOutput(state.previewUrl, breakpoints, rowsByBreakpoint, startedAt);
            await publishResult(result);
            const warningSuffix = result.summary.warningCount > 0
                ? ` (${result.summary.warningCount} warnings)`
                : '';
            const unloadedSuffix = result.summary.unloadedImageCount > 0
                ? ` (${result.summary.unloadedImageCount} unloaded rows)`
                : '';
            setStatus(`Done. ${result.summary.assetCount} assets across ${breakpoints.length} breakpoints.${warningSuffix}${unloadedSuffix}`);
        } catch (error) {
            setStatus(`Error: ${error.message}`);
        } finally {
            state.busy = false;
            state.stopRequested = false;
            state.waitSoftLimitReached = false;
            setProcessingState(false);
            setStopButtonVisibility(false);
            setButtonsDisabled(false);
        }
    }

    if (elements.btnRun) {
        elements.btnRun.addEventListener('click', async () => {
            await runProcessing();
        });
    }

    if (elements.btnCopy) {
        elements.btnCopy.addEventListener('click', async () => {
            if (!state.lastResult) {
                setStatus('No structured output available yet.');
                return;
            }

            const text = JSON.stringify(state.lastResult, null, 2);
            try {
                await navigator.clipboard.writeText(text);
                setStatus('Structured output copied to clipboard.');
            } catch (error) {
                setStatus('Copy failed.');
            }
        });
    }

    if (elements.btnStop) {
        elements.btnStop.addEventListener('click', () => {
            if (!state.busy) {
                return;
            }

            state.stopRequested = true;
            elements.btnStop.disabled = true;
            setStatus('Stopping after the current wait check...');
        });
    }

    if (elements.btnOpenPreview) {
        elements.btnOpenPreview.addEventListener('click', async () => {
            setPreviewVisibility(true);
            try {
                await loadPreviewForSelectedEntry('Preview opened.');
            } catch (error) {
                setStatus(`Error: ${error.message}`);
            }
        });
    }

    if (elements.btnClosePreview) {
        elements.btnClosePreview.addEventListener('click', () => {
            setPreviewVisibility(false);
            setStatus('Preview hidden.');
        });
    }

    async function loadInitialPreview() {
        getOrCreatePreviewFrame();
        const entryId = getSelectedEntryId();
        if (!entryId) {
            setStatus('Select a source entry.');
            return;
        }

        try {
            await loadPreviewForSelectedEntry('Entry loaded.');
        } catch (error) {
            setStatus(`Error: ${error.message}`);
        }
    }

    setPreviewVisibility(false);
    setProcessingState(false);
    setStopButtonVisibility(false);
    updateCopyButtonVisibility();
    state.selectedEntryId = getSelectedEntryId();
    bindSourceSelectionSync();
    setButtonsDisabled(false);
    setupDragToScroll();
    setupDatastarCardUpdateStatus();
    window.addEventListener('resize', scheduleBreakpointPreviewHeightSync);
    getConfiguredBreakpoints();
    void loadInitialPreview();
})();
