<?php

declare(strict_types=1);

namespace Pixiekat\LuminaUiBundle\Enum;

/**
 * Which EXACT management command an evaluation corresponds to.
 *
 * The value is the literal `manage.py` subcommand name, so we can build the
 * re-run command straight from the enum:
 *
 *   explain_trial_match           → docker exec exact_app python manage.py explain_trial_match --person-id .. --trial-id ..
 *   search_trials_for_patients    → docker exec exact_app python manage.py search_trials_for_patients
 *
 * The two kinds produce different shapes of result:
 *   - explain_trial_match: ONE patient × ONE trial, with a per-attribute breakdown.
 *   - search_trials_for_patients: a summary row PER patient (counts + best scores).
 */
enum EvaluationKind: string
{
    case ExplainTrialMatch = 'explain_trial_match';
    case SearchTrials = 'search_trials_for_patients';

    public function label(): string
    {
        return match ($this) {
            self::ExplainTrialMatch => 'Explain trial match',
            self::SearchTrials => 'Search trials for patient',
        };
    }

    /** True when this kind is scoped to a single trial (needs --trial-id). */
    public function requiresTrial(): bool
    {
        return $this === self::ExplainTrialMatch;
    }
}
