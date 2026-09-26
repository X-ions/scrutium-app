/**
 * "How it works" product tour.
 *
 * Registered as an Alpine data provider so it can be used declaratively as
 * `x-data="scrutiumTour(steps, options)"`. Keeping the logic here rather than
 * in an inline x-data expression means it is linted, and it keeps the app's
 * Content-Security-Policy happy: Alpine evaluates directives through new
 * Function(), and the app already relies on that, so no new inline script is
 * introduced.
 */

/**
 * Must match the `tour-spotlight` @utility in resources/css/app.css. Tailwind
 * only emits a utility when the class name appears in a scanned source, and
 * this JS directory is scanned by app.css, so keeping the literal here is what
 * puts the rule in the bundle.
 */
const HIGHLIGHT_CLASS = 'tour-spotlight';

const PENDING_KEY = 'scrutium.tour.pending';
const CARD_WIDTH = 380;
const CARD_GAP = 16;
const VIEWPORT_PADDING = 16;

const isRtl = () => document.documentElement.dir === 'rtl';

/** True when the event target is a field where arrow keys mean something else. */
const isTypingTarget = (element) =>
    !!element &&
    !!element.closest &&
    !!element.closest('input, textarea, select, [contenteditable="true"]');

export default function scrutiumTour(steps = [], options = {}) {
    const { storageKey = 'scrutium.tour.completed', autoStart = false, currentRoute = null } = options;

    return {
        steps,
        open: false,
        index: 0,
        spot: null,
        targetMissing: false,
        finished: false,

        get step() {
            return this.steps[this.index] ?? null;
        },

        get total() {
            return this.steps.length;
        },

        get isFirst() {
            return this.index === 0;
        },

        get isLast() {
            return this.index === this.steps.length - 1;
        },

        get progress() {
            return this.total > 0 ? Math.round(((this.index + 1) / this.total) * 100) : 0;
        },

        init() {
            this.onOpenChange = (isOpen) => {
                if (isOpen) {
                    this.$nextTick(() => {
                        this.locate(true);
                        this.$refs.dialog?.focus();
                    });
                } else {
                    this.clearSpot();
                }
            };

            this.onResize = () => this.locate(false);
            this.onScroll = () => this.locate(false);
            this.onKeydown = (event) => {
                if (!this.open) {
                    return;
                }

                if (event.key === 'Escape') {
                    this.close();
                } else if (isTypingTarget(event.target)) {
                    return;
                } else if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    this.next();
                } else if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    this.previous();
                }
            };

            this.$watch('open', this.onOpenChange);
            window.addEventListener('resize', this.onResize);
            window.addEventListener('scroll', this.onScroll, true);
            window.addEventListener('keydown', this.onKeydown);
            window.addEventListener('scrutium:open-tour', () => this.start(0));

            const resumeIndex = this.takePendingIndex();

            if (resumeIndex !== null) {
                // Continuing a tour that navigated to this page.
                window.setTimeout(() => this.start(resumeIndex), 400);
            } else if (autoStart && !this.hasCompleted()) {
                // Give the page a beat to settle before stealing focus.
                window.setTimeout(() => this.start(0), 900);
            }
        },

        destroy() {
            window.removeEventListener('resize', this.onResize);
            window.removeEventListener('scroll', this.onScroll, true);
            window.removeEventListener('keydown', this.onKeydown);
            this.clearSpot();
        },

        // ---------------------------------------------------------------- storage

        hasCompleted() {
            try {
                return window.localStorage.getItem(storageKey) === '1';
            } catch {
                return false;
            }
        },

        markCompleted() {
            try {
                window.localStorage.setItem(storageKey, '1');
            } catch {
                // Private browsing modes can throw; the tour still works.
            }
        },

        /**
         * Read and clear the cross-page hand-off written by advance(). The
         * route is stored alongside the index so a stale entry left behind by
         * a closed tab can never force the tour open on an unrelated page.
         *
         * @returns {number|null}
         */
        takePendingIndex() {
            try {
                const raw = window.sessionStorage.getItem(PENDING_KEY);

                if (raw === null) {
                    return null;
                }

                window.sessionStorage.removeItem(PENDING_KEY);

                const pending = JSON.parse(raw);

                if (pending.route !== currentRoute) {
                    return null;
                }

                return Number.isFinite(pending.index) ? pending.index : 0;
            } catch {
                return null;
            }
        },

        // ---------------------------------------------------------------- control

        start(from = 0) {
            this.finished = false;
            this.index = Math.min(Math.max(from, 0), Math.max(this.total - 1, 0));
            this.open = true;
            this.markCompleted();
        },

        close() {
            this.open = false;
            this.markCompleted();
        },

        finish() {
            this.finished = true;
            this.open = false;
            this.markCompleted();
            window.dispatchEvent(new CustomEvent('scrutium:tour-finished'));
        },

        next() {
            if (this.isLast) {
                this.finish();
                return;
            }
            this.advance(this.index + 1);
        },

        previous() {
            if (this.isFirst) {
                return;
            }
            this.advance(this.index - 1);
        },

        /** Jump to a step, navigating first if that step lives on another page. */
        goTo(position) {
            if (position === this.index) {
                return;
            }
            this.advance(position);
        },

        /**
         * Move to `targetIndex`, navigating when the destination step belongs to
         * a different page. The pending hand-off is written first so the next
         * page can pick the tour up where it left off.
         */
        advance(targetIndex) {
            if (targetIndex < 0 || targetIndex >= this.total) {
                return;
            }

            const nextStep = this.steps[targetIndex];
            // currentRoute is captured per page load, so this comparison stays
            // correct for the lifetime of the component.
            const shouldNavigate = Boolean(nextStep.route) && nextStep.route !== currentRoute;

            if (!shouldNavigate) {
                this.index = targetIndex;
                return;
            }

            try {
                window.sessionStorage.setItem(
                    PENDING_KEY,
                    JSON.stringify({ index: targetIndex, route: nextStep.route })
                );
            } catch {
                // Without session storage the tour restarts on the new page.
            }

            window.location.href = nextStep.href;
        },

        // ---------------------------------------------------------------- spotlight

        /**
         * Position the cut-out ring over the step's target.
         *
         * @param {boolean} scrollToTarget scroll the target into view first.
         *   Resize and scroll handlers pass false so the page is not yanked
         *   around while the user is already moving it.
         */
        locate(scrollToTarget = false) {
            const target = this.step?.target;

            if (!target) {
                this.spot = null;
                this.targetMissing = false;
                this.clearSpot();
                return;
            }

            const element = document.querySelector(target);

            if (!element) {
                this.spot = null;
                this.targetMissing = true;
                this.clearSpot();
                return;
            }

            this.targetMissing = false;

            if (scrollToTarget) {
                // Instant, not smooth: a smooth scroll would hand back the
                // pre-scroll rect and leave the ring and the card behind.
                element.scrollIntoView({ block: 'center', behavior: 'auto' });
            }

            this.spot = this.measure(element);
            this.punchHole(this.spot);
        },

        measure(element) {
            const rect = element.getBoundingClientRect();

            return {
                top: Math.max(rect.top - 6, 0),
                left: Math.max(rect.left - 6, 0),
                width: rect.width + 12,
                height: rect.height + 12,
            };
        },

        /**
         * Draw a cut-out ring around the highlighted element. A single element
         * with a large box-shadow dims the viewport and leaves a hole where the
         * target is. The ring's base styling lives in the `tour-spotlight`
         * utility in app.css; only the geometry is inline because it tracks the
         * element.
         */
        punchHole(spot) {
            let ring = document.querySelector(`.${HIGHLIGHT_CLASS}`);

            if (!ring) {
                ring = document.createElement('div');
                ring.className = HIGHLIGHT_CLASS;
                ring.setAttribute('aria-hidden', 'true');
                document.body.appendChild(ring);
            }

            ring.style.top = `${spot.top}px`;
            ring.style.left = `${spot.left}px`;
            ring.style.width = `${spot.width}px`;
            ring.style.height = `${spot.height}px`;
        },

        clearSpot() {
            this.spot = null;
            document.querySelector(`.${HIGHLIGHT_CLASS}`)?.remove();
        },

        /**
         * Park the card beside the highlight, preferring the inline-end side so
         * the card leads away from the target the same way in both directions.
         * Falls back to a centred card (handled in CSS) when there is no target.
         */
        cardStyle() {
            if (!this.open || !this.spot) {
                return '';
            }

            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;
            const rtl = isRtl();

            // "After" is the physical right. In RTL that is the inline-start
            // side, so it becomes the less desirable option.
            const spaceAfter = viewportWidth - (this.spot.left + this.spot.width);
            const spaceBefore = this.spot.left;

            const placeAfter = rtl
                ? spaceAfter > CARD_WIDTH + VIEWPORT_PADDING && spaceAfter >= spaceBefore
                : spaceAfter >= spaceBefore;

            let top = this.spot.top + this.spot.height + CARD_GAP;
            let left = placeAfter
                ? this.spot.left + this.spot.width + CARD_GAP
                : this.spot.left - CARD_WIDTH - CARD_GAP;

            const estimatedHeight = 340;

            if (top + estimatedHeight + VIEWPORT_PADDING > viewportHeight) {
                const above = this.spot.top - estimatedHeight - CARD_GAP;
                top = above >= VIEWPORT_PADDING ? above : VIEWPORT_PADDING;
            }

            if (left < VIEWPORT_PADDING) {
                left = VIEWPORT_PADDING;
            }

            if (left + CARD_WIDTH + VIEWPORT_PADDING > viewportWidth) {
                left = Math.max(viewportWidth - CARD_WIDTH - VIEWPORT_PADDING, VIEWPORT_PADDING);
            }

            return `top:${Math.round(top)}px;left:${Math.round(left)}px;`;
        },
    };
}
