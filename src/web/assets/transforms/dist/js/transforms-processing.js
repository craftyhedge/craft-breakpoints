export const PROCESSABLE_PICTURE_SELECTOR = 'picture[data-set]:not([data-bp-processing-ignore])';
export const PROCESSABLE_IMAGE_SELECTOR = `${PROCESSABLE_PICTURE_SELECTOR} img`;

export function getMeasurementWidthForBreakpoint(breakpoint, safetyPx = 1) {
    const parsed = parseInt(String(breakpoint), 10);
    if (!Number.isFinite(parsed) || parsed <= 1) {
        return 1;
    }

    return Math.max(1, parsed - Math.max(0, Number(safetyPx) || 0));
}

function getProcessingRowIdentity(row) {
    const pictureId = String(row?.pictureId || '').trim();
    if (pictureId !== '') {
        return `picture:${pictureId}`;
    }

    const transform = String(row?.transform || '').trim();
    const assetId = String(row?.assetId || '').trim();
    if (transform === '' && assetId === '') {
        return '';
    }

    return `asset:${transform}|${assetId}`;
}

export function selectFinalRows(normalRows, escapeRows) {
    const escapeByIdentity = new Map();

    for (const row of Array.isArray(escapeRows) ? escapeRows : []) {
        const identity = getProcessingRowIdentity(row);
        if (identity === '') {
            continue;
        }

        const matches = escapeByIdentity.get(identity) || [];
        matches.push(row);
        escapeByIdentity.set(identity, matches);
    }

    const unmatchedRows = [];
    const rows = (Array.isArray(normalRows) ? normalRows : []).map((row) => {
        const identity = getProcessingRowIdentity(row);
        const matches = identity !== '' ? escapeByIdentity.get(identity) : null;
        const escapeRow = Array.isArray(matches) ? matches.shift() : null;

        if (row?.includeEscapeWidth !== true) {
            return row;
        }

        if (!escapeRow) {
            unmatchedRows.push(row);
            return row;
        }

        return escapeRow;
    });

    escapeByIdentity.forEach((matches) => {
        matches.forEach((row) => {
            if (row?.includeEscapeWidth === true) {
                rows.push(row);
                return;
            }

            unmatchedRows.push(row);
            rows.push(row);
        });
    });

    return { rows, unmatchedRows };
}

