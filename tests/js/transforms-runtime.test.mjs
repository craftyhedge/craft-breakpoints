function buildRuntimeDom() {
    document.body.innerHTML = `
    <div class="bpts-transforms-page"></div>
    <span id="bpts-review-state-label"></span>
    <input id="bpts-ui-results-heading-signal-bridge" value="Saved Sets" />
    <input id="bpts-sidebar-saved-set-names-signal-bridge" value="[]" />
    <div id="bpts-editor-status" data-kind="ready" data-message=""></div>
    <input id="bpts-source-entry" value="" />
    <div id="bpts-status"></div>
    <div id="bpts-progress-host"></div>
    <div id="bpts-frame-pane"></div>
    <div id="bpts-frame-wrapper"></div>
    <div id="bpts-warnings"></div>
    <div id="bpts-visual-results"></div>
    <nav id="bpts-transform-sets-sidebar">
      <ul id="bpts-transform-sets-list"></ul>
    </nav>
    <button id="bpts-open-preview"></button>
    <button id="bpts-run-processing"></button>
    <button id="bpts-stop-processing"></button>
    <button id="bpts-close-preview"></button>
    <button id="bpts-copy-output"></button>
    <button id="bpts-compact-breakpoint-cards"></button>
    <input id="bpts-compact-breakpoint-cards-signal-bridge" type="checkbox" />
  `;

    document.querySelector('.bpts-transforms-page')?.setAttribute('data-can-edit-transforms', '0');
}

async function loadRuntimeHooks() {
    buildRuntimeDom();

    window.bpiProcessingConfig = {
        schemaVersion: 2,
        breakpointValues: [480, 768],
        breakpointSlots: [
            { key: 'xs', index: 0, mediaWidth: 480, measureWidth: 480, isBase: true, isFinal: false },
            { key: 'sm', index: 1, mediaWidth: 768, measureWidth: 768, isBase: false, isFinal: true },
        ],
        processing: {
            authorDiagnosticsEnabled: false,
            lazyLoading: {
                adapter: 'attributes',
                attributes: {
                    src: 'data-src',
                    srcset: 'data-srcset',
                    sizes: 'data-sizes',
                },
                customHandler: '',
            },
        },
    };

    window.__BPI_TEST_HOOKS = true;

    const processing = await import('../../src/web/assets/transforms/dist/js/transforms-processing.js?bpts-processing-test-hooks');
    const harness = {
        frameDocument: document,
        frameWindow: window,
    };
    const getFrameDocument = () => harness.frameDocument || document;
    const getFrameWindow = () => harness.frameWindow || window;
    const getTrackedPictures = (frameDocument) => Array.from(frameDocument.querySelectorAll(processing.PROCESSABLE_PICTURE_SELECTOR));
    const getPictureLoadKey = (picture, index) => {
        const pictureId = String(picture?.getAttribute('data-picture-id') || '').trim();
        if (pictureId !== '') {
            const duplicates = Array.from(picture?.ownerDocument?.querySelectorAll?.(processing.PROCESSABLE_PICTURE_SELECTOR) || [])
                .filter((candidate) => String(candidate.getAttribute('data-picture-id') || '').trim() === pictureId);

            return duplicates.length > 1 ? `${pictureId}#${index}` : pictureId;
        }

        const assetId = String(picture?.getAttribute('data-asset-id') || '').trim();
        return assetId !== '' ? `asset:${assetId}#${index}` : `unknown-${index}`;
    };
    const getPrimarySourceForBreakpoint = (picture, breakpoint) => picture?.querySelector(`source[data-bp-source="primary"][data-bp-size="${breakpoint}"]`)
        || picture?.querySelector(`source[data-bp-size="${breakpoint}"]`)
        || picture?.querySelector(`source[data-bp-source="primary"][data-bp-key="${breakpoint}"]`)
        || picture?.querySelector(`source[data-bp-key="${breakpoint}"]`)
        || null;

    window.__BPIRuntimeTestHookHarness = {
        activateSlotSources: processing.activateSlotSources,
        preloadBreakpointSources: (breakpoint, timeoutMs = 5000) => processing.preloadBreakpointSources({
            breakpoint,
            frameDocument: getFrameDocument(),
            timeoutMs,
            getPictureLoadKey,
            getPrimarySourceForBreakpoint,
            isTransparentSrcset: processing.isTransparentPixelSrcset,
            ImageCtor: Image,
            setTimeoutFn: (callback, ms) => window.setTimeout(callback, ms),
            requestAnimationFrameFn: (callback) => requestAnimationFrame(callback),
        }),
        prepareBreakpoints: (breakpoint, lazyLoading = null) => processing.prepareBreakpoints({
            breakpoint,
            frameDocument: getFrameDocument(),
            frameWindow: getFrameWindow(),
            getTrackedPictures,
            getPrimarySourceForBreakpoint,
            lazyLoading,
            requestAnimationFrameFn: (callback) => callback(),
        }),
        buildBreakpointReadinessTracker: (breakpoint, preloadStates = null, lazyTargetsByImage = null) => processing.buildBreakpointReadinessTracker({
            breakpoint,
            frameDocument: getFrameDocument(),
            preloadStates,
            getPictureLoadKey,
            getPrimarySourceForBreakpoint,
            deriveSource: processing.deriveSourceUsed,
            isTransparentSrcset: processing.isTransparentPixelSrcset,
            isRenderable: processing.isImageRenderable,
            lazyTargetsByImage,
        }),
        extractRowsForBreakpoint: (breakpoint, preloadStates = null, readinessByKey = null) => processing.extractRowsForBreakpoint({
            breakpoint,
            frameDocument: getFrameDocument(),
            preloadStates,
            readinessByKey,
            getPrimarySourceForBreakpoint,
            getPictureLoadKey,
            deriveSource: processing.deriveSourceUsed,
            isLikelyBroken: (img) => processing.isImageLikelyBroken(img, processing.isImageRenderable),
            toPositiveIntOrNullFn: processing.toPositiveIntOrNull,
        }),
    };

    globalThis.eval(`
        var preloadBreakpointSources = globalThis.__BPIRuntimeTestHookHarness.preloadBreakpointSources;
        var prepareBreakpoints = globalThis.__BPIRuntimeTestHookHarness.prepareBreakpoints;
        var buildBreakpointReadinessTracker = globalThis.__BPIRuntimeTestHookHarness.buildBreakpointReadinessTracker;
        var extractRowsForBreakpoint = globalThis.__BPIRuntimeTestHookHarness.extractRowsForBreakpoint;
    `);

    await import('../../src/web/assets/transforms/dist/js/transforms.js?bpts-test-hooks');

    const hooks = window.__BPIProcessingTestHooks;
    if (!hooks || typeof hooks !== 'object') {
        throw new Error('Expected transforms runtime test hooks to be exported.');
    }

    const setPreviewFrameForTests = hooks.setPreviewFrameForTests;
    hooks.setPreviewFrameForTests = (frameDocument, frameWindow = window) => {
        harness.frameDocument = frameDocument;
        harness.frameWindow = frameWindow;
        setPreviewFrameForTests(frameDocument, frameWindow);
    };

    const clearPreviewFrameForTests = hooks.clearPreviewFrameForTests;
    hooks.clearPreviewFrameForTests = () => {
        harness.frameDocument = document;
        harness.frameWindow = window;
        clearPreviewFrameForTests();
    };

    return hooks;
}

