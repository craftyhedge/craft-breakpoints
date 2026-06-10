export const PROCESSABLE_PICTURE_SELECTOR = 'picture[data-set]:not([data-bp-processing-ignore])';
export const PROCESSABLE_IMAGE_SELECTOR = `${PROCESSABLE_PICTURE_SELECTOR} img`;

export function getMeasurementWidthForBreakpoint(breakpoint, safetyPx = 2) {
    const parsed = parseInt(String(breakpoint), 10);
    if (!Number.isFinite(parsed) || parsed <= 1) {
        return 1;
    }

    return Math.max(1, parsed - Math.max(0, Number(safetyPx) || 0));
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
}) {
    const sourceValue = String(target.getAttribute(dataAttr) || '').trim();
    if (sourceValue === '') {
        return false;
    }

    const currentValue = String(target.getAttribute(targetAttr) || '').trim();
    if (currentValue !== '') {
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

export function activateLazySizes(frameWindow, frameDocument, prepareResult, pushStrategy = pushActivationStrategy) {
    const lazySizes = frameWindow?.lazySizes;
    if (!lazySizes || typeof lazySizes !== 'object') {
        return;
    }

    let strategyCount = 0;

    if (typeof lazySizes.loader?.checkElems === 'function') {
        lazySizes.loader.checkElems();
        strategyCount += 1;
    }

    if (typeof lazySizes.autoSizer?.checkElems === 'function') {
        lazySizes.autoSizer.checkElems();
        strategyCount += 1;
    }

    if (typeof lazySizes.loader?.unveil === 'function') {
                const candidates = Array.from(frameDocument.querySelectorAll(`${PROCESSABLE_IMAGE_SELECTOR}.lazyload`));
        candidates.forEach((img) => {
            try {
                lazySizes.loader.unveil(img);
                strategyCount += 1;
            } catch (_error) {
                // Keep activation resilient. Failures are captured in readiness issues.
            }
        });
    }

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
        } catch (_error) {
            // Keep activation resilient. Failures are captured in readiness issues.
        }
    }

    if (strategyCount > 0) {
        pushStrategy(prepareResult, 'lozad', strategyCount);
    }
}

export function prepareBreakpoints({
    breakpoint,
    slot = null,
    frameDocument,
    frameWindow,
    getTrackedPictures,
    getPrimarySourceForBreakpoint,
    sampleLimit = 12,
}) {
    const prepareResult = {
        activationStrategies: [],
        normalizationCount: 0,
        normalizationSamples: [],
    };

    const recordNormalizationSample = (sample) => {
        if (prepareResult.normalizationSamples.length >= sampleLimit) {
            return;
        }

        prepareResult.normalizationSamples.push(sample);
    };

    activateLazySizes(frameWindow, frameDocument, prepareResult);
    activateVanillaLazyLoad(frameWindow, frameDocument, prepareResult);
    activateLozad(frameWindow, frameDocument, prepareResult);

    const pictures = getTrackedPictures(frameDocument);
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

            const imgRules = [
                { dataAttr: 'data-src', targetAttr: 'src', forceWhenDataUri: true },
                { dataAttr: 'data-srcset', targetAttr: 'srcset', forceWhenDataUri: true },
                { dataAttr: 'data-sizes', targetAttr: 'sizes', forceWhenDataUri: false },
            ];

            imgRules.forEach((rule) => {
                if (normalizeLazyAttribute(img, rule)) {
                    prepareResult.normalizationCount += 1;
                    recordNormalizationSample({
                        element: 'img',
                        attr: rule.targetAttr,
                    });
                }
            });
        }

        const sourceNodes = Array.from(picture.querySelectorAll('source'));
        sourceNodes.forEach((sourceNode) => {
            const sourceRules = [
                { dataAttr: 'data-srcset', targetAttr: 'srcset', forceWhenDataUri: true },
                { dataAttr: 'data-sizes', targetAttr: 'sizes', forceWhenDataUri: false },
            ];

            sourceRules.forEach((rule) => {
                if (normalizeLazyAttribute(sourceNode, rule)) {
                    prepareResult.normalizationCount += 1;
                    recordNormalizationSample({
                        element: 'source',
                        attr: rule.targetAttr,
                    });
                }
            });
        });
    });

    if (prepareResult.activationStrategies.length === 0) {
        prepareResult.activationStrategies.push('none');
    }

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
}) {
    const images = Array.from(frameDocument.querySelectorAll(PROCESSABLE_IMAGE_SELECTOR));
    const readinessByKey = new Map();
    const cleanups = [];

    const setResolvedStatus = (entry, status, reason = null) => {
        if (!entry || entry.status !== 'pending') {
            return;
        }

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
        const entry = {
            key,
            status: 'pending',
            reason: null,
            enabled,
            sourceUsed,
            img,
            picture,
            source,
        };

        if (!enabled) {
            entry.status = 'loaded';
            entry.reason = 'disabled-breakpoint';
            readinessByKey.set(key, entry);
            return;
        }

        if (isTransparentSrcset(source.getAttribute('srcset') || '')) {
            entry.status = 'loaded';
            entry.reason = 'transparent-placeholder';
            readinessByKey.set(key, entry);
            return;
        }

        const preloadLoaded = preloadStates ? preloadStates.get(key) : undefined;
        if (preloadLoaded === true) {
            entry.status = 'loaded';
            entry.reason = 'preload';
            readinessByKey.set(key, entry);
            return;
        }

        if (sourceUsed === '') {
            entry.status = 'broken';
            entry.reason = 'unsupported-source';
            readinessByKey.set(key, entry);
            return;
        }

        if (img.complete) {
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

            if (entry.img.complete) {
                if (isRenderable(entry.img)) {
                    entry.status = 'loaded';
                    entry.reason = 'complete';
                } else if (entry.sourceUsed !== '') {
                    entry.status = 'broken';
                    entry.reason = 'network';
                }
            }
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
        timedOut: softDeadlineReached,
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

        const srcset = String(source.getAttribute('srcset') || '').trim();
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
        const sourceUsed = (readiness && typeof readiness.sourceUsed === 'string')
            ? readiness.sourceUsed
            : deriveSource(source, img);

        let loaded = false;
        let broken = false;
        let unresolved = false;

        if (!enabled) {
            loaded = true;
        } else if (readiness && readiness.status === 'loaded') {
            loaded = true;
        } else if (readiness && readiness.status === 'broken') {
            broken = true;
        } else if (readiness && readiness.status === 'unresolved') {
            unresolved = true;
        } else if (Boolean(preloadLoaded || loadedFromElement)) {
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
            assetId,
            transform: picture?.getAttribute('data-set') || 'unknown',
            title: picture?.getAttribute('data-asset-title') || '',
            includeEscapeWidth: picture?.getAttribute('data-include-escape-width') === 'true',
            enabled,
            isVisible: img.offsetWidth > 0 || img.offsetHeight > 0,
            src: img.currentSrc || img.getAttribute('src') || '',
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
            appendIssue(report, {
                severity: 'warning',
                code: 'unresolved-on-cancel',
                message: 'Image was still pending when processing was cancelled by the user.',
                breakpointWidth: breakpoint,
                assetId: entry.img?.getAttribute('data-asset-id') || entry.picture?.getAttribute('data-asset-id') || null,
                source: entry.sourceUsed,
            }, breakpointReport);
        }
    });
}