export function isTransparentPixelSrcset(srcset) {
    const normalized = String(srcset || '').trim();
    return normalized.startsWith('data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
}

export function sanitizeIssueSource(rawSource, baseOrigin = 'http://localhost') {
    const source = String(rawSource || '').trim();
    if (source === '') {
        return '';
    }

    try {
        const url = new URL(source, baseOrigin);
        return `${url.origin}${url.pathname}`;
    } catch (_error) {
        const queryStripped = source.split('?')[0] || source;
        return queryStripped.split('#')[0] || queryStripped;
    }
}

export function createRunReport({
    sourceUrl,
    breakpoints,
    diagnosticsEnabled,
    startedAtMs = Date.now(),
    schemaVersion = 1,
    sanitizeSource = (value) => String(value || '').trim(),
}) {
    const runId = `run-${startedAtMs}-${Math.random().toString(36).slice(2, 8)}`;

    const report = {
        schemaVersion,
        runId,
        startedAt: new Date(startedAtMs).toISOString(),
        completedAt: null,
        durationMs: 0,
        status: 'running',
        resultPublished: false,
        sourceUrl: sanitizeSource(sourceUrl),
        totals: {
            breakpointCount: Array.isArray(breakpoints) ? breakpoints.length : 0,
            rowCount: 0,
            loadedTotal: 0,
            brokenTotal: 0,
            unresolvedTotal: 0,
            warningCount: 0,
            errorCount: 0,
            issueCount: 0,
        },
        breakpoints: [],
        issues: [],
        issueOverflowCount: 0,
        _startedAtMs: startedAtMs,
    };

    if (diagnosticsEnabled) {
        report.authorDiagnostics = {
            stageTimings: [],
            activationTrace: [],
            normalizationSamples: [],
            readinessSnapshots: [],
            failure: null,
        };
    }

    return report;
}

export function createBreakpointReportEntry(breakpoint) {
    return {
        breakpointKey: String(breakpoint),
        width: Number.isFinite(Number(breakpoint)) ? Number(breakpoint) : null,
        status: 'skipped',
        activationStrategies: [],
        normalizationCount: 0,
        waitDurationMs: 0,
        loadedCount: 0,
        brokenCount: 0,
        unresolvedCount: 0,
        issueCount: 0,
    };
}

export function inspectProcessingMarkerHealth(frameDocument) {
    const document = frameDocument && typeof frameDocument.querySelectorAll === 'function'
        ? frameDocument
        : null;

    if (!document) {
        return {
            trackedPictureCount: 0,
            pictureCount: 0,
            imageCount: 0,
            hasImageMarkup: false,
            missingMarkers: false,
        };
    }

    const trackedPictureCount = document.querySelectorAll(PROCESSABLE_PICTURE_SELECTOR).length;
    const ignoredPictureCount = document.querySelectorAll('picture[data-bp-processing-ignore]').length;
    const pictureCount = document.querySelectorAll('picture').length;
    const imageCount = document.querySelectorAll('img').length;
    const hasImageMarkup = pictureCount > 0 || imageCount > 0;
    const hasOnlyIgnoredImageMarkup = ignoredPictureCount > 0 && pictureCount === ignoredPictureCount;

    return {
        trackedPictureCount,
        pictureCount,
        imageCount,
        hasImageMarkup,
        missingMarkers: hasImageMarkup && trackedPictureCount === 0 && !hasOnlyIgnoredImageMarkup,
    };
}

export function appendRunIssue({
    report,
    issue,
    breakpointReport = null,
    issueLimit = 200,
    sanitizeSource = (value) => String(value || '').trim(),
}) {
    if (!report || typeof report !== 'object') {
        return;
    }

    const normalized = {
        severity: issue?.severity === 'error' ? 'error' : 'warning',
        code: String(issue?.code || 'processing-issue'),
        message: String(issue?.message || 'Processing issue detected.'),
    };

    if (issue?.breakpointWidth !== undefined && issue?.breakpointWidth !== null) {
        const parsedWidth = parseInt(String(issue.breakpointWidth), 10);
        if (Number.isFinite(parsedWidth) && parsedWidth > 0) {
            normalized.breakpointWidth = parsedWidth;
        }
    }

    if (issue?.assetId !== undefined && issue?.assetId !== null) {
        const assetId = String(issue.assetId).trim();
        if (assetId !== '') {
            normalized.assetId = assetId;
        }
    }

    if (issue?.source !== undefined && issue?.source !== null) {
        const source = sanitizeSource(issue.source);
        if (source !== '') {
            normalized.source = source;
        }
    }

    report.totals.issueCount += 1;
    if (normalized.severity === 'error') {
        report.totals.errorCount += 1;
    } else {
        report.totals.warningCount += 1;
    }

    if (breakpointReport) {
        breakpointReport.issueCount += 1;
    }

    if (report.issues.length < issueLimit) {
        report.issues.push(normalized);
    } else {
        report.issueOverflowCount += 1;
    }
}

export function finalizeRunReport(report, {
    status,
    rowsByBreakpoint,
    resultPublished,
    failureStage = null,
    failureMessage = null,
    nowMs = () => Date.now(),
    nowIso = () => new Date().toISOString(),
}) {
    const normalizedRowsByBreakpoint = rowsByBreakpoint && typeof rowsByBreakpoint === 'object'
        ? rowsByBreakpoint
        : {};

    let rowCount = 0;
    let loadedTotal = 0;
    let brokenTotal = 0;
    let unresolvedTotal = 0;

    Object.values(normalizedRowsByBreakpoint).forEach((rows) => {
        if (!Array.isArray(rows)) {
            return;
        }

        rowCount += rows.length;
        rows.forEach((row) => {
            if (!row || typeof row !== 'object') {
                return;
            }

            if (row.loaded === true) {
                loadedTotal += 1;
            } else if (row.broken === true) {
                brokenTotal += 1;
            } else if (row.unresolved === true) {
                unresolvedTotal += 1;
            }
        });
    });

    const nowValue = nowMs();
    report.status = status;
    report.resultPublished = resultPublished === true;
    report.completedAt = nowIso();
    report.durationMs = Math.max(0, nowValue - (report._startedAtMs || nowValue));
    report.totals.rowCount = rowCount;
    report.totals.loadedTotal = loadedTotal;
    report.totals.brokenTotal = brokenTotal;
    report.totals.unresolvedTotal = unresolvedTotal;

    if (status === 'failed') {
        report.failure = {
            stage: failureStage || 'unknown',
            message: String(failureMessage || 'Processing failed.'),
        };
    }

    if (status === 'cancelled') {
        report.failure = {
            stage: failureStage || 'wait-images',
            message: String(failureMessage || 'Processing was cancelled by the user.'),
        };
    }

    delete report._startedAtMs;
    return report;
}

export function normalizeLazyAttribute(target, {
    dataAttr,
    targetAttr,
    forceWhenDataUri = false,
    replaceExisting = false,
}) {
    const sourceValue = String(target.getAttribute(dataAttr) || '').trim();
    if (sourceValue === '') {
        return false;
    }

    const currentValue = String(target.getAttribute(targetAttr) || '').trim();
    if (currentValue !== '' && !replaceExisting) {
        if (forceWhenDataUri && currentValue.startsWith('data:image')) {
            target.setAttribute(targetAttr, sourceValue);
            return true;
        }

        if (!forceWhenDataUri) {
            return false;
        }

        if (!currentValue.startsWith('data:image')) {
            return false;
        }
    }

    target.setAttribute(targetAttr, sourceValue);
    return true;
}

export function isImageRenderable(img) {
    return (img.naturalWidth > 0) || (img.naturalHeight > 0);
}

export function isImageLikelyBroken(img, isRenderable = isImageRenderable) {
    if (!img.complete) {
        return false;
    }

    if (isRenderable(img)) {
        return false;
    }

    const candidate = String(img.currentSrc || img.getAttribute('src') || '').trim();
    return candidate !== '';
}

export function deriveSourceUsed(source, img) {
    return String(
        img?.currentSrc
        || source?.getAttribute('srcset')
        || source?.getAttribute('src')
        || img?.getAttribute('src')
        || ''
    ).trim();
}

function normalizeSourceUrl(value, baseUrl = '') {
    const source = String(value || '').trim();
    if (source === '' || source.startsWith('data:')) {
        return '';
    }

    try {
        return new URL(source, baseUrl || undefined).href;
    } catch (_error) {
        return source;
    }
}

function extractSrcsetUrls(srcset, baseUrl = '') {
    return String(srcset || '')
        .split(',')
        .map((candidate) => candidate.trim().split(/\s+/)[0] || '')
        .map((candidate) => normalizeSourceUrl(candidate, baseUrl))
        .filter((candidate) => candidate !== '');
}

function collectLazyTargetUrls(source, img, baseUrl = '', attributes = null) {
    const attributeMap = attributes && typeof attributes === 'object' ? attributes : {};
    const srcAttribute = String(attributeMap.src || 'data-src');
    const srcsetAttribute = String(attributeMap.srcset || 'data-srcset');
    const targets = [
        ...extractSrcsetUrls(source?.getAttribute(srcsetAttribute), baseUrl),
        normalizeSourceUrl(source?.getAttribute(srcAttribute), baseUrl),
        ...extractSrcsetUrls(img?.getAttribute(srcsetAttribute), baseUrl),
        normalizeSourceUrl(img?.getAttribute(srcAttribute), baseUrl),
    ].filter((candidate) => candidate !== '');

    return Array.from(new Set(targets));
}

function collectSlotLazyTargetUrls(picture, primarySource, img, baseUrl = '', attributes = null) {
    const slotKey = String(primarySource?.getAttribute('data-bp-key') || '').trim();
    const slotIndex = String(primarySource?.getAttribute('data-bp-index') || '').trim();
    const slotSources = Array.from(picture?.querySelectorAll?.('source') || []).filter((source) => {
        if (source === primarySource) {
            return true;
        }

        const sourceKey = String(source.getAttribute('data-bp-key') || '').trim();
        const sourceIndex = String(source.getAttribute('data-bp-index') || '').trim();
        return (slotKey !== '' && sourceKey === slotKey)
            || (slotIndex !== '' && sourceIndex === slotIndex);
    });
    const sources = slotSources.length > 0 ? slotSources : [primarySource].filter(Boolean);
    const targets = sources.flatMap((source, index) => collectLazyTargetUrls(
        source,
        index === 0 ? img : null,
        baseUrl,
        attributes,
    ));

    return Array.from(new Set(targets));
}

function sourceMatchesLazyTarget(sourceUsed, lazyTargetUrls, baseUrl = '') {
    if (!Array.isArray(lazyTargetUrls) || lazyTargetUrls.length < 1) {
        return true;
    }

    const normalizedSource = normalizeSourceUrl(sourceUsed, baseUrl);
    return normalizedSource !== '' && lazyTargetUrls.includes(normalizedSource);
}

function hasLazyTargets(entry) {
    return Array.isArray(entry?.lazyTargetUrls) && entry.lazyTargetUrls.length > 0;
}

function isLazyTargetNotPromotedCandidate(entry, sourceUsed, baseUrl = '', isRenderable = isImageRenderable) {
    return Boolean(
        entry
        && hasLazyTargets(entry)
        && entry.preloadLoaded === true
        && entry.img?.complete === true
        && isSubstantialRenderableImage(entry.img, isRenderable)
        && !sourceMatchesLazyTarget(sourceUsed, entry.lazyTargetUrls, baseUrl)
    );
}

function getElementSourceState(entry, baseUrl = '') {
    const source = entry?.source || null;
    const img = entry?.img || null;
    const currentSrc = String(img?.currentSrc || '').trim();
    const sourceSrcset = String(source?.getAttribute?.('srcset') || '').trim();
    const sourceDataSrcset = String(source?.getAttribute?.('data-srcset') || '').trim();
    const sourceSrc = String(source?.getAttribute?.('src') || '').trim();
    const sourceDataSrc = String(source?.getAttribute?.('data-src') || '').trim();
    const imgSrc = String(img?.getAttribute?.('src') || '').trim();
    const imgDataSrc = String(img?.getAttribute?.('data-src') || '').trim();
    const imgSrcset = String(img?.getAttribute?.('srcset') || '').trim();
    const imgDataSrcset = String(img?.getAttribute?.('data-srcset') || '').trim();
    const derivedSource = String(
        currentSrc
        || sourceSrcset
        || sourceSrc
        || imgSrc
        || ''
    ).trim();

    return {
        currentSrc,
        sourceSrcset,
        sourceDataSrcset,
        sourceSrc,
        sourceDataSrc,
        imgSrc,
        imgDataSrc,
        imgSrcset,
        imgDataSrcset,
        derivedSource,
        derivedMatchesLazyTarget: sourceMatchesLazyTarget(derivedSource, entry?.lazyTargetUrls || [], baseUrl),
    };
}

function truncateDiagnosticValue(value, maxLength = 220) {
    const normalized = String(value || '').trim();
    if (normalized.length <= maxLength) {
        return normalized;
    }

    return `${normalized.slice(0, maxLength - 3)}...`;
}

async function activateLazySizesInsideFrame(frameWindow) {
    if (!frameWindow || typeof frameWindow.Function !== 'function') {
        return null;
    }

    const selectors = [
        `${PROCESSABLE_IMAGE_SELECTOR}.lazyload`,
        `${PROCESSABLE_IMAGE_SELECTOR}[data-src]`,
        `${PROCESSABLE_IMAGE_SELECTOR}[data-srcset]`,
        `${PROCESSABLE_PICTURE_SELECTOR} source[data-srcset] ~ img`,
        `${PROCESSABLE_PICTURE_SELECTOR} source[data-sizes] ~ img`,
    ];

    try {
        // Run lazysizes from the iframe realm so its document, window, timers, and events match the preview it owns.
        const runInFrame = frameWindow.Function('selectors', `
            return (async function () {
                var lazySizes = window.lazySizes;
                if (!lazySizes || typeof lazySizes !== 'object') {
                    return { activated: false, reason: 'missing-lazysizes' };
                }

                var samples = [];
                var eventCounts = {
                    lazybeforeunveil: 0,
                    lazyunveilread: 0,
                    lazyloaded: 0,
                    lazybeforeunveilPrevented: 0
                };
                var strategyCount = 0;
                var maxSamples = 24;
                var truncate = function (value, maxLength) {
                    var normalized = String(value || '').trim();
                    var limit = maxLength || 220;
                    return normalized.length <= limit ? normalized : normalized.slice(0, limit - 3) + '...';
                };
                var pushSample = function (sample) {
                    if (samples.length < maxSamples) {
                        samples.push(sample);
                    }
                };
                var recordLazyEvent = function (event) {
                    var type = String(event && event.type || '');
                    if (!Object.prototype.hasOwnProperty.call(eventCounts, type)) {
                        return;
                    }

                    eventCounts[type] += 1;
                    if (type === 'lazybeforeunveil' && event.defaultPrevented === true) {
                        eventCounts.lazybeforeunveilPrevented += 1;
                    }
                };
                var nextFrame = function () {
                    return new Promise(function (resolve) {
                        if (typeof window.requestAnimationFrame === 'function') {
                            window.requestAnimationFrame(function () { resolve(); });
                            return;
                        }

                        window.setTimeout(resolve, 0);
                    });
                };

                document.addEventListener('lazybeforeunveil', recordLazyEvent, true);
                document.addEventListener('lazyunveilread', recordLazyEvent, true);
                document.addEventListener('lazyloaded', recordLazyEvent, true);

                var originalConfig = lazySizes.cfg && typeof lazySizes.cfg === 'object'
                    ? {
                        loadMode: lazySizes.cfg.loadMode,
                        expand: lazySizes.cfg.expand,
                        expFactor: lazySizes.cfg.expFactor,
                        hFac: lazySizes.cfg.hFac,
                        loadHidden: lazySizes.cfg.loadHidden
                    }
                    : null;

                if (lazySizes.cfg && typeof lazySizes.cfg === 'object') {
                    lazySizes.cfg.loadMode = 3;
                    lazySizes.cfg.expand = 999999;
                    lazySizes.cfg.expFactor = 1;
                    lazySizes.cfg.hFac = 1;
                    lazySizes.cfg.loadHidden = true;
                }

                pushSample({
                    adapter: 'lazysizes',
                    action: 'iframe-api',
                    element: 'lazySizes',
                    loaderKeys: Object.keys(lazySizes.loader || {}).slice(0, 16)
                });

                if (lazySizes.loader && typeof lazySizes.loader.checkElems === 'function') {
                    lazySizes.loader.checkElems(true);
                    strategyCount += 1;
                }

                if (lazySizes.autoSizer && typeof lazySizes.autoSizer.checkElems === 'function') {
                    lazySizes.autoSizer.checkElems();
                    strategyCount += 1;
                }

                if (lazySizes.loader && typeof lazySizes.loader.unveil === 'function') {
                    Array.from(document.querySelectorAll(selectors.join(', '))).forEach(function (img) {
                        try {
                            var hadLazyRace = img._lazyRace === true;
                            var hadLazyCache = img._lazyCache === true;
                            var picture = img.closest('picture');
                            var sourceDataSrcsetBefore = picture ? picture.querySelectorAll('source[data-srcset]').length : 0;
                            var firstSource = picture ? picture.querySelector('source') : null;
                            var firstSourceSrcsetBefore = String(firstSource && firstSource.getAttribute('srcset') || '');
                            var classNameBefore = String(img.getAttribute('class') || '');
                            delete img._lazyRace;
                            delete img._lazyCache;
                            img.classList.remove('lazyloaded');
                            img.classList.remove('lazyloading');
                            img.classList.remove('ls-is-cached');
                            img.classList.add('lazyload');

                            lazySizes.loader.unveil(img);
                            strategyCount += 1;

                            var firstSourceSrcsetAfter = String(firstSource && firstSource.getAttribute('srcset') || '');
                            pushSample({
                                adapter: 'lazysizes',
                                action: 'iframe-unveil',
                                element: 'img',
                                classNameBefore: classNameBefore,
                                className: String(img.getAttribute('class') || ''),
                                hasDataSrc: img.hasAttribute('data-src'),
                                hasDataSrcset: img.hasAttribute('data-srcset'),
                                sourceDataSrcsetCount: sourceDataSrcsetBefore,
                                sourcePromotedSync: firstSourceSrcsetBefore !== firstSourceSrcsetAfter,
                                hadLazyRace: hadLazyRace,
                                hadLazyCache: hadLazyCache
                            });
                        } catch (error) {
                            pushSample({
                                adapter: 'lazysizes',
                                action: 'iframe-unveil-error',
                                element: 'img',
                                message: String(error && error.message || '')
                            });
                        }
                    });
                }

                if (lazySizes.loader && typeof lazySizes.loader.checkElems === 'function') {
                    lazySizes.loader.checkElems(true);
                    strategyCount += 1;
                }

                if (lazySizes.autoSizer && typeof lazySizes.autoSizer.checkElems === 'function') {
                    lazySizes.autoSizer.checkElems();
                    strategyCount += 1;
                }

                await nextFrame();
                await nextFrame();

                document.removeEventListener('lazybeforeunveil', recordLazyEvent, true);
                document.removeEventListener('lazyunveilread', recordLazyEvent, true);
                document.removeEventListener('lazyloaded', recordLazyEvent, true);

                pushSample({
                    adapter: 'lazysizes',
                    action: 'iframe-events',
                    element: 'document',
                    events: eventCounts,
                    config: {
                        lazyClass: String(lazySizes.cfg && lazySizes.cfg.lazyClass || ''),
                        loadingClass: String(lazySizes.cfg && lazySizes.cfg.loadingClass || ''),
                        loadedClass: String(lazySizes.cfg && lazySizes.cfg.loadedClass || ''),
                        srcAttr: String(lazySizes.cfg && lazySizes.cfg.srcAttr || ''),
                        srcsetAttr: String(lazySizes.cfg && lazySizes.cfg.srcsetAttr || ''),
                        sizesAttr: String(lazySizes.cfg && lazySizes.cfg.sizesAttr || ''),
                        loadMode: Number(lazySizes.cfg && lazySizes.cfg.loadMode),
                        expand: Number(lazySizes.cfg && lazySizes.cfg.expand),
                        expFactor: Number(lazySizes.cfg && lazySizes.cfg.expFactor),
                        hFac: Number(lazySizes.cfg && lazySizes.cfg.hFac),
                        loadHidden: Boolean(lazySizes.cfg && lazySizes.cfg.loadHidden),
                        original: originalConfig
                    }
                });

                return {
                    activated: strategyCount > 0,
                    strategyCount: strategyCount,
                    samples: samples
                };
            })();
        `);

        return await runInFrame(selectors);
    } catch (error) {
        return {
            activated: false,
            reason: 'iframe-activation-error',
            message: String(error?.message || ''),
        };
    }
}

export function buildReadinessDiagnosticsSnapshot({
    readinessByKey,
    breakpoint = null,
    slot = null,
    passKind = '',
    measurementWidth = null,
    baseUrl = '',
    maxEntries = 80,
} = {}) {
    if (!(readinessByKey instanceof Map)) {
        return [];
    }

    return Array.from(readinessByKey.values()).slice(0, maxEntries).map((entry) => {
        const sourceState = getElementSourceState(entry, baseUrl);
        const img = entry?.img || null;
        const source = entry?.source || null;

        return {
            breakpoint,
            slotKey: slot?.key || source?.getAttribute?.('data-bp-key') || null,
            passKind,
            measurementWidth,
            key: String(entry?.key || ''),
            pictureId: String(entry?.picture?.getAttribute?.('data-picture-id') || ''),
            assetId: String(img?.getAttribute?.('data-asset-id') || entry?.picture?.getAttribute?.('data-asset-id') || ''),
            transform: String(entry?.picture?.getAttribute?.('data-set') || ''),
            enabled: entry?.enabled === true,
            status: String(entry?.status || ''),
            reason: entry?.reason === null || entry?.reason === undefined ? null : String(entry.reason),
            preloadLoaded: entry?.preloadLoaded === true,
            complete: img?.complete === true,
            natural: {
                width: Number(img?.naturalWidth) || 0,
                height: Number(img?.naturalHeight) || 0,
            },
            rendered: {
                width: Number(img?.clientWidth || img?.offsetWidth) || 0,
                height: Number(img?.clientHeight || img?.offsetHeight) || 0,
            },
            sourceDimensions: {
                width: toPositiveIntOrNull(source?.getAttribute?.('data-set-width')),
                height: toPositiveIntOrNull(source?.getAttribute?.('data-set-height')),
                autoDimension: String(source?.getAttribute?.('data-auto-dimension') || '') || null,
            },
            sourceUsed: truncateDiagnosticValue(entry?.sourceUsed || ''),
            derivedSource: truncateDiagnosticValue(sourceState.derivedSource),
            derivedMatchesLazyTarget: sourceState.derivedMatchesLazyTarget,
            currentSrc: truncateDiagnosticValue(sourceState.currentSrc),
            sourceSrcset: truncateDiagnosticValue(sourceState.sourceSrcset),
            sourceDataSrcset: truncateDiagnosticValue(sourceState.sourceDataSrcset),
            imgSrc: truncateDiagnosticValue(sourceState.imgSrc),
            imgDataSrc: truncateDiagnosticValue(sourceState.imgDataSrc),
            lazyTargetUrls: Array.isArray(entry?.lazyTargetUrls)
                ? entry.lazyTargetUrls.slice(0, 4).map((url) => truncateDiagnosticValue(url))
                : [],
        };
    });
}

function isSubstantialRenderableImage(img, isRenderable = isImageRenderable) {
    if (!isRenderable(img)) {
        return false;
    }

    const naturalWidth = Number(img?.naturalWidth) || 0;
    const naturalHeight = Number(img?.naturalHeight) || 0;
    if (naturalWidth <= 1 && naturalHeight <= 1) {
        return false;
    }

    const renderedWidth = Number(img?.clientWidth || img?.offsetWidth) || 0;
    const renderedHeight = Number(img?.clientHeight || img?.offsetHeight) || 0;

    return renderedWidth > 1 || renderedHeight > 1;
}

export function createReadinessSummary(readinessByKey) {
    let loadedCount = 0;
    let brokenCount = 0;
    let unresolvedCount = 0;
    let pendingCount = 0;

    readinessByKey.forEach((entry) => {
        if (!entry || typeof entry !== 'object') {
            return;
        }

        if (entry.status === 'loaded') {
            loadedCount += 1;
        } else if (entry.status === 'broken') {
            brokenCount += 1;
        } else if (entry.status === 'unresolved') {
            unresolvedCount += 1;
        } else if (entry.status === 'pending') {
            pendingCount += 1;
        }
    });

    return {
        loadedCount,
        brokenCount,
        unresolvedCount,
        pendingCount,
    };
}

export function buildWaitingStatusMessage(breakpoint, pendingCount, waitedMs = null) {
    const imageLabel = `${pendingCount} image${pendingCount === 1 ? '' : 's'}`;
    if (Number.isFinite(waitedMs) && waitedMs > 0) {
        const seconds = Math.ceil(waitedMs / 1000);
        return `Waiting. Probably on transforms. ${imageLabel} still pending at ${breakpoint}px (${seconds}s). Click Quit Waiting to stop.`;
    }

    return `Waiting. Probably on transforms. ${imageLabel} still pending at ${breakpoint}px. Click Quit Waiting to stop.`;
}

export function pushActivationStrategy(prepareResult, strategy, count = 0) {
    if (!prepareResult || !Array.isArray(prepareResult.activationStrategies)) {
        return;
    }

    if (count > 0) {
        prepareResult.activationStrategies.push(`${strategy}:${count}`);
        return;
    }

    prepareResult.activationStrategies.push(strategy);
}

function isLazyLoadingAdapterAvailable(frameWindow, adapter) {
    if (adapter === 'lazysizes') {
        const lazySizes = frameWindow?.lazySizes;
        return Boolean(lazySizes && typeof lazySizes === 'object' && (
            typeof lazySizes.loader?.checkElems === 'function'
            || typeof lazySizes.autoSizer?.checkElems === 'function'
            || typeof lazySizes.loader?.unveil === 'function'
        ));
    }

    if (adapter === 'vanilla-lazyload') {
        return Boolean(
            frameWindow?.lazyLoadInstance
            || frameWindow?.__lazyLoadInstance
            || frameWindow?.lazyLoadInstances?.length
            || frameWindow?.__lazyLoadInstances?.length
            || typeof frameWindow?.LazyLoad?.load === 'function'
        );
    }

    if (adapter === 'lozad') {
        return Boolean(
            frameWindow?.lozadObserver
            || frameWindow?.lozadInstance
            || frameWindow?.__lozadObserver
            || frameWindow?.__lozadInstance
            || frameWindow?.lozadObservers?.length
            || frameWindow?.__lozadObservers?.length
            || typeof frameWindow?.lozad === 'function'
        );
    }

    return true;
}

async function waitForLazyLoadingAdapter(
    frameWindow,
    adapter,
    timeoutMs,
    pollIntervalMs,
    setTimeoutFn,
) {
    if (isLazyLoadingAdapterAvailable(frameWindow, adapter)) {
        return true;
    }

    const startedAt = Date.now();
    while (Date.now() - startedAt < timeoutMs) {
        await new Promise((resolve) => setTimeoutFn(resolve, pollIntervalMs));
        if (isLazyLoadingAdapterAvailable(frameWindow, adapter)) {
            return true;
        }
    }

    return false;
}

export async function activateLazySizes(frameWindow, frameDocument, prepareResult, pushStrategy = pushActivationStrategy) {
    const lazySizes = frameWindow?.lazySizes;
    if (!lazySizes || typeof lazySizes !== 'object') {
        return;
    }

    let strategyCount = 0;
    const eventCounts = {
        lazybeforeunveil: 0,
        lazyunveilread: 0,
        lazyloaded: 0,
        lazybeforeunveilPrevented: 0,
    };
    const recordActivationSample = (sample) => {
        if (!prepareResult || typeof prepareResult !== 'object') {
            return;
        }

        if (!Array.isArray(prepareResult.activationSamples)) {
            prepareResult.activationSamples = [];
        }

        if (prepareResult.activationSamples.length >= 24) {
            return;
        }

        prepareResult.activationSamples.push(sample);
    };

    const iframeActivation = await activateLazySizesInsideFrame(frameWindow);
    if (iframeActivation?.activated === true) {
        (Array.isArray(iframeActivation.samples) ? iframeActivation.samples : []).forEach(recordActivationSample);
        pushStrategy(prepareResult, 'lazysizes-iframe', Number(iframeActivation.strategyCount) || 0);
        return;
    }

    if (iframeActivation?.reason) {
        recordActivationSample({
            adapter: 'lazysizes',
            action: 'iframe-activation-unavailable',
            element: 'iframe',
            reason: String(iframeActivation.reason || ''),
            message: String(iframeActivation.message || ''),
        });
    }

    const recordLazyEvent = (event) => {
        const type = String(event?.type || '');
        if (!Object.prototype.hasOwnProperty.call(eventCounts, type)) {
            return;
        }

        eventCounts[type] += 1;
        if (type === 'lazybeforeunveil' && event?.defaultPrevented === true) {
            eventCounts.lazybeforeunveilPrevented += 1;
        }
    };

    frameDocument.addEventListener('lazybeforeunveil', recordLazyEvent, true);
    frameDocument.addEventListener('lazyunveilread', recordLazyEvent, true);
    frameDocument.addEventListener('lazyloaded', recordLazyEvent, true);

    const originalConfig = lazySizes.cfg && typeof lazySizes.cfg === 'object'
        ? {
            loadMode: lazySizes.cfg.loadMode,
            expand: lazySizes.cfg.expand,
            expFactor: lazySizes.cfg.expFactor,
            hFac: lazySizes.cfg.hFac,
            loadHidden: lazySizes.cfg.loadHidden,
        }
        : null;

    if (lazySizes.cfg && typeof lazySizes.cfg === 'object') {
        lazySizes.cfg.loadMode = 3;
        lazySizes.cfg.expand = 999999;
        lazySizes.cfg.expFactor = 1;
        lazySizes.cfg.hFac = 1;
        lazySizes.cfg.loadHidden = true;
    }

    if (typeof lazySizes.loader?.checkElems === 'function') {
        lazySizes.loader.checkElems(true);
        strategyCount += 1;
    }

    if (typeof lazySizes.autoSizer?.checkElems === 'function') {
        lazySizes.autoSizer.checkElems();
        strategyCount += 1;
    }

    if (typeof lazySizes.loader?.unveil === 'function') {
        recordActivationSample({
            adapter: 'lazysizes',
            action: 'api',
            element: 'lazySizes',
            loaderKeys: Object.keys(lazySizes.loader || {}).slice(0, 16),
        });
        const candidates = Array.from(frameDocument.querySelectorAll([
            `${PROCESSABLE_IMAGE_SELECTOR}.lazyload`,
            `${PROCESSABLE_IMAGE_SELECTOR}[data-src]`,
            `${PROCESSABLE_IMAGE_SELECTOR}[data-srcset]`,
            `${PROCESSABLE_PICTURE_SELECTOR} source[data-srcset] ~ img`,
            `${PROCESSABLE_PICTURE_SELECTOR} source[data-sizes] ~ img`,
        ].join(', ')));
        candidates.forEach((img) => {
            try {
                const hadLazyRace = img._lazyRace === true;
                const hadLazyCache = img._lazyCache === true;
                const picture = img.closest('picture');
                const sourceDataSrcsetBefore = picture?.querySelectorAll('source[data-srcset]').length || 0;
                const firstSource = picture?.querySelector('source');
                const firstSourceSrcsetBefore = String(firstSource?.getAttribute?.('srcset') || '');
                const classNameBefore = String(img.getAttribute('class') || '');
                delete img._lazyRace;
                delete img._lazyCache;
                img.classList.remove('lazyloaded');
                img.classList.remove('lazyloading');
                img.classList.remove('ls-is-cached');
                img.classList.add('lazyload');

                lazySizes.loader.unveil(img);
                strategyCount += 1;
                const firstSourceSrcsetAfter = String(firstSource?.getAttribute?.('srcset') || '');
                recordActivationSample({
                    adapter: 'lazysizes',
                    action: 'unveil',
                    element: 'img',
                    classNameBefore,
                    className: String(img.getAttribute('class') || ''),
                    hasDataSrc: img.hasAttribute('data-src'),
                    hasDataSrcset: img.hasAttribute('data-srcset'),
                    sourceDataSrcsetCount: sourceDataSrcsetBefore,
                    sourcePromotedSync: firstSourceSrcsetBefore !== firstSourceSrcsetAfter,
                    hadLazyRace,
                    hadLazyCache,
                });
            } catch (_error) {
                // Keep activation resilient. Failures are captured in readiness issues.
                recordActivationSample({
                    adapter: 'lazysizes',
                    action: 'unveil-error',
                    element: 'img',
                    message: String(_error?.message || ''),
                });
            }
        });
    }

    if (typeof lazySizes.loader?.checkElems === 'function') {
        lazySizes.loader.checkElems(true);
        strategyCount += 1;
    }

    if (typeof lazySizes.autoSizer?.checkElems === 'function') {
        lazySizes.autoSizer.checkElems();
        strategyCount += 1;
    }

    frameDocument.removeEventListener('lazybeforeunveil', recordLazyEvent, true);
    frameDocument.removeEventListener('lazyunveilread', recordLazyEvent, true);
    frameDocument.removeEventListener('lazyloaded', recordLazyEvent, true);
    recordActivationSample({
        adapter: 'lazysizes',
        action: 'events',
        element: 'document',
        events: eventCounts,
        config: {
            lazyClass: String(lazySizes.cfg?.lazyClass || ''),
            loadingClass: String(lazySizes.cfg?.loadingClass || ''),
            loadedClass: String(lazySizes.cfg?.loadedClass || ''),
            srcAttr: String(lazySizes.cfg?.srcAttr || ''),
            srcsetAttr: String(lazySizes.cfg?.srcsetAttr || ''),
            sizesAttr: String(lazySizes.cfg?.sizesAttr || ''),
            loadMode: Number(lazySizes.cfg?.loadMode),
            expand: Number(lazySizes.cfg?.expand),
            expFactor: Number(lazySizes.cfg?.expFactor),
            hFac: Number(lazySizes.cfg?.hFac),
            loadHidden: lazySizes.cfg?.loadHidden === true,
            original: originalConfig,
        },
    });

    if (strategyCount > 0) {
        pushStrategy(prepareResult, 'lazysizes', strategyCount);
    }
}

export function activateVanillaLazyLoad(frameWindow, frameDocument, prepareResult, pushStrategy = pushActivationStrategy) {
    const instanceCandidates = [
        frameWindow?.lazyLoadInstance,
        frameWindow?.__lazyLoadInstance,
    ];

    if (Array.isArray(frameWindow?.lazyLoadInstances)) {
        instanceCandidates.push(...frameWindow.lazyLoadInstances);
    }

    if (Array.isArray(frameWindow?.__lazyLoadInstances)) {
        instanceCandidates.push(...frameWindow.__lazyLoadInstances);
    }

    const instances = Array.from(new Set(instanceCandidates.filter((candidate) => candidate && typeof candidate === 'object')));
    let strategyCount = 0;

    instances.forEach((instance) => {
        if (typeof instance.update === 'function') {
            instance.update();
            strategyCount += 1;
        }

        if (typeof instance.loadAll === 'function') {
            instance.loadAll();
            strategyCount += 1;
        }

        if (typeof instance.load === 'function') {
            const candidates = Array.from(frameDocument.querySelectorAll(`${PROCESSABLE_IMAGE_SELECTOR}[data-src], ${PROCESSABLE_IMAGE_SELECTOR}[data-srcset]`));
            candidates.forEach((img) => {
                try {
                    instance.load(img);
                    strategyCount += 1;
                } catch (_error) {
                    // Keep activation resilient. Failures are captured in readiness issues.
                }
            });
        }
    });

    if (typeof frameWindow?.LazyLoad?.load === 'function') {
        const candidates = Array.from(frameDocument.querySelectorAll(`${PROCESSABLE_IMAGE_SELECTOR}[data-src], ${PROCESSABLE_IMAGE_SELECTOR}[data-srcset]`));
        candidates.forEach((img) => {
            try {
                frameWindow.LazyLoad.load(img);
                strategyCount += 1;
            } catch (_error) {
                // Keep activation resilient. Failures are captured in readiness issues.
            }
        });
    }

    if (strategyCount > 0) {
        pushStrategy(prepareResult, 'vanilla-lazyload', strategyCount);
    }
}

export function activateLozad(frameWindow, frameDocument, prepareResult, pushStrategy = pushActivationStrategy) {
    const instanceCandidates = [
        frameWindow?.lozadObserver,
        frameWindow?.lozadInstance,
        frameWindow?.__lozadObserver,
        frameWindow?.__lozadInstance,
    ];

    if (Array.isArray(frameWindow?.lozadObservers)) {
        instanceCandidates.push(...frameWindow.lozadObservers);
    }

    if (Array.isArray(frameWindow?.__lozadObservers)) {
        instanceCandidates.push(...frameWindow.__lozadObservers);
    }

    const instances = Array.from(new Set(instanceCandidates.filter((candidate) => candidate && typeof candidate === 'object')));
    const lozadElements = Array.from(frameDocument.querySelectorAll(`${PROCESSABLE_PICTURE_SELECTOR} .lozad`));
    let strategyCount = 0;

    instances.forEach((instance) => {
        if (typeof instance.observe === 'function') {
            instance.observe();
            strategyCount += 1;
        }

        if (typeof instance.triggerLoad === 'function' && lozadElements.length > 0) {
            lozadElements.forEach((el) => {
                try {
                    instance.triggerLoad(el);
                    strategyCount += 1;
                } catch (_error) {
                    // Keep activation resilient. Failures are captured in readiness issues.
                }
            });
        }
    });

    if (instances.length === 0 && typeof frameWindow?.lozad === 'function' && lozadElements.length > 0) {
        try {
            const observer = frameWindow.lozad('.lozad');
            if (observer && typeof observer.observe === 'function') {
                observer.observe();
                strategyCount += 1;
            }
            if (observer && typeof observer.triggerLoad === 'function') {
                lozadElements.forEach((element) => {
                    observer.triggerLoad(element);
                    strategyCount += 1;
                });
            }
        } catch (_error) {
            // Keep activation resilient. Failures are captured in readiness issues.
        }
    }

    if (strategyCount > 0) {
        pushStrategy(prepareResult, 'lozad', strategyCount);
    }
}

function resolveGlobalHandler(frameWindow, handlerName) {
    const segments = String(handlerName || '')
        .trim()
        .replace(/^window\./, '')
        .split('.')
        .filter((segment) => segment !== '');

    let current = frameWindow;
    for (const segment of segments) {
        current = current?.[segment];
    }

    return typeof current === 'function' ? current : null;
}

function swapLazyLoadingAttributes(pictures, attributes, prepareResult, recordNormalizationSample) {
    const attributeMap = attributes && typeof attributes === 'object' ? attributes : {};
    const rules = [
        { dataAttr: attributeMap.src || 'data-src', targetAttr: 'src', imgOnly: true },
        { dataAttr: attributeMap.srcset || 'data-srcset', targetAttr: 'srcset', imgOnly: false },
        { dataAttr: attributeMap.sizes || 'data-sizes', targetAttr: 'sizes', imgOnly: false },
    ];

    pictures.forEach((picture) => {
        const targets = [picture.querySelector('img'), ...picture.querySelectorAll('source')]
            .filter((target) => target !== null);

        targets.forEach((target) => {
            rules.forEach((rule) => {
                if (rule.imgOnly && target.tagName?.toLowerCase() !== 'img') {
                    return;
                }

                if (normalizeLazyAttribute(target, {
                    dataAttr: rule.dataAttr,
                    targetAttr: rule.targetAttr,
                    forceWhenDataUri: true,
                    replaceExisting: true,
                })) {
                    prepareResult.normalizationCount += 1;
                    recordNormalizationSample({
                        element: target.tagName?.toLowerCase() || 'unknown',
                        attr: rule.targetAttr,
                    });
                }
            });
        });
    });
}

export function activateSlotSources(pictures, slot) {
    const slotKey = String(slot?.key || '').trim();
    const slotIndex = Number.isFinite(Number(slot?.index)) ? String(slot.index) : '';
    if (slotKey === '' && slotIndex === '') {
        return 0;
    }

    let activatedCount = 0;
    (Array.isArray(pictures) ? pictures : []).forEach((picture) => {
        const sources = Array.from(picture?.querySelectorAll?.('source[data-bp-key], source[data-bp-index]') || []);
        let pictureActivated = false;

        sources.forEach((source) => {
            const sourceKey = String(source.getAttribute('data-bp-key') || '').trim();
            const sourceIndex = String(source.getAttribute('data-bp-index') || '').trim();
            const matchesSlot = (slotKey !== '' && sourceKey === slotKey)
                || (slotIndex !== '' && sourceIndex === slotIndex);

            source.setAttribute('media', matchesSlot ? 'all' : 'not all');
            pictureActivated ||= matchesSlot;
        });

        if (pictureActivated) {
            activatedCount += 1;
        }
    });

    return activatedCount;
}

export async function prepareBreakpoints({
    breakpoint,
    slot = null,
    frameDocument,
    frameWindow,
    getTrackedPictures,
    getPrimarySourceForBreakpoint,
    lazyLoading = null,
    sampleLimit = 12,
    requestAnimationFrameFn = (callback) => requestAnimationFrame(callback),
    setTimeoutFn = (callback, delay) => setTimeout(callback, delay),
    adapterWaitTimeoutMs = 2000,
    adapterPollIntervalMs = 50,
}) {
    const prepareResult = {
        activationStrategies: [],
        activationSamples: [],
        normalizationCount: 0,
        normalizationSamples: [],
        lazyTargetsByImage: new Map(),
    };

    const recordNormalizationSample = (sample) => {
        if (prepareResult.normalizationSamples.length >= sampleLimit) {
            return;
        }

        prepareResult.normalizationSamples.push(sample);
    };

    const pictures = getTrackedPictures(frameDocument);
    const lazyLoadingConfig = lazyLoading && typeof lazyLoading === 'object' ? lazyLoading : {};
    const adapter = String(lazyLoadingConfig.adapter || 'attributes').trim();
    const libraryAdapters = ['lazysizes', 'vanilla-lazyload', 'lozad'];
    const sourceTrackedAdapters = [...libraryAdapters, 'custom'];

    const activatedPictureCount = activateSlotSources(pictures, slot);
    if (activatedPictureCount > 0) {
        pushActivationStrategy(prepareResult, 'slot-source', activatedPictureCount);
    }

    if (sourceTrackedAdapters.includes(adapter)) {
        pictures.forEach((picture) => {
            const img = picture.querySelector('img');
            const source = getPrimarySourceForBreakpoint(picture, breakpoint);
            if (!img || !source) {
                return;
            }

            const lazyTargetUrls = collectSlotLazyTargetUrls(
                picture,
                source,
                img,
                frameDocument?.baseURI || '',
                adapter === 'custom' ? lazyLoadingConfig.attributes : null,
            );
            if (lazyTargetUrls.length > 0) {
                prepareResult.lazyTargetsByImage.set(img, lazyTargetUrls);
            }
        });
    }

    if (libraryAdapters.includes(adapter)) {
        const adapterAvailable = await waitForLazyLoadingAdapter(
            frameWindow,
            adapter,
            adapterWaitTimeoutMs,
            adapterPollIntervalMs,
            setTimeoutFn,
        );
        if (!adapterAvailable) {
            throw new Error(
                `Configured processing lazy-loading adapter was not found in the processing preview iframe after ${adapterWaitTimeoutMs}ms: ${adapter}`,
            );
        }
    }

    if (adapter === 'lazysizes') {
        await activateLazySizes(frameWindow, frameDocument, prepareResult);
    } else if (adapter === 'vanilla-lazyload') {
        activateVanillaLazyLoad(frameWindow, frameDocument, prepareResult);
    } else if (adapter === 'lozad') {
        activateLozad(frameWindow, frameDocument, prepareResult);
    } else if (adapter === 'custom') {
        const handlerName = String(lazyLoadingConfig.customHandler || '').trim();
        const handler = resolveGlobalHandler(frameWindow, handlerName);
        if (!handler) {
            throw new Error(`Configured processing lazy-loading handler was not found: ${handlerName || '(empty)'}`);
        }

        await Promise.resolve(handler.call(frameWindow, {
            document: frameDocument,
            window: frameWindow,
            breakpoint,
            slot,
            pictures,
        }));
        pushActivationStrategy(prepareResult, 'custom', 1);
    }

    if (libraryAdapters.includes(adapter) && prepareResult.activationStrategies.length === 0) {
        throw new Error(`Configured processing lazy-loading adapter could not be activated: ${adapter}`);
    }

    if (adapter === 'attributes') {
        swapLazyLoadingAttributes(
            pictures,
            lazyLoadingConfig.attributes,
            prepareResult,
            recordNormalizationSample,
        );
    }

    pictures.forEach((picture) => {
        const img = picture.querySelector('img');
        const source = getPrimarySourceForBreakpoint(picture, breakpoint);

        if (img) {
            if (img.getAttribute('loading') !== 'eager') {
                img.setAttribute('loading', 'eager');
            }

            if (source && source.getAttribute('data-bp-enabled') !== 'false' && img.getAttribute('fetchpriority') !== 'high') {
                img.setAttribute('fetchpriority', 'high');
            }

        }
    });

    if (prepareResult.activationStrategies.length === 0) {
        prepareResult.activationStrategies.push(adapter === 'none' ? 'none' : adapter);
    }

    await new Promise((resolve) => requestAnimationFrameFn(resolve));
    await new Promise((resolve) => requestAnimationFrameFn(resolve));

    return prepareResult;
}

export function buildBreakpointReadinessTracker({
    breakpoint,
    slot = null,
    frameDocument,
    preloadStates = null,
    getPictureLoadKey,
    getPrimarySourceForBreakpoint,
    deriveSource,
    isTransparentSrcset,
    isRenderable,
    lazyTargetsByImage = null,
}) {
    const images = Array.from(frameDocument.querySelectorAll(PROCESSABLE_IMAGE_SELECTOR));
    const readinessByKey = new Map();
    const cleanups = [];

    const setResolvedStatus = (entry, status, reason = null) => {
        if (!entry || entry.status !== 'pending') {
            return;
        }

        const sourceUsed = deriveSource(entry.source, entry.img);
        if (!sourceMatchesLazyTarget(
            sourceUsed,
            entry.lazyTargetUrls,
            frameDocument?.baseURI || '',
        )) {
            if (isLazyTargetNotPromotedCandidate(
                entry,
                sourceUsed,
                frameDocument?.baseURI || '',
                isRenderable,
            )) {
                entry.reason = 'lazy-target-not-promoted';
            }
            return;
        }

        entry.sourceUsed = sourceUsed;
        entry.status = status;
        entry.reason = reason;
    };

    images.forEach((img, index) => {
        const picture = img.closest('picture');
        const source = getPrimarySourceForBreakpoint(picture, breakpoint);
        if (!source) {
            return;
        }

        const key = getPictureLoadKey(picture, index);
        const enabled = source.getAttribute('data-bp-enabled') !== 'false';
        const sourceUsed = deriveSource(source, img);
        const lazyTargetUrls = lazyTargetsByImage instanceof Map
            ? (lazyTargetsByImage.get(img) || [])
            : [];
        const entry = {
            key,
            status: 'pending',
            reason: null,
            enabled,
            sourceUsed,
            preloadLoaded: preloadStates instanceof Map ? preloadStates.get(key) === true : false,
            img,
            picture,
            source,
            lazyTargetUrls,
            lazyTargetNotPromotedSince: null,
        };

        if (!enabled) {
            entry.status = 'loaded';
            entry.reason = 'disabled-breakpoint';
            readinessByKey.set(key, entry);
            return;
        }

        if (lazyTargetUrls.length < 1 && isTransparentSrcset(source.getAttribute('srcset') || '')) {
            entry.status = 'loaded';
            entry.reason = 'transparent-placeholder';
            readinessByKey.set(key, entry);
            return;
        }

        if (sourceUsed === '' && lazyTargetUrls.length < 1) {
            entry.status = 'broken';
            entry.reason = 'unsupported-source';
            readinessByKey.set(key, entry);
            return;
        }

        const completeSourceMatches = sourceMatchesLazyTarget(
            sourceUsed,
            lazyTargetUrls,
            frameDocument?.baseURI || '',
        );

        if (img.complete && completeSourceMatches) {
            entry.sourceUsed = deriveSource(source, img);
            if (isRenderable(img)) {
                entry.status = 'loaded';
                entry.reason = 'complete';
            } else {
                entry.status = 'broken';
                entry.reason = 'network';
            }

            readinessByKey.set(key, entry);
            return;
        }

        const onLoad = () => {
            if (isRenderable(img)) {
                setResolvedStatus(entry, 'loaded', 'load-event');
            } else {
                setResolvedStatus(entry, 'broken', 'network');
            }
        };

        const onError = () => {
            setResolvedStatus(entry, 'broken', 'network');
        };

        img.addEventListener('load', onLoad);
        img.addEventListener('error', onError);
        cleanups.push(() => {
            img.removeEventListener('load', onLoad);
            img.removeEventListener('error', onError);
        });

        if (typeof img.decode === 'function') {
            img.decode()
                .then(() => {
                    if (isRenderable(img)) {
                        setResolvedStatus(entry, 'loaded', 'decode');
                    }
                })
                .catch(() => {
                    if (img.complete) {
                        setResolvedStatus(entry, 'broken', 'decode');
                    }
                });
        }

        readinessByKey.set(key, entry);
    });

    return {
        readinessByKey,
        cleanup: () => {
            cleanups.forEach((cleanup) => cleanup());
        },
    };
}

export async function waitForImagesToSettle({
    readinessByKey,
    softDeadlineMs = 4000,
    hardDeadlineMs = 30000,
    pollMs = 250,
    shouldStop = () => false,
    onSoftDeadline = null,
    onWaitingTick = null,
    createSummary = createReadinessSummary,
    isRenderable = isImageRenderable,
    setTimeoutFn = (callback, ms) => window.setTimeout(callback, ms),
    requestAnimationFrameFn = (callback) => requestAnimationFrame(callback),
    nowMs = () => Date.now(),
} = {}) {
    if (!(readinessByKey instanceof Map) || readinessByKey.size < 1) {
        return {
            aborted: false,
            timedOut: false,
            waitedMs: 0,
            pendingCount: 0,
            loadedCount: 0,
            brokenCount: 0,
            unresolvedCount: 0,
        };
    }

    const startedAt = nowMs();
    let softDeadlineReached = false;
    let hardDeadlineReached = false;
    let lastTickAt = 0;

    while (true) {
        const pendingEntries = Array.from(readinessByKey.values()).filter((entry) => entry.status === 'pending');

        if (pendingEntries.length < 1) {
            break;
        }

        pendingEntries.forEach((entry) => {
            if (entry.status !== 'pending') {
                return;
            }

            const currentSource = String(
                entry.img.currentSrc
                || entry.source?.getAttribute('srcset')
                || entry.source?.getAttribute('src')
                || entry.img.getAttribute?.('src')
                || entry.sourceUsed
                || ''
            ).trim();
            const sourceIsReady = sourceMatchesLazyTarget(
                currentSource,
                entry.lazyTargetUrls,
                entry.img.ownerDocument?.baseURI || '',
            );

            if (entry.img.complete && sourceIsReady) {
                entry.sourceUsed = currentSource;
                if (isRenderable(entry.img)) {
                    entry.status = 'loaded';
                    entry.reason = 'complete';
                } else if (entry.sourceUsed !== '') {
                    entry.status = 'broken';
                    entry.reason = 'network';
                }
                entry.lazyTargetNotPromotedSince = null;
                return;
            }

            if (isLazyTargetNotPromotedCandidate(
                entry,
                currentSource,
                entry.img.ownerDocument?.baseURI || '',
                isRenderable,
            )) {
                if (!Number.isFinite(entry.lazyTargetNotPromotedSince)) {
                    entry.lazyTargetNotPromotedSince = nowMs();
                }
                entry.reason = 'lazy-target-not-promoted';

                if ((nowMs() - entry.lazyTargetNotPromotedSince) >= 1000) {
                    entry.status = 'unresolved';
                    entry.sourceUsed = currentSource;
                }
                return;
            }

            entry.lazyTargetNotPromotedSince = null;
        });

        const pendingAfterCompleteCheck = Array.from(readinessByKey.values()).filter((entry) => entry.status === 'pending');
        if (pendingAfterCompleteCheck.length < 1) {
            break;
        }

        if (shouldStop()) {
            pendingAfterCompleteCheck.forEach((entry) => {
                if (entry.status === 'pending') {
                    entry.status = 'unresolved';
                    entry.reason = 'cancelled';
                }
            });

            const abortedSummary = createSummary(readinessByKey);
            return {
                aborted: true,
                timedOut: softDeadlineReached,
                waitedMs: nowMs() - startedAt,
                pendingCount: abortedSummary.pendingCount,
                loadedCount: abortedSummary.loadedCount,
                brokenCount: abortedSummary.brokenCount,
                unresolvedCount: abortedSummary.unresolvedCount,
            };
        }

        const waitedMs = nowMs() - startedAt;
        if (Number.isFinite(hardDeadlineMs) && hardDeadlineMs > 0 && waitedMs >= hardDeadlineMs) {
            hardDeadlineReached = true;
            pendingAfterCompleteCheck.forEach((entry) => {
                if (entry.status === 'pending') {
                    entry.status = 'unresolved';
                    entry.reason = 'timeout';
                }
            });
            break;
        }

        if (!softDeadlineReached && waitedMs >= softDeadlineMs) {
            softDeadlineReached = true;
            if (typeof onSoftDeadline === 'function') {
                onSoftDeadline({
                    waitedMs,
                    pendingCount: pendingAfterCompleteCheck.length,
                });
            }

            lastTickAt = waitedMs;
        }

        if (softDeadlineReached && typeof onWaitingTick === 'function' && (waitedMs - lastTickAt) >= 1000) {
            onWaitingTick({
                waitedMs,
                pendingCount: pendingAfterCompleteCheck.length,
            });
            lastTickAt = waitedMs;
        }

        await new Promise((resolve) => setTimeoutFn(resolve, pollMs));
    }

    await new Promise((resolve) => requestAnimationFrameFn(resolve));
    await new Promise((resolve) => requestAnimationFrameFn(resolve));

    const completedSummary = createSummary(readinessByKey);
    return {
        aborted: false,
        timedOut: softDeadlineReached || hardDeadlineReached,
        waitedMs: nowMs() - startedAt,
        pendingCount: completedSummary.pendingCount,
        loadedCount: completedSummary.loadedCount,
        brokenCount: completedSummary.brokenCount,
        unresolvedCount: completedSummary.unresolvedCount,
    };
}

export async function preloadBreakpointSources({
    breakpoint,
    slot = null,
    frameDocument,
    timeoutMs = 5000,
    getPictureLoadKey,
    getPrimarySourceForBreakpoint,
    isTransparentSrcset = isTransparentPixelSrcset,
    ImageCtor = Image,
    setTimeoutFn = (callback, ms) => window.setTimeout(callback, ms),
    requestAnimationFrameFn = (callback) => requestAnimationFrame(callback),
}) {
    const pictures = Array.from(frameDocument.querySelectorAll(PROCESSABLE_PICTURE_SELECTOR));
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

        const srcset = String(source.getAttribute('data-srcset') || source.getAttribute('srcset') || '').trim();
        if (!srcset || isTransparentSrcset(srcset)) {
            loadStates.set(key, true);
            resolve();
            return;
        }

        const probe = new ImageCtor();
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

        setTimeoutFn(() => finish(false), timeoutMs);
    }));

    await Promise.all(waiters);
    await new Promise((resolve) => requestAnimationFrameFn(resolve));
    await new Promise((resolve) => requestAnimationFrameFn(resolve));

    return loadStates;
}

