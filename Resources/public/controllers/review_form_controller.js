import { Controller } from '@hotwired/stimulus';

/**
 * Hides the "why do you disagree?" dropdown while "I agree" is selected.
 *
 * ── This controller is allowed to fail ──────────────────────────────────────
 * Everything it does is cosmetic. The form is rendered by the server in its
 * working state — reason dropdown visible, labelled as applying to
 * disagreement — so with JavaScript disabled, blocked, or simply broken, the
 * evaluator sees one extra select and the form submits correctly regardless.
 * EvaluationReviewController re-checks every rule this appears to enforce.
 *
 * That is the deal with progressive enhancement, and it is why the hiding is
 * done HERE rather than by rendering the field hidden and revealing it: a
 * server-rendered `hidden` attribute would make the field unreachable for
 * anyone this script never reaches.
 */
export default class extends Controller {
  static targets = ['verdict', 'reason'];

  /**
   * Sync on load.
   *
   * Necessary because the page can arrive with a verdict already selected: an
   * evaluator editing their existing review, or a browser restoring form state
   * on a back-navigation. Without this the dropdown would show for someone whose
   * saved answer is "agree", which is precisely the state this exists to tidy.
   */
  connect() {
    this.toggleReason();
  }

  /**
   * Show the reason field only when it is relevant.
   *
   * Two things are toggled, and both are needed:
   *
   *   hidden    removes the field from the page AND from the accessibility tree,
   *             so a screen reader does not read out a control that no longer
   *             applies. (CSS `display:none` would do the same, but `hidden` is
   *             the one that survives a missing stylesheet.)
   *   disabled  keeps the browser from submitting a stale value. A hidden-but-
   *             enabled select still posts whatever was last chosen, so
   *             switching disagree → agree would send a reason the server then
   *             has to remember to ignore. Better that it never arrives.
   *
   * Note there is no `required` toggling: the field is deliberately never marked
   * required in the markup, because "required" would be wrong half the time and
   * a browser's own validation bubble cannot express "unless you agree". The
   * server states the rule instead, in a message that says what to do.
   */
  toggleReason() {
    if (!this.hasReasonTarget) {
      return;
    }

    const selected = this.verdictTargets.find((input) => input.checked);
    // No selection yet (a fresh form) counts as "might disagree" — showing the
    // field is the safe default, matching what the server rendered.
    const needsReason = !selected || selected.value === 'disagree';

    this.reasonTarget.hidden = !needsReason;

    const select = this.reasonTarget.querySelector('select');
    if (select) {
      select.disabled = !needsReason;
    }
  }
}
