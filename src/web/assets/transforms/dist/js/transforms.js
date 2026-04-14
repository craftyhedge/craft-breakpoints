import {
    activateLazySizes as processingActivateLazySizes,
    activateLozad as processingActivateLozad,
    activateVanillaLazyLoad as processingActivateVanillaLazyLoad,
    appendRunIssue as processingAppendRunIssue,
    appendBreakpointReadinessIssues as processingAppendBreakpointReadinessIssues,
    buildBreakpointReadinessTracker as processingBuildBreakpointReadinessTracker,
    buildStructuredOutput as processingBuildStructuredOutput,
    buildWaitingStatusMessage as processingBuildWaitingStatusMessage,
    createBreakpointReportEntry as processingCreateBreakpointReportEntry,
    createReadinessSummary as processingCreateReadinessSummary,
    createRunReport as processingCreateRunReport,
    deriveSourceUsed as processingDeriveSourceUsed,
    extractRowsForBreakpoint as processingExtractRowsForBreakpoint,
    finalizeRunReport as processingFinalizeRunReport,
    getMeasurementWidthForBreakpoint as processingGetMeasurementWidthForBreakpoint,
    isImageLikelyBroken as processingIsImageLikelyBroken,
    isImageRenderable as processingIsImageRenderable,
    isTransparentPixelSrcset as processingIsTransparentPixelSrcset,
    normalizeLazyAttribute as processingNormalizeLazyAttribute,
    prepareBreakpointImages as processingPrepareBreakpointImages,
    preloadBreakpointSources as processingPreloadBreakpointSources,
    sanitizeIssueSource as processingSanitizeIssueSource,
    toPositiveIntOrNull as processingToPositiveIntOrNull,
    waitForImagesToSettle as processingWaitForImagesToSettle,
} from './transforms-processing.js';