export function toPositiveIntOrNull(value) {
    const parsed = parseInt(String(value ?? '').trim(), 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

export function toPositiveFloatOrNull(value) {
    const parsed = Number.parseFloat(String(value ?? '').trim());
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

export function extractRowsForBreakpoint({
    breakpoint,
    slot = null,
    frameDocument,
    preloadStates = null,
    readinessByKey = null,
    getPrimarySourceForBreakpoint,
    getPictureLoadKey,
    deriveSource,
    isLikelyBroken,
    toPositiveIntOrNullFn = toPositiveIntOrNull,
    toPositiveFloatOrNullFn = toPositiveFloatOrNull,
}) {
    const images = Array.from(frameDocument.querySelectorAll(PROCESSABLE_IMAGE_SELECTOR));

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
        const readiness = readinessByKey instanceof Map ? readinessByKey.get(preloadKey) : null;
        const derivedSource = deriveSource(source, img);
        const hasTrackedLazyTargets = readiness
            && Array.isArray(readiness.lazyTargetUrls)
            && readiness.lazyTargetUrls.length > 0;
        const derivedMatchesLazyTarget = !hasTrackedLazyTargets || sourceMatchesLazyTarget(
            derivedSource,
            readiness.lazyTargetUrls,
            frameDocument?.baseURI || '',
        );
        const unresolvedLazyPlaceholder = Boolean(
            hasTrackedLazyTargets
            && !derivedMatchesLazyTarget
        );
        const sourceUsed = unresolvedLazyPlaceholder
            ? ''
            : (derivedSource || ((readiness && typeof readiness.sourceUsed === 'string') ? readiness.sourceUsed : ''));

        let loaded = false;
        let broken = false;
        let unresolved = false;

        if (!enabled) {
            loaded = true;
        } else if (readiness && readiness.status === 'loaded' && !unresolvedLazyPlaceholder) {
            loaded = true;
        } else if (readiness && readiness.status === 'broken') {
            broken = true;
        } else if (readiness && readiness.status === 'unresolved') {
            unresolved = true;
        } else if (unresolvedLazyPlaceholder) {
            unresolved = true;
        } else if (Boolean((preloadLoaded && !hasTrackedLazyTargets) || (loadedFromElement && derivedMatchesLazyTarget))) {
            loaded = true;
        } else if (isLikelyBroken(img)) {
            broken = true;
        }

        if (loaded) {
            broken = false;
            unresolved = false;
        } else if (broken) {
            loaded = false;
            unresolved = false;
        } else if (unresolved) {
            loaded = false;
            broken = false;
        }

        return {
            slotKey: slot?.key || source?.getAttribute('data-bp-key') || null,
            slotIndex: Number.isFinite(Number(slot?.index)) ? Number(slot.index) : toPositiveIntOrNullFn(source?.getAttribute('data-bp-index')),
            mediaWidth: Number.isFinite(Number(slot?.mediaWidth)) ? Number(slot.mediaWidth) : breakpoint,
            measureWidth: Number.isFinite(Number(slot?.measureWidth)) ? Number(slot.measureWidth) : toPositiveIntOrNullFn(source?.getAttribute('data-bp-measure-width')),
            pictureId: picture?.getAttribute('data-picture-id') || null,
            assetId,
            transform: picture?.getAttribute('data-set') || 'unknown',
            title: picture?.getAttribute('data-asset-title') || '',
            includeEscapeWidth: picture?.getAttribute('data-include-escape-width') === 'true',
            enabled,
            isVisible: img.offsetWidth > 0 || img.offsetHeight > 0,
            src: unresolvedLazyPlaceholder ? '' : (img.currentSrc || img.getAttribute('src') || ''),
            sourceUsed,
            loaded,
            broken,
            unresolved,
            rendered: {
                width: img.clientWidth || 0,
                height: img.clientHeight || 0,
            },
            intrinsic: {
                width: img.naturalWidth || 0,
                height: img.naturalHeight || 0,
            },
            transformDimensions: {
                width: toPositiveIntOrNullFn(source?.getAttribute('data-set-width')),
                height: toPositiveIntOrNullFn(source?.getAttribute('data-set-height')),
                autoDimension: source?.getAttribute('data-auto-dimension') || null,
            },
        };
    }).filter((row) => row !== null);
}

export function buildStructuredOutput({
    sourceUrl,
    breakpoints,
    slots = [],
    rowsByBreakpoint,
    rowsBySlot = null,
    startedAt,
    runReport = null,
    configSchemaVersion = null,
    runCount = 0,
    nowMs = () => Date.now(),
    nowIso = () => new Date().toISOString(),
}) {
    const assetsById = new Set();
    const setsByName = new Set();
    let unloadedImageCount = 0;
    let loadedImageCount = 0;
    let brokenImageCount = 0;
    let unresolvedImageCount = 0;

    Object.values(rowsByBreakpoint).forEach((rows) => {
        rows.forEach((row) => {
            assetsById.add(String(row.assetId || ''));
            setsByName.add(String(row.transform || ''));

            if (row.loaded === true) {
                loadedImageCount += 1;
                return;
            }

            if (row.broken === true) {
                brokenImageCount += 1;
                unloadedImageCount += 1;
                return;
            }

            if (row.unresolved === true) {
                unresolvedImageCount += 1;
                unloadedImageCount += 1;
            }
        });
    });

    const durationMs = nowMs() - startedAt;
    const rowCount = Object.values(rowsByBreakpoint).reduce((count, rows) => count + rows.length, 0);

    return {
        schemaVersion: 2,
        configSchemaVersion,
        runId: runReport?.runId || `run-${nowMs()}`,
        sourceUrl: runReport?.sourceUrl || sourceUrl,
        timestamp: nowIso(),
        breakpoints,
        slots,
        rowsByBreakpoint,
        rowsBySlot: rowsBySlot || rowsByBreakpoint,
        processingReport: runReport,
        summary: {
            runs: runCount,
            breakpointCount: slots.length || breakpoints.length,
            assetCount: Array.from(assetsById).filter((assetId) => assetId !== '').length,
            setCount: Array.from(setsByName).filter((setName) => setName !== '').length,
            rowCount,
            warningCount: runReport?.totals?.warningCount || 0,
            loadedImageCount,
            brokenImageCount,
            unresolvedImageCount,
            unloadedImageCount,
            durationMs,
        },
    };
}

export function appendBreakpointReadinessIssues({
    report,
    breakpointReport,
    breakpoint,
    readinessByKey,
    appendIssue,
}) {
    if (!(readinessByKey instanceof Map)) {
        return;
    }

    readinessByKey.forEach((entry) => {
        if (!entry || typeof entry !== 'object') {
            return;
        }

        if (entry.status === 'broken') {
            const reason = String(entry.reason || 'network');
            const code = reason === 'decode'
                ? 'decode-failure'
                : reason === 'unsupported-source'
                    ? 'unsupported-source'
                    : 'network-failure';

            const message = reason === 'decode'
                ? 'Image decode failed during processing readiness checks.'
                : reason === 'unsupported-source'
                    ? 'Image source could not be resolved for this breakpoint.'
                    : 'Image failed to load for this breakpoint.';

            appendIssue(report, {
                severity: 'warning',
                code,
                message,
                breakpointWidth: breakpoint,
                assetId: entry.img?.getAttribute('data-asset-id') || entry.picture?.getAttribute('data-asset-id') || null,
                source: entry.sourceUsed,
            }, breakpointReport);
        }

        if (entry.status === 'unresolved') {
            const timedOut = entry.reason === 'timeout';
            const notPromoted = entry.reason === 'lazy-target-not-promoted';
            appendIssue(report, {
                severity: 'warning',
                code: notPromoted ? 'lazy-target-not-promoted' : (timedOut ? 'lazy-load-timeout' : 'unresolved-on-cancel'),
                message: notPromoted
                    ? 'The lazy-loaded image target was available, but the preview DOM did not promote it from the lazy attribute to the active source.'
                    : (timedOut
                    ? 'The lazy-loaded image did not replace its placeholder before the processing timeout.'
                    : 'Image was still pending when processing was cancelled by the user.'),
                breakpointWidth: breakpoint,
                assetId: entry.img?.getAttribute('data-asset-id') || entry.picture?.getAttribute('data-asset-id') || null,
                source: entry.sourceUsed,
            }, breakpointReport);
        }
    });
}