describe('transforms runtime helper logic', () => {
    let hooks;
    let originalCraft;

    beforeAll(async () => {
        hooks = await loadRuntimeHooks();
        originalCraft = window.Craft;
    });

    afterAll(() => {
        window.Craft = originalCraft;
    });

    afterEach(() => {
        hooks.clearPreviewFrameForTests();
        hooks.clearRunProcessingOverridesForTests();
        const savedSetNamesBridge = document.getElementById('bpts-sidebar-saved-set-names-signal-bridge');
        if (savedSetNamesBridge instanceof HTMLInputElement) {
            savedSetNamesBridge.value = '[]';
        }
    });

    it('defaults read-only review cards to compact previews without overwriting the stored editor preference', () => {
        const compactBridge = document.getElementById('bpts-compact-breakpoint-cards-signal-bridge');
        const compactButton = document.getElementById('bpts-compact-breakpoint-cards');

        expect(compactBridge).toBeInstanceOf(HTMLInputElement);
        expect(compactButton).toBeInstanceOf(HTMLButtonElement);
        expect(compactBridge.checked).toBe(true);
        expect(compactButton.classList.contains('active')).toBe(true);
        expect(window.localStorage.getItem('breakpoints.transforms.compactBreakpointCards')).toBeNull();
    });

    it('applies initial stored review payload without requiring processing', async () => {
        const patchListener = (event) => {
            const detail = event?.detail || {};
            if (detail.type !== 'datastar-patch-elements') {
                return;
            }

            const selector = detail.argsRaw?.selector;
            const mode = detail.argsRaw?.mode;
            const markup = detail.argsRaw?.elements;
            const target = typeof selector === 'string' ? document.querySelector(selector) : null;
            if (!target || mode !== 'inner' || typeof markup !== 'string') {
                return;
            }

            target.innerHTML = markup;
        };
        document.addEventListener('datastar-fetch', patchListener);

        const sendActionRequest = vi.fn().mockResolvedValue({
            data: {
                warningsHtml: '<div class="warning-marker">Initial warning</div>',
                visualResultsHtml: '<div class="initial-review-marker">Initial cards</div>',
                warningCount: 3,
                savedSetNames: ['heroImage', 'cardImage'],
                editScopeBySet: {},
                editTabBySet: {},
            },
        });

        window.Craft = {
            sendActionRequest,
        };

        const result = await hooks.renderInitialStoredReview();

        document.removeEventListener('datastar-fetch', patchListener);

        expect(sendActionRequest).toHaveBeenCalledWith(
            'POST',
            'breakpoints/transforms/render-initial-review',
            expect.any(Object),
        );
        expect(result.warningCount).toBe(3);
        expect(document.getElementById('bpts-visual-results').innerHTML).toContain('Initial cards');
        expect(document.getElementById('bpts-warnings').innerHTML).toContain('Initial warning');
        expect(document.getElementById('bpts-sidebar-saved-set-names-signal-bridge').value).toBe('["heroImage","cardImage"]');
        expect(document.getElementById('bpts-copy-output').hidden).toBe(true);
    });

    it('sends newly applied set names when rendering verified processing results', async () => {
        const sendActionRequest = vi.fn().mockResolvedValue({
            data: {
                warningsHtml: '',
                visualResultsHtml: '<article data-set="newHero"></article>',
                warningCount: 0,
                savedSetNames: ['newHero'],
                editScopeBySet: {},
                editTabBySet: {},
            },
        });

        window.Craft = {
            sendActionRequest,
        };

        await hooks.renderResultReview({
            breakpoints: [480],
            rowsBySlot: {
                base: [{
                    assetId: '2458',
                    transform: 'newHero',
                    loaded: true,
                    broken: false,
                    unresolved: false,
                }],
            },
        }, {
            autoApplySummary: {
                appliedCount: 1,
                requestedSetNames: ['newHero', 'existingHero'],
                skipped: [{ name: 'existingHero', reason: 'already_saved' }],
            },
        });

        expect(sendActionRequest).toHaveBeenCalledWith(
            'POST',
            'breakpoints/transforms/render-result-review',
            expect.objectContaining({
                data: expect.objectContaining({
                    newSetNames: ['newHero'],
                }),
            }),
        );
    });

    it('sanitizes issue source URLs by removing query and hash', () => {
        expect(hooks.sanitizeIssueSource('https://example.test/path/image.jpg?token=abc#frag'))
            .toBe('https://example.test/path/image.jpg');

        expect(hooks.sanitizeIssueSource('/relative/path/image.jpg?foo=bar#x'))
            .toBe('http://localhost:3000/relative/path/image.jpg');
    });

    it('caps issue payload size and tracks overflow counts', () => {
        const report = hooks.createRunReport('https://example.test/source?page=1', [480], false);

        for (let i = 0; i < 205; i += 1) {
            hooks.appendRunIssue(report, {
                severity: 'warning',
                code: `warn-${i}`,
                message: 'warning',
                source: `https://example.test/image-${i}.jpg?token=secret`,
            });
        }

        expect(report.issues).toHaveLength(200);
        expect(report.issueOverflowCount).toBe(5);
        expect(report.totals.issueCount).toBe(205);
        expect(report.totals.warningCount).toBe(205);
        expect(report.issues[0].source).toBe('https://example.test/image-0.jpg');
    });

    it('inspects processing marker health for empty, tracked, and cached image markup', () => {
        const createFrameDocument = (html) => {
            const frameDocument = document.implementation.createHTMLDocument('Preview');
            frameDocument.body.innerHTML = html;
            return frameDocument;
        };

        expect(hooks.inspectProcessingMarkerHealth(createFrameDocument('<main>No images</main>'))).toEqual({
            trackedPictureCount: 0,
            pictureCount: 0,
            imageCount: 0,
            hasImageMarkup: false,
            missingMarkers: false,
        });

        expect(hooks.inspectProcessingMarkerHealth(createFrameDocument(`
            <picture data-set="hero"><img src="/hero.jpg" /></picture>
        `))).toEqual({
            trackedPictureCount: 1,
            pictureCount: 1,
            imageCount: 1,
            hasImageMarkup: true,
            missingMarkers: false,
        });

        expect(hooks.inspectProcessingMarkerHealth(createFrameDocument(`
            <picture><img src="/cached-hero.jpg" /></picture>
        `))).toEqual({
            trackedPictureCount: 0,
            pictureCount: 1,
            imageCount: 1,
            hasImageMarkup: true,
            missingMarkers: true,
        });

        expect(hooks.inspectProcessingMarkerHealth(createFrameDocument(`
            <picture data-set="hero" data-bp-processing-ignore="svg"><img src="/hero.svg" /></picture>
        `))).toEqual({
            trackedPictureCount: 0,
            pictureCount: 1,
            imageCount: 1,
            hasImageMarkup: true,
            missingMarkers: false,
        });
    });

    it('finalizes run report totals and strips internal timing marker', () => {
        const report = hooks.createRunReport('https://example.test/source?secret=1', [480], false);

        const finalized = hooks.finalizeRunReport(report, {
            status: 'completed',
            resultPublished: true,
            rowsByBreakpoint: {
                480: [
                    { loaded: true, broken: false, unresolved: false },
                    { loaded: false, broken: true, unresolved: false },
                    { loaded: false, broken: false, unresolved: true },
                ],
            },
        });

        expect(finalized.status).toBe('completed');
        expect(finalized.resultPublished).toBe(true);
        expect(finalized.totals.rowCount).toBe(3);
        expect(finalized.totals.loadedTotal).toBe(1);
        expect(finalized.totals.brokenTotal).toBe(1);
        expect(finalized.totals.unresolvedTotal).toBe(1);
        expect(finalized._startedAtMs).toBeUndefined();
        expect(typeof finalized.completedAt).toBe('string');
    });

    it('finalizes failed and cancelled runs with failure metadata', () => {
        const failed = hooks.finalizeRunReport(
            hooks.createRunReport('https://example.test/source', [480], false),
            {
                status: 'failed',
                resultPublished: false,
                failureStage: 'prepare-breakpoint-images',
                failureMessage: 'prepare failed',
                rowsByBreakpoint: {},
            },
        );

        expect(failed.status).toBe('failed');
        expect(failed.resultPublished).toBe(false);
        expect(failed.failure.stage).toBe('prepare-breakpoint-images');
        expect(failed.failure.message).toBe('prepare failed');

        const cancelled = hooks.finalizeRunReport(
            hooks.createRunReport('https://example.test/source', [480], false),
            {
                status: 'cancelled',
                resultPublished: false,
                failureStage: 'wait-for-image-readiness',
                failureMessage: 'cancelled by user',
                rowsByBreakpoint: {},
            },
        );

        expect(cancelled.status).toBe('cancelled');
        expect(cancelled.resultPublished).toBe(false);
        expect(cancelled.failure.stage).toBe('wait-for-image-readiness');
        expect(cancelled.failure.message).toBe('cancelled by user');
    });

    it('creates report diagnostics only when enabled and builds breakpoint report shape', () => {
        const withDiagnostics = hooks.createRunReport('https://example.test/source?secret=1', [480, 768], true);
        expect(withDiagnostics.sourceUrl).toBe('https://example.test/source');
        expect(withDiagnostics.totals.breakpointCount).toBe(2);
        expect(withDiagnostics.authorDiagnostics).toBeDefined();
        expect(withDiagnostics.authorDiagnostics.stageTimings).toEqual([]);

        const withoutDiagnostics = hooks.createRunReport('https://example.test/source', [480], false);
        expect(withoutDiagnostics.authorDiagnostics).toBeUndefined();

        const breakpointReport = hooks.createBreakpointReportEntry('768');
        expect(breakpointReport.breakpointKey).toBe('768');
        expect(breakpointReport.width).toBe(768);
        expect(breakpointReport.status).toBe('skipped');

        const invalidBreakpointReport = hooks.createBreakpointReportEntry('abc');
        expect(invalidBreakpointReport.width).toBeNull();
    });

    it('computes measurement widths and diagnostics flag state', () => {
        expect(hooks.getMeasurementWidthForBreakpoint(480)).toBe(479);
        expect(hooks.getMeasurementWidthForBreakpoint(1)).toBe(1);
        expect(hooks.getMeasurementWidthForBreakpoint('bad')).toBe(1);

        window.bpiProcessingConfig.processing.authorDiagnosticsEnabled = true;
        expect(hooks.isAuthorDiagnosticsEnabled()).toBe(true);

        window.bpiProcessingConfig.processing.authorDiagnosticsEnabled = false;
        expect(hooks.isAuthorDiagnosticsEnabled()).toBe(false);
    });

    it('retains the available final measurement when the preferred pass has no match', () => {
        const normalRow = {
            pictureId: 'final-only',
            assetId: 'asset-1',
            transform: 'hero',
            includeEscapeWidth: true,
            measureWidth: 1536,
            rendered: { width: 1500, height: 750 },
        };

        const selection = hooks.selectFinalRows([normalRow], []);

        expect(selection.rows).toEqual([normalRow]);
        expect(selection.unmatchedRows).toEqual([normalRow]);

        const escapeOnlyEnabled = {
            ...normalRow,
            pictureId: 'escape-only-enabled',
            includeEscapeWidth: true,
            measureWidth: 1920,
        };
        const enabledSelection = hooks.selectFinalRows([], [escapeOnlyEnabled]);
        expect(enabledSelection.rows).toEqual([escapeOnlyEnabled]);
        expect(enabledSelection.unmatchedRows).toEqual([]);

        const escapeOnlyDisabled = {
            ...escapeOnlyEnabled,
            pictureId: 'escape-only-disabled',
            includeEscapeWidth: false,
        };
        const disabledSelection = hooks.selectFinalRows([], [escapeOnlyDisabled]);
        expect(disabledSelection.rows).toEqual([escapeOnlyDisabled]);
        expect(disabledSelection.unmatchedRows).toEqual([escapeOnlyDisabled]);
    });

    it('derives sourceUsed in priority order and detects likely broken images', () => {
        const source = {
            getAttribute: (name) => {
                if (name === 'srcset') {
                    return 'https://example.test/from-source-set.webp 1x';
                }

                if (name === 'src') {
                    return 'https://example.test/from-source-src.webp';
                }

                return '';
            },
        };

        const imgWithCurrent = {
            currentSrc: 'https://example.test/current.jpg',
            getAttribute: () => 'https://example.test/fallback.jpg',
        };
        expect(hooks.deriveSourceUsed(source, imgWithCurrent)).toBe('https://example.test/current.jpg');

        const imgWithoutCurrent = {
            currentSrc: '',
            getAttribute: (name) => {
                if (name === 'src') {
                    return 'https://example.test/img-src.jpg';
                }

                return '';
            },
        };
        expect(hooks.deriveSourceUsed(source, imgWithoutCurrent)).toBe('https://example.test/from-source-set.webp 1x');
        expect(hooks.deriveSourceUsed(null, imgWithoutCurrent)).toBe('https://example.test/img-src.jpg');

        const incomplete = { complete: false, naturalWidth: 0, naturalHeight: 0, currentSrc: '', getAttribute: () => '' };
        expect(hooks.isImageLikelyBroken(incomplete)).toBe(false);

        const renderable = { complete: true, naturalWidth: 100, naturalHeight: 0, currentSrc: '', getAttribute: () => '' };
        expect(hooks.isImageLikelyBroken(renderable)).toBe(false);

        const brokenNoSource = { complete: true, naturalWidth: 0, naturalHeight: 0, currentSrc: '', getAttribute: () => '' };
        expect(hooks.isImageLikelyBroken(brokenNoSource)).toBe(false);

        const brokenWithSource = {
            complete: true,
            naturalWidth: 0,
            naturalHeight: 0,
            currentSrc: '',
            getAttribute: (name) => (name === 'src' ? 'https://example.test/broken.jpg' : ''),
        };
        expect(hooks.isImageLikelyBroken(brokenWithSource)).toBe(true);
    });

    it('normalizes lazy attributes with data-uri replacement rules', () => {
        const img = document.createElement('img');
        img.setAttribute('data-src', 'https://example.test/full.jpg');

        expect(hooks.normalizeLazyAttribute(img, {
            dataAttr: 'data-src',
            targetAttr: 'src',
            forceWhenDataUri: true,
        })).toBe(true);
        expect(img.getAttribute('src')).toBe('https://example.test/full.jpg');

        img.setAttribute('src', 'https://example.test/already-set.jpg');
        img.setAttribute('data-src', 'https://example.test/new.jpg');

        expect(hooks.normalizeLazyAttribute(img, {
            dataAttr: 'data-src',
            targetAttr: 'src',
            forceWhenDataUri: true,
        })).toBe(false);
        expect(img.getAttribute('src')).toBe('https://example.test/already-set.jpg');

        img.setAttribute('src', 'data:image/gif;base64,AAAA');
        expect(hooks.normalizeLazyAttribute(img, {
            dataAttr: 'data-src',
            targetAttr: 'src',
            forceWhenDataUri: true,
        })).toBe(true);
        expect(img.getAttribute('src')).toBe('https://example.test/new.jpg');
    });

    it('computes readiness summary counts by status', () => {
        const readiness = new Map([
            ['a', { status: 'loaded' }],
            ['b', { status: 'broken' }],
            ['c', { status: 'unresolved' }],
            ['d', { status: 'pending' }],
            ['e', { status: 'loaded' }],
        ]);

        const summary = hooks.createReadinessSummary(readiness);
        expect(summary.loadedCount).toBe(2);
        expect(summary.brokenCount).toBe(1);
        expect(summary.unresolvedCount).toBe(1);
        expect(summary.pendingCount).toBe(1);
    });

    it('tracks error-severity issues separately from warnings', () => {
        const report = hooks.createRunReport('https://example.test/source', [480], false);

        hooks.appendRunIssue(report, {
            severity: 'error',
            code: 'network-failure',
            message: 'network failed',
            source: 'https://example.test/image.jpg?secret=true',
        });

        hooks.appendRunIssue(report, {
            severity: 'warning',
            code: 'decode-failure',
            message: 'decode failed',
            source: 'https://example.test/image2.jpg?secret=true',
        });

        expect(report.totals.issueCount).toBe(2);
        expect(report.totals.errorCount).toBe(1);
        expect(report.totals.warningCount).toBe(1);
        expect(report.issues[0].severity).toBe('error');
        expect(report.issues[0].source).toBe('https://example.test/image.jpg');
    });

    it('builds structured output unloaded count from broken and unresolved rows', () => {
        const report = hooks.createRunReport('https://example.test/page', [480], false);
        report.totals.warningCount = 2;

        const result = hooks.buildStructuredOutput(
            'https://example.test/page',
            [480],
            {
                480: [
                    { assetId: '1', transform: 'hero', loaded: true, broken: false, unresolved: false },
                    { assetId: '2', transform: 'hero', loaded: false, broken: true, unresolved: false },
                    { assetId: '3', transform: 'card', loaded: false, broken: false, unresolved: true },
                ],
            },
            Date.now() - 50,
            report,
        );

        expect(result.runId).toBe(report.runId);
        expect(result.processingReport).toBe(report);
        expect(result.summary.assetCount).toBe(3);
        expect(result.summary.setCount).toBe(2);
        expect(result.summary.loadedImageCount).toBe(1);
        expect(result.summary.brokenImageCount).toBe(1);
        expect(result.summary.unresolvedImageCount).toBe(1);
        expect(result.summary.unloadedImageCount).toBe(2);
        expect(result.summary.warningCount).toBe(2);
    });

    it('prepares breakpoint images with normalization and eager loading attributes', async () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero" data-asset-id="asset-hero">
                <source data-bp-source="primary" data-bp-size="480" data-bp-enabled="true" data-srcset="https://example.test/hero-480.webp 1x" data-sizes="100vw" srcset="https://example.test/placeholder-source.jpg" />
                <img data-src="https://example.test/hero.jpg" data-srcset="https://example.test/hero@2x.jpg 2x" data-sizes="100vw" src="https://example.test/placeholder.jpg" class="lazyload" />
            </picture>
        `;

        hooks.setPreviewFrameForTests(frameDocument, {});
        const result = await hooks.prepareBreakpoints(480, {
            adapter: 'attributes',
            attributes: {
                src: 'data-src',
                srcset: 'data-srcset',
                sizes: 'data-sizes',
            },
        });

        const img = frameDocument.querySelector('img');
        const source = frameDocument.querySelector('source');

        expect(result.activationStrategies).toEqual(['attributes']);
        expect(result.normalizationCount).toBe(5);
        expect(img.getAttribute('loading')).toBe('eager');
        expect(img.getAttribute('fetchpriority')).toBe('high');
        expect(img.getAttribute('src')).toBe('https://example.test/hero.jpg');
        expect(img.getAttribute('srcset')).toBe('https://example.test/hero@2x.jpg 2x');
        expect(img.getAttribute('sizes')).toBe('100vw');
        expect(source.getAttribute('srcset')).toBe('https://example.test/hero-480.webp 1x');
        expect(source.getAttribute('sizes')).toBe('100vw');
    });

    it('forces the requested canonical source active without changing the viewport boundary', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero">
                <source data-bp-key="xl" data-bp-index="0" media="(max-width: 97.1875rem)" srcset="/xl.jpg 1x">
                <source data-bp-key="2xl" data-bp-index="1" media="(min-width: 97.1875rem)" srcset="/2xl.jpg 1x">
                <img src="/fallback.jpg">
            </picture>
        `;

        const picture = frameDocument.querySelector('picture');
        expect(window.__BPIRuntimeTestHookHarness.activateSlotSources([picture], { key: '2xl', index: 1 })).toBe(1);
        expect(picture.querySelector('source[data-bp-key="xl"]').getAttribute('media')).toBe('not all');
        expect(picture.querySelector('source[data-bp-key="2xl"]').getAttribute('media')).toBe('all');

        expect(window.__BPIRuntimeTestHookHarness.activateSlotSources([picture], { key: 'xl', index: 0 })).toBe(1);
        expect(picture.querySelector('source[data-bp-key="xl"]').getAttribute('media')).toBe('all');
        expect(picture.querySelector('source[data-bp-key="2xl"]').getAttribute('media')).toBe('not all');
    });

    it('extracts breakpoint rows honoring readiness states and fallback classification', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="alpha" data-picture-id="pic-1" data-asset-id="asset-1">
                <source data-bp-source="primary" data-bp-size="480" data-bp-enabled="true" srcset="https://example.test/alpha.webp 1x" />
                <img data-asset-id="asset-1" src="https://example.test/alpha.jpg" />
            </picture>
            <picture data-set="beta" data-picture-id="pic-2" data-asset-id="asset-2">
                <source data-bp-source="primary" data-bp-size="480" data-bp-enabled="true" data-bp-asset-id="asset-2-source" data-bp-asset-title="Beta source" srcset="https://example.test/beta.webp 1x" />
                <img data-asset-id="asset-2" src="https://example.test/beta.jpg" />
            </picture>
            <picture data-set="gamma" data-picture-id="pic-3" data-asset-id="asset-3">
                <source data-bp-source="primary" data-bp-size="480" data-bp-enabled="true" srcset="https://example.test/gamma.webp 1x" />
                <img data-asset-id="asset-3" src="https://example.test/gamma.jpg" />
            </picture>
            <picture data-set="delta" data-picture-id="pic-4" data-asset-id="asset-4">
                <source data-bp-source="primary" data-bp-size="480" data-bp-enabled="false" srcset="https://example.test/delta.webp 1x" />
                <img data-asset-id="asset-4" src="https://example.test/delta.jpg" />
            </picture>
        `;

        hooks.setPreviewFrameForTests(frameDocument, {});

        const images = frameDocument.querySelectorAll('img');
        Object.defineProperty(images[2], 'complete', { configurable: true, value: true });
        Object.defineProperty(images[2], 'naturalWidth', { configurable: true, value: 0 });
        Object.defineProperty(images[2], 'naturalHeight', { configurable: true, value: 0 });

        const preloadStates = new Map([
            ['pic-1', true],
            ['pic-2', false],
            ['pic-3', false],
            ['pic-4', false],
        ]);

        const readinessByKey = new Map([
            ['pic-1', {
                status: 'loaded',
                sourceUsed: 'https://example.test/alpha.jpg',
            }],
            ['pic-2', {
                status: 'unresolved',
                sourceUsed: 'https://example.test/beta.jpg',
            }],
        ]);

        const rows = hooks.extractRowsForBreakpoint(480, preloadStates, readinessByKey);

        expect(rows).toHaveLength(4);
        expect(rows[0].pictureId).toBe('pic-1');
        expect(rows[0].loaded).toBe(true);
        expect(rows[0].broken).toBe(false);
        expect(rows[0].unresolved).toBe(false);

        expect(rows[1].loaded).toBe(false);
        expect(rows[1].broken).toBe(false);
        expect(rows[1].unresolved).toBe(true);
        expect(rows[1].assetId).toBe('asset-2-source');
        expect(rows[1].title).toBe('Beta source');

        expect(rows[2].loaded).toBe(false);
        expect(rows[2].broken).toBe(true);
        expect(rows[2].unresolved).toBe(false);

        expect(rows[3].loaded).toBe(true);
        expect(rows[3].broken).toBe(false);
        expect(rows[3].unresolved).toBe(false);
    });

    it('marks lazy rows unresolved when the active source never promotes to the target', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero" data-picture-id="hero" data-asset-id="asset-hero">
                <source data-bp-source="primary" data-bp-size="480" data-bp-enabled="true" data-srcset="https://example.test/hero.webp 1x" srcset="https://example.test/placeholder.gif" />
                <img data-asset-id="asset-hero" src="https://example.test/placeholder.gif" />
            </picture>
        `;

        hooks.setPreviewFrameForTests(frameDocument, {});

        const readinessByKey = new Map([
            ['hero', {
                status: 'loaded',
                reason: 'lazy-target-not-promoted',
                sourceUsed: 'https://example.test/hero.webp',
                lazyTargetUrls: ['https://example.test/hero.webp'],
            }],
        ]);

        const rows = hooks.extractRowsForBreakpoint(480, new Map([['hero', true]]), readinessByKey);

        expect(rows).toHaveLength(1);
        expect(rows[0].loaded).toBe(false);
        expect(rows[0].unresolved).toBe(true);
        expect(rows[0].sourceUsed).toBe('');
    });

    it('ignores SVG processing markers while preserving raster rows', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero" data-picture-id="pic-raster" data-asset-id="asset-raster">
                <source data-bp-source="primary" data-bp-size="480" data-bp-enabled="true" srcset="https://example.test/hero.webp 1x" />
                <img data-asset-id="asset-raster" src="https://example.test/hero.jpg" />
            </picture>
            <picture data-set="icon" data-picture-id="pic-svg" data-asset-id="asset-svg" data-bp-processing-ignore="svg">
                <img data-asset-id="asset-svg" src="https://example.test/icon.svg" />
            </picture>
        `;

        hooks.setPreviewFrameForTests(frameDocument, {});

        const preloadStates = new Map([
            ['pic-raster', true],
            ['pic-svg', true],
        ]);
        const readinessByKey = new Map([
            ['pic-raster', {
                status: 'loaded',
                sourceUsed: 'https://example.test/hero.jpg',
            }],
            ['pic-svg', {
                status: 'loaded',
                sourceUsed: 'https://example.test/icon.svg',
            }],
        ]);

        const rows = hooks.extractRowsForBreakpoint(480, preloadStates, readinessByKey);

        expect(rows).toHaveLength(1);
        expect(rows[0].assetId).toBe('asset-raster');
        expect(rows[0].transform).toBe('hero');
    });

    it('builds readiness tracker statuses for static and dynamic image states', async () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="disabled" data-picture-id="disabled">
                <source data-bp-source="primary" data-bp-size="480" data-bp-enabled="false" srcset="https://example.test/disabled.webp 1x" />
                <img src="https://example.test/disabled.jpg" />
            </picture>
            <picture data-set="transparent" data-picture-id="transparent">
                <source data-bp-source="primary" data-bp-size="480" srcset="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" />
                <img src="https://example.test/transparent.jpg" />
            </picture>
            <picture data-set="preload" data-picture-id="preload">
                <source data-bp-source="primary" data-bp-size="480" srcset="https://example.test/preload.webp 1x" />
                <img src="https://example.test/preload.jpg" />
            </picture>
            <picture data-set="unsupported" data-picture-id="unsupported">
                <source data-bp-source="primary" data-bp-size="480"></source>
                <img />
            </picture>
            <picture data-set="complete-loaded" data-picture-id="complete-loaded">
                <source data-bp-source="primary" data-bp-size="480" srcset="https://example.test/complete-loaded.webp 1x" />
                <img src="https://example.test/complete-loaded.jpg" />
            </picture>
            <picture data-set="complete-broken" data-picture-id="complete-broken">
                <source data-bp-source="primary" data-bp-size="480" srcset="https://example.test/complete-broken.webp 1x" />
                <img src="https://example.test/complete-broken.jpg" />
            </picture>
            <picture data-set="decode" data-picture-id="decode">
                <source data-bp-source="primary" data-bp-size="480" srcset="https://example.test/decode.webp 1x" />
                <img src="https://example.test/decode.jpg" />
            </picture>
            <picture data-set="load" data-picture-id="load">
                <source data-bp-source="primary" data-bp-size="480" srcset="https://example.test/load.webp 1x" />
                <img src="https://example.test/load.jpg" />
            </picture>
            <picture data-set="error" data-picture-id="error">
                <source data-bp-source="primary" data-bp-size="480" srcset="https://example.test/error.webp 1x" />
                <img src="https://example.test/error.jpg" />
            </picture>
        `;

        hooks.setPreviewFrameForTests(frameDocument, {});

        const getImg = (key) => frameDocument.querySelector(`picture[data-picture-id="${key}"] img`);

        const completeLoadedImg = getImg('complete-loaded');
        Object.defineProperty(completeLoadedImg, 'complete', { configurable: true, value: true });
        Object.defineProperty(completeLoadedImg, 'naturalWidth', { configurable: true, value: 640 });
        Object.defineProperty(completeLoadedImg, 'naturalHeight', { configurable: true, value: 360 });

        const completeBrokenImg = getImg('complete-broken');
        Object.defineProperty(completeBrokenImg, 'complete', { configurable: true, value: true });
        Object.defineProperty(completeBrokenImg, 'naturalWidth', { configurable: true, value: 0 });
        Object.defineProperty(completeBrokenImg, 'naturalHeight', { configurable: true, value: 0 });

        const decodeImg = getImg('decode');
        Object.defineProperty(decodeImg, 'naturalWidth', { configurable: true, value: 1200 });
        Object.defineProperty(decodeImg, 'naturalHeight', { configurable: true, value: 800 });
        decodeImg.decode = () => Promise.resolve();

        const loadImg = getImg('load');
        Object.defineProperty(loadImg, 'naturalWidth', { configurable: true, writable: true, value: 0 });
        Object.defineProperty(loadImg, 'naturalHeight', { configurable: true, writable: true, value: 0 });

        const preloadImg = getImg('preload');
        Object.defineProperty(preloadImg, 'complete', { configurable: true, value: false });
        Object.defineProperty(preloadImg, 'naturalWidth', { configurable: true, writable: true, value: 0 });
        Object.defineProperty(preloadImg, 'naturalHeight', { configurable: true, writable: true, value: 0 });

        const tracker = hooks.buildBreakpointReadinessTracker(480, new Map([['preload', true]]));
        await Promise.resolve();

        expect(tracker.readinessByKey.get('preload').status).toBe('pending');
        preloadImg.naturalWidth = 640;
        preloadImg.naturalHeight = 360;
        preloadImg.dispatchEvent(new window.Event('load'));

        loadImg.naturalWidth = 900;
        loadImg.naturalHeight = 500;
        loadImg.dispatchEvent(new window.Event('load'));

        getImg('error').dispatchEvent(new window.Event('error'));

        const readiness = tracker.readinessByKey;
        expect(readiness.get('disabled').status).toBe('loaded');
        expect(readiness.get('disabled').reason).toBe('disabled-breakpoint');
        expect(readiness.get('transparent').status).toBe('loaded');
        expect(readiness.get('transparent').reason).toBe('transparent-placeholder');
        expect(readiness.get('preload').status).toBe('loaded');
        expect(readiness.get('preload').reason).toBe('load-event');
        expect(readiness.get('unsupported').status).toBe('broken');
        expect(readiness.get('unsupported').reason).toBe('unsupported-source');
        expect(readiness.get('complete-loaded').status).toBe('loaded');
        expect(readiness.get('complete-loaded').reason).toBe('complete');
        expect(readiness.get('complete-broken').status).toBe('broken');
        expect(readiness.get('complete-broken').reason).toBe('network');
        expect(readiness.get('decode').status).toBe('loaded');
        expect(readiness.get('decode').reason).toBe('decode');
        expect(readiness.get('load').status).toBe('loaded');
        expect(readiness.get('load').reason).toBe('load-event');
        expect(readiness.get('error').status).toBe('broken');
        expect(readiness.get('error').reason).toBe('network');

        tracker.cleanup();
    });

    it('waits for a lazy target instead of accepting a complete placeholder', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero" data-picture-id="hero">
                <source data-bp-source="primary" data-bp-size="480" data-srcset="https://example.test/hero.webp 1x" srcset="https://example.test/placeholder.gif" />
                <img src="https://example.test/placeholder.gif" />
            </picture>
        `;

        const img = frameDocument.querySelector('img');
        let currentSrc = 'https://example.test/placeholder.gif';
        Object.defineProperty(img, 'currentSrc', { configurable: true, get: () => currentSrc });
        Object.defineProperty(img, 'complete', { configurable: true, value: true });
        Object.defineProperty(img, 'naturalWidth', { configurable: true, value: 640 });
        Object.defineProperty(img, 'naturalHeight', { configurable: true, value: 360 });

        hooks.setPreviewFrameForTests(frameDocument, {});
        const tracker = hooks.buildBreakpointReadinessTracker(
            480,
            null,
            new Map([[img, ['https://example.test/hero.webp']]]),
        );
        const entry = tracker.readinessByKey.get('hero');

        expect(entry.status).toBe('pending');
        img.dispatchEvent(new window.Event('load'));
        expect(entry.status).toBe('pending');

        currentSrc = 'https://example.test/hero.webp';
        img.dispatchEvent(new window.Event('load'));
        expect(entry.status).toBe('loaded');
        expect(entry.sourceUsed).toBe('https://example.test/hero.webp');

        tracker.cleanup();
    });

    it('keeps substantial renderable images pending when lazy source matching is stale', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero" data-picture-id="hero">
                <source data-bp-source="primary" data-bp-size="480" data-srcset="https://example.test/hero.webp 1x" srcset="https://example.test/placeholder.gif" />
                <img src="https://example.test/placeholder.gif" />
            </picture>
        `;

        const img = frameDocument.querySelector('img');
        Object.defineProperty(img, 'currentSrc', {
            configurable: true,
            value: 'https://example.test/placeholder.gif',
        });
        Object.defineProperty(img, 'complete', { configurable: true, value: true });
        Object.defineProperty(img, 'naturalWidth', { configurable: true, value: 431 });
        Object.defineProperty(img, 'naturalHeight', { configurable: true, value: 242 });
        Object.defineProperty(img, 'clientWidth', { configurable: true, value: 431 });
        Object.defineProperty(img, 'clientHeight', { configurable: true, value: 242 });

        hooks.setPreviewFrameForTests(frameDocument, {});
        const tracker = hooks.buildBreakpointReadinessTracker(
            480,
            new Map([['hero', true]]),
            new Map([[img, ['https://example.test/hero.webp']]]),
        );
        const entry = tracker.readinessByKey.get('hero');

        expect(entry.status).toBe('pending');
        expect(entry.reason).toBe(null);
        expect(entry.sourceUsed).toBe('https://example.test/placeholder.gif');

        tracker.cleanup();
    });

    it('keeps substantial renderable images pending when the lazy target did not preload', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero" data-picture-id="hero">
                <source data-bp-source="primary" data-bp-size="480" data-srcset="https://example.test/fresh-transform.webp 1x" srcset="https://example.test/placeholder.gif" />
                <img src="https://example.test/placeholder.gif" />
            </picture>
        `;

        const img = frameDocument.querySelector('img');
        Object.defineProperty(img, 'currentSrc', {
            configurable: true,
            value: 'https://example.test/placeholder.gif',
        });
        Object.defineProperty(img, 'complete', { configurable: true, value: true });
        Object.defineProperty(img, 'naturalWidth', { configurable: true, value: 431 });
        Object.defineProperty(img, 'naturalHeight', { configurable: true, value: 242 });
        Object.defineProperty(img, 'clientWidth', { configurable: true, value: 431 });
        Object.defineProperty(img, 'clientHeight', { configurable: true, value: 242 });

        hooks.setPreviewFrameForTests(frameDocument, {});
        const tracker = hooks.buildBreakpointReadinessTracker(
            480,
            new Map([['hero', false]]),
            new Map([[img, ['https://example.test/fresh-transform.webp']]]),
        );
        const entry = tracker.readinessByKey.get('hero');

        expect(entry.status).toBe('pending');

        tracker.cleanup();
    });

    it('keeps duplicate picture ids as separate readiness entries', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="card" data-picture-id="repeat" data-asset-id="asset-1">
                <source data-bp-source="primary" data-bp-size="480" srcset="https://example.test/one.webp 1x" />
                <img src="https://example.test/one.jpg" />
            </picture>
            <picture data-set="card" data-picture-id="repeat" data-asset-id="asset-2">
                <source data-bp-source="primary" data-bp-size="480" srcset="https://example.test/two.webp 1x" />
                <img src="https://example.test/two.jpg" />
            </picture>
        `;

        hooks.setPreviewFrameForTests(frameDocument, {});
        const tracker = hooks.buildBreakpointReadinessTracker(480, null);

        expect(Array.from(tracker.readinessByKey.keys())).toEqual(['repeat#0', 'repeat#1']);

        tracker.cleanup();
    });

    it('skips pictures without a matching source for the target breakpoint', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="other" data-picture-id="other">
                <source data-bp-source="primary" data-bp-size="999" srcset="https://example.test/other.webp 1x" />
                <img src="https://example.test/other.jpg" />
            </picture>
        `;

        hooks.setPreviewFrameForTests(frameDocument, {});
        const tracker = hooks.buildBreakpointReadinessTracker(480, null);

        expect(tracker.readinessByKey.size).toBe(0);
        tracker.cleanup();
    });

    it('preloads breakpoint sources across source-state branches', async () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="missing" data-picture-id="missing">
                <source data-bp-source="primary" data-bp-size="999" srcset="https://example.test/missing.webp 1x" />
                <img />
            </picture>
            <picture data-set="disabled" data-picture-id="disabled">
                <source data-bp-source="primary" data-bp-size="480" data-bp-enabled="false" srcset="https://example.test/disabled.webp 1x" />
                <img />
            </picture>
            <picture data-set="transparent" data-picture-id="transparent">
                <source data-bp-source="primary" data-bp-size="480" srcset="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" />
                <img />
            </picture>
            <picture data-set="empty" data-picture-id="empty">
                <source data-bp-source="primary" data-bp-size="480" srcset="" />
                <img />
            </picture>
            <picture data-set="ok" data-picture-id="ok">
                <source data-bp-source="primary" data-bp-size="480" srcset="https://example.test/ok.webp 1x" sizes="100vw" />
                <img />
            </picture>
            <picture data-set="error" data-picture-id="error">
                <source data-bp-source="primary" data-bp-size="480" srcset="https://example.test/error.webp 1x" />
                <img />
            </picture>
        `;

        hooks.setPreviewFrameForTests(frameDocument, {});

        const OriginalImage = globalThis.Image;
        class MockImage {
            constructor() {
                this.listeners = {};
                this.sizes = '';
            }

            addEventListener(type, callback) {
                this.listeners[type] = callback;
            }

            removeEventListener(type) {
                delete this.listeners[type];
            }

            set srcset(value) {
                this._srcset = value;
                if (String(value).includes('/ok.')) {
                    this.listeners.load?.();
                } else {
                    this.listeners.error?.();
                }
            }

            get srcset() {
                return this._srcset || '';
            }
        }

        globalThis.Image = MockImage;

        try {
            const states = await hooks.preloadBreakpointSources(480, 5);
            expect(states.get('missing')).toBe(false);
            expect(states.get('disabled')).toBe(true);
            expect(states.get('transparent')).toBe(true);
            expect(states.get('empty')).toBe(true);
            expect(states.get('ok')).toBe(true);
            expect(states.get('error')).toBe(false);
        } finally {
            globalThis.Image = OriginalImage;
        }
    });

    it('builds waiting status messages with singular/plural and elapsed time', () => {
        expect(hooks.buildWaitingStatusMessage(480, 1)).toContain('1 image still pending at 480px.');
        expect(hooks.buildWaitingStatusMessage(768, 2)).toContain('2 images still pending at 768px.');
        expect(hooks.buildWaitingStatusMessage(1024, 3, 2100)).toContain('3 images still pending at 1024px (3s).');
    });

    it('activates lazysizes strategies and records strategy counts', async () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero"><img class="lazyload" /></picture>
            <picture data-set="card"><img class="lazyload" /></picture>
        `;

        const checkElemsCalls = [];
        let autoSizerCount = 0;
        let unveilCount = 0;

        const frameWindow = {
            lazySizes: {
                cfg: {
                    loadMode: 2,
                    expand: 400,
                    expFactor: 1.5,
                    hFac: 0.8,
                    loadHidden: false,
                },
                loader: {
                    checkElems: (isPriority) => {
                        checkElemsCalls.push(isPriority);
                    },
                    unveil: () => {
                        unveilCount += 1;
                    },
                },
                autoSizer: {
                    checkElems: () => {
                        autoSizerCount += 1;
                    },
                },
            },
        };

        const prepareResult = { activationStrategies: [] };
        await hooks.activateLazySizes(frameWindow, frameDocument, prepareResult);

        expect(checkElemsCalls).toEqual([true, true]);
        expect(autoSizerCount).toBe(2);
        expect(unveilCount).toBe(2);
        expect(prepareResult.activationStrategies).toContain('lazysizes:6');
        expect(frameWindow.lazySizes.cfg).toMatchObject({
            loadMode: 3,
            expand: 999999,
            expFactor: 1,
            hFac: 1,
            loadHidden: true,
        });
    });

    it('activates lazysizes for tracked picture sources with lazy source attrs', async () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero">
                <source data-srcset="/hero.webp 1x" srcset="/placeholder.gif 1x" />
                <img class="ls-is-cached lazyloaded lazyload" src="/placeholder.gif" />
            </picture>
        `;

        const unveiled = [];
        const frameWindow = {
            lazySizes: {
                loader: {
                    checkElems: vi.fn(),
                    unveil: (img) => {
                        unveiled.push(img);
                    },
                },
            },
        };

        const img = frameDocument.querySelector('img');
        img._lazyRace = true;
        img._lazyCache = true;

        const prepareResult = { activationStrategies: [] };
        await hooks.activateLazySizes(frameWindow, frameDocument, prepareResult);

        expect(unveiled).toEqual([img]);
        expect(img._lazyRace).toBeUndefined();
        expect(img._lazyCache).toBeUndefined();
        expect(img.classList.contains('lazyload')).toBe(true);
        expect(img.classList.contains('lazyloaded')).toBe(false);
        expect(img.classList.contains('lazyloading')).toBe(false);
        expect(img.classList.contains('ls-is-cached')).toBe(false);
        expect(prepareResult.activationStrategies).toContain('lazysizes:3');
        expect(prepareResult.activationSamples[0]).toMatchObject({
            adapter: 'lazysizes',
            action: 'api',
        });
        expect(prepareResult.activationSamples.find((sample) => sample.action === 'unveil')).toMatchObject({
            adapter: 'lazysizes',
            action: 'unveil',
            hasDataSrc: false,
            hasDataSrcset: false,
            sourceDataSrcsetCount: 1,
            hadLazyRace: true,
            hadLazyCache: true,
        });
    });

    it('runs lazysizes activation inside the frame realm when available', async () => {
        const originalLazySizes = window.lazySizes;
        document.body.insertAdjacentHTML('beforeend', `
            <picture data-set="hero">
                <source data-srcset="/hero.webp 1x" srcset="/placeholder.gif 1x" />
                <img class="ls-is-cached lazyloaded lazyload" src="/placeholder.gif" />
            </picture>
        `);
        const picture = document.body.lastElementChild;

        const unveiled = [];
        window.lazySizes = {
            cfg: {
                loadMode: 2,
                expand: 400,
                expFactor: 1.5,
                hFac: 0.8,
                loadHidden: false,
            },
            loader: {
                checkElems: vi.fn(),
                unveil: (img) => {
                    unveiled.push(img);
                },
            },
            autoSizer: {
                checkElems: vi.fn(),
            },
        };

        const img = document.querySelector('img');
        img._lazyRace = true;
        img._lazyCache = true;

        const prepareResult = { activationStrategies: [] };
        try {
            await hooks.activateLazySizes(window, document, prepareResult);
        } finally {
            window.lazySizes = originalLazySizes;
        }

        expect(unveiled).toEqual([img]);
        expect(img._lazyRace).toBeUndefined();
        expect(img._lazyCache).toBeUndefined();
        expect(prepareResult.activationStrategies).toContain('lazysizes-iframe:5');
        expect(prepareResult.activationSamples[0]).toMatchObject({
            adapter: 'lazysizes',
            action: 'iframe-api',
        });
        expect(prepareResult.activationSamples.find((sample) => sample.action === 'iframe-unveil')).toMatchObject({
            adapter: 'lazysizes',
            hasDataSrc: false,
            sourceDataSrcsetCount: 1,
            hadLazyRace: true,
            hadLazyCache: true,
        });
        expect(window.lazySizes).toBe(originalLazySizes);
        picture.remove();
    });

    it('activates vanilla-lazyload and lozad fallback observers', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero"><img data-src="/hero.jpg" /></picture>
            <picture data-set="card"><img data-srcset="/card.jpg 1x" /></picture>
            <picture data-set="lozad"><img class="lozad" /></picture>
        `;

        const vanillaCalls = {
            update: 0,
            loadAll: 0,
            load: 0,
            staticLoad: 0,
        };

        const instance = {
            update: () => {
                vanillaCalls.update += 1;
            },
            loadAll: () => {
                vanillaCalls.loadAll += 1;
            },
            load: () => {
                vanillaCalls.load += 1;
            },
        };

        let lozadSelector = null;
        let lozadObserveCount = 0;
        let lozadTriggerCount = 0;

        const frameWindow = {
            lazyLoadInstance: instance,
            LazyLoad: {
                load: () => {
                    vanillaCalls.staticLoad += 1;
                },
            },
            lozad: (selector) => {
                lozadSelector = selector;
                return {
                    observe: () => {
                        lozadObserveCount += 1;
                    },
                    triggerLoad: () => {
                        lozadTriggerCount += 1;
                    },
                };
            },
        };

        const prepareResult = { activationStrategies: [] };
        hooks.activateVanillaLazyLoad(frameWindow, frameDocument, prepareResult);
        hooks.activateLozad(frameWindow, frameDocument, prepareResult);

        expect(vanillaCalls.update).toBe(1);
        expect(vanillaCalls.loadAll).toBe(1);
        expect(vanillaCalls.load).toBe(2);
        expect(vanillaCalls.staticLoad).toBe(2);
        expect(prepareResult.activationStrategies).toContain('vanilla-lazyload:6');
        expect(lozadSelector).toBe('.lozad');
        expect(lozadObserveCount).toBe(1);
        expect(lozadTriggerCount).toBe(1);
        expect(prepareResult.activationStrategies).toContain('lozad:2');
    });

    it('captures configured lazy targets for a custom adapter', async () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero" data-picture-id="hero">
                <source data-bp-source="primary" data-bp-size="480" data-original-set="https://example.test/hero.webp 1x" />
                <img data-original="https://example.test/hero.jpg" src="https://example.test/placeholder.gif" />
            </picture>
        `;

        const frameWindow = {
            project: {
                prepareImages: () => Promise.resolve(),
            },
        };
        hooks.setPreviewFrameForTests(frameDocument, frameWindow);

        const result = await hooks.prepareBreakpoints(480, {
            adapter: 'custom',
            attributes: {
                src: 'data-original',
                srcset: 'data-original-set',
                sizes: 'data-sizes',
            },
            customHandler: 'window.project.prepareImages',
        });
        const img = frameDocument.querySelector('img');

        expect(result.activationStrategies).toContain('custom:1');
        expect(result.lazyTargetsByImage.get(img)).toEqual([
            'https://example.test/hero.webp',
            'https://example.test/hero.jpg',
        ]);
    });

    it('accepts lazy targets from each format source in the selected slot', async () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="hero" data-picture-id="hero">
                <source data-bp-source="primary" data-bp-size="480" data-bp-key="base" data-bp-index="0" data-original-set="https://example.test/hero.jpg 1x" />
                <source data-bp-source="secondary" data-bp-size="480" data-bp-key="base" data-bp-index="0" data-original-set="https://example.test/hero.webp 1x" />
                <source data-bp-source="primary" data-bp-size="768" data-bp-key="sm" data-bp-index="1" data-original-set="https://example.test/hero-sm.jpg 1x" />
                <img src="https://example.test/placeholder.gif" />
            </picture>
        `;

        const frameWindow = {
            project: {
                prepareImages: () => Promise.resolve(),
            },
        };
        hooks.setPreviewFrameForTests(frameDocument, frameWindow);

        const result = await hooks.prepareBreakpoints(480, {
            adapter: 'custom',
            attributes: {
                src: 'data-original',
                srcset: 'data-original-set',
                sizes: 'data-sizes',
            },
            customHandler: 'window.project.prepareImages',
        });
        const img = frameDocument.querySelector('img');

        expect(result.lazyTargetsByImage.get(img)).toEqual([
            'https://example.test/hero.jpg',
            'https://example.test/hero.webp',
        ]);

        Object.defineProperty(img, 'currentSrc', {
            configurable: true,
            value: 'https://example.test/hero.webp',
        });
        Object.defineProperty(img, 'complete', { configurable: true, value: true });
        Object.defineProperty(img, 'naturalWidth', { configurable: true, value: 640 });
        Object.defineProperty(img, 'naturalHeight', { configurable: true, value: 360 });

        const tracker = hooks.buildBreakpointReadinessTracker(
            480,
            null,
            result.lazyTargetsByImage,
        );
        expect(tracker.readinessByKey.get('hero').status).toBe('loaded');
        tracker.cleanup();
    });

    it('marks pending entries unresolved when cancelled during image wait', async () => {
        const readinessByKey = new Map([
            ['img-1', {
                status: 'pending',
                reason: null,
                sourceUsed: 'https://example.test/image.jpg',
                img: { complete: false, naturalWidth: 0, naturalHeight: 0 },
            }],
        ]);

        const result = await hooks.waitForImagesToSettle({
            readinessByKey,
            softDeadlineMs: 0,
            pollMs: 1,
            shouldStop: () => true,
        });

        expect(result.aborted).toBe(true);
        expect(result.unresolvedCount).toBe(1);
        expect(readinessByKey.get('img-1').status).toBe('unresolved');
        expect(readinessByKey.get('img-1').reason).toBe('cancelled');
    });

    it('returns empty wait summary when no readiness entries exist', async () => {
        const result = await hooks.waitForImagesToSettle({
            readinessByKey: new Map(),
        });

        expect(result.aborted).toBe(false);
        expect(result.timedOut).toBe(false);
        expect(result.pendingCount).toBe(0);
        expect(result.loadedCount).toBe(0);
        expect(result.brokenCount).toBe(0);
        expect(result.unresolvedCount).toBe(0);
    });

    it('classifies complete pending images as loaded or broken before waiting', async () => {
        const readinessByKey = new Map([
            ['loaded', {
                status: 'pending',
                reason: null,
                sourceUsed: 'https://example.test/loaded.jpg',
                img: { complete: true, naturalWidth: 1200, naturalHeight: 800 },
            }],
            ['broken', {
                status: 'pending',
                reason: null,
                sourceUsed: 'https://example.test/broken.jpg',
                img: { complete: true, naturalWidth: 0, naturalHeight: 0 },
            }],
        ]);

        const result = await hooks.waitForImagesToSettle({
            readinessByKey,
            pollMs: 1,
        });

        expect(result.aborted).toBe(false);
        expect(result.loadedCount).toBe(1);
        expect(result.brokenCount).toBe(1);
        expect(readinessByKey.get('loaded').status).toBe('loaded');
        expect(readinessByKey.get('loaded').reason).toBe('complete');
        expect(readinessByKey.get('broken').status).toBe('broken');
        expect(readinessByKey.get('broken').reason).toBe('network');
    });

    it('marks pending renderable images unresolved when lazy target matching remains stale', async () => {
        const img = document.createElement('img');
        const source = document.createElement('source');
        source.setAttribute('srcset', 'https://example.test/placeholder.gif 1x');
        Object.defineProperty(img, 'currentSrc', {
            configurable: true,
            value: 'https://example.test/placeholder.gif',
        });
        Object.defineProperty(img, 'complete', { configurable: true, value: true });
        Object.defineProperty(img, 'naturalWidth', { configurable: true, value: 431 });
        Object.defineProperty(img, 'naturalHeight', { configurable: true, value: 242 });
        Object.defineProperty(img, 'clientWidth', { configurable: true, value: 431 });
        Object.defineProperty(img, 'clientHeight', { configurable: true, value: 242 });

        const readinessByKey = new Map([
            ['image', {
                status: 'pending',
                reason: null,
                sourceUsed: 'https://example.test/hero.webp',
                preloadLoaded: true,
                source,
                img,
                lazyTargetUrls: ['https://example.test/hero.webp'],
            }],
        ]);

        const result = await hooks.waitForImagesToSettle({
            readinessByKey,
            pollMs: 1,
        });

        expect(result.aborted).toBe(false);
        expect(result.unresolvedCount).toBe(1);
        expect(readinessByKey.get('image').status).toBe('unresolved');
        expect(readinessByKey.get('image').reason).toBe('lazy-target-not-promoted');
        expect(readinessByKey.get('image').sourceUsed).toBe('https://example.test/placeholder.gif');
    });

    it('emits soft-deadline and waiting-tick callbacks during long waits', async () => {
        const readinessByKey = new Map([
            ['pending', {
                status: 'pending',
                reason: null,
                sourceUsed: 'https://example.test/pending.jpg',
                img: { complete: false, naturalWidth: 0, naturalHeight: 0 },
            }],
        ]);

        const softDeadlineEvents = [];
        const waitingTickEvents = [];
        let shouldStopCalls = 0;

        const nowValues = [0, 0, 1200, 1200, 1200, 1200, 1200, 1200];
        let nowIndex = 0;
        const nowSpy = vi.spyOn(Date, 'now').mockImplementation(() => {
            const value = nowValues[Math.min(nowIndex, nowValues.length - 1)];
            nowIndex += 1;
            return value;
        });

        try {
            const result = await hooks.waitForImagesToSettle({
                readinessByKey,
                softDeadlineMs: 0,
                pollMs: 1,
                shouldStop: () => {
                    shouldStopCalls += 1;
                    return shouldStopCalls >= 3;
                },
                onSoftDeadline: (payload) => {
                    softDeadlineEvents.push(payload);
                },
                onWaitingTick: (payload) => {
                    waitingTickEvents.push(payload);
                },
            });

            expect(result.aborted).toBe(true);
            expect(result.timedOut).toBe(true);
            expect(softDeadlineEvents).toHaveLength(1);
            expect(softDeadlineEvents[0].pendingCount).toBe(1);
            expect(waitingTickEvents.length).toBeGreaterThanOrEqual(1);
            expect(waitingTickEvents[0].pendingCount).toBe(1);
        } finally {
            nowSpy.mockRestore();
        }
    });

    it('appends readiness issues for broken and unresolved entries', () => {
        const report = hooks.createRunReport('https://example.test/source', [480], false);
        const breakpointReport = { issueCount: 0 };

        const brokenImg = document.createElement('img');
        brokenImg.setAttribute('data-asset-id', 'asset-broken');
        const brokenSource = document.createElement('source');
        brokenSource.setAttribute('data-bp-asset-id', 'asset-broken-source');

        const unresolvedImg = document.createElement('img');
        unresolvedImg.setAttribute('data-asset-id', 'asset-unresolved');

        const readinessByKey = new Map([
            ['broken', {
                status: 'broken',
                reason: 'decode',
                sourceUsed: 'https://example.test/broken.jpg?token=secret',
                img: brokenImg,
                source: brokenSource,
            }],
            ['unresolved', {
                status: 'unresolved',
                reason: 'cancelled',
                sourceUsed: 'https://example.test/pending.jpg?token=secret',
                img: unresolvedImg,
            }],
        ]);

        hooks.appendBreakpointReadinessIssues(report, breakpointReport, 480, readinessByKey);

        expect(report.totals.issueCount).toBe(2);
        expect(report.totals.warningCount).toBe(2);
        expect(breakpointReport.issueCount).toBe(2);
        expect(report.issues[0].code).toBe('decode-failure');
        expect(report.issues[0].assetId).toBe('asset-broken-source');
        expect(report.issues[0].source).toBe('https://example.test/broken.jpg');
        expect(report.issues[1].code).toBe('unresolved-on-cancel');
        expect(report.issues[1].source).toBe('https://example.test/pending.jpg');
    });

    it('maps readiness issue codes for unsupported-source and network failures', () => {
        const report = hooks.createRunReport('https://example.test/source', [480], false);
        const breakpointReport = { issueCount: 0 };

        const unsupportedImg = document.createElement('img');
        unsupportedImg.setAttribute('data-asset-id', 'asset-unsupported');

        const networkImg = document.createElement('img');
        networkImg.setAttribute('data-asset-id', 'asset-network');

        const readinessByKey = new Map([
            ['unsupported', {
                status: 'broken',
                reason: 'unsupported-source',
                sourceUsed: 'https://example.test/no-source.jpg?token=secret',
                img: unsupportedImg,
            }],
            ['network', {
                status: 'broken',
                reason: 'network',
                sourceUsed: 'https://example.test/network.jpg?token=secret',
                img: networkImg,
            }],
        ]);

        hooks.appendBreakpointReadinessIssues(report, breakpointReport, 480, readinessByKey);

        expect(report.issues[0].code).toBe('unsupported-source');
        expect(report.issues[0].source).toBe('https://example.test/no-source.jpg');
        expect(report.issues[1].code).toBe('network-failure');
        expect(report.issues[1].source).toBe('https://example.test/network.jpg');
    });

    it('activates lozad observer instances when available', () => {
        const frameDocument = document.implementation.createHTMLDocument('preview');
        frameDocument.body.innerHTML = `
            <picture data-set="lozad"><img class="lozad" /></picture>
            <picture data-set="lozad"><img class="lozad" /></picture>
        `;

        let observeCount = 0;
        let triggerCount = 0;

        const observer = {
            observe: () => {
                observeCount += 1;
            },
            triggerLoad: () => {
                triggerCount += 1;
            },
        };

        const frameWindow = {
            lozadObserver: observer,
            lozad: () => {
                throw new Error('fallback should not run when observer exists');
            },
        };

        const prepareResult = { activationStrategies: [] };
        hooks.activateLozad(frameWindow, frameDocument, prepareResult);

        expect(observeCount).toBe(1);
        expect(triggerCount).toBe(2);
        expect(prepareResult.activationStrategies).toContain('lozad:3');
    });

    it('publishes processing report events and stores last report state', () => {
        const report = hooks.createRunReport('https://example.test/source', [480], false);
        let eventPayload = null;

        const onReport = (event) => {
            eventPayload = event.detail;
        };

        document.addEventListener('bpi:processing-report', onReport, { once: true });
        hooks.publishRunReport(report);

        expect(eventPayload).toBe(report);
        expect(hooks.getLastReport()).toBe(report);
    });

    it('labels saved and page-processed review states', () => {
        const label = document.getElementById('bpts-review-state-label');
        const bridge = document.getElementById('bpts-ui-results-heading-signal-bridge');

        expect(label.textContent).toBe('Saved Sets');
        expect(bridge.value).toBe('Saved Sets');

        hooks.setLastResultForTests({ rowsByBreakpoint: {} });

        expect(label.textContent).toBe('Page Processed Sets');
        expect(bridge.value).toBe('Page Processed Sets');

        hooks.setLastResultForTests(null);

        expect(label.textContent).toBe('Saved Sets');
        expect(bridge.value).toBe('Saved Sets');
    });

    it('collects review edit state from rendered transform cards', () => {
        const visualResults = document.getElementById('bpts-visual-results');
        visualResults.innerHTML = `
            <article class="bpts-transform-card" data-set="hero" data-scope-mode="all" data-active-tab="settings" data-selected-asset-key="asset-hero"></article>
            <article class="bpts-transform-card" data-set="card" data-scope-mode="breakpoint" data-scope-breakpoint="768" data-active-tab="ratio" data-selected-asset-key="asset-card"></article>
            <article class="bpts-transform-card" data-set="gallery" data-scope-mode="breakpoint" data-scope-breakpoint="1024" data-active-tab="notes"></article>
            <article class="bpts-transform-card" data-set="teaser" data-scope-mode="invalid" data-active-tab="invalid"></article>
        `;

        const state = hooks.collectReviewEditStateFromDom();

        expect(state.preferredOrderBySet).toEqual(['hero', 'card', 'gallery', 'teaser']);
        expect(state.editScopeBySet.hero).toEqual({ mode: 'all', breakpoint: null });
        expect(state.editScopeBySet.card).toEqual({ mode: 'breakpoint', breakpoint: 768 });
        expect(state.editScopeBySet.gallery).toEqual({ mode: 'breakpoint', breakpoint: 1024 });
        expect(state.editScopeBySet.teaser).toEqual({ mode: 'all', breakpoint: null });
        expect(state.editTabBySet.hero).toBe('settings');
        expect(state.editTabBySet.card).toBe('ratio');
        expect(state.editTabBySet.gallery).toBe('notes');
        expect(state.editTabBySet.teaser).toBe('dimensions');
        expect(state.selectedAssetKeyBySet.hero).toBe('asset-hero');
        expect(state.selectedAssetKeyBySet.card).toBe('asset-card');
    });

    it('maps report issue codes into failure reason counts', () => {
        const counts = hooks.summarizeFailureReasonCountsFromReport({
            issues: [
                { code: 'network-failure' },
                { code: 'network-failure' },
                { code: 'decode-failure' },
                { code: 'unsupported-source' },
                { code: 'unresolved-on-cancel' },
                { code: 'processing-markers-missing' },
                { code: 'other' },
            ],
        });

        expect(counts).toEqual({
            network: 2,
            decode: 1,
            'unsupported-source': 1,
            cancelled: 1,
            'markers-missing': 1,
        });
    });

    it('fails processing before measurement when iframe image markup has no processing markers', async () => {
        const frameDocument = document.implementation.createHTMLDocument('Cached preview');
        frameDocument.body.innerHTML = '<picture><source srcset="/cached.webp 1x"><img src="/cached.jpg"></picture>';
        hooks.setPreviewFrameForTests(frameDocument);

        const setPreviewWidth = vi.fn();
        const publishResult = vi.fn();
        const persistRunSnapshot = vi.fn().mockResolvedValue(true);

        hooks.setRunProcessingOverridesForTests({
            resolveSelectedEntryUrl: vi.fn().mockResolvedValue('https://example.test/source'),
            ensurePreviewFrame: vi.fn().mockResolvedValue(undefined),
            setPreviewWidth,
            publishResult,
            persistRunSnapshot,
        });

        await hooks.runProcessing();

        expect(setPreviewWidth).not.toHaveBeenCalled();
        expect(publishResult).not.toHaveBeenCalled();
        expect(persistRunSnapshot).toHaveBeenCalledTimes(1);

        const [report, rowsBySlot] = persistRunSnapshot.mock.calls[0];
        expect(report.status).toBe('failed');
        expect(report.failure.stage).toBe('inspect-processing-markers');
        expect(report.issues).toEqual(expect.arrayContaining([
            expect.objectContaining({
                severity: 'error',
                code: 'processing-markers-missing',
                message: 'Breakpoints processing markers were not found. Check whether local full-page/static caching is enabled.',
            }),
        ]));
        expect(rowsBySlot).toEqual({});
        expect(document.getElementById('bpts-status').textContent)
            .toContain('Breakpoints processing markers were not found.');
    });

    it('persists run snapshots and refreshes review when persistence succeeds', async () => {
        const report = hooks.createRunReport('https://example.test/source', [480], false);
        report.status = 'completed';
        report.completedAt = '2026-04-18T00:00:00.000Z';

        hooks.setLastResultForTests({
            summary: { warningCount: 0 },
            rowsByBreakpoint: { 480: [] },
        });

        const sendActionRequest = vi.fn().mockImplementation((_method, action) => {
            if (action === 'breakpoints/transforms/persist-run-snapshot') {
                return Promise.resolve({ data: { ok: true } });
            }

            if (action === 'breakpoints/transforms/render-result-review') {
                return Promise.resolve({
                    data: {
                        warningsHtml: '<div>ok</div>',
                        visualResultsHtml: '<div class="bpts-transform-card" data-set="hero"></div>',
                        warningCount: 0,
                    },
                });
            }

            return Promise.reject(new Error(`Unexpected action: ${action}`));
        });

        window.Craft = { sendActionRequest };

        const ok = await hooks.persistRunSnapshot(report, { 480: [] });

        expect(ok).toBe(true);
        expect(sendActionRequest).toHaveBeenNthCalledWith(
            1,
            'POST',
            'breakpoints/transforms/persist-run-snapshot',
            expect.objectContaining({
                data: expect.objectContaining({
                    runStatus: 'completed',
                    sourceUrl: 'https://example.test/source',
                }),
            }),
        );
        expect(sendActionRequest).toHaveBeenNthCalledWith(
            2,
            'POST',
            'breakpoints/transforms/render-result-review',
            expect.any(Object),
        );
    });

    it('returns false when snapshot persistence response is not ok', async () => {
        const report = hooks.createRunReport('https://example.test/source', [480], false);
        hooks.setLastResultForTests({ summary: { warningCount: 0 }, rowsByBreakpoint: { 480: [] } });

        const sendActionRequest = vi.fn().mockResolvedValue({ data: { ok: false } });
        window.Craft = { sendActionRequest };

        const ok = await hooks.persistRunSnapshot(report, { 480: [] });

        expect(ok).toBe(false);
        expect(sendActionRequest).toHaveBeenCalledTimes(1);
        expect(sendActionRequest).toHaveBeenCalledWith(
            'POST',
            'breakpoints/transforms/persist-run-snapshot',
            expect.any(Object),
        );
    });

    it('sends failed run status in snapshot persistence payload', async () => {
        const report = hooks.createRunReport('https://example.test/source', [480], false);
        const finalized = hooks.finalizeRunReport(report, {
            status: 'failed',
            resultPublished: false,
            failureStage: 'prepare',
            failureMessage: 'prepare failed',
            rowsByBreakpoint: {},
        });

        hooks.setLastResultForTests(null);

        const sendActionRequest = vi.fn().mockResolvedValue({ data: { ok: true } });
        window.Craft = { sendActionRequest };

        const ok = await hooks.persistRunSnapshot(finalized, {});

        expect(ok).toBe(true);
        expect(sendActionRequest).toHaveBeenCalledWith(
            'POST',
            'breakpoints/transforms/persist-run-snapshot',
            expect.objectContaining({
                data: expect.objectContaining({
                    runStatus: 'failed',
                }),
            }),
        );
    });

    it('sends cancelled run status in snapshot persistence payload', async () => {
        const report = hooks.createRunReport('https://example.test/source', [480], false);
        const finalized = hooks.finalizeRunReport(report, {
            status: 'cancelled',
            resultPublished: false,
            failureStage: 'wait',
            failureMessage: 'user cancelled',
            rowsByBreakpoint: { xs: [{ transform: 'hero', loaded: false, broken: false, unresolved: true }] },
            rowsBySlot: { xs: [{ transform: 'hero', loaded: false, broken: false, unresolved: true }] },
        });

        hooks.setLastResultForTests(null);

        const sendActionRequest = vi.fn().mockResolvedValue({ data: { ok: true } });
        window.Craft = { sendActionRequest };

        const ok = await hooks.persistRunSnapshot(finalized, {
            xs: [{ transform: 'hero', loaded: false, broken: false, unresolved: true }],
        });

        expect(ok).toBe(true);
        expect(sendActionRequest).toHaveBeenCalledWith(
            'POST',
            'breakpoints/transforms/persist-run-snapshot',
            expect.objectContaining({
                data: expect.objectContaining({
                    runStatus: 'cancelled',
                    rowsBySlot: expect.objectContaining({
                        xs: expect.arrayContaining([
                            expect.objectContaining({ transform: 'hero' }),
                        ]),
                    }),
                }),
            }),
        );
    });

    it('includes rowsBySlot with first asset data in persistence payload', async () => {
        const report = hooks.createRunReport('https://example.test/source', [480, 768], false);
        const rowsBySlot = {
            xs: [
                { transform: 'hero', src: 'https://example.test/hero-480.jpg', loaded: true, broken: false, unresolved: false, rendered: { width: 480, height: 320 } },
                { transform: 'thumb', src: 'https://example.test/thumb-480.jpg', loaded: true, broken: false, unresolved: false, rendered: { width: 100, height: 100 } },
            ],
            sm: [
                { transform: 'hero', src: 'https://example.test/hero-768.jpg', loaded: true, broken: false, unresolved: false, rendered: { width: 768, height: 512 } },
            ],
        };

        const finalized = hooks.finalizeRunReport(report, {
            status: 'completed',
            resultPublished: true,
            rowsByBreakpoint: rowsBySlot,
            rowsBySlot,
        });

        hooks.setLastResultForTests({ summary: { warningCount: 0 }, rowsBySlot });

        const sendActionRequest = vi.fn().mockImplementation((_method, action) => {
            if (action === 'breakpoints/transforms/persist-run-snapshot') {
                return Promise.resolve({ data: { ok: true } });
            }
            return Promise.resolve({
                data: { warningsHtml: '', visualResultsHtml: '', warningCount: 0 },
            });
        });
        window.Craft = { sendActionRequest };

        const ok = await hooks.persistRunSnapshot(finalized, rowsBySlot);

        expect(ok).toBe(true);
        const persistCall = sendActionRequest.mock.calls.find(
            (c) => c[1] === 'breakpoints/transforms/persist-run-snapshot',
        );
        expect(persistCall).toBeTruthy();
        const payload = persistCall[2].data;
        expect(payload.rowsBySlot.xs).toHaveLength(2);
        expect(payload.rowsBySlot.sm).toHaveLength(1);
        expect(payload.rowsBySlot.xs[0].transform).toBe('hero');
        expect(payload.rowsBySlot.xs[0].src).toBe('https://example.test/hero-480.jpg');
    });

    it('short-circuits processing with status when no configured breakpoints exist', async () => {
        window.bpiProcessingConfig.breakpointValues = [];
        window.bpiProcessingConfig.breakpointSlots = [];

        await hooks.runProcessing();

        expect(document.getElementById('bpts-status').textContent)
            .toContain('No configured breakpoints available. Check plugin settings.');

        window.bpiProcessingConfig.breakpointValues = [480, 768];
        window.bpiProcessingConfig.breakpointSlots = [
            { key: 'xs', index: 0, mediaWidth: 480, measureWidth: 480, isBase: true, isFinal: false },
            { key: 'sm', index: 1, mediaWidth: 768, measureWidth: 768, isBase: false, isFinal: true },
        ];
    });

    it('completes runProcessing success flow with expected report and completion status', async () => {
        const sourceEntry = document.getElementById('bpts-source-entry');
        sourceEntry.innerHTML = '<input type="hidden" name="bpts-source-entry-id" value="42" />';

        let publishedResult = null;
        let persistCallCount = 0;
        let preloadCallCount = 0;
        let waitCallCount = 0;

        hooks.setRunProcessingOverridesForTests({
            resolveSelectedEntryUrl: async () => 'https://example.test/page?secret=1',
            ensurePreviewFrame: async () => null,
            setPreviewWidth: async () => null,
            prepareBreakpoints: () => ({
                activationStrategies: ['none'],
                normalizationCount: 0,
                normalizationSamples: [],
            }),
            preloadBreakpointSources: async () => {
                preloadCallCount += 1;
                return new Map([['pic-1', true]]);
            },
            buildBreakpointReadinessTracker: () => ({
                readinessByKey: new Map([
                    ['pic-1', {
                        status: 'loaded',
                        reason: 'preload',
                        sourceUsed: 'https://example.test/asset.jpg',
                        img: document.createElement('img'),
                        picture: document.createElement('picture'),
                    }],
                ]),
                cleanup: () => { },
            }),
            waitForImagesToSettle: async () => {
                waitCallCount += 1;
                return {
                    aborted: false,
                    waitedMs: 6,
                };
            },
            extractRowsForBreakpoint: (breakpoint) => [{
                assetId: `asset-${breakpoint}`,
                transform: 'hero',
                loaded: true,
                broken: false,
                unresolved: false,
            }],
            publishResult: async (result) => {
                publishedResult = result;
            },
            persistRunSnapshot: async () => {
                persistCallCount += 1;
                return true;
            },
            // Auto-save runs by default after a successful process; with no new
            // sets applied the flow falls straight through to publishing.
            autoApplyNewSets: async () => ({
                ok: true,
                persisted: true,
                appliedCount: 0,
                skippedCount: 1,
                skipped: [{ name: 'hero', reason: 'already_saved' }],
            }),
        });

        await hooks.runProcessing();

        expect(preloadCallCount).toBe(2);
        expect(waitCallCount).toBe(2);
        expect(persistCallCount).toBe(1);
        expect(publishedResult).toBeTruthy();
        expect(publishedResult.summary.loadedImageCount).toBe(2);
        expect(hooks.getLastReport().status).toBe('completed');
        expect(hooks.getLastReport().resultPublished).toBe(true);
        const status = document.getElementById('bpts-status');
        expect(status.textContent).toContain('All passed. No new sets');
        expect(status.textContent).not.toContain('could not be auto-saved');
        expect(status.classList.contains('bpts-header-status-success')).toBe(true);
        expect(status.querySelector('[data-icon="check"]')).toBeTruthy();
    });

    it('shows warning completion when rendered review reports breakpoint mismatches', async () => {
        const sourceEntry = document.getElementById('bpts-source-entry');
        sourceEntry.innerHTML = '<input type="hidden" name="bpts-source-entry-id" value="42" />';

        window.Craft = {
            sendActionRequest: vi.fn().mockResolvedValue({
                data: {
                    warningsHtml: '',
                    visualResultsHtml: '',
                    warningCount: 0,
                    breakpointMismatchCount: 1,
                    assetMismatchCount: 0,
                    mismatchCount: 1,
                },
            }),
        };

        hooks.setRunProcessingOverridesForTests({
            resolveSelectedEntryUrl: async () => 'https://example.test/page',
            ensurePreviewFrame: async () => null,
            setPreviewWidth: async () => null,
            prepareBreakpoints: () => ({
                activationStrategies: ['none'],
                normalizationCount: 0,
                normalizationSamples: [],
            }),
            preloadBreakpointSources: async () => new Map([['pic-1', true]]),
            buildBreakpointReadinessTracker: () => ({
                readinessByKey: new Map([
                    ['pic-1', {
                        status: 'loaded',
                        reason: 'preload',
                        sourceUsed: 'https://example.test/asset.jpg',
                        img: document.createElement('img'),
                        picture: document.createElement('picture'),
                    }],
                ]),
                cleanup: () => { },
            }),
            waitForImagesToSettle: async () => ({ aborted: false, waitedMs: 1 }),
            extractRowsForBreakpoint: (breakpoint) => [{
                assetId: `asset-${breakpoint}`,
                transform: 'hero',
                loaded: true,
                broken: false,
                unresolved: false,
            }],
            persistRunSnapshot: async () => true,
            autoApplyNewSets: async () => ({
                ok: true,
                persisted: true,
                appliedCount: 0,
                skippedCount: 1,
                skipped: [{ name: 'hero', reason: 'already_saved' }],
            }),
        });

        await hooks.runProcessing();

        const status = document.getElementById('bpts-status');
        expect(status.textContent).toContain('Warnings to address');
        expect(status.textContent).not.toContain('All passed');
        expect(status.textContent).not.toContain('No new sets');
        expect(status.classList.contains('bpts-header-status-warning')).toBe(true);
        expect(status.querySelector('[data-icon="alert"]')).toBeTruthy();
    });

    it('selects per-set rows from normal and escape final-source passes', async () => {
        const sourceEntry = document.getElementById('bpts-source-entry');
        sourceEntry.innerHTML = '<input type="hidden" name="bpts-source-entry-id" value="42" />';
        window.bpiProcessingConfig.breakpointSlots = [
            { key: 'xl', index: 0, mediaWidth: 1536, measureWidth: 1536, isBase: true, isFinal: false },
            { key: '2xl', index: 1, mediaWidth: 1536, measureWidth: 1920, isBase: false, isFinal: true },
        ];

        const measuredWidths = [];
        let persistedRows = null;

        hooks.setRunProcessingOverridesForTests({
            resolveSelectedEntryUrl: async () => 'https://example.test/page',
            ensurePreviewFrame: async () => null,
            setPreviewWidth: async (width) => measuredWidths.push(width),
            prepareBreakpoints: () => ({
                activationStrategies: ['none'],
                normalizationCount: 0,
                normalizationSamples: [],
            }),
            preloadBreakpointSources: async () => new Map(),
            buildBreakpointReadinessTracker: (slot) => {
                const isFinal = slot.key === '2xl';
                const isEscapePass = isFinal && slot.measureWidth === 1920;
                const offPicture = document.createElement('picture');
                offPicture.setAttribute('data-picture-id', 'escape-off-picture');
                offPicture.setAttribute('data-include-escape-width', 'false');
                const onPicture = document.createElement('picture');
                onPicture.setAttribute('data-picture-id', 'escape-on-picture');
                onPicture.setAttribute('data-include-escape-width', 'true');

                return {
                    readinessByKey: new Map([
                        ['escape-off-picture', {
                            status: isFinal && isEscapePass ? 'broken' : 'loaded',
                            reason: isFinal && isEscapePass ? 'network' : 'preload',
                            picture: offPicture,
                            img: document.createElement('img'),
                        }],
                        ['escape-on-picture', {
                            status: !isFinal || isEscapePass ? 'loaded' : 'broken',
                            reason: !isFinal || isEscapePass ? 'preload' : 'network',
                            picture: onPicture,
                            img: document.createElement('img'),
                        }],
                    ]),
                    cleanup: () => { },
                };
            },
            waitForImagesToSettle: async () => ({ aborted: false, waitedMs: 0 }),
            extractRowsForBreakpoint: (slot) => {
                const isFinal = slot.key === '2xl';
                const isEscapePass = isFinal && slot.measureWidth === 1920;
                return [
                    {
                        pictureId: 'escape-off-picture',
                        assetId: 'asset-off',
                        transform: 'escape-off',
                        includeEscapeWidth: false,
                        slotKey: slot.key,
                        slotIndex: slot.index,
                        mediaWidth: slot.mediaWidth,
                        measureWidth: slot.measureWidth,
                        sourceUsed: isFinal ? '/off-final.jpg' : '/off-xl.jpg',
                        transformDimensions: { width: isFinal ? 1900 : 1500, height: null, autoDimension: null },
                        rendered: isEscapePass ? { width: 1800, height: 900 } : { width: 1500, height: 760 },
                        isVisible: !isEscapePass,
                        loaded: true,
                        broken: false,
                        unresolved: false,
                    },
                    {
                        pictureId: 'escape-on-picture',
                        assetId: 'asset-on',
                        transform: 'escape-on',
                        includeEscapeWidth: true,
                        slotKey: slot.key,
                        slotIndex: slot.index,
                        mediaWidth: slot.mediaWidth,
                        measureWidth: slot.measureWidth,
                        sourceUsed: isFinal ? '/on-final.jpg' : '/on-xl.jpg',
                        transformDimensions: { width: isFinal ? 1920 : 1535, height: null, autoDimension: null },
                        rendered: isEscapePass ? { width: 1820, height: 910 } : { width: 1520, height: 760 },
                        isVisible: isEscapePass,
                        loaded: true,
                        broken: false,
                        unresolved: false,
                    },
                ];
            },
            persistRunSnapshot: async (_report, rowsBySlot) => {
                persistedRows = rowsBySlot;
                return true;
            },
            publishResult: async () => { },
            autoApplyNewSets: async () => ({ ok: true, persisted: true, appliedCount: 0, skippedCount: 0, skipped: [] }),
        });

        await hooks.runProcessing();

        expect(measuredWidths).toEqual([1535, 1536, 1920]);
        expect(persistedRows['2xl']).toHaveLength(2);
        expect(persistedRows['2xl'][0]).toEqual(expect.objectContaining({
            slotKey: '2xl',
            measureWidth: 1536,
            sourceUsed: '/off-final.jpg',
            transformDimensions: { width: 1900, height: null, autoDimension: null },
            rendered: { width: 1500, height: 760 },
            isVisible: true,
        }));
        expect(persistedRows['2xl'][1]).toEqual(expect.objectContaining({
            slotKey: '2xl',
            measureWidth: 1920,
            sourceUsed: '/on-final.jpg',
            rendered: { width: 1820, height: 910 },
            isVisible: true,
        }));
        expect(hooks.getLastReport().totals.breakpointCount).toBe(2);
        expect(hooks.getLastReport().issues).toEqual([]);
        expect(hooks.getLastReport().breakpoints[1]).toEqual(expect.objectContaining({
            loadedCount: 2,
            brokenCount: 0,
            unresolvedCount: 0,
        }));
    });

    it('auto-applies observed processed sets even when the saved-name signal is stale', async () => {
        document.getElementById('bpts-sidebar-saved-set-names-signal-bridge').value = '["image-test"]';

        const requestedSets = hooks.buildAutoApplyNewSetDescriptors({
            rowsBySlot: {
                base: [{
                    assetId: '2458',
                    transform: 'image-test',
                    sourceUsed: 'https://example.test/image-test.jpg',
                    loaded: true,
                    broken: false,
                    unresolved: false,
                }],
            },
        });

        expect(requestedSets).toEqual([{
            name: 'image-test',
            selectedAssetKey: 'asset:image-test:2458',
        }]);
    });

    it('reports cancelled processing when wait is aborted and avoids publishing results', async () => {
        const sourceEntry = document.getElementById('bpts-source-entry');
        sourceEntry.innerHTML = '<input type="hidden" name="bpts-source-entry-id" value="42" />';

        let publishCount = 0;
        let persistCallCount = 0;

        hooks.setRunProcessingOverridesForTests({
            resolveSelectedEntryUrl: async () => 'https://example.test/page?secret=1',
            ensurePreviewFrame: async () => null,
            setPreviewWidth: async () => null,
            prepareBreakpoints: () => ({
                activationStrategies: ['none'],
                normalizationCount: 0,
                normalizationSamples: [],
            }),
            preloadBreakpointSources: async () => new Map([['pic-1', false]]),
            buildBreakpointReadinessTracker: () => ({
                readinessByKey: new Map([
                    ['pic-1', {
                        status: 'unresolved',
                        reason: 'cancelled',
                        sourceUsed: 'https://example.test/asset.jpg',
                        img: document.createElement('img'),
                        picture: document.createElement('picture'),
                    }],
                ]),
                cleanup: () => { },
            }),
            waitForImagesToSettle: async () => ({
                aborted: true,
                waitedMs: 20,
            }),
            extractRowsForBreakpoint: () => [{
                assetId: 'asset-1',
                transform: 'hero',
                loaded: false,
                broken: false,
                unresolved: true,
            }],
            publishResult: async () => {
                publishCount += 1;
            },
            persistRunSnapshot: async () => {
                persistCallCount += 1;
                return true;
            },
        });

        await hooks.runProcessing();

        expect(publishCount).toBe(0);
        expect(persistCallCount).toBe(1);
        expect(hooks.getLastReport().status).toBe('cancelled');
        expect(hooks.getLastReport().resultPublished).toBe(false);
        expect(document.getElementById('bpts-status').textContent)
            .toContain('Processing cancelled. No partial results were published.');
    });

    it('updates transform status when finished arrives after card replacement', async () => {
        vi.useFakeTimers();

        const visualResults = document.getElementById('bpts-visual-results');
        visualResults.innerHTML = `
            <article class="bpts-transform-card" data-set="hero">
                <button data-bpts-action="saveSet" id="bpts-datastar-trigger"></button>
                <div data-role="transform-update-status" data-state="idle" aria-label="">
                    <span data-role="transform-update-status-label"></span>
                </div>
            </article>
        `;

        const sendActionRequest = vi.fn().mockResolvedValue({
            data: {
                warningsHtml: '',
                visualResultsHtml: visualResults.innerHTML,
                warningCount: 0,
            },
        });

        window.Craft = { sendActionRequest };

        const trigger = document.getElementById('bpts-datastar-trigger');
        document.dispatchEvent(new CustomEvent('datastar-fetch', {
            detail: {
                type: 'started',
                el: trigger,
            },
        }));

        let statusElement = document.querySelector('#bpts-visual-results [data-role="transform-update-status"]');
        let labelElement = document.querySelector('#bpts-visual-results [data-role="transform-update-status-label"]');

        expect(statusElement.getAttribute('data-state')).toBe('pending');
        expect(labelElement.textContent).toBe('Saving...');

        // Simulate server patch replacing the card before datastar emits "finished".
        visualResults.innerHTML = `
            <article class="bpts-transform-card" data-set="hero">
                <div data-role="transform-update-status" data-state="idle" aria-label="">
                    <span data-role="transform-update-status-label"></span>
                </div>
            </article>
        `;

        const editorStatus = document.getElementById('bpts-editor-status');
        editorStatus.dataset.kind = 'success';
        editorStatus.dataset.message = 'Saved';

        document.dispatchEvent(new CustomEvent('datastar-fetch', {
            detail: {
                type: 'finished',
                el: trigger,
            },
        }));

        await vi.advanceTimersByTimeAsync(700);
        statusElement = document.querySelector('#bpts-visual-results [data-role="transform-update-status"]');
        expect(statusElement.getAttribute('data-state')).toBe('success');
        expect(statusElement.getAttribute('aria-label')).toBe('Saved');

        await vi.advanceTimersByTimeAsync(1200);
        statusElement = document.querySelector('#bpts-visual-results [data-role="transform-update-status"]');
        expect(statusElement.getAttribute('data-state')).toBe('idle');

        vi.useRealTimers();
    });

    describe('syncSidebarObservedUnsavedFromSavedNames', () => {
        const seedSidebar = () => {
            const list = document.getElementById('bpts-transform-sets-list');
            list.innerHTML = `
                <li data-set="heroImage" class="bpts-transform-sidebar-item-warning" data-observed-unsaved="1">
                    <a href="#" class="bpts-transform-sidebar-link bpts-transform-sidebar-link-warning" data-set="heroImage">
                        <span class="bpts-transform-sidebar-warning-icon" aria-hidden="true"><svg></svg></span>
                        <span class="visually-hidden">Not saved: </span>
                        heroImage
                    </a>
                </li>
                <li data-set="cardImage" class="bpts-transform-sidebar-item-warning" data-observed-unsaved="1">
                    <a href="#" class="bpts-transform-sidebar-link bpts-transform-sidebar-link-warning" data-set="cardImage">
                        <span class="bpts-transform-sidebar-warning-icon" aria-hidden="true"><svg></svg></span>
                        <span class="visually-hidden">Not saved: </span>
                        cardImage
                    </a>
                </li>
                <li data-set="staticImage">
                    <a href="#" class="bpts-transform-sidebar-link" data-set="staticImage">staticImage</a>
                </li>
            `;
        };

        it('clears warning state for handles now present in saved names', () => {
            seedSidebar();

            hooks.syncSidebarObservedUnsavedFromSavedNames(['heroImage', 'staticImage']);

            const hero = document.querySelector('li[data-set="heroImage"]');
            expect(hero.classList.contains('bpts-transform-sidebar-item-warning')).toBe(false);
            expect(hero.hasAttribute('data-observed-unsaved')).toBe(false);
            const heroLink = hero.querySelector('a.bpts-transform-sidebar-link');
            expect(heroLink.classList.contains('bpts-transform-sidebar-link-warning')).toBe(false);
            expect(heroLink.querySelector('.bpts-transform-sidebar-warning-icon')).toBeNull();
            expect(heroLink.querySelector('.visually-hidden')).toBeNull();

            const card = document.querySelector('li[data-set="cardImage"]');
            expect(card.classList.contains('bpts-transform-sidebar-item-warning')).toBe(true);
            expect(card.getAttribute('data-observed-unsaved')).toBe('1');
            expect(card.querySelector('.bpts-transform-sidebar-warning-icon')).not.toBeNull();
        });

        it('is a no-op when savedSetNames is not an array', () => {
            seedSidebar();

            hooks.syncSidebarObservedUnsavedFromSavedNames(null);
            hooks.syncSidebarObservedUnsavedFromSavedNames(undefined);
            hooks.syncSidebarObservedUnsavedFromSavedNames('heroImage');

            const hero = document.querySelector('li[data-set="heroImage"]');
            expect(hero.classList.contains('bpts-transform-sidebar-item-warning')).toBe(true);
            expect(hero.getAttribute('data-observed-unsaved')).toBe('1');
        });

        it('ignores non-observed rows even if their handles match saved names', () => {
            seedSidebar();

            hooks.syncSidebarObservedUnsavedFromSavedNames(['staticImage']);

            const staticItem = document.querySelector('li[data-set="staticImage"]');
            expect(staticItem.classList.contains('bpts-transform-sidebar-item-warning')).toBe(false);
            expect(staticItem.hasAttribute('data-observed-unsaved')).toBe(false);
        });
    });

    describe('ensureSidebarItemsForSavedNames', () => {
        it('appends a saved-state row for a first-time-saved set with no existing item', () => {
            const list = document.getElementById('bpts-transform-sets-list');
            list.innerHTML = `
                <li data-set="staticImage">
                    <a href="#" class="bpts-transform-sidebar-link" data-set="staticImage">staticImage</a>
                </li>
            `;

            hooks.ensureSidebarItemsForSavedNames(['staticImage', 'newImage']);

            const created = document.querySelector('li[data-set="newImage"]');
            expect(created).not.toBeNull();
            expect(created.hasAttribute('data-observed-unsaved')).toBe(false);
            expect(created.classList.contains('bpts-transform-sidebar-item-warning')).toBe(false);

            const link = created.querySelector('a.bpts-transform-sidebar-link[data-set="newImage"]');
            expect(link).not.toBeNull();
            expect(link.getAttribute('href')).toBe('#');
            expect(link.textContent).toBe('newImage');
            expect(link.querySelector('.bpts-transform-sidebar-warning-icon')).toBeNull();
        });

        it('does not duplicate rows for sets that already exist', () => {
            const list = document.getElementById('bpts-transform-sets-list');
            list.innerHTML = `
                <li data-set="newImage">
                    <a href="#" class="bpts-transform-sidebar-link" data-set="newImage">newImage</a>
                </li>
            `;

            hooks.ensureSidebarItemsForSavedNames(['newImage']);
            hooks.ensureSidebarItemsForSavedNames(['newImage']);

            expect(document.querySelectorAll('li[data-set="newImage"]').length).toBe(1);
        });

        it('is a no-op when savedSetNames is not an array', () => {
            const list = document.getElementById('bpts-transform-sets-list');
            list.innerHTML = '';

            hooks.ensureSidebarItemsForSavedNames(null);
            hooks.ensureSidebarItemsForSavedNames('newImage');

            expect(list.querySelectorAll('li[data-set]').length).toBe(0);
        });
    });

    describe('removeSidebarItemsNotInSavedNames', () => {
        const seedSaved = () => {
            const list = document.getElementById('bpts-transform-sets-list');
            list.innerHTML = `
                <li data-set="heroImage">
                    <a href="#" class="bpts-transform-sidebar-link" data-set="heroImage">heroImage</a>
                </li>
                <li data-set="cardImage">
                    <a href="#" class="bpts-transform-sidebar-link" data-set="cardImage">cardImage</a>
                </li>
                <li data-set="observedImage" class="bpts-transform-sidebar-item-warning" data-observed-unsaved="1">
                    <a href="#" class="bpts-transform-sidebar-link bpts-transform-sidebar-link-warning" data-set="observedImage">observedImage</a>
                </li>
            `;
        };

        it('removes a saved-state row whose set is no longer in saved names', () => {
            seedSaved();

            hooks.removeSidebarItemsNotInSavedNames(['heroImage']);

            expect(document.querySelector('li[data-set="cardImage"]')).toBeNull();
            expect(document.querySelector('li[data-set="heroImage"]')).not.toBeNull();
        });

        it('removes all saved-state rows when saved names is empty', () => {
            seedSaved();

            hooks.removeSidebarItemsNotInSavedNames([]);

            expect(document.querySelector('li[data-set="heroImage"]')).toBeNull();
            expect(document.querySelector('li[data-set="cardImage"]')).toBeNull();
        });

        it('removes observed-unsaved rows when they are not saved', () => {
            seedSaved();

            hooks.removeSidebarItemsNotInSavedNames([]);

            const observed = document.querySelector('li[data-set="observedImage"]');
            expect(observed).toBeNull();
        });

        it('is a no-op when savedSetNames is not an array', () => {
            seedSaved();

            hooks.removeSidebarItemsNotInSavedNames(null);
            hooks.removeSidebarItemsNotInSavedNames('heroImage');

            expect(document.querySelectorAll('li[data-set]').length).toBe(3);
        });
    });
});
