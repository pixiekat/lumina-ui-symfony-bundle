<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Service;

/**
 * Turns the plain-text stdout of the EXACT commands into structured arrays we
 * can store as JSON and render as tables.
 *
 * Two formats are understood — see the sample output in each method. The regexes
 * are deliberately permissive and forgiving: if a line does not match it is
 * simply skipped, so an upstream format tweak degrades to "fewer parsed fields"
 * rather than a hard failure (the raw output is always kept verbatim too).
 *
 * Expansion points are marked with TODO — e.g. capturing a CB value column if
 * the reference data file is present.
 */
class OutputParser {

  /**
   * Parse `explain_trial_match` output (one patient × one trial).
   *
   * Sample:
   *   === Evelyn Lopez (person_id=1097) → Trial 24660 [breast cancer] ===
   *     Overall  CTOMOP: not_eligible   CB: —
   *     patient_age            unknown   ctomop=None        ◄ DIFFERS
   *     ...
   *
   * @return array{
   *   patientName: ?string, personId: ?int, trialId: ?int, disease: ?string,
   *   summary: array{overallCtomop: ?string, overallCb: ?string},
   *   attributes: list<array{name: string, status: string, ctomop: ?string, cb: ?string, differs: bool}>
   * }
   */
  public function parseExplain(string $stdout): array {
    $patientName = $personId = $trialId = $disease = null;
    $overallCtomop = $overallCb = null;
    $attributes = [];

    // Header line.
    if (preg_match('/^=== (.+?) \(person_id=(\d+)\) → Trial (\d+) \[(.+?)\] ===/mu', $stdout, $m)) {
      $patientName = trim($m[1]);
      $personId = (int) $m[2];
      $trialId = (int) $m[3];
      $disease = trim($m[4]);
    }

    // Overall verdicts line.
    if (preg_match('/Overall\s+CTOMOP:\s*(\S+)\s+CB:\s*(\S+)/u', $stdout, $m)) {
      $overallCtomop = $m[1];
      $overallCb = $m[2];
    }

    // Attribute rows: "  <name>   <status>   ctomop=<value>   [◄ DIFFERS]".
    // <value> may contain spaces (e.g. "One line"), so capture lazily up to the
    // optional DIFFERS marker / end of line.
    foreach (preg_split('/\R/u', $stdout) as $line) {
      if (preg_match('/^\s+([a-z0-9_]+)\s+(matched|not_matched|unknown)\s+ctomop=(.*?)(?:\s+◄ DIFFERS)?\s*$/u', $line, $m)) {
        $value = trim($m[3]);
        $attributes[] = [
          'name'    => $m[1],
          'status'  => $m[2],
          'ctomop'  => $value === '' ? null : $value,
          // TODO: capture a CB value column when reference data is present.
          'cb'      => null,
          'differs' => str_contains($line, '◄ DIFFERS'),
        ];
      }
    }

    return [
      'patientName' => $patientName,
      'personId'    => $personId,
      'trialId'     => $trialId,
      'disease'     => $disease,
      'summary'     => ['overallCtomop' => $overallCtomop, 'overallCb' => $overallCb],
      'attributes'  => $attributes,
    ];
  }

  /**
   * Parse `search_trials_for_patients` output (one summary line per patient).
   *
   * Sample line:
   *   person_id=1000 [Breast Cancer] → 16 total | 0 eligible | 16 potential | best match: 98% | best goodness: 84.0
   * Footer:
   *   Done. Patients processed: 100, Errors: 0
   *
   * @return array{
   *   patients: list<array{personId:int, disease:string, total:int, eligible:int, potential:int, bestMatchPercent:int, bestGoodness:float}>,
   *   footer: array{processed: ?int, errors: ?int}
   * }
   */
  public function parseSearch(string $stdout): array {
    $patients = [];

    $lineRe = '/person_id=(\d+)\s+\[(.+?)\]\s+→\s+(\d+) total \| (\d+) eligible \| (\d+) potential \| best match: (\d+)% \| best goodness: ([\d.]+)/u';
    if (preg_match_all($lineRe, $stdout, $rows, PREG_SET_ORDER)) {
      foreach ($rows as $m) {
        $patients[] = [
          'personId'         => (int) $m[1],
          'disease'          => trim($m[2]),
          'total'            => (int) $m[3],
          'eligible'         => (int) $m[4],
          'potential'        => (int) $m[5],
          'bestMatchPercent' => (int) $m[6],
          'bestGoodness'     => (float) $m[7],
        ];
      }
    }

    $processed = $errors = null;
    if (preg_match('/Done\. Patients processed: (\d+), Errors: (\d+)/u', $stdout, $m)) {
      $processed = (int) $m[1];
      $errors = (int) $m[2];
    }

    return [
      'patients' => $patients,
      'footer'   => ['processed' => $processed, 'errors' => $errors],
    ];
  }
}
