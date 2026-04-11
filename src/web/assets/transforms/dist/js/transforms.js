(() => {
    const BREAKPOINT_SAFETY_PX = 2;
    const FIRST_BREAKPOINT_COLUMN_WIDTH_PX = 120;
    const DRAG_SCROLL_THRESHOLD_PX = 4;
    const PROCESSING_QUERY_PARAM = '__bpiProcessing';
    const PREVIEW_FRAME_TAG = 'ifr' + 'ame';
    const IMAGE_WAIT_SOFT_DEADLINE_MS = 4000;
    const IMAGE_WAIT_POLL_MS = 250;

    const bpiProcessingManifest = window.bpiProcessingManifest || {};
    const ENTRY_URL_ACTION = 'craft-breakpoint-images/default/entry-url';

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
        editPanelOpenTransforms: new Set(),
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

    function getEscapeBreakpointValue() {
        const rawValue = bpiProcessingManifest?.breakpoints?.escape;
        const parsed = parseInt(String(rawValue ?? ''), 10);

        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    }

    function getBreakpointsForTransformConfig(transformConfig, breakpoints) {
        const includeEscapeWidth = transformConfig?.includeEscapeWidth === true;
        const escapeBreakpoint = getEscapeBreakpointValue();

        if (includeEscapeWidth || escapeBreakpoint === null) {
            return breakpoints;
        }

        return breakpoints.filter((breakpoint) => breakpoint !== escapeBreakpoint);
    }

    function getBreakpointsForObservedTransform(transformName, breakpoints, rowsByBreakpoint) {
        const observed = breakpoints.filter((breakpoint) => {
            const rows = rowsByBreakpoint?.[breakpoint] || rowsByBreakpoint?.[String(breakpoint)] || [];
            return Array.isArray(rows) && rows.some((row) => row?.transform === transformName);
        });

        if (observed.length > 0) {
            return observed;
        }

        const manifestTransforms = bpiProcessingManifest?.transforms || {};
        const transformConfig = manifestTransforms[transformName] || {};

        return getBreakpointsForTransformConfig(transformConfig, breakpoints);
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

    function collectTransformNames(rowsByBreakpoint) {
        const names = new Set();

        Object.values(rowsByBreakpoint).forEach((rows) => {
            rows.forEach((row) => {
                if (row.transform && row.transform !== 'unknown') {
                    names.add(row.transform);
                }
            });
        });

        return Array.from(names).sort();
    }

    function buildCurrentProposal(transformName, transformBreakpoints) {
        const manifestTransforms = bpiProcessingManifest?.transforms || {};
        const transformConfig = manifestTransforms[transformName] || {};
        const transformEntries = Array.isArray(transformConfig.transforms) ? transformConfig.transforms : [];

        return {
            includeEscapeWidth: transformConfig.includeEscapeWidth === true,
            transforms: transformBreakpoints.map((breakpoint, index) => {
                const current = transformEntries[index] || {};

                return {
                    breakpoint,
                    width: toPositiveIntOrNull(current.width),
                    height: toPositiveIntOrNull(current.height),
                    enabled: current.enabled !== false,
                    autoDimension: current.autoDimension || null
                };
            })
        };
    }

    function pickSuggestedDimensions(rows) {
        const validRows = rows.filter((row) =>
            row.enabled === true
            && row.isVisible === true
            && (row.rendered?.width || 0) > 0
            && (row.rendered?.height || 0) > 0
        );

        if (!validRows.length) {
            return {
                width: null,
                height: null
            };
        }

        const maxWidth = Math.max(...validRows.map((row) => row.rendered.width || 0));
        const maxHeight = Math.max(...validRows.map((row) => row.rendered.height || 0));

        return {
            width: maxWidth > 0 ? Math.round(maxWidth) : null,
            height: maxHeight > 0 ? Math.round(maxHeight) : null
        };
    }

    function buildSuggestedProposal(transformName, rowsByBreakpoint, currentProposal) {
        return {
            includeEscapeWidth: currentProposal.includeEscapeWidth,
            transforms: currentProposal.transforms.map((current, index) => {
                const breakpoint = current.breakpoint;
                const rows = (rowsByBreakpoint[breakpoint] || []).filter((row) => row.transform === transformName);
                const suggestedDimensions = pickSuggestedDimensions(rows);
                const currentRow = currentProposal.transforms[index] || current;

                return {
                    breakpoint,
                    width: suggestedDimensions.width ?? currentRow.width,
                    height: suggestedDimensions.height ?? currentRow.height,
                    enabled: currentRow.enabled,
                    autoDimension: currentRow.autoDimension
                };
            })
        };
    }

    function buildEdits(currentProposal, suggestedProposal) {
        const edits = [];

        suggestedProposal.transforms.forEach((suggestedRow, index) => {
            const currentRow = currentProposal.transforms[index];
            if (!currentRow) {
                return;
            }

            if (
                currentRow.width === suggestedRow.width
                && currentRow.height === suggestedRow.height
                && currentRow.enabled === suggestedRow.enabled
                && currentRow.autoDimension === suggestedRow.autoDimension
            ) {
                return;
            }

            edits.push({
                breakpoint: suggestedRow.breakpoint,
                from: currentRow,
                to: suggestedRow
            });
        });

        return edits;
    }

    function buildProposalOutput(breakpoints, rowsByBreakpoint) {
        const proposals = {};
        const transformNames = collectTransformNames(rowsByBreakpoint);

        transformNames.forEach((transformName) => {
            const transformBreakpoints = getBreakpointsForObservedTransform(transformName, breakpoints, rowsByBreakpoint);
            const current = buildCurrentProposal(transformName, transformBreakpoints);
            const suggested = buildSuggestedProposal(transformName, rowsByBreakpoint, current);
            const edits = buildEdits(current, suggested);

            proposals[transformName] = {
                current,
                edits
            };
        });

        return proposals;
    }

    function sortAssetIds(assetIds) {
        return assetIds.sort((left, right) => {
            const leftNumber = Number.parseInt(String(left), 10);
            const rightNumber = Number.parseInt(String(right), 10);

            if (Number.isFinite(leftNumber) && Number.isFinite(rightNumber)) {
                return leftNumber - rightNumber;
            }

            return String(left).localeCompare(String(right));
        });
    }

    function collectObservedTransformNames(rowsByBreakpoint) {
        const names = new Set();

        Object.values(rowsByBreakpoint).forEach((rows) => {
            rows.forEach((row) => {
                if (row.transform && row.transform !== 'unknown') {
                    names.add(row.transform);
                }
            });
        });

        return Array.from(names).sort();
    }

    function countUnknownTransformRows(rowsByBreakpoint) {
        return Object.values(rowsByBreakpoint).reduce((count, rows) => {
            const unknownInRows = rows.filter((row) => !row.transform || row.transform === 'unknown').length;
            return count + unknownInRows;
        }, 0);
    }

    function buildWarnings(rowsByBreakpoint) {
        const warnings = [];
        const manifestTransforms = bpiProcessingManifest?.transforms || {};
        const manifestTransformNames = Object.keys(manifestTransforms).sort();
        const observedTransformNames = collectObservedTransformNames(rowsByBreakpoint);

        const missingTransformDefinitions = observedTransformNames.filter(
            (name) => !manifestTransformNames.includes(name)
        );

        if (missingTransformDefinitions.length > 0) {
            warnings.push({
                code: 'missing-transform-definitions',
                message: 'Transforms found in markup are missing from manifest configuration.',
                transforms: missingTransformDefinitions
            });
        }

        const unknownTransformRows = countUnknownTransformRows(rowsByBreakpoint);
        if (unknownTransformRows > 0) {
            warnings.push({
                code: 'unknown-transform-rows',
                message: 'Some rows were missing the data-transform attribute.',
                rowCount: unknownTransformRows
            });
        }

        return warnings;
    }

    function buildStructuredOutput(sourceUrl, breakpoints, rowsByBreakpoint, startedAt) {
        const assets = {};
        const transforms = {};
        const proposals = buildProposalOutput(breakpoints, rowsByBreakpoint);
        const warnings = buildWarnings(rowsByBreakpoint);
        let unloadedImageCount = 0;

        Object.entries(rowsByBreakpoint).forEach(([breakpoint, rows]) => {
            rows.forEach((row) => {
                if (!assets[row.assetId]) {
                    assets[row.assetId] = {
                        meta: {
                            assetId: row.assetId,
                            title: row.title
                        },
                        breakpoints: {}
                    };
                }

                assets[row.assetId].breakpoints[breakpoint] = {
                    rendered: row.rendered,
                    intrinsic: row.intrinsic,
                    transform: row.transformDimensions,
                    enabled: row.enabled,
                    isVisible: row.isVisible,
                    loaded: row.loaded,
                    src: row.src
                };

                if (!row.loaded) {
                    unloadedImageCount += 1;
                }

                if (!transforms[row.transform]) {
                    transforms[row.transform] = {
                        assetIds: []
                    };
                }

                if (!transforms[row.transform].assetIds.includes(row.assetId)) {
                    transforms[row.transform].assetIds.push(row.assetId);
                }
            });
        });

        Object.values(transforms).forEach((transformGroup) => {
            sortAssetIds(transformGroup.assetIds);
        });

        const durationMs = Date.now() - startedAt;
        const rowCount = Object.values(rowsByBreakpoint).reduce((count, rows) => count + rows.length, 0);
        const editCount = Object.values(proposals).reduce((count, proposal) => count + proposal.edits.length, 0);

        return {
            schemaVersion: 1,
            manifestSchemaVersion: bpiProcessingManifest?.schemaVersion || null,
            runId: `run-${Date.now()}`,
            sourceUrl,
            timestamp: new Date().toISOString(),
            breakpoints,
            proposals,
            transforms,
            assets,
            warnings,
            summary: {
                runs: state.runCount,
                breakpointCount: breakpoints.length,
                assetCount: Object.keys(assets).length,
                rowCount,
                editCount,
                warningCount: warnings.length,
                unloadedImageCount,
                durationMs
            }
        };
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatDimensionPair(width, height, autoDimension = null) {
        const widthText = autoDimension === 'width'
            ? 'auto'
            : (Number.isFinite(width) && width > 0 ? String(width) : '-');
        const heightText = autoDimension === 'height'
            ? 'auto'
            : (Number.isFinite(height) && height > 0 ? String(height) : '-');

        return `${widthText}:${heightText}`;
    }

    function getWarningItemClass(code) {
        if (code === 'missing-transform-definitions' || code === 'unknown-transform-rows') {
            return 'bpi-warning-item bpi-warning-item-danger';
        }

        return 'bpi-warning-item bpi-warning-item-neutral';
    }

    function renderWarningsPanel(result) {
        if (!elements.warnings) {
            return;
        }

        const warnings = Array.isArray(result?.warnings) ? result.warnings : [];
        if (warnings.length < 1) {
            elements.warnings.innerHTML = '<div class="bpi-warning-item bpi-warning-item-success">No warnings detected.</div>';
            return;
        }

        const warningMarkup = warnings.map((warning) => {
            const code = escapeHtml(warning.code || 'warning');
            const message = escapeHtml(warning.message || 'Warning');
            const transforms = Array.isArray(warning.transforms) ? warning.transforms : [];
            const transformDetail = transforms.length > 0
                ? `<div class="bpi-warning-detail">${transforms.map(escapeHtml).join(', ')}</div>`
                : '';
            const rowCount = Number.isFinite(warning.rowCount)
                ? `<div class="bpi-warning-detail">rows: ${warning.rowCount}</div>`
                : '';

            return `<div class="${getWarningItemClass(warning.code)}"><span class="bpi-warning-code">${code}</span> - ${message}${transformDetail}${rowCount}</div>`;
        }).join('');

        elements.warnings.innerHTML = warningMarkup;
    }

    function getTransformRowsForBreakpoint(result, transformName, breakpoint) {
        const rawRowsByBreakpoint = result?._rowsByBreakpoint;
        if (rawRowsByBreakpoint && typeof rawRowsByBreakpoint === 'object') {
            const rawRows = rawRowsByBreakpoint[breakpoint] || rawRowsByBreakpoint[String(breakpoint)] || [];
            if (Array.isArray(rawRows)) {
                return rawRows.filter((row) => row?.transform === transformName);
            }
        }

        const transformGroup = result?.transforms?.[transformName];
        const assetIds = Array.isArray(transformGroup?.assetIds) ? transformGroup.assetIds : [];
        const breakpointKey = String(breakpoint);

        return assetIds
            .map((assetId) => result?.assets?.[assetId]?.breakpoints?.[breakpointKey] || null)
            .filter((row) => row !== null);
    }

    function summarizeBreakpointRows(rows) {
        const enabledRows = rows.filter((row) => row.enabled === true);
        const visibleRows = enabledRows.filter((row) => row.isVisible === true);
        const preferredRows = visibleRows.length > 0 ? visibleRows : enabledRows;

        const renderedWidth = preferredRows.length > 0
            ? Math.max(...preferredRows.map((row) => row.rendered?.width || 0))
            : 0;
        const renderedHeight = preferredRows.length > 0
            ? Math.max(...preferredRows.map((row) => row.rendered?.height || 0))
            : 0;

        return {
            renderedWidth,
            renderedHeight,
            hiddenCount: enabledRows.filter((row) => row.isVisible !== true).length,
            unloadedCount: rows.filter((row) => row.loaded !== true).length,
        };
    }

    function pickPreviewRow(rows) {
        if (!Array.isArray(rows) || rows.length < 1) {
            return null;
        }

        const loadedVisibleEnabled = rows.filter(
            (row) => row.loaded === true && row.isVisible === true && row.enabled === true && row.src
        );
        if (loadedVisibleEnabled.length > 0) {
            return loadedVisibleEnabled[0];
        }

        const loadedEnabled = rows.filter(
            (row) => row.loaded === true && row.enabled === true && row.src
        );
        if (loadedEnabled.length > 0) {
            return loadedEnabled[0];
        }

        const loadedAny = rows.filter((row) => row.loaded === true && row.src);
        if (loadedAny.length > 0) {
            return loadedAny[0];
        }

        const withSrc = rows.filter((row) => row.src);
        if (withSrc.length > 0) {
            return withSrc[0];
        }

        return rows[0];
    }

    function getDimensionClass(renderedValue, transformValue, autoDimension, dimension) {
        if (autoDimension === dimension) {
            return 'bpi_dimension-auto';
        }

        if (!Number.isFinite(transformValue) || transformValue <= 0) {
            return 'bpi_dimension-no-transform';
        }

        if (!Number.isFinite(renderedValue) || renderedValue <= 0) {
            return 'bpi_dimension-no-transform';
        }

        if (Math.abs(renderedValue - transformValue) <= 1) {
            return 'bpi_dimension-match';
        }

        return 'bpi_dimension-mismatch';
    }

    function getCurrentDimensionDisplay(value, autoDimension, dimension) {
        if (autoDimension === dimension) {
            return 'auto';
        }

        const parsed = Number(value);
        if (Number.isFinite(parsed) && parsed > 0) {
            return String(Math.round(parsed));
        }

        return '-';
    }

    function calculateBreakpointColumnWidths(breakpoints) {
        const validBreakpoints = Array.isArray(breakpoints)
            ? breakpoints
                .map((breakpoint) => parseInt(String(breakpoint), 10))
                .filter((breakpoint) => Number.isFinite(breakpoint) && breakpoint > 0)
            : [];

        if (validBreakpoints.length < 1) {
            return {};
        }

        const firstBreakpoint = validBreakpoints[0];

        return validBreakpoints.reduce((widths, breakpoint) => {
            widths[String(breakpoint)] = (breakpoint / firstBreakpoint) * FIRST_BREAKPOINT_COLUMN_WIDTH_PX;
            return widths;
        }, {});
    }

    function renderBreakpointColumn(result, transformName, breakpoint, breakpointColumnWidths) {
        const currentRows = getTransformRowsForBreakpoint(result, transformName, breakpoint);
        const summary = summarizeBreakpointRows(currentRows);
        const currentProposalRows = result?.proposals?.[transformName]?.current?.transforms || [];
        const currentProposal = currentProposalRows.find((row) => row.breakpoint === breakpoint) || {};

        const renderedWidth = Number(summary.renderedWidth) || 0;
        const renderedHeight = Number(summary.renderedHeight) || 0;
        const previewRow = pickPreviewRow(currentRows);
        const previewSrc = previewRow?.src ? escapeHtml(previewRow.src) : '';
        const previewAlt = `Preview ${transformName} ${breakpoint}px`;
        const relativeWidth = breakpoint > 0
            ? Math.max(0, Math.min(100, (renderedWidth / breakpoint) * 100))
            : 0;
        const breakpointColumnWidth = Math.max(
            1,
            Number(breakpointColumnWidths?.[String(breakpoint)]) || 0
        );
        const aspectRatio = renderedWidth > 0 && renderedHeight > 0
            ? `${renderedWidth} / ${renderedHeight}`
            : '1 / 1';

        const widthClass = getDimensionClass(
            renderedWidth,
            Number(currentProposal.width),
            currentProposal.autoDimension || null,
            'width'
        );
        const heightClass = getDimensionClass(
            renderedHeight,
            Number(currentProposal.height),
            currentProposal.autoDimension || null,
            'height'
        );
        const currentWidth = getCurrentDimensionDisplay(
            currentProposal.width,
            currentProposal.autoDimension || null,
            'width'
        );
        const currentHeight = getCurrentDimensionDisplay(
            currentProposal.height,
            currentProposal.autoDimension || null,
            'height'
        );

        const hiddenBadge = summary.hiddenCount > 0
            ? `<span class="bpi_hidden-notice">Hidden ${summary.hiddenCount}</span>`
            : '';
        const unloadedBadge = summary.unloadedCount > 0
            ? `<span class="bpi-row-badge">Unloaded ${summary.unloadedCount}</span>`
            : '';
        const escapeBreakpoint = getEscapeBreakpointValue();
        const escapeBadge = escapeBreakpoint !== null && breakpoint === escapeBreakpoint
            ? '<span class="bpi_escaped-notice">ESC</span>'
            : '';

        return `
            <div class="bpi-breakpoint-column" style="--bpi-breakpoint-column-width:${breakpointColumnWidth}px;">
                <div class="bpi_breakpoint-size-heading">
                    <span>${breakpoint}px</span>
                    ${escapeBadge}
                    ${hiddenBadge}
                    ${unloadedBadge}
                </div>
                <div class="bpi_image-display-section">
                    <div class="bpi_breakpoint-result-wrapper">
                        <div class="bpi_breakpoint-result">
                            <div class="bpi_image-outer" style="--bpi-relative-width:${relativeWidth}%;">
                                ${previewSrc
                ? `<img src="${previewSrc}" alt="${escapeHtml(previewAlt)}" class="bpi_breakpoint-result-image" draggable="false" style="--bpi-aspect-ratio:${aspectRatio};">`
                : `<div class="bpi_breakpoint-result-image" style="--bpi-aspect-ratio:${aspectRatio};"></div>`}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bpi-dimension-table-wrap">
                    <div class="bpi-dimension-matrix" role="table" aria-label="Rendered and current dimensions">
                        <div class="bpi-dimension-row" role="row">
                            <span class="bpi-dimension-icon bpi-dimension-icon-rendered" role="rowheader" title="Rendered" aria-label="Rendered">
                                <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                                    <rect x="2.5" y="3" width="11" height="8.5" rx="1.5"></rect>
                                    <path d="M4.4 5.2h2"></path>
                                    <path d="M3.5 10.8l3.1-2.8 2.2 2 2-1.6 2.2 2.4"></path>
                                </svg>
                            </span>
                            <span class="bpi_rendered-dimension ${widthClass}" role="cell">${renderedWidth || '-'}</span>
                            <span class="bpi_rendered-dimension ${heightClass}" role="cell">${renderedHeight || '-'}</span>
                        </div>
                        <div class="bpi-dimension-row" role="row">
                            <span class="bpi-dimension-icon bpi-dimension-icon-current" role="rowheader" title="Current" aria-label="Current">
                                <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                                    <path d="M3 4h10"></path>
                                    <path d="M3 8h10"></path>
                                    <path d="M3 12h10"></path>
                                    <path d="M6 3.1v1.8"></path>
                                    <path d="M10 7.1v1.8"></path>
                                    <path d="M7.5 11.1v1.8"></path>
                                </svg>
                            </span>
                            <span class="bpi_current-dimension" role="cell">${escapeHtml(currentWidth)}</span>
                            <span class="bpi_current-dimension" role="cell">${escapeHtml(currentHeight)}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
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

    function slugifyTransformName(transformName) {
        return String(transformName || 'transform')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            || 'transform';
    }

    function getEditPanelId(transformName) {
        return `bpi-edit-panel-${slugifyTransformName(transformName)}`;
    }

    function setTransformEditPanelOpen(card, isOpen) {
        const panel = card.querySelector('.bpi-transform-edit-panel');
        const toggle = card.querySelector('.bpi-edit-panel-toggle');
        if (!panel || !toggle) {
            return;
        }

        card.classList.toggle('bpi-transform-card-edit-open', isOpen);
        panel.hidden = !isOpen;
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        toggle.textContent = isOpen ? 'Close options' : 'Edit options';
    }

    function setupEditPanelToggle() {
        if (!elements.visualResults) {
            return;
        }

        elements.visualResults.addEventListener('click', (event) => {
            const toggle = event.target.closest('.bpi-edit-panel-toggle');
            if (!toggle || !elements.visualResults.contains(toggle)) {
                return;
            }

            const card = toggle.closest('.bpi-transform-card');
            if (!card) {
                return;
            }

            const transformName = card.getAttribute('data-transform') || '';
            if (!transformName) {
                return;
            }

            event.preventDefault();

            const isOpen = state.editPanelOpenTransforms.has(transformName) === false;
            if (isOpen) {
                state.editPanelOpenTransforms.add(transformName);
            } else {
                state.editPanelOpenTransforms.delete(transformName);
            }

            setTransformEditPanelOpen(card, isOpen);
        });
    }

    function renderVisualResults(result) {
        if (!elements.visualResults) {
            return;
        }

        const transformNames = Object.keys(result?.proposals || {}).sort();
        if (transformNames.length < 1) {
            elements.visualResults.innerHTML = '<div class="bpi-empty-state light">No transforms found in results.</div>';
            return;
        }

        const cardsMarkup = transformNames.map((transformName) => {
            const transformAssetCount = result?.transforms?.[transformName]?.assetIds?.length || 0;
            const editsCount = result?.proposals?.[transformName]?.edits?.length || 0;
            const transformBreakpoints = getBreakpointsForObservedTransform(
                transformName,
                Array.isArray(result?.breakpoints) ? result.breakpoints : [],
                result?._rowsByBreakpoint || {}
            );
            const breakpointColumnWidths = calculateBreakpointColumnWidths(transformBreakpoints);
            const breakpointColumns = transformBreakpoints
                .map((breakpoint) => renderBreakpointColumn(result, transformName, breakpoint, breakpointColumnWidths))
                .join('');
            const isEditPanelOpen = state.editPanelOpenTransforms.has(transformName);
            const editPanelId = getEditPanelId(transformName);

            return `
                <section class="bpi-transform-card ${isEditPanelOpen ? 'bpi-transform-card-edit-open' : ''}" data-transform="${escapeHtml(transformName)}">
                    <header class="bpi-transform-card-header">
                        <div class="bpi-transform-name">${escapeHtml(transformName)}</div>
                        <div class="bpi-transform-header-actions">
                            <div class="bpi-transform-stats">${transformAssetCount} assets | ${editsCount} edits</div>
                            <button
                                type="button"
                                class="btn small bpi-edit-panel-toggle"
                                aria-expanded="${isEditPanelOpen ? 'true' : 'false'}"
                                aria-controls="${editPanelId}"
                            >${isEditPanelOpen ? 'Close options' : 'Edit options'}</button>
                        </div>
                    </header>
                    <div class="bpi-breakpoint-grid">${breakpointColumns}</div>
                    <section class="bpi-transform-edit-panel" id="${editPanelId}" ${isEditPanelOpen ? '' : 'hidden'}>
                        <div class="bpi-transform-edit-panel-copy">Editing options will appear here.</div>
                    </section>
                </section>
            `;
        }).join('');

        elements.visualResults.innerHTML = cardsMarkup;
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

    function renderResultReview(result) {
        renderWarningsPanel(result);
        renderVisualResults(result);
    }

    function publishResult(result) {
        state.lastResult = result;
        updateCopyButtonVisibility();
        renderResultReview(result);

        document.dispatchEvent(new CustomEvent('bpi:processing-result', {
            detail: result
        }));
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
            await ensurePreviewFrame(sourceUrl, false);

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
                        setStatus(`Waiting. Probably on transforms. ${pendingCount} image${pendingCount === 1 ? '' : 's'} still pending at ${breakpoint}px. Click Quit Waiting to stop.`);
                    },
                    onWaitingTick: ({ pendingCount, waitedMs }) => {
                        setStatus(`Waiting. Probably on transforms. ${pendingCount} image${pendingCount === 1 ? '' : 's'} still pending at ${breakpoint}px (${Math.ceil(waitedMs / 1000)}s). Click Quit Waiting to stop.`);
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
            Object.defineProperty(result, '_rowsByBreakpoint', {
                value: rowsByBreakpoint,
                enumerable: false,
                writable: false,
                configurable: false,
            });
            publishResult(result);
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
                setStatus('Loading preview...');
                const firstMeasurementWidth = getFirstBreakpointMeasurementWidth();
                if (firstMeasurementWidth !== null) {
                    await setPreviewWidth(firstMeasurementWidth);
                }

                const sourceUrl = await resolveSelectedEntryUrl();
                await ensurePreviewFrame(sourceUrl, false);
                setStatus('Preview opened.');
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
            setStatus('Loading preview...');
            const firstMeasurementWidth = getFirstBreakpointMeasurementWidth();
            if (firstMeasurementWidth !== null) {
                await setPreviewWidth(firstMeasurementWidth);
            }
            const sourceUrl = await resolveSelectedEntryUrl();
            await ensurePreviewFrame(sourceUrl, false);
            setStatus('Preview loaded from source entry.');
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
    setupEditPanelToggle();
    window.addEventListener('resize', scheduleBreakpointPreviewHeightSync);
    getConfiguredBreakpoints();
    void loadInitialPreview();
})();
