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
    const ENTRY_URL_ACTION = 'craft-breakpoint-images/default/entry-url';
    const PROCESS_DETAILS_SLIDEOUT_ACTION = 'craft-breakpoint-images/default/processing-run-details';
    const PROCESS_DETAILS_CP_URL = 'craft-breakpoint-images/processing-run-details';
    const RENDER_RESULT_REVIEW_ACTION = 'craft-breakpoint-images/transforms/render-result-review';
    const RENDER_INITIAL_REVIEW_ACTION = 'craft-breakpoint-images/transforms/render-initial-review';
    const PERSIST_RUN_SNAPSHOT_ACTION = 'craft-breakpoint-images/transforms/persist-run-snapshot';
    const DATASTAR_FETCH_EVENT = 'datastar-fetch';
    const DATASTAR_PATCH_SIGNALS_EVENT = 'datastar-patch-signals';
    const DATASTAR_SIGNAL_PATCH_EVENT = 'datastar-signal-patch';

    const elements = {
        page: document.querySelector('.bpi-transforms-page'),
        showCardSettingsSignalBridge: document.getElementById('bpi-show-card-settings-signal-bridge'),
        uiResultsHeadingSignalBridge: document.getElementById('bpi-ui-results-heading-signal-bridge'),
        uiShowWarningOrderSignalBridge: document.getElementById('bpi-ui-show-warning-order-signal-bridge'),
        uiResultsOrderingNoteLabelSignalBridge: document.getElementById('bpi-ui-results-ordering-note-label-signal-bridge'),
        transformSetsSidebar: document.getElementById('bpi-transform-sets-sidebar'),
        transformSetsList: document.getElementById('bpi-transform-sets-list'),
        sourceEntry: document.getElementById('bpi-source-entry'),
        status: document.getElementById('bpi-status'),
        progressHost: document.getElementById('bpi-progress-host'),
        resultsSettingsLightswitch: document.getElementById('bpi-results-settings-lightswitch'),
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

    const RESULTS_COPY = {
        saved: {
            heading: 'Saved Sets',
        },
        processed: {
            heading: 'Processed Sets',
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

        elements.page.classList.toggle('bpi-review-hydrating', !Boolean(isHydrated));
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
                ? event.target.closest('.bpi-entry-link[data-bpi-open-entry="true"]')
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

    function openProcessDetailsSlideout(link) {
        const transformHandle = String(link?.dataset?.transformHandle || '').trim();
        if (!transformHandle) {
            return;
        }

        const entryId = parsePositiveInt(link?.dataset?.entryId);
        const params = {
            transformHandle,
        };

        if (entryId) {
            params.entryId = entryId;
        }

        if (typeof Craft?.CpScreenSlideout === 'function') {
            new Craft.CpScreenSlideout(PROCESS_DETAILS_SLIDEOUT_ACTION, {
                params,
                containerElement: 'div',
            });
            return;
        }

        if (typeof Craft?.getCpUrl === 'function') {
            window.location.href = Craft.getCpUrl(PROCESS_DETAILS_CP_URL, params);
        }
    }

    function bindProcessDetailsSlideoutLinks() {
        if (document.documentElement.dataset.bpiProcessDetailsSlideoutBound === '1') {
            return;
        }

        document.documentElement.dataset.bpiProcessDetailsSlideoutBound = '1';

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('.bpi-process-details-link[data-bpi-open-process-details="true"]')
                : null;

            if (!(target instanceof Element)) {
                return;
            }

            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            openProcessDetailsSlideout(target);
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

        const hiddenInputs = Array.from(elements.sourceEntry.querySelectorAll('input[type="hidden"][name="bpi-source-entry-id"]'));
        if (hiddenInputs.length) {
            hiddenInputs[hiddenInputs.length - 1].value = String(entryId);
            hiddenInputs[hiddenInputs.length - 1].dispatchEvent(new Event('change', { bubbles: true }));
        }

        syncSelectedEntryIdToUrl(entryId);
        state.selectedEntryId = getSelectedEntryId();
        setButtonsDisabled(false);

        return getSelectedEntryId() === entryId;
    }

    function closeProcessDetailsPanel(triggerElement) {
        if (!(triggerElement instanceof Element) || typeof window.jQuery !== 'function') {
            return;
        }

        const screenContainer = triggerElement.closest('.cp-screen');
        if (!(screenContainer instanceof Element)) {
            return;
        }

        const cpScreen = window.jQuery(screenContainer).data('cpScreen');
        if (cpScreen && typeof cpScreen.close === 'function') {
            cpScreen.close();
        }
    }

    function bindProcessAgainButtons() {
        if (document.documentElement.dataset.bpiProcessAgainBound === '1') {
            return;
        }

        document.documentElement.dataset.bpiProcessAgainBound = '1';

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('.bpi-process-again-button[data-bpi-process-again="true"]')
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

                closeProcessDetailsPanel(target);

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

    function getVisualResultCardOrder() {
        if (!elements.visualResults) {
            return [];
        }

        const cards = Array.from(elements.visualResults.querySelectorAll('.bpi-transform-card[data-set]'));
        return cards
            .map((card) => String(card.getAttribute('data-set') || '').trim())
            .filter((setName) => setName !== '');
    }

    function syncSidebarTransformOrderToCards() {
        const list = elements.transformSetsList;
        if (!(list instanceof HTMLElement)) {
            return;
        }

        const cardOrder = getVisualResultCardOrder();
        if (cardOrder.length < 1) {
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
    }

    function bindTransformSidebarCardNavigation() {
        const list = elements.transformSetsList;
        if (!(list instanceof HTMLElement)) {
            return;
        }

        list.addEventListener('click', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('a.bpi-transform-sidebar-link[data-set]')
                : null;

            if (!(target instanceof HTMLAnchorElement)) {
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

    function getConfiguredBreakpoints() {
        const rawBreakpoints = Array.isArray(bpiProcessingConfig?.breakpointValues)
            ? bpiProcessingConfig.breakpointValues
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

    function setupDragToScroll() {
        bindHorizontalDragScroll({
            bindingKey: '__BPI_TRANSFORMS_GRID_DRAG_BOUND',
            findGridFromTarget: (target) => (target instanceof Element ? target.closest('.bpi-breakpoint-grid') : null),
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
            editTabBySet[transformName] = (activeTab === 'ratio' || activeTab === 'settings')
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

    function bindAssetPaginationReviewRerender() {
        if (document.documentElement.dataset.bpiAssetPaginationBound === '1') {
            return;
        }

        document.documentElement.dataset.bpiAssetPaginationBound = '1';

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element
                ? event.target.closest('.bpi-transform-asset-page[data-asset-key]')
                : null;

            if (!(target instanceof Element)) {
                return;
            }

            if (!elements.visualResults) {
                return;
            }

            const card = target.closest('.bpi-transform-card[data-set]');
            if (!(card instanceof Element) || !elements.visualResults.contains(card)) {
                return;
            }

            const nextAssetKey = String(target.getAttribute('data-asset-key') || '').trim();
            if (!nextAssetKey) {
                return;
            }

            const transformName = String(card.getAttribute('data-set') || '').trim();
            if (!transformName) {
                return;
            }

            const selectedAssetKeyBySetOverride = {
                [transformName]: nextAssetKey,
            };

            event.preventDefault();
            void (async () => {
                try {
                    if (state.lastResult && typeof state.lastResult === 'object') {
                        await renderResultReview(state.lastResult, {
                            selectedAssetKeyBySetOverride,
                        });
                        return;
                    }

                    await renderInitialStoredReview({
                        selectedAssetKeyBySetOverride,
                    });
                } catch (error) {
                    console.error(error);
                }
            })();
        });
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

        const cards = Array.from(elements.visualResults.querySelectorAll('.bpi-transform-card[data-set]'));
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

    async function refreshReviewCardsAfterSuccessfulUpdate() {
        if (state.lastResult) {
            await renderResultReview(state.lastResult);
            return;
        }

        await renderInitialStoredReview();
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
                if (action === 'deleteSet') {
                    removeTransformFromLastResult(transformName);
                }

                const runId = getUpdateStatusRunId(transformName);
                scheduleTransformTerminalStatus(transformName, 'Updated', 'success', CARD_UPDATE_STATUS_CLEAR_DELAY_MS, runId);
            });

            void refreshReviewCardsAfterSuccessfulUpdate().catch((error) => {
                console.error(error);
            });

            return;
        }

        pendingTransformEntries.forEach(({ transformName }) => {
            const runId = getUpdateStatusRunId(transformName);
            scheduleTransformTerminalStatus(transformName, 'Update failed', 'error', CARD_UPDATE_STATUS_ERROR_CLEAR_DELAY_MS, runId);
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

        const action = String(state.pendingTransformActionsByName[transformName] || '');
        removePendingTransformUpdate(transformName);
        const runId = getUpdateStatusRunId(transformName);

        if (kind === 'success') {
            if (action === 'deleteSet') {
                removeTransformFromLastResult(transformName);
            }

            scheduleTransformTerminalStatus(transformName, 'Updated', 'success', CARD_UPDATE_STATUS_CLEAR_DELAY_MS, runId);
            void refreshReviewCardsAfterSuccessfulUpdate().catch((error) => {
                console.error(error);
            });
            return;
        }

        scheduleTransformTerminalStatus(transformName, 'Update failed', 'error', CARD_UPDATE_STATUS_ERROR_CLEAR_DELAY_MS, runId);
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
        if (!(sourceElement instanceof Element)) {
            return null;
        }

        const actionHost = sourceElement.closest('[data-bpi-action]');
        if (!(actionHost instanceof Element)) {
            return null;
        }

        const action = String(actionHost.getAttribute('data-bpi-action') || '').trim();
        return action !== '' ? action : null;
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
                state.pendingTransformActionsByName[transformName] = action;
                clearUpdateStatusResetTimer(transformName);
                clearUpdateStatusTransitionTimer(transformName);
                state.updateStatusStartedAtByTransform[transformName] = Date.now();
                nextUpdateStatusRunId(transformName);
                setTransformUpdateStatus(transformName, 'Saving...', 'pending');
                return;
            }

            if (detail.type === 'finished') {
                // Keep the visible pending copy stable; terminal status will apply after min linger.
                return;
            }

            if (detail.type === 'error' || detail.type === 'retries-failed') {
                removePendingTransformUpdate(transformName);
                const runId = getUpdateStatusRunId(transformName);
                scheduleTransformTerminalStatus(transformName, 'Update failed', 'error', CARD_UPDATE_STATUS_ERROR_CLEAR_DELAY_MS, runId);
            }
        });
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

            setShowCardSettingsSignal(latest.on === true);
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

        if (typeof Craft === 'undefined' || typeof Craft.sendActionRequest !== 'function') {
            return null;
        }

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
        const mergedSelectedAssetKeyBySet = selectedAssetKeyBySetOverride
            ? {
                ...selectedAssetKeyBySet,
                ...selectedAssetKeyBySetOverride,
            }
            : selectedAssetKeyBySet;

        const response = await Craft.sendActionRequest('POST', RENDER_RESULT_REVIEW_ACTION, {
            data: {
                result,
                editScopeBySet,
                editTabBySet,
                selectedAssetKeyBySet: mergedSelectedAssetKeyBySet,
                preferredOrderBySet,
            },
        });

        const payload = response?.data || null;
        applyRenderedReviewPayload(payload);
        return payload;
    }

    async function renderInitialStoredReview(options = null) {
        if (typeof Craft === 'undefined' || typeof Craft.sendActionRequest !== 'function') {
            return null;
        }

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
        const mergedSelectedAssetKeyBySet = selectedAssetKeyBySetOverride
            ? {
                ...selectedAssetKeyBySet,
                ...selectedAssetKeyBySetOverride,
            }
            : selectedAssetKeyBySet;

        const response = await Craft.sendActionRequest('POST', RENDER_INITIAL_REVIEW_ACTION, {
            data: {
                editScopeBySet,
                editTabBySet,
                selectedAssetKeyBySet: mergedSelectedAssetKeyBySet,
                preferredOrderBySet,
            },
        });

        const payload = response?.data || null;
        applyRenderedReviewPayload(payload);
        return payload;
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

    async function persistRunSnapshot(report, rowsByBreakpoint) {
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
                rowsByBreakpoint: rowsByBreakpoint && typeof rowsByBreakpoint === 'object' ? rowsByBreakpoint : {},
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

            for (const breakpoint of breakpoints) {
                const breakpointReport = createBreakpointReportEntry(breakpoint);
                runReport.breakpoints.push(breakpointReport);

                state.waitSoftLimitReached = false;
                setStopButtonVisibility(false);
                const measurementWidth = getMeasurementWidthForBreakpoint(breakpoint);
                setStatus(`Processing ${breakpoint}px...`);

                failureStage = 'set-breakpoint-width';
                const previewWidthSetter = getRunOverride('setPreviewWidth') || setPreviewWidth;
                await previewWidthSetter(measurementWidth);

                failureStage = 'prepare-breakpoint-images';
                const prepareStartedAt = Date.now();
                const breakpointPreparer = getRunOverride('prepareBreakpointImages') || prepareBreakpointImages;
                const prepareResult = breakpointPreparer(breakpoint);
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
                const breakpointPreloader = getRunOverride('preloadBreakpointSources') || preloadBreakpointSources;
                const preloadStates = await breakpointPreloader(breakpoint);
                if (runReport.authorDiagnostics) {
                    runReport.authorDiagnostics.stageTimings.push({
                        stage: 'preload-breakpoint-sources',
                        breakpoint,
                        durationMs: Math.max(0, Date.now() - preloadStartedAt),
                    });
                }

                failureStage = 'wait-for-image-readiness';
                const readinessTrackerBuilder = getRunOverride('buildBreakpointReadinessTracker') || buildBreakpointReadinessTracker;
                const readinessTracker = readinessTrackerBuilder(breakpoint, preloadStates);
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

                const rowExtractor = getRunOverride('extractRowsForBreakpoint') || extractRowsForBreakpoint;
                rowsByBreakpoint[breakpoint] = rowExtractor(
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

            const resultPublisher = getRunOverride('publishResult') || publishResult;
            await resultPublisher(result);
            let snapshotPersisted = true;
            try {
                const snapshotPersister = getRunOverride('persistRunSnapshot') || persistRunSnapshot;
                snapshotPersisted = await snapshotPersister(finalizedReport, rowsByBreakpoint);
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
                rowsByBreakpoint,
                resultPublished: false,
                failureStage,
                failureMessage: String(error?.message || 'Processing failed.'),
            });
            publishRunReport(finalizedReport);
            let snapshotPersisted = true;
            try {
                const snapshotPersister = getRunOverride('persistRunSnapshot') || persistRunSnapshot;
                snapshotPersisted = await snapshotPersister(finalizedReport, rowsByBreakpoint);
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
            runProcessing,
            persistRunSnapshot,
            summarizeFailureReasonCountsFromReport,
            collectReviewEditStateFromDom,
            parseServerStatusFromPatchSignalsArgs,
            renderInitialStoredReview,
            renderResultReview,
            applyRenderedReviewPayload,
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
            prepareBreakpointImages,
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
    bindProcessDetailsSlideoutLinks();
    bindProcessAgainButtons();
    bindAssetPaginationReviewRerender();
    bindTransformSidebarCardNavigation();
    setButtonsDisabled(false);
    setupDragToScroll();
    setupResultsSettingsLightswitchSync();
    setupDatastarCardUpdateStatus();
    window.addEventListener('resize', scheduleBreakpointPreviewHeightSync);
    getConfiguredBreakpoints();
    void renderInitialStoredReview().catch((error) => {
        console.error(error);
        setReviewHydrated(true);
    });
    void loadInitialPreview();
})();
