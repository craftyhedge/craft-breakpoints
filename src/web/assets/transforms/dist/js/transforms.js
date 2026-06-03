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
    prepareBreakpoints as processingPrepareBreakpoints,
    preloadBreakpointSources as processingPreloadBreakpointSources,
    sanitizeIssueSource as processingSanitizeIssueSource,
    toPositiveIntOrNull as processingToPositiveIntOrNull,
    waitForImagesToSettle as processingWaitForImagesToSettle,
} from './transforms-processing.js';
import { bindHorizontalDragScroll } from './drag-scroll-util.js';

(() => {
    const BREAKPOINT_SAFETY_PX = 2;
    const PROCESSING_QUERY_PARAM = '__bpiProcessing';
    const ENTRY_ID_QUERY_PARAM = 'entry_id';
    const PREVIEW_WIDTH_SETTLE_TIMEOUT_MS = 800;
    const PREVIEW_WIDTH_SETTLE_TOLERANCE_PX = 2;
    const PREVIEW_FRAME_TAG = 'ifr' + 'ame';
    const IMAGE_WAIT_SOFT_DEADLINE_MS = 4000;
    const IMAGE_WAIT_POLL_MS = 250;
    const CARD_UPDATE_STATUS_CLEAR_DELAY_MS = 1000;
    const CARD_UPDATE_STATUS_ERROR_CLEAR_DELAY_MS = 3600;
    const CARD_UPDATE_STATUS_PENDING_MIN_DURATION_MS = 600;
    const REPORT_SCHEMA_VERSION = 1;
    const REPORT_ISSUE_LIMIT = 200;
    const PREPARE_NORMALIZATION_SAMPLE_LIMIT = 12;

    const bpiProcessingConfig = window.bpiProcessingConfig || {};
    const ENTRY_URL_ACTION = 'breakpoints/default/entry-url';
    const RENDER_RESULT_REVIEW_ACTION = 'breakpoints/transforms/render-result-review';
    const RENDER_INITIAL_REVIEW_ACTION = 'breakpoints/transforms/render-initial-review';
    const PERSIST_RUN_SNAPSHOT_ACTION = 'breakpoints/transforms/persist-run-snapshot';
    const DATASTAR_FETCH_EVENT = 'datastar-fetch';

    const elements = {
        page: document.querySelector('.bpts-transforms-page'),
        showCardSettingsSignalBridge: document.getElementById('bpts-show-card-settings-signal-bridge'),
        uiResultsHeadingSignalBridge: document.getElementById('bpts-ui-results-heading-signal-bridge'),
        resultsHeading: document.getElementById('bpts-results-heading'),
        uiShowWarningOrderSignalBridge: document.getElementById('bpts-ui-show-warning-order-signal-bridge'),
        uiResultsOrderingNoteLabelSignalBridge: document.getElementById('bpts-ui-results-ordering-note-label-signal-bridge'),
        sidebarSavedSetNamesSignalBridge: document.getElementById('bpts-sidebar-saved-set-names-signal-bridge'),
        transformSetsSidebar: document.getElementById('bpts-transform-sets-sidebar'),
        transformSetsList: document.getElementById('bpts-transform-sets-list'),
        sourceEntry: document.getElementById('bpts-source-entry'),
        status: document.getElementById('bpts-status'),
        progressHost: document.getElementById('bpts-progress-host'),
        resultsSettingsLightswitch: document.getElementById('bpts-results-settings-lightswitch'),
        framePane: document.getElementById('bpts-frame-pane'),
        wrapper: document.getElementById('bpts-frame-wrapper'),
        warnings: document.getElementById('bpts-warnings'),
        visualResults: document.getElementById('bpts-visual-results'),
        btnOpenPreview: document.getElementById('bpts-open-preview'),
        btnRun: document.getElementById('bpts-run-processing'),
        btnStop: document.getElementById('bpts-stop-processing'),
        btnClosePreview: document.getElementById('bpts-close-preview'),
        btnCopy: document.getElementById('bpts-copy-output')
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
        updateStatusResetTimersByTransform: {},
        updateStatusTransitionTimersByTransform: {},
        updateStatusStartedAtByTransform: {},
        updateStatusRunIdByTransform: {},
        updateStatusByTransform: {},
        pendingTransformUpdates: new Set(),
        pendingTransformActionsByName: {},
        progressBar: null,
        testRunProcessingOverrides: null,
    };
    const pendingTransformBySourceElement = new WeakMap();

    const RESULTS_COPY = {
        saved: {
            heading: 'Saved',
        },
        processed: {
            heading: 'Processed',
        },
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

    function setReviewHydrated(isHydrated) {
        if (!elements.page) {
            return;
        }

        elements.page.classList.toggle('bpts-review-hydrating', !Boolean(isHydrated));
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

    function parsePositiveInt(value) {
        const parsed = Number.parseInt(String(value ?? ''), 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    }

    function openEntrySlideout(link) {
        const elementId = parsePositiveInt(link?.dataset?.entryId);
        const fallbackSiteId = parsePositiveInt(elements.page?.dataset?.siteId)
            || parsePositiveInt(typeof Craft !== 'undefined' ? Craft.siteId : null);
        const siteId = parsePositiveInt(link?.dataset?.siteId) || fallbackSiteId;

        if (!elementId || !siteId || typeof Craft?.createElementEditor !== 'function') {
            return false;
        }

        Craft.createElementEditor('craft\\elements\\Entry', {
            elementId,
            siteId,
            params: {
                fresh: 1,
            },
        });

        return true;
    }

    function bindEntrySlideoutLinks() {
        if (document.documentElement.dataset.bpiEntrySlideoutBound === '1') {
            return;
        }

        document.documentElement.dataset.bpiEntrySlideoutBound = '1';

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('.bpts-entry-link[data-bpts-open-entry="true"]')
                : null;

            if (!(target instanceof Element)) {
                return;
            }

            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const opened = openEntrySlideout(target);
            if (opened) {
                event.preventDefault();
            }
        });
    }


    function getSourceEntrySelectInput() {
        if (!elements.sourceEntry || typeof window.jQuery !== 'function') {
            return null;
        }

        const instance = window.jQuery(elements.sourceEntry).data('elementSelect');
        if (!instance || typeof instance !== 'object') {
            return null;
        }

        return instance;
    }

    async function setSourceEntryFromRun(entryIdRaw) {
        const entryId = parsePositiveInt(entryIdRaw);
        if (!entryId || !elements.sourceEntry) {
            return false;
        }

        const selectedEntryId = getSelectedEntryId();
        if (selectedEntryId === entryId) {
            syncSelectedEntryIdToUrl(entryId);
            return true;
        }

        const sourceSelectInput = getSourceEntrySelectInput();
        const siteId = parsePositiveInt(elements.page?.dataset?.siteId)
            || parsePositiveInt(typeof Craft !== 'undefined' ? Craft.siteId : null);

        if (sourceSelectInput
            && typeof sourceSelectInput.selectElements === 'function'
            && typeof Craft?.sendActionRequest === 'function'
            && typeof Craft?.getElementInfo === 'function') {
            try {
                if (sourceSelectInput.$elements && sourceSelectInput.$elements.length && typeof sourceSelectInput.removeElements === 'function') {
                    sourceSelectInput.removeElements(sourceSelectInput.$elements);
                }

                const response = await Craft.sendActionRequest('POST', 'app/render-elements', {
                    data: {
                        elements: [
                            {
                                type: 'craft\\elements\\Entry',
                                id: [entryId],
                                siteId: siteId || undefined,
                                instances: [
                                    {
                                        context: 'field',
                                        ui: 'chip',
                                        size: 'small',
                                        showActionMenu: true,
                                    },
                                ],
                            },
                        ],
                    },
                });

                const renderedCollection = response?.data?.elements || {};
                const renderedElement = renderedCollection?.[entryId]?.[0] || renderedCollection?.[String(entryId)]?.[0];
                if (renderedElement) {
                    const elementInfo = Craft.getElementInfo(renderedElement);
                    await sourceSelectInput.selectElements([elementInfo]);
                }
            } catch (_error) {
                // Fall back to hidden input update below.
            }
        }

        const hiddenInputs = Array.from(elements.sourceEntry.querySelectorAll('input[type="hidden"][name="bpts-source-entry-id"]'));
        if (hiddenInputs.length) {
            hiddenInputs[hiddenInputs.length - 1].value = String(entryId);
            hiddenInputs[hiddenInputs.length - 1].dispatchEvent(new Event('change', { bubbles: true }));
        }

        syncSelectedEntryIdToUrl(entryId);
        state.selectedEntryId = getSelectedEntryId();
        setButtonsDisabled(false);

        return getSelectedEntryId() === entryId;
    }

    function bindProcessAgainButtons() {
        if (document.documentElement.dataset.bpiProcessAgainBound === '1') {
            return;
        }

        document.documentElement.dataset.bpiProcessAgainBound = '1';

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('.bpts-process-again-button[data-bpts-process-again="true"]')
                : null;

            if (!(target instanceof Element)) {
                return;
            }

            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();

            void (async () => {
                const entryId = parsePositiveInt(target.dataset.entryId);
                if (!entryId) {
                    setStatus('No entry is associated with this run.');
                    return;
                }

                const selected = await setSourceEntryFromRun(entryId);
                if (!selected) {
                    setStatus('Could not select the run entry.');
                    return;
                }

                if (elements.btnRun) {
                    elements.btnRun.click();
                }
            })();
        });
    }

    function bindProcessObservedEntryButtons() {
        if (document.documentElement.dataset.bpiProcessObservedBound === '1') {
            return;
        }

        document.documentElement.dataset.bpiProcessObservedBound = '1';

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('button.bpts-warning-process-observed[data-bpts-action="processObservedEntry"]')
                : null;

            if (!(target instanceof HTMLButtonElement) || target.disabled) {
                return;
            }

            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();

            void (async () => {
                const entryId = parsePositiveInt(target.dataset.entryId);
                if (!entryId) {
                    setStatus('No observed entry is available to process.');
                    return;
                }

                const selected = await setSourceEntryFromRun(entryId);
                if (!selected) {
                    setStatus('Could not select the observed entry.');
                    return;
                }

                if (elements.btnRun) {
                    elements.btnRun.click();
                }
            })();
        });
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

    function updateResultsHeadingCopy() {
        const hasRun = state.lastResult !== null;
        const copy = hasRun ? RESULTS_COPY.processed : RESULTS_COPY.saved;

        if (elements.resultsHeading) {
            elements.resultsHeading.textContent = String(copy.heading || '');
        }

        const bridge = elements.uiResultsHeadingSignalBridge;
        if (!(bridge instanceof HTMLInputElement)) {
            return;
        }

        bridge.value = String(copy.heading || '');
        bridge.dispatchEvent(new Event('input', { bubbles: true }));
        bridge.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function updateResultsOrderingNote() {
        updateResultsHeadingCopy();

        const showWarningBridge = elements.uiShowWarningOrderSignalBridge;
        const labelBridge = elements.uiResultsOrderingNoteLabelSignalBridge;
        if (!(showWarningBridge instanceof HTMLInputElement)
            || showWarningBridge.type !== 'checkbox'
            || !(labelBridge instanceof HTMLInputElement)) {
            return;
        }

        const hasRun = state.lastResult !== null;
        if (!hasRun) {
            showWarningBridge.checked = false;
            showWarningBridge.dispatchEvent(new Event('input', { bubbles: true }));
            showWarningBridge.dispatchEvent(new Event('change', { bubbles: true }));

            labelBridge.value = '';
            labelBridge.dispatchEvent(new Event('input', { bubbles: true }));
            labelBridge.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }

        const warningCount = Math.max(0, Number(state.lastResult?.summary?.warningCount) || 0);
        const showWarningOrder = warningCount > 0;

        showWarningBridge.checked = showWarningOrder;
        showWarningBridge.dispatchEvent(new Event('input', { bubbles: true }));
        showWarningBridge.dispatchEvent(new Event('change', { bubbles: true }));

        labelBridge.value = showWarningOrder ? 'Warnings first' : '';
        labelBridge.dispatchEvent(new Event('input', { bubbles: true }));
        labelBridge.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function getSidebarSavedSetNamesSignalValue() {
        const bridge = elements.sidebarSavedSetNamesSignalBridge;
        if (!(bridge instanceof HTMLInputElement)) {
            return [];
        }

        const raw = String(bridge.value || '').trim();
        if (raw === '') {
            return [];
        }

        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed)
                ? parsed.filter((name) => typeof name === 'string' && name.trim() !== '')
                : [];
        } catch (_error) {
            return [];
        }
    }

    function syncPostPatchReviewState() {
        const savedSetNames = getSidebarSavedSetNamesSignalValue();
        if (savedSetNames.length > 0) {
            syncSidebarObservedUnsavedFromSavedNames(savedSetNames);
        }

        syncSidebarTransformOrderToCards();
        scheduleBreakpointPreviewHeightSync();
        window.setTimeout(scheduleBreakpointPreviewHeightSync, 120);
        updateResultsOrderingNote();
    }

    window.bptsSyncPostPatchReviewState = syncPostPatchReviewState;
    window.bptsGetLastResultForReview = () => (
        state.lastResult && typeof state.lastResult === 'object' ? state.lastResult : {}
    );

    function getVisualResultCardOrder() {
        if (!elements.visualResults) {
            return [];
        }

        const cards = Array.from(elements.visualResults.querySelectorAll('.bpts-transform-card[data-set]'));
        return cards
            .map((card) => String(card.getAttribute('data-set') || '').trim())
            .filter((setName) => setName !== '');
    }

    function restoreVisualResultCardOrder(preferredOrderBySet = []) {
        if (!elements.visualResults || !Array.isArray(preferredOrderBySet) || preferredOrderBySet.length < 1) {
            return;
        }

        const cards = Array.from(elements.visualResults.querySelectorAll('.bpts-transform-card[data-set]'));
        if (cards.length < 2) {
            return;
        }

        const cardBySetName = new Map();
        cards.forEach((card) => {
            const setName = String(card.getAttribute('data-set') || '').trim();
            if (setName !== '' && !cardBySetName.has(setName)) {
                cardBySetName.set(setName, card);
            }
        });

        const orderedCards = [];
        preferredOrderBySet.forEach((rawSetName) => {
            const setName = typeof rawSetName === 'string' ? rawSetName.trim() : '';
            if (setName === '') {
                return;
            }

            const card = cardBySetName.get(setName) || null;
            if (!card) {
                return;
            }

            orderedCards.push(card);
            cardBySetName.delete(setName);
        });

        if (orderedCards.length < 1) {
            return;
        }

        Array.from(cardBySetName.values()).forEach((card) => {
            orderedCards.push(card);
        });

        orderedCards.forEach((card) => {
            elements.visualResults.appendChild(card);
        });

        syncSidebarTransformOrderToCards();
        scheduleBreakpointPreviewHeightSync();
    }

    function syncSidebarTransformAvailability(cardOrder = []) {
        const list = elements.transformSetsList;
        if (!(list instanceof HTMLElement)) {
            return;
        }

        const availableSetNames = new Set(
            Array.isArray(cardOrder)
                ? cardOrder.filter((setName) => typeof setName === 'string' && setName !== '')
                : []
        );

        const setItems = Array.from(list.querySelectorAll('li[data-set]'));
        setItems.forEach((item) => {
            const setName = String(item.getAttribute('data-set') || '').trim();
            const isAvailable = setName !== '' && availableSetNames.has(setName);
            const link = item.querySelector('a.bpts-transform-sidebar-link[data-set]');

            item.classList.toggle('bpts-transform-sidebar-item-disabled', !isAvailable);

            if (!(link instanceof HTMLAnchorElement)) {
                return;
            }

            link.classList.toggle('bpts-transform-sidebar-link-disabled', !isAvailable);
            link.setAttribute('aria-disabled', isAvailable ? 'false' : 'true');
            link.tabIndex = isAvailable ? 0 : -1;
        });
    }

    function syncSidebarObservedUnsavedFromSavedNames(savedSetNames) {
        const list = elements.transformSetsList;
        if (!(list instanceof HTMLElement) || !Array.isArray(savedSetNames)) {
            return;
        }

        const savedSet = new Set(
            savedSetNames
                .filter((name) => typeof name === 'string' && name !== '')
                .map((name) => String(name))
        );

        const items = Array.from(list.querySelectorAll('li[data-observed-unsaved="1"][data-set]'));
        items.forEach((item) => {
            const setName = String(item.getAttribute('data-set') || '').trim();
            if (setName === '' || !savedSet.has(setName)) {
                return;
            }

            item.classList.remove('bpts-transform-sidebar-item-warning');
            item.removeAttribute('data-observed-unsaved');

            const link = item.querySelector('a.bpts-transform-sidebar-link');
            if (link instanceof HTMLElement) {
                link.classList.remove('bpts-transform-sidebar-link-warning');
                const icon = link.querySelector('.bpts-transform-sidebar-warning-icon');
                if (icon) {
                    icon.remove();
                }
                const srOnly = link.querySelector('.visually-hidden');
                if (srOnly) {
                    srOnly.remove();
                }
            }
        });
    }

    function syncSidebarTransformOrderToCards() {
        const list = elements.transformSetsList;
        if (!(list instanceof HTMLElement)) {
            return;
        }

        const cardOrder = getVisualResultCardOrder();
        if (cardOrder.length < 1) {
            syncSidebarTransformAvailability([]);
            return;
        }

        const allItem = list.querySelector('li[data-role="all"]');
        const setItems = Array.from(list.querySelectorAll('li[data-set]'));
        const setItemByName = new Map();

        setItems.forEach((item) => {
            const setName = String(item.getAttribute('data-set') || '').trim();
            if (setName !== '' && !setItemByName.has(setName)) {
                setItemByName.set(setName, item);
            }
        });

        const orderedItems = [];
        cardOrder.forEach((setName) => {
            const item = setItemByName.get(setName) || null;
            if (item) {
                orderedItems.push(item);
                setItemByName.delete(setName);
            }
        });

        const remainingItems = Array.from(setItemByName.values());
        const destinationItems = orderedItems.concat(remainingItems);

        destinationItems.forEach((item) => {
            list.appendChild(item);
        });

        if (allItem) {
            list.insertBefore(allItem, list.firstChild);
        }

        syncSidebarTransformAvailability(cardOrder);
    }

    function bindTransformSidebarCardNavigation() {
        const list = elements.transformSetsList;
        if (!(list instanceof HTMLElement)) {
            return;
        }

        list.addEventListener('click', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('a.bpts-transform-sidebar-link[data-set]')
                : null;

            if (!(target instanceof HTMLAnchorElement)) {
                return;
            }

            if (target.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
                return;
            }

            const setName = String(target.getAttribute('data-set') || '').trim();
            if (setName === '') {
                return;
            }

            const card = findTransformCard(setName);
            if (!(card instanceof HTMLElement)) {
                return;
            }

            event.preventDefault();
            card.scrollIntoView({ behavior: 'smooth', block: 'start', inline: 'nearest' });
        });
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

    function getConfiguredSlots() {
        const rawSlots = Array.isArray(bpiProcessingConfig?.breakpointSlots)
            ? bpiProcessingConfig.breakpointSlots
            : [];

        return rawSlots
            .map((slot, fallbackIndex) => {
                const key = String(slot?.key || '').trim();
                const index = Number.isFinite(Number(slot?.index)) ? parseInt(String(slot.index), 10) : fallbackIndex;
                const mediaWidth = parseInt(String(slot?.mediaWidth ?? ''), 10);
                const measureWidth = parseInt(String(slot?.measureWidth ?? mediaWidth), 10);
                if (!key || !Number.isFinite(index) || !Number.isFinite(mediaWidth) || mediaWidth <= 0) {
                    return null;
                }

                return {
                    key,
                    index,
                    mediaWidth,
                    measureWidth: Number.isFinite(measureWidth) && measureWidth > 0 ? measureWidth : mediaWidth,
                    isBase: slot?.isBase === true,
                    isFinal: slot?.isFinal === true,
                };
            })
            .filter((slot) => slot !== null);
    }

    function getFirstBreakpointMeasurementWidth() {
        const slots = getConfiguredSlots();
        if (!slots.length) {
            return null;
        }

        return getMeasurementWidthForBreakpoint(slots[0].mediaWidth);
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

    function setProcessingFrameMounted(isMounted) {
        if (!(elements.wrapper instanceof HTMLElement)) {
            return;
        }

        elements.wrapper.style.display = isMounted ? '' : 'none';
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

        const hiddenInputs = Array.from(elements.sourceEntry.querySelectorAll('input[type="hidden"][name="bpts-source-entry-id"]'));
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
            previewFrame.id = 'bpts-processing-preview';
            previewFrame.className = 'bpts-frame';
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
        const styleId = 'bpts-processing-scrollbar-style';
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

    function getPrimarySourceForSlot(picture, slot) {
        const key = String(slot?.key || '').replace(/"/g, '\\"');
        const index = String(slot?.index ?? '');
        return picture?.querySelector(`source[data-bp-source="primary"][data-bp-key="${key}"]`)
            || picture?.querySelector(`source[data-bp-key="${key}"]`)
            || picture?.querySelector(`source[data-bp-source="primary"][data-bp-index="${index}"]`)
            || picture?.querySelector(`source[data-bp-index="${index}"]`)
            || null;
    }

    function isTransparentPixelSrcset(srcset) {
        return processingIsTransparentPixelSrcset(srcset);
    }

    function isAuthorDiagnosticsEnabled() {
        return bpiProcessingConfig?.processing?.authorDiagnosticsEnabled === true;
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

    function prepareSlot(slot) {
        return processingPrepareBreakpoints({
            breakpoint: slot.mediaWidth,
            slot,
            frameDocument: getFrameDocument(),
            frameWindow: state.previewFrame?.contentWindow || window,
            getTrackedPictures,
            getPrimarySourceForBreakpoint: (_picture, _breakpoint) => getPrimarySourceForSlot(_picture, slot),
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

    function buildSlotReadinessTracker(slot, preloadStates = null) {
        return processingBuildBreakpointReadinessTracker({
            breakpoint: slot.mediaWidth,
            slot,
            frameDocument: getFrameDocument(),
            preloadStates,
            getPictureLoadKey,
            getPrimarySourceForBreakpoint: (_picture, _breakpoint) => getPrimarySourceForSlot(_picture, slot),
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

    async function preloadSlotSources(slot, timeoutMs = 5000) {
        return processingPreloadBreakpointSources({
            breakpoint: slot.mediaWidth,
            slot,
            frameDocument: getFrameDocument(),
            timeoutMs,
            getPictureLoadKey,
            getPrimarySourceForBreakpoint: (_picture, _breakpoint) => getPrimarySourceForSlot(_picture, slot),
            isTransparentSrcset: isTransparentPixelSrcset,
            ImageCtor: Image,
            setTimeoutFn: (callback, ms) => window.setTimeout(callback, ms),
            requestAnimationFrameFn: (callback) => requestAnimationFrame(callback),
        });
    }

    function extractRowsForSlot(slot, preloadStates = null, readinessByKey = null) {
        return processingExtractRowsForBreakpoint({
            breakpoint: slot.mediaWidth,
            slot,
            frameDocument: getFrameDocument(),
            preloadStates,
            readinessByKey,
            getPrimarySourceForBreakpoint: (_picture, _breakpoint) => getPrimarySourceForSlot(_picture, slot),
            getPictureLoadKey,
            deriveSource: deriveSourceUsed,
            isLikelyBroken: isImageLikelyBroken,
            toPositiveIntOrNullFn: toPositiveIntOrNull,
        });
    }

    function toPositiveIntOrNull(value) {
        return processingToPositiveIntOrNull(value);
    }

    function buildStructuredOutput(sourceUrl, slots, rowsBySlot, startedAt, runReport = null) {
        return processingBuildStructuredOutput({
            sourceUrl,
            breakpoints: slots.map((slot) => slot.mediaWidth),
            slots,
            rowsByBreakpoint: rowsBySlot,
            rowsBySlot,
            startedAt,
            runReport,
            configSchemaVersion: bpiProcessingConfig?.schemaVersion || null,
            runCount: state.runCount,
            nowMs: () => Date.now(),
            nowIso: () => new Date().toISOString(),
        });
    }

    function syncBreakpointPreviewHeights() {
        // Preview min-heights are now provided by server-rendered CSS vars per breakpoint column.
        updateDragScrollability();
    }

    function updateDragScrollability() {
        if (!elements.visualResults) {
            return;
        }

        const grids = Array.from(elements.visualResults.querySelectorAll('.bpts-breakpoint-grid'));
        grids.forEach((grid) => {
            updateGridScrollAffordance(grid);
        });
    }

    function updateGridScrollAffordance(grid) {
        const maxScrollLeft = Math.max(0, grid.scrollWidth - grid.clientWidth);
        const isScrollable = maxScrollLeft > 1;

        grid.classList.toggle('bpts-drag-scrollable', isScrollable);
    }

    function setupDragToScroll() {
        bindHorizontalDragScroll({
            bindingKey: '__BPI_TRANSFORMS_GRID_DRAG_BOUND',
            findGridFromTarget: (target) => (target instanceof Element ? target.closest('.bpts-breakpoint-grid') : null),
            isManagedGrid: (grid) => Boolean(grid instanceof Element && elements.visualResults && elements.visualResults.contains(grid)),
            onPotentialDragStart: (grid) => {
                updateGridScrollAffordance(grid);
            },
            onDragMove: (grid) => {
                updateGridScrollAffordance(grid);
            },
            onScrollGrid: (grid) => {
                updateGridScrollAffordance(grid);
            },
            preventDragStartSelector: '.bpi_breakpoint-result-image',
        });
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
        const selectedAssetKeyBySet = {};
        const preferredOrderBySet = [];

        if (!elements.visualResults) {
            return {
                editScopeBySet,
                editTabBySet,
                selectedAssetKeyBySet,
                preferredOrderBySet,
            };
        }

        const cards = Array.from(elements.visualResults.querySelectorAll('.bpts-transform-card[data-set]'));
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
                const fallbackBreakpoint = toPositiveIntOrNull(card.getAttribute('data-scope-breakpoint'))
                    || toPositiveIntOrNull(card.querySelector('.bpts-breakpoint-column[data-breakpoint]')?.getAttribute('data-breakpoint'));
                editScopeBySet[transformName] = {
                    mode: fallbackBreakpoint ? 'breakpoint' : 'all',
                    breakpoint: fallbackBreakpoint,
                };
            }

            const activeTab = String(card.getAttribute('data-active-tab') || '').trim().toLowerCase();
            editTabBySet[transformName] = (activeTab === 'ratio' || activeTab === 'settings' || activeTab === 'notes')
                ? activeTab
                : 'dimensions';

            const selectedAssetKey = String(card.getAttribute('data-selected-asset-key') || '').trim();
            selectedAssetKeyBySet[transformName] = selectedAssetKey;
        });

        return {
            editScopeBySet,
            editTabBySet,
            selectedAssetKeyBySet,
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

    function clearUpdateStatusTransitionTimer(transformName) {
        const existingTimer = state.updateStatusTransitionTimersByTransform[transformName] || null;
        if (existingTimer !== null) {
            window.clearTimeout(existingTimer);
            delete state.updateStatusTransitionTimersByTransform[transformName];
        }
    }

    function getUpdateStatusRunId(transformName) {
        return Number(state.updateStatusRunIdByTransform[transformName] || 0);
    }

    function nextUpdateStatusRunId(transformName) {
        const nextId = getUpdateStatusRunId(transformName) + 1;
        state.updateStatusRunIdByTransform[transformName] = nextId;
        return nextId;
    }

    function findTransformCard(transformName) {
        if (!elements.visualResults || !transformName) {
            return null;
        }

        const cards = Array.from(elements.visualResults.querySelectorAll('.bpts-transform-card[data-set]'));
        return cards.find((card) => (card.getAttribute('data-set') || '') === transformName) || null;
    }

    function applyTransformUpdateStatusToDom(transformName, message, statusState) {
        const card = findTransformCard(transformName);
        if (!card) {
            return;
        }

        const statusElement = card.querySelector('[data-role="transform-update-status"]');
        if (!statusElement) {
            return;
        }

        const labelElement = statusElement.querySelector('[data-role="transform-update-status-label"]');
        if (labelElement) {
            const normalizedMessage = typeof message === 'string' ? message : '';
            if (statusState === 'pending') {
                labelElement.textContent = normalizedMessage || 'Saving...';
            } else if (statusState === 'error') {
                labelElement.textContent = normalizedMessage || 'Update failed';
            } else {
                labelElement.textContent = '';
            }
        }

        const ariaMessage = statusState === 'pending'
            ? (typeof message === 'string' && message.trim() !== '' ? message : 'Saving...')
            : (statusState === 'success'
                ? 'Saved'
                : (statusState === 'error' ? (typeof message === 'string' ? message : 'Update failed') : ''));
        statusElement.setAttribute('aria-label', ariaMessage);
        statusElement.setAttribute('data-state', statusState);
    }

    function setTransformUpdateStatus(transformName, message, statusState) {
        if (!transformName) {
            return;
        }

        const normalizedMessage = typeof message === 'string' ? message : '';
        const normalizedStatusState = typeof statusState === 'string' ? statusState : 'idle';

        if (normalizedStatusState === 'idle' || normalizedMessage.trim() === '') {
            delete state.updateStatusByTransform[transformName];
        } else {
            state.updateStatusByTransform[transformName] = {
                message: normalizedMessage,
                statusState: normalizedStatusState,
            };
        }

        if (normalizedStatusState === 'pending') {
            state.updateStatusStartedAtByTransform[transformName] = Date.now();
        }

        applyTransformUpdateStatusToDom(transformName, normalizedMessage, normalizedStatusState);
    }

    function reapplyTransformUpdateStatuses() {
        const entries = Object.entries(state.updateStatusByTransform || {});
        if (entries.length < 1) {
            return;
        }

        entries.forEach(([transformName, status]) => {
            const message = typeof status?.message === 'string' ? status.message : '';
            const statusState = typeof status?.statusState === 'string' ? status.statusState : 'idle';
            applyTransformUpdateStatusToDom(transformName, message, statusState);
        });
    }

    function scheduleTransformUpdateStatusClear(transformName, delayMs = CARD_UPDATE_STATUS_CLEAR_DELAY_MS, runId = null) {
        clearUpdateStatusResetTimer(transformName);

        const timer = window.setTimeout(() => {
            if (runId !== null && getUpdateStatusRunId(transformName) !== Number(runId)) {
                clearUpdateStatusResetTimer(transformName);
                return;
            }

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
        delete state.pendingTransformActionsByName[transformName];
    }

    function removeTransformFromLastResult(transformName) {
        if (!transformName || !state.lastResult || typeof state.lastResult !== 'object') {
            return;
        }

        const rowsByBreakpoint = state.lastResult.rowsByBreakpoint;
        if (!rowsByBreakpoint || typeof rowsByBreakpoint !== 'object') {
            return;
        }

        Object.keys(rowsByBreakpoint).forEach((breakpoint) => {
            const rows = rowsByBreakpoint[breakpoint];
            if (!Array.isArray(rows)) {
                return;
            }

            rowsByBreakpoint[breakpoint] = rows.filter((row) => {
                if (!row || typeof row !== 'object') {
                    return true;
                }

                return String(row.transform || '') !== transformName;
            });
        });
    }

    function scheduleTransformTerminalStatus(transformName, message, statusState, clearDelayMs, runId) {
        const startedAt = Number(state.updateStatusStartedAtByTransform[transformName] || 0);
        const elapsedMs = startedAt > 0 ? (Date.now() - startedAt) : 0;
        const remainingPendingMs = Math.max(0, CARD_UPDATE_STATUS_PENDING_MIN_DURATION_MS - elapsedMs);

        clearUpdateStatusTransitionTimer(transformName);

        const applyTerminalStatus = () => {
            if (runId !== null && getUpdateStatusRunId(transformName) !== Number(runId)) {
                clearUpdateStatusTransitionTimer(transformName);
                return;
            }

            delete state.updateStatusStartedAtByTransform[transformName];
            setTransformUpdateStatus(transformName, message, statusState);
            scheduleTransformUpdateStatusClear(transformName, clearDelayMs, runId);
            clearUpdateStatusTransitionTimer(transformName);
        };

        if (remainingPendingMs > 0) {
            const timer = window.setTimeout(applyTerminalStatus, remainingPendingMs);
            state.updateStatusTransitionTimersByTransform[transformName] = timer;
            return;
        }

        applyTerminalStatus();
    }

    function getServerStatusFromEditorStatusElement() {
        const statusElement = document.getElementById('bpts-editor-status');
        if (!(statusElement instanceof HTMLElement)) {
            return null;
        }

        const kind = String(statusElement.dataset.kind || '').trim();
        const message = String(statusElement.dataset.message || '').trim();
        if (kind === '') {
            return null;
        }

        return { kind, message };
    }

    function parseServerStatusFromPatchSignalsArgs(argsRaw) {
        const rawArgs = argsRaw && typeof argsRaw === 'object' ? argsRaw : null;
        const rawSignals = rawArgs?.signals;

        let signals = rawSignals;
        if (typeof rawSignals === 'string') {
            try {
                signals = JSON.parse(rawSignals);
            } catch (_error) {
                return null;
            }
        }

        if (!signals || typeof signals !== 'object') {
            return null;
        }

        const editor = signals.editor;
        if (!editor || typeof editor !== 'object') {
            return null;
        }

        const serverStatus = editor.serverStatus;
        if (!serverStatus || typeof serverStatus !== 'object') {
            return null;
        }

        const kind = String(serverStatus.kind || '').trim();
        if (kind === '') {
            return null;
        }

        const message = String(serverStatus.message || '').trim();
        return { kind, message };
    }

    function displayCpNotice(message) {
        const text = String(message || '').trim();
        if (text === '') {
            return;
        }

        if (typeof Craft === 'undefined' || !Craft.cp || typeof Craft.cp.displayNotice !== 'function') {
            return;
        }

        Craft.cp.displayNotice(text);
    }

    function displayCpError(message) {
        const text = String(message || '').trim();
        if (text === '') {
            return;
        }

        if (typeof Craft === 'undefined' || !Craft.cp || typeof Craft.cp.displayError !== 'function') {
            return;
        }

        Craft.cp.displayError(text);
    }

    async function refreshReviewCardsAfterSuccessfulUpdate() {
        if (state.lastResult) {
            await renderResultReview(state.lastResult, {
                preserveCardOrder: true,
            });
            return;
        }

        await renderInitialStoredReview({
            preserveCardOrder: true,
        });
    }

    function finalizePendingTransformUpdatesFromServerStatus(serverStatus) {
        const status = serverStatus && typeof serverStatus === 'object' ? serverStatus : null;
        const kind = String(status?.kind || '').trim().toLowerCase();
        if (kind !== 'success' && kind !== 'error' && kind !== 'conflict') {
            return;
        }

        const pendingTransforms = Array.from(state.pendingTransformUpdates);
        if (pendingTransforms.length < 1) {
            return;
        }

        const pendingTransformEntries = pendingTransforms.map((transformName) => ({
            transformName,
            action: String(state.pendingTransformActionsByName[transformName] || ''),
        }));

        state.pendingTransformUpdates.clear();
        pendingTransformEntries.forEach(({ transformName }) => {
            delete state.pendingTransformActionsByName[transformName];
        });

        if (kind === 'success') {
            pendingTransformEntries.forEach(({ transformName, action }) => {
                if (action === 'deleteSet' && state.lastResult) {
                    removeTransformFromLastResult(transformName);
                }

                const runId = getUpdateStatusRunId(transformName);
                scheduleTransformTerminalStatus(transformName, 'Updated', 'success', CARD_UPDATE_STATUS_CLEAR_DELAY_MS, runId);
            });

            displayCpNotice(status?.message);

            return;
        }

        if (kind === 'error' || kind === 'conflict') {
            displayCpError(status?.message);
        }

        const terminalMessage = typeof status?.message === 'string' && status.message.trim() !== ''
            ? status.message
            : 'Update failed';

        pendingTransformEntries.forEach(({ transformName }) => {
            const runId = getUpdateStatusRunId(transformName);
            scheduleTransformTerminalStatus(transformName, terminalMessage, 'error', CARD_UPDATE_STATUS_ERROR_CLEAR_DELAY_MS, runId);
        });
    }

    function finalizeTransformUpdateFromServerStatus(transformName, serverStatus) {
        if (!transformName || !state.pendingTransformUpdates.has(transformName)) {
            return;
        }

        const status = serverStatus && typeof serverStatus === 'object' ? serverStatus : null;
        const kind = String(status?.kind || '').trim().toLowerCase();
        if (kind !== 'success' && kind !== 'error' && kind !== 'conflict') {
            return;
        }

        const action = String(state.pendingTransformActionsByName[transformName] || '');
        removePendingTransformUpdate(transformName);
        const runId = getUpdateStatusRunId(transformName);

        if (kind === 'success') {
            if (action === 'deleteSet' && state.lastResult) {
                removeTransformFromLastResult(transformName);
            }

            scheduleTransformTerminalStatus(transformName, 'Updated', 'success', CARD_UPDATE_STATUS_CLEAR_DELAY_MS, runId);

            displayCpNotice(status?.message);
            return;
        }

        if (kind === 'error' || kind === 'conflict') {
            displayCpError(status?.message);
        }

        const terminalMessage = typeof status?.message === 'string' && status.message.trim() !== ''
            ? status.message
            : 'Update failed';
        scheduleTransformTerminalStatus(transformName, terminalMessage, 'error', CARD_UPDATE_STATUS_ERROR_CLEAR_DELAY_MS, runId);
    }

    function getDatastarUpdateAction(sourceElement) {
        if (!(sourceElement instanceof Element)) {
            return null;
        }

        const actionHost = sourceElement.closest('[data-bpts-action]');
        if (!(actionHost instanceof Element)) {
            return null;
        }

        const action = String(actionHost.getAttribute('data-bpts-action') || '').trim();
        return action !== '' ? action : null;
    }

    function logDatastarUpdateFailure(detail, transformName, action) {
        if (!window.console || typeof window.console.error !== 'function') {
            return;
        }

        const response = detail && typeof detail === 'object' ? detail.response : null;
        const error = detail && typeof detail === 'object' ? detail.error : null;
        window.console.error('[Breakpoints] Transform update request failed', {
            type: detail && typeof detail === 'object' ? detail.type : undefined,
            transformName,
            action,
            status: response && typeof response === 'object' ? response.status : undefined,
            statusText: response && typeof response === 'object' ? response.statusText : undefined,
            url: response && typeof response === 'object' ? response.url : undefined,
            errorMessage: error && typeof error === 'object' ? error.message : undefined,
            detail,
        });
    }

    function setupDatastarCardUpdateStatus() {
        document.addEventListener(DATASTAR_FETCH_EVENT, (event) => {
            const detail = event?.detail || {};
            const sourceElement = detail.el;
            const eventType = String(detail.type || '').trim().toLowerCase();

            const getTrackedTransformName = () => {
                if (!sourceElement || typeof sourceElement.closest !== 'function') {
                    return '';
                }

                const tracked = String(pendingTransformBySourceElement.get(sourceElement) || '').trim();
                if (tracked !== '') {
                    return tracked;
                }

                const card = sourceElement.closest('.bpts-transform-card');
                if (!(card instanceof Element)) {
                    return '';
                }

                return String(card.getAttribute('data-set') || '').trim();
            };

            if (eventType === 'finished') {
                const serverStatus = getServerStatusFromEditorStatusElement();

                const transformName = getTrackedTransformName();
                if (transformName !== '') {
                    finalizeTransformUpdateFromServerStatus(transformName, serverStatus);
                    if (sourceElement && typeof sourceElement === 'object') {
                        pendingTransformBySourceElement.delete(sourceElement);
                    }
                    return;
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

            const card = sourceElement.closest('.bpts-transform-card');
            if (!card || !elements.visualResults || !elements.visualResults.contains(card)) {
                return;
            }

            const transformName = card.getAttribute('data-set') || '';
            if (!transformName) {
                return;
            }

            if (detail.type === 'started') {
                state.pendingTransformUpdates.add(transformName);
                state.pendingTransformActionsByName[transformName] = action;
                pendingTransformBySourceElement.set(sourceElement, transformName);
                clearUpdateStatusResetTimer(transformName);
                clearUpdateStatusTransitionTimer(transformName);
                state.updateStatusStartedAtByTransform[transformName] = Date.now();
                nextUpdateStatusRunId(transformName);
                setTransformUpdateStatus(transformName, 'Saving...', 'pending');
                return;
            }

            if (detail.type === 'error' || detail.type === 'retries-failed') {
                const trackedTransformName = getTrackedTransformName() || transformName;
                logDatastarUpdateFailure(detail, trackedTransformName, action);
                if (trackedTransformName !== '') {
                    removePendingTransformUpdate(trackedTransformName);
                    const runId = getUpdateStatusRunId(trackedTransformName);
                    scheduleTransformTerminalStatus(trackedTransformName, 'Update failed', 'error', CARD_UPDATE_STATUS_ERROR_CLEAR_DELAY_MS, runId);
                }
                pendingTransformBySourceElement.delete(sourceElement);
            }
        });
    }

    function setupDatastarReviewPatchSync() {
        document.addEventListener(DATASTAR_FETCH_EVENT, (event) => {
            const detail = event?.detail || {};
            const eventType = String(detail.type || '').trim().toLowerCase();
            if (eventType !== 'finished') {
                return;
            }

            const sourceElement = detail.el;
            if (!sourceElement || typeof sourceElement.closest !== 'function') {
                return;
            }

            if (!(sourceElement.closest('[data-bpts-review-patch]') instanceof Element)) {
                return;
            }

            window.setTimeout(syncPostPatchReviewState, 0);
            window.setTimeout(scheduleBreakpointPreviewHeightSync, 120);
        });
    }

    function findScrollAnchorCard() {
        if (!(elements.visualResults instanceof HTMLElement)) {
            return null;
        }
        const cards = elements.visualResults.querySelectorAll('.bpts-transform-card[data-set]');
        for (const card of cards) {
            if (card.getBoundingClientRect().top >= 0) {
                return card;
            }
        }
        return null;
    }

    function setShowCardSettingsSignal(isEnabled) {
        const bridge = elements.showCardSettingsSignalBridge;
        if (!(bridge instanceof HTMLInputElement) || bridge.type !== 'checkbox') {
            return;
        }

        bridge.checked = Boolean(isEnabled);
        bridge.dispatchEvent(new Event('input', { bubbles: true }));
        bridge.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function getShowCardSettingsSignalValue() {
        const bridge = elements.showCardSettingsSignalBridge;
        if (!(bridge instanceof HTMLInputElement) || bridge.type !== 'checkbox') {
            return false;
        }

        return bridge.checked === true;
    }

    function getResultsSettingsLightSwitchInstance() {
        if (!(elements.resultsSettingsLightswitch instanceof HTMLElement)) {
            return null;
        }

        if (typeof window.jQuery !== 'function') {
            return null;
        }

        const $lightswitch = window.jQuery(elements.resultsSettingsLightswitch);
        if (!$lightswitch.data('lightswitch') && typeof $lightswitch.lightswitch === 'function') {
            $lightswitch.lightswitch();
        }

        const instance = $lightswitch.data('lightswitch');
        return instance && typeof instance === 'object' ? instance : null;
    }

    function syncResultsSettingsLightswitchFromSignal() {
        const instance = getResultsSettingsLightSwitchInstance();
        if (!instance || typeof instance.on !== 'boolean') {
            return;
        }

        const shouldBeOn = getShowCardSettingsSignalValue();
        if (instance.on === shouldBeOn) {
            return;
        }

        if (shouldBeOn) {
            if (typeof instance.turnOn === 'function') {
                instance.turnOn(true);
            }
            return;
        }

        if (typeof instance.turnOff === 'function') {
            instance.turnOff(true);
        }
    }

    function setupResultsSettingsLightswitchSync() {
        if (!(elements.resultsSettingsLightswitch instanceof HTMLElement)) {
            return;
        }

        if (typeof window.jQuery !== 'function') {
            return;
        }

        const instance = getResultsSettingsLightSwitchInstance();
        if (!instance || typeof instance.on !== 'boolean') {
            return;
        }

        const $lightswitch = window.jQuery(elements.resultsSettingsLightswitch);

        const syncFromLightswitch = () => {
            const latest = getResultsSettingsLightSwitchInstance();
            if (!latest || typeof latest.on !== 'boolean') {
                return;
            }

            const anchor = findScrollAnchorCard();
            const anchorTop = anchor ? anchor.getBoundingClientRect().top : null;

            setShowCardSettingsSignal(latest.on === true);

            if (anchor && anchorTop !== null) {
                const observer = new ResizeObserver(() => {
                    observer.disconnect();
                    const newTop = anchor.getBoundingClientRect().top;
                    const drift = newTop - anchorTop;
                    if (Math.abs(drift) > 1) {
                        window.scrollBy(0, drift);
                    }
                });
                observer.observe(anchor);

                // Safety: disconnect if no resize fires (e.g. card already at target size)
                requestAnimationFrame(() => requestAnimationFrame(() => observer.disconnect()));
            }
        };

        $lightswitch.off('change.bpiShowCardSettings');
        $lightswitch.on('change.bpiShowCardSettings', syncFromLightswitch);

        if (elements.showCardSettingsSignalBridge instanceof HTMLInputElement
            && elements.showCardSettingsSignalBridge.dataset.bpiSignalBridgeBound !== '1') {
            elements.showCardSettingsSignalBridge.addEventListener('change', syncResultsSettingsLightswitchFromSignal);
            elements.showCardSettingsSignalBridge.addEventListener('input', syncResultsSettingsLightswitchFromSignal);
            elements.showCardSettingsSignalBridge.dataset.bpiSignalBridgeBound = '1';
        }

        syncResultsSettingsLightswitchFromSignal();
    }

    function applyRenderedReviewPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        setReviewHydrated(true);

        if (elements.warnings && typeof payload.warningsHtml === 'string') {
            elements.warnings.innerHTML = payload.warningsHtml;
        }

        if (elements.visualResults && typeof payload.visualResultsHtml === 'string') {
            elements.visualResults.innerHTML = payload.visualResultsHtml;

            syncSidebarObservedUnsavedFromSavedNames(payload.savedSetNames);
            syncSidebarTransformOrderToCards();
            reapplyTransformUpdateStatuses();
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

    async function renderResultReview(result, options = null) {
        if (!result || typeof result !== 'object') {
            return null;
        }

        const context = buildReviewRenderRequestContext(options);
        const payload = await requestReviewRenderPayload(RENDER_RESULT_REVIEW_ACTION, {
            result,
            editScopeBySet: context.editScopeBySet,
            editTabBySet: context.editTabBySet,
            selectedAssetKeyBySet: context.selectedAssetKeyBySet,
            preferredOrderBySet: context.preferredOrderBySet,
        });
        applyRenderedReviewPayload(payload);
        if (context.preserveCardOrder) {
            restoreVisualResultCardOrder(context.preferredOrderBySet);
        }
        return payload;
    }

    async function renderInitialStoredReview(options = null) {
        const context = buildReviewRenderRequestContext(options);
        const payload = await requestReviewRenderPayload(RENDER_INITIAL_REVIEW_ACTION, {
            result: state.lastResult && typeof state.lastResult === 'object'
                ? {
                    breakpoints: Array.isArray(state.lastResult.breakpoints) ? state.lastResult.breakpoints : [],
                    rowsByBreakpoint: state.lastResult.rowsByBreakpoint && typeof state.lastResult.rowsByBreakpoint === 'object'
                        ? state.lastResult.rowsByBreakpoint
                        : {},
                }
                : {},
            editScopeBySet: context.editScopeBySet,
            editTabBySet: context.editTabBySet,
            selectedAssetKeyBySet: context.selectedAssetKeyBySet,
            preferredOrderBySet: context.preferredOrderBySet,
        });
        applyRenderedReviewPayload(payload);
        if (context.preserveCardOrder) {
            restoreVisualResultCardOrder(context.preferredOrderBySet);
        }
        return payload;
    }

    function buildReviewRenderRequestContext(options = null) {
        const {
            editScopeBySet,
            editTabBySet,
            selectedAssetKeyBySet,
            preferredOrderBySet,
        } = collectReviewEditStateFromDom();
        const selectedAssetKeyBySetOverride = options
            && typeof options === 'object'
            && options.selectedAssetKeyBySetOverride
            && typeof options.selectedAssetKeyBySetOverride === 'object'
            ? options.selectedAssetKeyBySetOverride
            : null;

        return {
            editScopeBySet,
            editTabBySet,
            selectedAssetKeyBySet: selectedAssetKeyBySetOverride
                ? {
                    ...selectedAssetKeyBySet,
                    ...selectedAssetKeyBySetOverride,
                }
                : selectedAssetKeyBySet,
            preferredOrderBySet,
            preserveCardOrder: Boolean(options && typeof options === 'object' && options.preserveCardOrder === true),
        };
    }

    async function requestReviewRenderPayload(action, data) {
        // Keep review rerender transport browser-local while PHP remains
        // the renderer of card and warning markup.
        if (typeof Craft === 'undefined' || typeof Craft.sendActionRequest !== 'function') {
            return null;
        }

        const response = await Craft.sendActionRequest('POST', action, {
            data,
        });

        return response?.data || null;
    }

    async function publishResult(result) {
        state.lastResult = result;
        setShowCardSettingsSignal(true);
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

    function summarizeFailureReasonCountsFromReport(report) {
        const counts = {
            network: 0,
            decode: 0,
            'unsupported-source': 0,
            cancelled: 0,
        };

        const issues = Array.isArray(report?.issues) ? report.issues : [];
        issues.forEach((issue) => {
            const code = String(issue?.code || '').trim();
            if (code === 'decode-failure') {
                counts.decode += 1;
                return;
            }

            if (code === 'unsupported-source') {
                counts['unsupported-source'] += 1;
                return;
            }

            if (code === 'network-failure') {
                counts.network += 1;
                return;
            }

            if (code === 'unresolved-on-cancel') {
                counts.cancelled += 1;
            }
        });

        return counts;
    }

    async function persistRunSnapshot(report, rowsBySlot) {
        if (!report || typeof report !== 'object') {
            return false;
        }

        if (typeof Craft === 'undefined' || typeof Craft.sendActionRequest !== 'function') {
            return false;
        }

        const response = await Craft.sendActionRequest('POST', PERSIST_RUN_SNAPSHOT_ACTION, {
            data: {
                runId: String(report.runId || ''),
                timestamp: String(report.completedAt || new Date().toISOString()),
                runStatus: String(report.status || 'failed'),
                durationMs: Math.max(0, Number(report.durationMs) || 0),
                entryId: getSelectedEntryId(),
                sourceUrl: String(report.sourceUrl || ''),
                failureReasonCounts: summarizeFailureReasonCountsFromReport(report),
                rowsBySlot: rowsBySlot && typeof rowsBySlot === 'object' ? rowsBySlot : {},
            },
        });

        const persisted = response?.data?.ok === true;
        if (!persisted) {
            return false;
        }

        if (state.lastResult && typeof state.lastResult === 'object') {
            try {
                await renderResultReview(state.lastResult);
            } catch (error) {
                // Keep persisted snapshot status true even if UI refresh fails.
                console.error(error);
            }
        }

        return true;
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

        // Initial review and preview load start in parallel.
        if (!state.lastResult) {
            await renderInitialStoredReview({
                preserveCardOrder: true,
            });
        }

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

        const getRunOverride = (name) => {
            const overrides = state.testRunProcessingOverrides;
            if (!overrides || typeof overrides !== 'object') {
                return null;
            }

            const candidate = overrides[name];
            return typeof candidate === 'function' ? candidate : null;
        };

        const slots = getConfiguredSlots();
        const totalProgressSteps = slots.length + 1;
        let completedProgressSteps = 0;

        if (!slots.length) {
            setStatus('No configured breakpoints available. Check plugin settings.');
            return;
        }

        state.busy = true;
        state.stopRequested = false;
        state.waitSoftLimitReached = false;
        setProcessingFrameMounted(true);
        setProcessingState(true);
        setStopButtonVisibility(false);
        setButtonsDisabled(true);
        startProcessingProgress(totalProgressSteps);
        setStatus('Getting ready...');
        const diagnosticsEnabled = isAuthorDiagnosticsEnabled();
        const startedAt = Date.now();
        let failureStage = 'initialization';
        const rowsBySlot = {};
        const runReport = createRunReport(state.previewUrl || '', slots, diagnosticsEnabled);

        try {
            failureStage = 'resolve-entry-url';
            const sourceUrlResolver = getRunOverride('resolveSelectedEntryUrl') || resolveSelectedEntryUrl;
            const sourceUrl = await sourceUrlResolver();
            runReport.sourceUrl = sanitizeIssueSource(sourceUrl);

            syncSelectedEntryIdToUrl(getSelectedEntryId());
            getOrCreatePreviewFrame();

            failureStage = 'ensure-preview-frame';
            const ensureFrame = getRunOverride('ensurePreviewFrame') || ensurePreviewFrame;
            await ensureFrame(sourceUrl, true);
            completedProgressSteps += 1;
            updateProcessingProgress(completedProgressSteps);

            for (const slot of slots) {
                const breakpoint = slot.mediaWidth;
                const breakpointReport = createBreakpointReportEntry(breakpoint);
                breakpointReport.slotKey = slot.key;
                breakpointReport.slotIndex = slot.index;
                breakpointReport.mediaWidth = slot.mediaWidth;
                breakpointReport.measureWidth = slot.measureWidth;
                runReport.breakpoints.push(breakpointReport);

                state.waitSoftLimitReached = false;
                setStopButtonVisibility(false);
                const measurementWidth = (slot.isFinal && slot.measureWidth !== slot.mediaWidth)
                    ? getMeasurementWidthForBreakpoint(slot.measureWidth)
                    : getMeasurementWidthForBreakpoint(slot.mediaWidth);
                setStatus(`Processing ${slot.key} (${slot.mediaWidth}px)...`);

                failureStage = 'set-breakpoint-width';
                const previewWidthSetter = getRunOverride('setPreviewWidth') || setPreviewWidth;
                await previewWidthSetter(measurementWidth);

                failureStage = 'prepare-breakpoint-images';
                const prepareStartedAt = Date.now();
                const breakpointPreparer = getRunOverride('prepareBreakpoints') || prepareSlot;
                const prepareResult = breakpointPreparer(slot);
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
                const breakpointPreloader = getRunOverride('preloadBreakpointSources') || preloadSlotSources;
                const preloadStates = await breakpointPreloader(slot);
                if (runReport.authorDiagnostics) {
                    runReport.authorDiagnostics.stageTimings.push({
                        stage: 'preload-breakpoint-sources',
                        breakpoint,
                        durationMs: Math.max(0, Date.now() - preloadStartedAt),
                    });
                }

                failureStage = 'wait-for-image-readiness';
                const readinessTrackerBuilder = getRunOverride('buildBreakpointReadinessTracker') || buildSlotReadinessTracker;
                const readinessTracker = readinessTrackerBuilder(slot, preloadStates);
                const waitStartedAt = Date.now();
                let waitResult = null;

                try {
                    const imagesSettleWaiter = getRunOverride('waitForImagesToSettle') || waitForImagesToSettle;
                    waitResult = await imagesSettleWaiter({
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

                const rowExtractor = getRunOverride('extractRowsForBreakpoint') || extractRowsForSlot;
                rowsBySlot[slot.key] = rowExtractor(
                    slot,
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
                rowsByBreakpoint: rowsBySlot,
                rowsBySlot,
                resultPublished: true,
            });
            publishRunReport(finalizedReport);

            const result = buildStructuredOutput(state.previewUrl || runReport.sourceUrl || '', slots, rowsBySlot, startedAt, finalizedReport);

            const resultPublisher = getRunOverride('publishResult') || publishResult;
            await resultPublisher(result);
            let snapshotPersisted = true;
            try {
                const snapshotPersister = getRunOverride('persistRunSnapshot') || persistRunSnapshot;
                snapshotPersisted = await snapshotPersister(finalizedReport, rowsBySlot);
            } catch (error) {
                // Snapshot persistence should never block processing completion UX.
                console.error(error);
                snapshotPersisted = false;
            }
            const setCount = Math.max(0, Number(result.summary.setCount) || 0);
            const warningCount = Math.max(0, Number(result.summary.warningCount) || 0);
            const setLabel = setCount === 1 ? 'set' : 'sets';
            const warningLabel = warningCount === 1 ? 'warning' : 'warnings';
            const completionStatus = `Done. ${setCount} ${setLabel} processed. ${warningCount} ${warningLabel} to address.`;
            setStatus(snapshotPersisted
                ? completionStatus
                : `${completionStatus} Run details were not saved.`);
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
                rowsByBreakpoint: rowsBySlot,
                rowsBySlot,
                resultPublished: false,
                failureStage,
                failureMessage: String(error?.message || 'Processing failed.'),
            });
            publishRunReport(finalizedReport);
            let snapshotPersisted = true;
            try {
                const snapshotPersister = getRunOverride('persistRunSnapshot') || persistRunSnapshot;
                snapshotPersisted = await snapshotPersister(finalizedReport, rowsBySlot);
            } catch (persistError) {
                // Snapshot persistence should never block failure/cancel status updates.
                console.error(persistError);
                snapshotPersisted = false;
            }

            const snapshotFailureNote = snapshotPersisted ? '' : ' Run details were not saved.';
            if (cancelled) {
                setStatus(`Processing cancelled. No partial results were published.${snapshotFailureNote}`);
            } else {
                setStatus(`Error: ${error.message}${snapshotFailureNote}`);
            }
        } finally {
            state.busy = false;
            state.stopRequested = false;
            state.waitSoftLimitReached = false;
            setProcessingState(false);
            if (!state.previewVisible) {
                setProcessingFrameMounted(false);
            }
            setStopButtonVisibility(false);
            setButtonsDisabled(false);
            hideProcessingProgress();
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
            setProcessingFrameMounted(true);
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
            if (!state.busy) {
                setProcessingFrameMounted(false);
            }
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
            await loadPreviewForSelectedEntry('Entry ready to process.');
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
            runProcessing,
            persistRunSnapshot,
            summarizeFailureReasonCountsFromReport,
            collectReviewEditStateFromDom,
            parseServerStatusFromPatchSignalsArgs,
            renderInitialStoredReview,
            renderResultReview,
            applyRenderedReviewPayload,
            syncSidebarObservedUnsavedFromSavedNames,
            setLastResultForTests: (result) => {
                state.lastResult = result;
            },
            setRunProcessingOverridesForTests: (overrides) => {
                state.testRunProcessingOverrides = overrides && typeof overrides === 'object'
                    ? overrides
                    : null;
            },
            clearRunProcessingOverridesForTests: () => {
                state.testRunProcessingOverrides = null;
            },
            setPreviewFrameForTests: (frameDocument, frameWindow = window) => {
                state.previewFrame = {
                    contentDocument: frameDocument,
                    contentWindow: frameWindow,
                };
            },
            clearPreviewFrameForTests: () => {
                state.previewFrame = null;
            },
            prepareBreakpoints,
            buildBreakpointReadinessTracker,
            extractRowsForBreakpoint,
        };
    }

    setPreviewVisibility(false);
    setProcessingState(false);
    setReviewHydrated(false);
    setStopButtonVisibility(false);
    updateCopyButtonVisibility();
    updateResultsOrderingNote();
    state.selectedEntryId = getSelectedEntryId();
    setupDatastarModalIgnoreGuard();
    bindSourceSelectionSync();
    bindEntrySlideoutLinks();
    bindProcessAgainButtons();
    bindProcessObservedEntryButtons();
    bindTransformSidebarCardNavigation();
    setButtonsDisabled(false);
    setupDragToScroll();
    setupResultsSettingsLightswitchSync();
    setupDatastarCardUpdateStatus();
    setupDatastarReviewPatchSync();
    window.addEventListener('resize', scheduleBreakpointPreviewHeightSync);
    getConfiguredSlots();
    void renderInitialStoredReview().catch((error) => {
        console.error(error);
        setReviewHydrated(true);
    });
    void loadInitialPreview();
})();