(() => {
    const BREAKPOINT_SAFETY_PX = 2;
    const DRAG_SCROLL_THRESHOLD_PX = 8;
    const PROCESSING_QUERY_PARAM = '__bpiProcessing';
    const ENTRY_ID_QUERY_PARAM = 'entry_id';
    const LEGACY_ENTRY_ID_QUERY_PARAMS = ['entryId', 'id'];
    const PREVIEW_WIDTH_SETTLE_TIMEOUT_MS = 800;
    const PREVIEW_WIDTH_SETTLE_TOLERANCE_PX = 2;
    const PREVIEW_FRAME_TAG = 'ifr' + 'ame';
    const IMAGE_WAIT_SOFT_DEADLINE_MS = 4000;
    const IMAGE_WAIT_POLL_MS = 250;
    const CARD_UPDATE_STATUS_CLEAR_DELAY_MS = 1800;
    const LEAVE_PAGE_WARNING_MESSAGE = 'Are you sure? The current results will be lost.';
    const REPORT_SCHEMA_VERSION = 1;
    const REPORT_ISSUE_LIMIT = 200;
    const PREPARE_NORMALIZATION_SAMPLE_LIMIT = 12;

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
        progressHost: document.getElementById('bpi-progress-host'),
        resultsMeta: document.getElementById('bpi-results-meta'),
        resultsOrderingNote: document.getElementById('bpi-results-ordering-note'),
        resultsOrderingNoteLabel: document.getElementById('bpi-results-ordering-note-label'),
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
        lastReport: null,
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
        progressBar: null,
        dragScroll: {
            active: false,
            moved: false,
            pointerId: null,
            grid: null,
            startX: 0,
            startY: 0,
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

    function getOrCreateProgressBar() {
        if (state.progressBar) {
            return state.progressBar;
        }

        if (!elements.progressHost) {
            return null;
        }

        if (typeof Craft === 'undefined' || typeof Craft.ProgressBar !== 'function' || typeof window.jQuery !== 'function') {
            return null;
        }

        state.progressBar = new Craft.ProgressBar(window.jQuery(elements.progressHost), false, {
            announceProgress: false,
        });

        return state.progressBar;
    }

    function startProcessingProgress(totalSteps) {
        const progressBar = getOrCreateProgressBar();
        if (!progressBar) {
            return;
        }

        if (elements.progressHost) {
            elements.progressHost.hidden = false;
            elements.progressHost.setAttribute('aria-hidden', 'false');
        }

        const normalizedTotalSteps = Math.max(1, Number(totalSteps) || 1);
        progressBar.resetProgressBar();
        progressBar.setItemCount(normalizedTotalSteps);
        progressBar.setProcessedItemCount(0);
        progressBar.updateProgressBar();
        progressBar.showProgressBar();
    }

    function updateProcessingProgress(processedSteps) {
        const progressBar = getOrCreateProgressBar();
        if (!progressBar) {
            return;
        }

        progressBar.setProcessedItemCount(Math.max(0, Number(processedSteps) || 0));
        progressBar.updateProgressBar();
    }

    function hideProcessingProgress() {
        if (!state.progressBar) {
            if (elements.progressHost) {
                elements.progressHost.hidden = true;
                elements.progressHost.setAttribute('aria-hidden', 'true');
            }
            return;
        }

        state.progressBar.hideProgressBar();

        if (elements.progressHost) {
            elements.progressHost.hidden = true;
            elements.progressHost.setAttribute('aria-hidden', 'true');
        }
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

    function updateResultsOrderingNote() {
        if (!elements.resultsMeta || !elements.resultsOrderingNote || !elements.resultsOrderingNoteLabel) {
            return;
        }

        const hasRun = state.lastResult !== null;
        if (!hasRun) {
            elements.resultsMeta.hidden = true;
            elements.resultsOrderingNote.hidden = true;
            elements.resultsOrderingNoteLabel.textContent = '';
            return;
        }

        const warningCount = Math.max(0, Number(state.lastResult?.summary?.warningCount) || 0);
        const showWarningOrder = warningCount > 0;

        elements.resultsMeta.hidden = !showWarningOrder;
        elements.resultsOrderingNote.hidden = !showWarningOrder;
        elements.resultsOrderingNoteLabel.textContent = showWarningOrder
            ? 'Warnings first'
            : '';
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

    function applyDatastarIgnoreAttribute(target) {
        if (!(target instanceof Element)) {
            return;
        }

        target.setAttribute('data-ignore', '');
    }

    function getIgnoreTargets(target) {
        if (!target) {
            return [];
        }

        if (target instanceof Element) {
            return [target];
        }

        if (typeof target.length === 'number') {
            return Array.from(target).filter((node) => node instanceof Element);
        }

        return [];
    }

    function applyDatastarIgnoreToModal(modal) {
        if (!modal || typeof modal !== 'object') {
            return;
        }

        const targets = [
            modal.$container,
            modal.$shade,
            modal.$modal,
            modal.$body,
            modal.$content,
            modal.$main,
            modal.$sidebar,
            modal.$elements,
            modal.$tbody,
        ];

        targets.forEach((target) => {
            getIgnoreTargets(target).forEach((node) => {
                applyDatastarIgnoreAttribute(node);
            });
        });
    }

    function patchCraftElementSelectorModalIgnore() {
        if (typeof Craft === 'undefined' || !Craft.BaseElementSelectorModal || !Craft.BaseElementSelectorModal.prototype) {
            return;
        }

        const selectorModalPrototype = Craft.BaseElementSelectorModal.prototype;
        if (selectorModalPrototype.__bpiDatastarIgnorePatched) {
            return;
        }

        const applySelectorIgnoreTargets = (modalInstance) => {
            applyDatastarIgnoreToModal(modalInstance);

            document.querySelectorAll(
                '.elementselectormodal, .elementselectormodal .main, .elementselectormodal .elements, .elementselectormodal .elementindex, .modal-shade'
            ).forEach((node) => {
                applyDatastarIgnoreAttribute(node);
            });
        };

        ['init', 'onFadeIn', 'show'].forEach((methodName) => {
            const originalMethod = selectorModalPrototype[methodName];
            if (typeof originalMethod !== 'function') {
                return;
            }

            selectorModalPrototype[methodName] = function (...args) {
                const result = originalMethod.apply(this, args);
                applySelectorIgnoreTargets(this);
                return result;
            };
        });

        selectorModalPrototype.__bpiDatastarIgnorePatched = true;
    }

    function getElementNode(target) {
        if (target instanceof Element) {
            return target;
        }

        if (target && typeof target === 'object' && typeof target.length === 'number' && target[0] instanceof Element) {
            return target[0];
        }

        return null;
    }

    function moveFocusBeforeAriaHide(hiddenTarget) {
        const hiddenNode = getElementNode(hiddenTarget);
        const activeElement = document.activeElement;

        if (!(hiddenNode instanceof Element) || !(activeElement instanceof HTMLElement) || !hiddenNode.contains(activeElement)) {
            return;
        }

        const modalContainer = (typeof Garnish !== 'undefined' && Garnish?.uiLayerManager?.currentLayer?.$container)
            ? getElementNode(Garnish.uiLayerManager.currentLayer.$container)
            : null;

        if (modalContainer instanceof Element) {
            const nextFocus = modalContainer.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (nextFocus instanceof HTMLElement) {
                nextFocus.focus({ preventScroll: true });
            }
        }

        if (hiddenNode.contains(document.activeElement) && document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }
    }

    function patchGarnishAriaHideFocusGuard() {
        if (typeof Garnish === 'undefined' || typeof Garnish.ariaHide !== 'function' || Garnish.__bpiAriaHideFocusPatched) {
            return;
        }

        const originalAriaHide = Garnish.ariaHide;
        Garnish.ariaHide = function (target) {
            moveFocusBeforeAriaHide(target);
            return originalAriaHide.call(this, target);
        };

        Garnish.__bpiAriaHideFocusPatched = true;
    }

    function setupDatastarModalIgnoreGuard() {
        document.querySelectorAll('.modal, .modal-shade, .elementselectormodal, .elementselectormodal .elementindex').forEach((node) => {
            applyDatastarIgnoreAttribute(node);
        });

        patchGarnishAriaHideFocusGuard();
        patchCraftElementSelectorModalIgnore();

        if (typeof Garnish === 'undefined' || !Garnish.Modal || !Garnish.Modal.prototype) {
            return;
        }

        const modalPrototype = Garnish.Modal.prototype;
        if (modalPrototype.__bpiDatastarIgnorePatched || typeof modalPrototype.init !== 'function') {
            return;
        }

        const originalInit = modalPrototype.init;
        modalPrototype.init = function (...args) {
            const result = originalInit.apply(this, args);
            applyDatastarIgnoreToModal(this);
            return result;
        };

        modalPrototype.__bpiDatastarIgnorePatched = true;
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

    function syncSelectedEntryIdToUrl(entryIdRaw) {
        const entryId = parseEntryId(entryIdRaw);
        if (entryId === null || typeof window === 'undefined' || !window.location || !window.history || typeof window.history.replaceState !== 'function') {
            return;
        }

        try {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set(ENTRY_ID_QUERY_PARAM, String(entryId));
            LEGACY_ENTRY_ID_QUERY_PARAMS.forEach((paramName) => {
                currentUrl.searchParams.delete(paramName);
            });

            const nextUrl = currentUrl.toString();
            if (nextUrl !== window.location.href) {
                window.history.replaceState(window.history.state, '', nextUrl);
            }
        } catch (_error) {
            // Ignore URL sync failures so processing flow remains uninterrupted.
        }
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
        // Measure at the true frontend breakpoint size (inside the target range).
        // Oversized media ranges are only a guard against accidental source rollover.
        return processingGetMeasurementWidthForBreakpoint(breakpoint, BREAKPOINT_SAFETY_PX);
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
        return processingIsTransparentPixelSrcset(srcset);
    }

    function isAuthorDiagnosticsEnabled() {
        return bpiProcessingManifest?.processing?.authorDiagnosticsEnabled === true;
    }

    function sanitizeIssueSource(rawSource) {
        return processingSanitizeIssueSource(rawSource, window.location.origin);
    }

    function createRunReport(sourceUrl, breakpoints, diagnosticsEnabled) {
        return processingCreateRunReport({
            sourceUrl,
            breakpoints,
            diagnosticsEnabled,
            schemaVersion: REPORT_SCHEMA_VERSION,
            sanitizeSource: sanitizeIssueSource,
        });
    }

    function createBreakpointReportEntry(breakpoint) {
        return processingCreateBreakpointReportEntry(breakpoint);
    }

    function appendRunIssue(report, issue, breakpointReport = null) {
        processingAppendRunIssue({
            report,
            issue,
            breakpointReport,
            issueLimit: REPORT_ISSUE_LIMIT,
            sanitizeSource: sanitizeIssueSource,
        });
    }

    function publishRunReport(report) {
        state.lastReport = report;
        document.dispatchEvent(new CustomEvent('bpi:processing-report', {
            detail: report,
        }));
    }

    function finalizeRunReport(report, {
        status,
        rowsByBreakpoint,
        resultPublished,
        failureStage = null,
        failureMessage = null,
    }) {
        return processingFinalizeRunReport(report, {
            status,
            rowsByBreakpoint,
            resultPublished,
            failureStage,
            failureMessage,
        });
    }

    function getTrackedPictures(frameDocument) {
        return Array.from(frameDocument.querySelectorAll('picture[data-set]'));
    }

    function recordNormalizationSample(target, sample) {
        if (!target || !Array.isArray(target)) {
            return;
        }

        if (target.length >= PREPARE_NORMALIZATION_SAMPLE_LIMIT) {
            return;
        }

        target.push(sample);
    }

    function pushActivationStrategy(prepareResult, strategy, count = 0) {
        if (!prepareResult || !Array.isArray(prepareResult.activationStrategies)) {
            return;
        }

        if (count > 0) {
            prepareResult.activationStrategies.push(`${strategy}:${count}`);
            return;
        }

        prepareResult.activationStrategies.push(strategy);
    }

    function activateLazySizes(frameWindow, frameDocument, prepareResult) {
        processingActivateLazySizes(frameWindow, frameDocument, prepareResult, pushActivationStrategy);
    }

    function activateVanillaLazyLoad(frameWindow, frameDocument, prepareResult) {
        processingActivateVanillaLazyLoad(frameWindow, frameDocument, prepareResult, pushActivationStrategy);
    }

    function activateLozad(frameWindow, frameDocument, prepareResult) {
        processingActivateLozad(frameWindow, frameDocument, prepareResult, pushActivationStrategy);
    }

    function normalizeLazyAttribute(target, {
        dataAttr,
        targetAttr,
        forceWhenDataUri = false,
    }) {
        return processingNormalizeLazyAttribute(target, {
            dataAttr,
            targetAttr,
            forceWhenDataUri,
        });
    }

    function prepareBreakpointImages(breakpoint) {
        return processingPrepareBreakpointImages({
            breakpoint,
            frameDocument: getFrameDocument(),
            frameWindow: state.previewFrame?.contentWindow || window,
            getTrackedPictures,
            getPrimarySourceForBreakpoint,
            sampleLimit: PREPARE_NORMALIZATION_SAMPLE_LIMIT,
        });
    }

    function isImageRenderable(img) {
        return processingIsImageRenderable(img);
    }

    function isImageLikelyBroken(img) {
        return processingIsImageLikelyBroken(img, isImageRenderable);
    }

    function deriveSourceUsed(source, img) {
        return processingDeriveSourceUsed(source, img);
    }

    function createReadinessSummary(readinessByKey) {
        return processingCreateReadinessSummary(readinessByKey);
    }

    function buildBreakpointReadinessTracker(breakpoint, preloadStates = null) {
        return processingBuildBreakpointReadinessTracker({
            breakpoint,
            frameDocument: getFrameDocument(),
            preloadStates,
            getPictureLoadKey,
            getPrimarySourceForBreakpoint,
            deriveSource: deriveSourceUsed,
            isTransparentSrcset: isTransparentPixelSrcset,
            isRenderable: isImageRenderable,
        });
    }

    async function waitForImagesToSettle({
        readinessByKey,
        softDeadlineMs = IMAGE_WAIT_SOFT_DEADLINE_MS,
        pollMs = IMAGE_WAIT_POLL_MS,
        shouldStop = () => false,
        onSoftDeadline = null,
        onWaitingTick = null,
    } = {}) {
        return processingWaitForImagesToSettle({
            readinessByKey,
            softDeadlineMs,
            pollMs,
            shouldStop,
            onSoftDeadline,
            onWaitingTick,
            createSummary: createReadinessSummary,
            isRenderable: isImageRenderable,
            setTimeoutFn: (callback, ms) => window.setTimeout(callback, ms),
            requestAnimationFrameFn: (callback) => requestAnimationFrame(callback),
            nowMs: () => Date.now(),
        });
    }

    async function preloadBreakpointSources(breakpoint, timeoutMs = 5000) {
        return processingPreloadBreakpointSources({
            breakpoint,
            frameDocument: getFrameDocument(),
            timeoutMs,
            getPictureLoadKey,
            getPrimarySourceForBreakpoint,
            isTransparentSrcset: isTransparentPixelSrcset,
            ImageCtor: Image,
            setTimeoutFn: (callback, ms) => window.setTimeout(callback, ms),
            requestAnimationFrameFn: (callback) => requestAnimationFrame(callback),
        });
    }

    function extractRowsForBreakpoint(breakpoint, preloadStates = null, readinessByKey = null) {
        return processingExtractRowsForBreakpoint({
            breakpoint,
            frameDocument: getFrameDocument(),
            preloadStates,
            readinessByKey,
            getPrimarySourceForBreakpoint,
            getPictureLoadKey,
            deriveSource: deriveSourceUsed,
            isLikelyBroken: isImageLikelyBroken,
            toPositiveIntOrNullFn: toPositiveIntOrNull,
        });
    }

    function toPositiveIntOrNull(value) {
        return processingToPositiveIntOrNull(value);
    }

    function buildStructuredOutput(sourceUrl, breakpoints, rowsByBreakpoint, startedAt, runReport = null) {
        return processingBuildStructuredOutput({
            sourceUrl,
            breakpoints,
            rowsByBreakpoint,
            startedAt,
            runReport,
            manifestSchemaVersion: bpiProcessingManifest?.schemaVersion || null,
            runCount: state.runCount,
            nowMs: () => Date.now(),
            nowIso: () => new Date().toISOString(),
        });
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
        drag.startY = 0;
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
            state.dragScroll.startY = event.clientY;
            state.dragScroll.startScrollLeft = grid.scrollLeft;
        });

        window.addEventListener('pointermove', (event) => {
            const drag = state.dragScroll;
            if (!drag.active || drag.pointerId !== event.pointerId || !drag.grid) {
                return;
            }

            const deltaX = event.clientX - drag.startX;
            const deltaY = event.clientY - drag.startY;
            const absDeltaX = Math.abs(deltaX);
            const absDeltaY = Math.abs(deltaY);

            if (!drag.moved && absDeltaX < DRAG_SCROLL_THRESHOLD_PX && absDeltaY < DRAG_SCROLL_THRESHOLD_PX) {
                return;
            }

            if (!drag.moved && absDeltaY > absDeltaX) {
                endDragScroll(event.pointerId);
                return;
            }

            if (!drag.moved) {
                drag.moved = true;
                drag.grid.classList.add('bpi-drag-scrolling');
                state.dragScrollSuppressClick = true;

                if (drag.grid.setPointerCapture) {
                    try {
                        drag.grid.setPointerCapture(event.pointerId);
                    } catch (_error) {
                        // Ignore pointer capture errors.
                    }
                }
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
        const editScopeBySet = {};
        const editTabBySet = {};
        const preferredOrderBySet = [];

        if (!elements.visualResults) {
            return {
                editScopeBySet,
                editTabBySet,
                preferredOrderBySet,
            };
        }

        const cards = Array.from(elements.visualResults.querySelectorAll('.bpi-transform-card[data-set]'));
        cards.forEach((card) => {
            const transformName = String(card.getAttribute('data-set') || '').trim();
            if (!transformName) {
                return;
            }

            preferredOrderBySet.push(transformName);

            const rawScopeMode = String(card.getAttribute('data-scope-mode') || '').trim().toLowerCase();
            if (rawScopeMode === 'all') {
                editScopeBySet[transformName] = {
                    mode: 'all',
                    breakpoint: null,
                };
            } else if (rawScopeMode === 'breakpoint') {
                const scopeBreakpoint = toPositiveIntOrNull(card.getAttribute('data-scope-breakpoint'));
                if (scopeBreakpoint !== null) {
                    editScopeBySet[transformName] = {
                        mode: 'breakpoint',
                        breakpoint: scopeBreakpoint,
                    };
                }
            } else {
                editScopeBySet[transformName] = {
                    mode: 'unset',
                    breakpoint: null,
                };
            }

            const activeTab = String(card.getAttribute('data-active-tab') || '').trim().toLowerCase();
            editTabBySet[transformName] = activeTab === 'ratio'
                ? activeTab
                : 'dimensions';
        });

        return {
            editScopeBySet,
            editTabBySet,
            preferredOrderBySet,
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

        const cards = Array.from(elements.visualResults.querySelectorAll('.bpi-transform-card[data-set]'));
        return cards.find((card) => (card.getAttribute('data-set') || '') === transformName) || null;
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
        const isRenderedAction = classList?.contains('bpi-rendered-apply-single')
            || classList?.contains('bpi-rendered-apply-all')
            || classList?.contains('bpi-warning-apply-rendered');

        if (isRenderedAction) {
            return 'renderedValues';
        }

        if (isDimensionsApply) {
            return 'dimensions';
        }

        if (isRatioApply) {
            return 'ratio';
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
                    const transformName = card?.getAttribute('data-set') || '';
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

            const transformName = card.getAttribute('data-set') || '';
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
            updateResultsOrderingNote();
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
            editScopeBySet,
            editTabBySet,
            preferredOrderBySet,
        } = collectReviewEditStateFromDom();

        const response = await Craft.sendActionRequest('POST', RENDER_RESULT_REVIEW_ACTION, {
            data: {
                result,
                editScopeBySet,
                editTabBySet,
                preferredOrderBySet,
            },
        });

        const payload = response?.data || null;
        applyRenderedReviewPayload(payload);
        return payload;
    }

    async function publishResult(result) {
        state.lastResult = result;
        updateCopyButtonVisibility();
        updateResultsOrderingNote();
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
        return processingBuildWaitingStatusMessage(breakpoint, pendingCount, waitedMs);
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

    class ProcessingCancelledError extends Error {
        constructor(message) {
            super(message);
            this.name = 'ProcessingCancelledError';
        }
    }

    function appendBreakpointReadinessIssues(report, breakpointReport, breakpoint, readinessByKey) {
        processingAppendBreakpointReadinessIssues({
            report,
            breakpointReport,
            breakpoint,
            readinessByKey,
            appendIssue: appendRunIssue,
        });
    }

    async function runProcessing() {
        if (state.busy) {
            return;
        }

        const breakpoints = getConfiguredBreakpoints();
        const totalProgressSteps = breakpoints.length + 1;
        let completedProgressSteps = 0;

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
        startProcessingProgress(totalProgressSteps);
        setStatus('Getting ready...');
        const diagnosticsEnabled = isAuthorDiagnosticsEnabled();
        const startedAt = Date.now();
        let failureStage = 'initialization';
        const rowsByBreakpoint = {};
        const runReport = createRunReport(state.previewUrl || '', breakpoints, diagnosticsEnabled);

        try {
            failureStage = 'resolve-entry-url';
            const sourceUrl = await resolveSelectedEntryUrl();
            runReport.sourceUrl = sanitizeIssueSource(sourceUrl);

            syncSelectedEntryIdToUrl(getSelectedEntryId());
            getOrCreatePreviewFrame();

            failureStage = 'ensure-preview-frame';
            await ensurePreviewFrame(sourceUrl, true);
            completedProgressSteps += 1;
            updateProcessingProgress(completedProgressSteps);

            for (const breakpoint of breakpoints) {
                const breakpointReport = createBreakpointReportEntry(breakpoint);
                runReport.breakpoints.push(breakpointReport);

                state.waitSoftLimitReached = false;
                setStopButtonVisibility(false);
                const measurementWidth = getMeasurementWidthForBreakpoint(breakpoint);
                setStatus(`Processing ${breakpoint}px...`);

                failureStage = 'set-breakpoint-width';
                await setPreviewWidth(measurementWidth);

                failureStage = 'prepare-breakpoint-images';
                const prepareStartedAt = Date.now();
                const prepareResult = prepareBreakpointImages(breakpoint);
                breakpointReport.activationStrategies = prepareResult.activationStrategies.slice();
                breakpointReport.normalizationCount = prepareResult.normalizationCount;

                if (runReport.authorDiagnostics) {
                    runReport.authorDiagnostics.stageTimings.push({
                        stage: 'prepare-breakpoint-images',
                        breakpoint,
                        durationMs: Math.max(0, Date.now() - prepareStartedAt),
                    });

                    runReport.authorDiagnostics.activationTrace.push({
                        breakpoint,
                        strategies: prepareResult.activationStrategies.slice(),
                        normalizationCount: prepareResult.normalizationCount,
                    });

                    prepareResult.normalizationSamples.forEach((sample) => {
                        recordNormalizationSample(runReport.authorDiagnostics.normalizationSamples, {
                            breakpoint,
                            element: sample.element,
                            attr: sample.attr,
                        });
                    });
                }

                failureStage = 'preload-breakpoint-sources';
                const preloadStartedAt = Date.now();
                const preloadStates = await preloadBreakpointSources(breakpoint);
                if (runReport.authorDiagnostics) {
                    runReport.authorDiagnostics.stageTimings.push({
                        stage: 'preload-breakpoint-sources',
                        breakpoint,
                        durationMs: Math.max(0, Date.now() - preloadStartedAt),
                    });
                }

                failureStage = 'wait-for-image-readiness';
                const readinessTracker = buildBreakpointReadinessTracker(breakpoint, preloadStates);
                const waitStartedAt = Date.now();
                let waitResult = null;

                try {
                    waitResult = await waitForImagesToSettle({
                        readinessByKey: readinessTracker.readinessByKey,
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
                } finally {
                    readinessTracker.cleanup();

                    if (runReport.authorDiagnostics) {
                        runReport.authorDiagnostics.stageTimings.push({
                            stage: 'wait-for-image-readiness',
                            breakpoint,
                            durationMs: Math.max(0, Date.now() - waitStartedAt),
                        });
                    }
                }

                state.waitSoftLimitReached = false;
                setStopButtonVisibility(false);

                rowsByBreakpoint[breakpoint] = extractRowsForBreakpoint(
                    breakpoint,
                    preloadStates,
                    readinessTracker.readinessByKey,
                );

                breakpointReport.status = waitResult?.aborted ? 'cancelled' : 'processed';
                breakpointReport.waitDurationMs = Math.max(0, Number(waitResult?.waitedMs) || 0);

                const readinessSummary = createReadinessSummary(readinessTracker.readinessByKey);
                breakpointReport.loadedCount = readinessSummary.loadedCount;
                breakpointReport.brokenCount = readinessSummary.brokenCount;
                breakpointReport.unresolvedCount = readinessSummary.unresolvedCount;

                appendBreakpointReadinessIssues(
                    runReport,
                    breakpointReport,
                    breakpoint,
                    readinessTracker.readinessByKey,
                );

                completedProgressSteps += 1;
                updateProcessingProgress(completedProgressSteps);

                if (waitResult?.aborted) {
                    throw new ProcessingCancelledError('Processing stopped by user during image wait.');
                }
            }

            state.runCount += 1;
            const finalizedReport = finalizeRunReport(runReport, {
                status: 'completed',
                rowsByBreakpoint,
                resultPublished: true,
            });
            publishRunReport(finalizedReport);

            const result = buildStructuredOutput(
                state.previewUrl || runReport.sourceUrl || '',
                breakpoints,
                rowsByBreakpoint,
                startedAt,
                finalizedReport,
            );

            await publishResult(result);
            const setCount = Math.max(0, Number(result.summary.setCount) || 0);
            const warningCount = Math.max(0, Number(result.summary.warningCount) || 0);
            const setLabel = setCount === 1 ? 'set' : 'sets';
            const warningLabel = warningCount === 1 ? 'warning' : 'warnings';
            setStatus(`Done. ${setCount} ${setLabel} processed. ${warningCount} ${warningLabel} to address.`);
        } catch (error) {
            const cancelled = error instanceof ProcessingCancelledError;

            if (runReport.authorDiagnostics) {
                runReport.authorDiagnostics.failure = {
                    stage: failureStage,
                    message: String(error?.message || 'Processing failure.'),
                };
            }

            const finalizedReport = finalizeRunReport(runReport, {
                status: cancelled ? 'cancelled' : 'failed',
                rowsByBreakpoint,
                resultPublished: false,
                failureStage,
                failureMessage: String(error?.message || 'Processing failed.'),
            });
            publishRunReport(finalizedReport);

            if (cancelled) {
                setStatus('Processing cancelled. No partial results were published.');
            } else {
                setStatus(`Error: ${error.message}`);
            }
        } finally {
            state.busy = false;
            state.stopRequested = false;
            state.waitSoftLimitReached = false;
            setProcessingState(false);
            setStopButtonVisibility(false);
            setButtonsDisabled(false);
            hideProcessingProgress();
        }
    }

    function shouldWarnOnPageLeave() {
        return state.lastResult !== null;
    }

    function handleBeforeUnload(event) {
        if (!shouldWarnOnPageLeave()) {
            return;
        }

        event.preventDefault();
        event.returnValue = LEAVE_PAGE_WARNING_MESSAGE;
        return LEAVE_PAGE_WARNING_MESSAGE;
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

    if (window.__BPI_TEST_HOOKS === true) {
        window.__BPIProcessingTestHooks = {
            sanitizeIssueSource,
            createRunReport,
            createBreakpointReportEntry,
            appendRunIssue,
            finalizeRunReport,
            createReadinessSummary,
            normalizeLazyAttribute,
            isTransparentPixelSrcset,
            isImageLikelyBroken,
            deriveSourceUsed,
            buildStructuredOutput,
            getMeasurementWidthForBreakpoint,
            isAuthorDiagnosticsEnabled,
            activateLazySizes,
            activateVanillaLazyLoad,
            activateLozad,
            waitForImagesToSettle,
            preloadBreakpointSources,
            appendBreakpointReadinessIssues,
            buildWaitingStatusMessage,
            publishRunReport,
            getLastReport: () => state.lastReport,
            setPreviewFrameForTests: (frameDocument, frameWindow = window) => {
                state.previewFrame = {
                    contentDocument: frameDocument,
                    contentWindow: frameWindow,
                };
            },
            clearPreviewFrameForTests: () => {
                state.previewFrame = null;
            },
            prepareBreakpointImages,
            buildBreakpointReadinessTracker,
            extractRowsForBreakpoint,
        };
    }

    setPreviewVisibility(false);
    setProcessingState(false);
    setStopButtonVisibility(false);
    updateCopyButtonVisibility();
    updateResultsOrderingNote();
    state.selectedEntryId = getSelectedEntryId();
    setupDatastarModalIgnoreGuard();
    bindSourceSelectionSync();
    setButtonsDisabled(false);
    setupDragToScroll();
    setupDatastarCardUpdateStatus();
    window.addEventListener('beforeunload', handleBeforeUnload);
    window.addEventListener('resize', scheduleBreakpointPreviewHeightSync);
    getConfiguredBreakpoints();
    void loadInitialPreview();
})();
