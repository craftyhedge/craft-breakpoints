export function bindHorizontalDragScroll(options = {}) {
    const thresholdPx = Number.isFinite(options.thresholdPx) ? Number(options.thresholdPx) : 8;
    const bindingKey = typeof options.bindingKey === 'string' ? options.bindingKey : '';
    const interactiveSelector = typeof options.interactiveSelector === 'string'
        ? options.interactiveSelector
        : 'a, button, input, select, textarea, label, [role="button"], .btn';
    const preventDragStartSelector = typeof options.preventDragStartSelector === 'string'
        ? options.preventDragStartSelector
        : '';

    if (bindingKey && window[bindingKey]) {
        return () => { };
    }

    if (bindingKey) {
        window[bindingKey] = true;
    }

    const state = {
        active: false,
        moved: false,
        pointerId: null,
        grid: null,
        startX: 0,
        startY: 0,
        startScrollLeft: 0,
        suppressClick: false,
    };

    function isSamePointer(pointerId = null) {
        return pointerId !== null && state.pointerId !== null && Number(pointerId) === Number(state.pointerId);
    }

    const findGridFromTarget = typeof options.findGridFromTarget === 'function'
        ? options.findGridFromTarget
        : (target) => (target instanceof Element ? target.closest('.bpts-breakpoint-grid') : null);

    const isManagedGrid = typeof options.isManagedGrid === 'function'
        ? options.isManagedGrid
        : (grid) => grid instanceof Element;

    const onPotentialDragStart = typeof options.onPotentialDragStart === 'function'
        ? options.onPotentialDragStart
        : null;

    const onDragMove = typeof options.onDragMove === 'function'
        ? options.onDragMove
        : null;

    const onDragEnd = typeof options.onDragEnd === 'function'
        ? options.onDragEnd
        : null;

    const onScrollGrid = typeof options.onScrollGrid === 'function'
        ? options.onScrollGrid
        : null;

    function endDrag(pointerId = null) {
        if (!state.active) {
            return;
        }

        if (pointerId !== null && state.pointerId !== pointerId) {
            return;
        }

        if (state.grid) {
            state.grid.classList.remove('bpts-drag-scrolling');

            if (state.pointerId !== null && state.grid.hasPointerCapture?.(state.pointerId)) {
                try {
                    state.grid.releasePointerCapture(state.pointerId);
                } catch (_error) {
                    // Ignore pointer capture release errors.
                }
            }
        }

        if (onDragEnd && state.grid) {
            onDragEnd(state.grid);
        }

        state.active = false;
        state.moved = false;
        state.pointerId = null;
        state.grid = null;
        state.startX = 0;
        state.startY = 0;
        state.startScrollLeft = 0;
    }

    function onPointerDown(event) {
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        const grid = findGridFromTarget(target);
        if (!(grid instanceof Element) || !isManagedGrid(grid)) {
            return;
        }

        if (target.closest(interactiveSelector)) {
            return;
        }

        if (onPotentialDragStart) {
            onPotentialDragStart(grid);
        }

        const maxScrollLeft = Math.max(0, grid.scrollWidth - grid.clientWidth);
        if (maxScrollLeft <= 1) {
            return;
        }

        if (state.active) {
            endDrag();
        }

        state.active = true;
        state.moved = false;
        state.pointerId = event.pointerId;
        state.grid = grid;
        state.startX = event.clientX;
        state.startY = event.clientY;
        state.startScrollLeft = grid.scrollLeft;

        if (state.grid.setPointerCapture) {
            try {
                state.grid.setPointerCapture(event.pointerId);
            } catch (_error) {
                // Ignore pointer capture errors.
            }
        }
    }

    function onPointerMove(event) {
        if (!state.active || state.pointerId !== event.pointerId || !(state.grid instanceof Element)) {
            return;
        }

        const deltaX = event.clientX - state.startX;
        const deltaY = event.clientY - state.startY;
        const absDeltaX = Math.abs(deltaX);
        const absDeltaY = Math.abs(deltaY);

        if (!state.moved && absDeltaX < thresholdPx && absDeltaY < thresholdPx) {
            return;
        }

        if (!state.moved && absDeltaY > absDeltaX) {
            endDrag(event.pointerId);
            return;
        }

        if (!state.moved) {
            state.moved = true;
            state.suppressClick = true;
            state.grid.classList.add('bpts-drag-scrolling');
        }

        event.preventDefault();
        state.grid.scrollLeft = state.startScrollLeft - deltaX;

        if (onDragMove) {
            onDragMove(state.grid);
        }
    }

    function onPointerUp(event) {
        endDrag(event.pointerId);
    }

    function onPointerCancel(event) {
        endDrag(event.pointerId);
    }

    function onLostPointerCapture(event) {
        if (!isSamePointer(event.pointerId)) {
            return;
        }

        endDrag(event.pointerId);
    }

    function onDragStart(event) {
        if (!preventDragStartSelector || !event.target || typeof event.target.closest !== 'function') {
            return;
        }

        if (event.target.closest(preventDragStartSelector)) {
            event.preventDefault();
        }
    }

    function onScroll(event) {
        if (!onScrollGrid) {
            return;
        }

        const target = event.target;
        if (!target || typeof target.closest !== 'function') {
            return;
        }

        const grid = target.closest('.bpts-breakpoint-grid');
        if (!(grid instanceof Element) || !isManagedGrid(grid)) {
            return;
        }

        onScrollGrid(grid);
    }

    function onClick(event) {
        if (!state.suppressClick) {
            return;
        }

        const target = event.target;
        if (!(target instanceof Element)) {
            state.suppressClick = false;
            return;
        }

        const grid = findGridFromTarget(target);
        if (grid instanceof Element && isManagedGrid(grid)) {
            event.preventDefault();
            event.stopPropagation();
        }

        state.suppressClick = false;
    }

    function onWindowBlur() {
        endDrag();
    }

    function onVisibilityChange() {
        if (document.visibilityState !== 'visible') {
            endDrag();
        }
    }

    document.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('pointermove', onPointerMove, { passive: false });
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerCancel);
    document.addEventListener('lostpointercapture', onLostPointerCapture);
    document.addEventListener('dragstart', onDragStart);
    document.addEventListener('scroll', onScroll, true);
    document.addEventListener('click', onClick, true);
    window.addEventListener('blur', onWindowBlur);
    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
        document.removeEventListener('pointerdown', onPointerDown);
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', onPointerCancel);
        document.removeEventListener('lostpointercapture', onLostPointerCapture);
        document.removeEventListener('dragstart', onDragStart);
        document.removeEventListener('scroll', onScroll, true);
        document.removeEventListener('click', onClick, true);
        window.removeEventListener('blur', onWindowBlur);
        document.removeEventListener('visibilitychange', onVisibilityChange);

        if (bindingKey) {
            window[bindingKey] = false;
        }

        endDrag();
    };
}
