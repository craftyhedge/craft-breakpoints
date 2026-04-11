(() => {
    const BREAKPOINT_SAFETY_PX = 2;
    const PROCESSING_QUERY_PARAM = '__bpiProcessing';
    const PREVIEW_FRAME_TAG = 'ifr' + 'ame';

    const bpiProcessingManifest = window.bpiProcessingManifest || {};

    const elements = {
        url: document.getElementById('bpi-process-url'),
        status: document.getElementById('bpi-status'),
        framePane: document.getElementById('bpi-frame-pane'),
        wrapper: document.getElementById('bpi-frame-wrapper'),
        warnings: document.getElementById('bpi-warnings'),
        visualResults: document.getElementById('bpi-visual-results'),
        resultsMeta: document.getElementById('bpi-results-meta'),
        btnOpenPreview: document.getElementById('bpi-open-preview'),
        btnLoad: document.getElementById('bpi-load-preview'),
        btnRun: document.getElementById('bpi-run-processing'),
        btnRerun: document.getElementById('bpi-rerun-processing'),
        btnRefresh: document.getElementById('bpi-refresh-preview'),
        btnClosePreview: document.getElementById('bpi-close-preview'),
        btnCopy: document.getElementById('bpi-copy-output')
    };

    if (!elements.url || !elements.wrapper) {
        return;
    }

    const state = {
        previewFrame: null,
        previewUrl: null,
        lastResult: null,
        runCount: 0,
        busy: false,
        previewVisible: false
    };

    function setStatus(message) {
        if (elements.status) {
            elements.status.textContent = message;
        }
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
        if (elements.btnLoad) {
            elements.btnLoad.disabled = disabled;
        }
        if (elements.btnRun) {
            elements.btnRun.disabled = disabled;
        }
        if (elements.btnRerun) {
            elements.btnRerun.disabled = disabled;
        }
        if (elements.btnRefresh) {
            elements.btnRefresh.disabled = disabled;
        }
    }

    function setPreviewVisibility(isVisible) {
        state.previewVisible = Boolean(isVisible);

        if (elements.framePane) {
            elements.framePane.classList.toggle('is-visible', state.previewVisible);
            elements.framePane.classList.toggle('is-hidden', !state.previewVisible);
        }

        if (elements.btnOpenPreview) {
            elements.btnOpenPreview.disabled = state.previewVisible;
        }
    }

    function normalizeUrl(rawUrl) {
        const input = String(rawUrl || '').trim();
        if (!input) {
            throw new Error('Source URL is required.');
        }
        return new URL(input, window.location.origin).toString();
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

    async function waitForImagesToSettle(timeoutMs = 4000) {
        const frameDocument = getFrameDocument();
        const images = Array.from(frameDocument.querySelectorAll('picture[data-transform] img'));

        if (!images.length) {
            await new Promise((resolve) => requestAnimationFrame(resolve));
            return;
        }

        // Lazy/offscreen images may never fire load during processing. Only wait on
        // images that have started fetching so we do not hit timeout every breakpoint.
        const candidates = images.filter((img) => {
            if (img.complete) {
                return false;
            }

            if (img.currentSrc && !img.currentSrc.startsWith('data:image')) {
                return true;
            }

            return img.loading !== 'lazy';
        });

        const waiters = candidates.map((img) => new Promise((resolve) => {
            if (img.complete && (img.naturalWidth > 0 || img.naturalHeight > 0 || img.currentSrc)) {
                resolve();
                return;
            }

            const onDone = () => {
                img.removeEventListener('load', onDone);
                img.removeEventListener('error', onDone);
                resolve();
            };

            img.addEventListener('load', onDone, { once: true });
            img.addEventListener('error', onDone, { once: true });
        }));

        await Promise.race([
            Promise.all(waiters),
            new Promise((resolve) => window.setTimeout(resolve, timeoutMs))
        ]);

        await new Promise((resolve) => requestAnimationFrame(resolve));
        await new Promise((resolve) => requestAnimationFrame(resolve));
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
            const source = picture?.querySelector(`source[data-bp-size="${breakpoint}"]`);
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
                loaded: enabled ? (preloadLoaded ?? loadedFromElement) : true,
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
        });
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

    function buildCurrentProposal(transformName, breakpoints) {
        const manifestTransforms = bpiProcessingManifest?.transforms || {};
        const transformConfig = manifestTransforms[transformName] || {};
        const transformEntries = Array.isArray(transformConfig.transforms) ? transformConfig.transforms : [];

        return {
            includeEscapeWidth: transformConfig.includeEscapeWidth === true,
            transforms: breakpoints.map((breakpoint, index) => {
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

    function buildSuggestedProposal(transformName, breakpoints, rowsByBreakpoint, currentProposal) {
        return {
            includeEscapeWidth: currentProposal.includeEscapeWidth,
            transforms: breakpoints.map((breakpoint, index) => {
                const rows = (rowsByBreakpoint[breakpoint] || []).filter((row) => row.transform === transformName);
                const suggestedDimensions = pickSuggestedDimensions(rows);
                const current = currentProposal.transforms[index] || {
                    width: null,
                    height: null,
                    enabled: true,
                    autoDimension: null
                };

                return {
                    breakpoint,
                    width: suggestedDimensions.width ?? current.width,
                    height: suggestedDimensions.height ?? current.height,
                    enabled: current.enabled,
                    autoDimension: current.autoDimension
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
            const current = buildCurrentProposal(transformName, breakpoints);
            const suggested = buildSuggestedProposal(transformName, breakpoints, rowsByBreakpoint, current);
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

    function renderBreakpointColumn(result, transformName, breakpoint, index) {
        const breakpoints = Array.isArray(result?.breakpoints) ? result.breakpoints : [];
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

        const hiddenBadge = summary.hiddenCount > 0
            ? `<span class="bpi_hidden-notice">Hidden ${summary.hiddenCount}</span>`
            : '';
        const unloadedBadge = summary.unloadedCount > 0
            ? `<span class="bpi-row-badge">Unloaded ${summary.unloadedCount}</span>`
            : '';
        const escapeBadge = index === breakpoints.length - 1
            ? '<span class="bpi_escaped-notice">ESC</span>'
            : '';

        return `
            <div class="bpi-breakpoint-column">
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
                ? `<img src="${previewSrc}" alt="${escapeHtml(previewAlt)}" class="bpi_breakpoint-result-image" style="--bpi-aspect-ratio:${aspectRatio};">`
                : `<div class="bpi_breakpoint-result-image" style="--bpi-aspect-ratio:${aspectRatio};"></div>`}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bpi_rendered-dimensions">
                    <span>Rendered</span>
                    <span class="bpi_rendered-info-container">
                        <span class="bpi_rendered-dimension ${widthClass}">${renderedWidth || '-'}</span>
                        <span>:</span>
                        <span class="bpi_rendered-dimension ${heightClass}">${renderedHeight || '-'}</span>
                    </span>
                </div>
                <div class="bpi-current-suggested">
                    <div>Current ${formatDimensionPair(currentProposal.width, currentProposal.height, currentProposal.autoDimension || null)}</div>
                </div>
            </div>
        `;
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
            const breakpointColumns = (result.breakpoints || [])
                .map((breakpoint, index) => renderBreakpointColumn(result, transformName, breakpoint, index))
                .join('');

            return `
                <section class="bpi-transform-card" data-transform="${escapeHtml(transformName)}">
                    <header class="bpi-transform-card-header">
                        <div class="bpi-transform-name">${escapeHtml(transformName)}</div>
                        <div class="bpi-transform-stats">${transformAssetCount} assets | ${editsCount} edits</div>
                    </header>
                    <div class="bpi-breakpoint-grid">${breakpointColumns}</div>
                </section>
            `;
        }).join('');

        elements.visualResults.innerHTML = cardsMarkup;
    }

    function renderResultReview(result) {
        renderWarningsPanel(result);
        renderVisualResults(result);

        if (elements.resultsMeta) {
            const summary = result?.summary || {};
            const warningCount = Number(summary.warningCount) || 0;
            const warningsText = warningCount > 0 ? `${warningCount} warnings` : 'no warnings';
            elements.resultsMeta.textContent = `${summary.assetCount || 0} assets, ${summary.breakpointCount || 0} breakpoints, ${warningsText}`;
        }
    }

    function publishResult(result) {
        state.lastResult = result;
        renderResultReview(result);

        document.dispatchEvent(new CustomEvent('bpi:processing-result', {
            detail: result
        }));
    }

    async function runProcessing(useRefresh = false) {
        if (state.busy) {
            return;
        }

        const sourceUrl = elements.url.value;
        const breakpoints = getConfiguredBreakpoints();

        if (!breakpoints.length) {
            setStatus('No configured breakpoints available. Check plugin settings.');
            return;
        }

        state.busy = true;
        setButtonsDisabled(true);
        setStatus('Preparing preview...');

        const startedAt = Date.now();

        try {
            getOrCreatePreviewFrame();
            await ensurePreviewFrame(sourceUrl, useRefresh);

            const rowsByBreakpoint = {};
            for (const breakpoint of breakpoints) {
                const measurementWidth = getMeasurementWidthForBreakpoint(breakpoint);
                setStatus(`Processing ${breakpoint}px...`);
                await setPreviewWidth(measurementWidth);
                const preloadStates = await preloadBreakpointSources(breakpoint);
                await waitForImagesToSettle();
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
            setButtonsDisabled(false);
        }
    }

    if (elements.btnLoad) {
        elements.btnLoad.addEventListener('click', async () => {
            try {
                setStatus('Loading preview...');
                const firstMeasurementWidth = getFirstBreakpointMeasurementWidth();
                if (firstMeasurementWidth !== null) {
                    await setPreviewWidth(firstMeasurementWidth);
                }
                await ensurePreviewFrame(elements.url.value, false);
                setStatus('Preview loaded. Ready to process.');
            } catch (error) {
                setStatus(`Error: ${error.message}`);
            }
        });
    }

    if (elements.btnRun) {
        elements.btnRun.addEventListener('click', async () => {
            await runProcessing(false);
        });
    }

    if (elements.btnRerun) {
        elements.btnRerun.addEventListener('click', async () => {
            await runProcessing(false);
        });
    }

    if (elements.btnRefresh) {
        elements.btnRefresh.addEventListener('click', async () => {
            await runProcessing(true);
        });
    }

    if (elements.btnCopy) {
        elements.btnCopy.addEventListener('click', async () => {
            const text = JSON.stringify(state.lastResult || {}, null, 2);
            try {
                await navigator.clipboard.writeText(text);
                setStatus('Structured output copied to clipboard.');
            } catch (error) {
                setStatus('Copy failed.');
            }
        });
    }

    if (elements.btnOpenPreview) {
        elements.btnOpenPreview.addEventListener('click', () => {
            setPreviewVisibility(true);
            setStatus('Preview opened.');
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
        const initialUrl = String(elements.url.value || '').trim();
        if (!initialUrl) {
            setStatus('Enter a Source URL and click Load Preview.');
            return;
        }

        try {
            setStatus('Loading preview...');
            const firstMeasurementWidth = getFirstBreakpointMeasurementWidth();
            if (firstMeasurementWidth !== null) {
                await setPreviewWidth(firstMeasurementWidth);
            }
            await ensurePreviewFrame(initialUrl, false);
            setStatus('Preview loaded from Source URL.');
        } catch (error) {
            setStatus(`Error: ${error.message}`);
        }
    }

    setPreviewVisibility(false);
    getConfiguredBreakpoints();
    void loadInitialPreview();
})();
