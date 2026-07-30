import { Controller } from '@hotwired/stimulus';

/**
 * evaluation-poll
 * ===============
 *
 * Keeps the patient/status table fresh while background evaluations are running,
 * by periodically reloading the <turbo-frame> this controller is attached to.
 *
 * This is a PROGRESSIVE ENHANCEMENT and nothing more. With JavaScript disabled
 * the frame renders its contents server-side exactly as it does here, and the
 * "Refresh statuses" link reloads the page by hand. Nothing below is required
 * for the screen to work — it only removes the need to click that link.
 *
 * ── Why polling ─────────────────────────────────────────────────────────────
 * Real server push would mean Mercure, which this stack does not run. Polling a
 * frame is the honest alternative: no extra infrastructure, and the same HTML
 * endpoint serves both the first render and every refresh, so the two can never
 * disagree about how a row should look.
 *
 * ── Three things that keep it well-behaved ──────────────────────────────────
 *   1. It STOPS once every row is terminal. The server tells us via
 *      data-active on the state target, which is re-read after each reload
 *      because that element lives *inside* the frame and gets replaced with it.
 *      Without this, a forgotten open tab would poll for the rest of the day.
 *   2. It PAUSES while the tab is hidden. A background tab has nobody watching
 *      it; resuming on focus is both cheaper and more immediate than grinding
 *      away unseen.
 *   3. It BACKS OFF on failure, doubling the delay up to a ceiling. If the
 *      server is struggling, a stuck tab must not become part of the problem.
 *
 * Note there is deliberately no spinner. The frame gets aria-busy while a
 * request is in flight, and the counts above the table are inside a polite live
 * region, so both sighted and screen reader users learn what changed without a
 * moving element that would need a prefers-reduced-motion escape hatch.
 */
export default class extends Controller {
  /** The element carrying data-active; lives inside the frame, so it is replaced on every reload. */
  static targets = ['state'];

  static values = {
    /** Frame endpoint to poll. Set once, on the first tick. */
    url: String,
    /** Base delay between polls, in milliseconds. */
    interval: { type: Number, default: 5000 },
    /** Ceiling for the backed-off delay after repeated failures. */
    maxInterval: { type: Number, default: 60000 },
  };

  connect() {
    this.failures = 0;

    // Bound reference so removeEventListener in disconnect() matches.
    this.onVisibilityChange = () => {
      if (!document.hidden) {
        this.schedule();
      }
    };
    document.addEventListener('visibilitychange', this.onVisibilityChange);

    this.schedule();
  }

  disconnect() {
    this.clearTimer();
    document.removeEventListener('visibilitychange', this.onVisibilityChange);
  }

  /**
   * True while at least one evaluation on screen is pending or running.
   *
   * Read from the DOM rather than kept in a field on purpose: the server is the
   * authority on whether work is outstanding, and re-reading it after each
   * reload is what lets the loop terminate by itself.
   */
  get isActive() {
    return this.hasStateTarget && this.stateTarget.dataset.active === 'true';
  }

  /** Queue the next poll, unless there is nothing left worth watching. */
  schedule() {
    this.clearTimer();

    if (!this.isActive || !this.hasUrlValue) {
      return;
    }

    // Exponential backoff on consecutive failures, capped. 0 failures → base interval.
    const delay = Math.min(
      this.intervalValue * 2 ** this.failures,
      this.maxIntervalValue,
    );

    this.timer = window.setTimeout(() => this.poll(), delay);
  }

  async poll() {
    // Nobody is looking. Skip this round; visibilitychange will restart us.
    if (document.hidden) {
      return;
    }

    this.element.setAttribute('aria-busy', 'true');

    try {
      if (this.element.src) {
        await this.element.reload();
      } else {
        // First tick sets src, which is itself what triggers the fetch. Leaving
        // src off the server-rendered markup avoids a pointless duplicate
        // request the moment the page loads.
        this.element.src = this.urlValue;
        await this.element.loaded;
      }
      this.failures = 0;
    } catch (error) {
      this.failures += 1;
      // Console only: a transient poll failure is not worth interrupting someone
      // mid-task over, and the table on screen is still valid, just stale.
      console.warn('evaluation-poll: refresh failed, backing off.', error);
    } finally {
      this.element.removeAttribute('aria-busy');
    }

    this.schedule();
  }

  clearTimer() {
    if (this.timer) {
      window.clearTimeout(this.timer);
      this.timer = undefined;
    }
  }
}
