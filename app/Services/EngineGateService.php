<?php

namespace App\Services;

/**
 * Release gates for the WorkSuite Sinhala AI Engine (SRS §17.2, §17.3).
 *
 * Thresholds are the SRS "proposed initial release thresholds". Metrics are
 * fractions (0.20 = 20%) except MOS (1–5) and timing (ms). A metric that is
 * missing is reported as "insufficient evidence", never as passing.
 */
class EngineGateService
{
    /** task => [metric => [op, threshold, label]] ; op: max | min */
    public const GATES = [
        'asr' => [
            'wer_clean'          => ['max', 0.20, 'Clean Sinhala WER ≤20%'],
            'cer_clean'          => ['max', 0.10, 'Clean Sinhala CER ≤10%'],
            'wer_noisy'          => ['max', 0.35, 'Noisy/mixed WER ≤35%'],
            'critical_token_acc' => ['min', 0.90, 'Number & name token accuracy ≥90%'],
            'silence_clean_rate' => ['min', 0.99, 'No output on ≥99% silence clips'],
        ],
        'tts' => [
            'mos'              => ['min', 3.8, 'MOS ≥3.8'],
            'mos_ci_low'       => ['min', 3.5, 'MOS 95% CI lower bound ≥3.5'],
            'intelligibility'  => ['min', 0.95, 'Intelligibility ≥95%'],
            'numeric_accuracy' => ['min', 0.98, 'Numeric reading accuracy ≥98%'],
        ],
        'captions' => [
            'boundary_median_ms' => ['max', 300, 'Median cue boundary error ≤300 ms'],
            'boundary_p95_ms'    => ['max', 750, 'p95 cue boundary error ≤750 ms'],
        ],
        'transliteration' => [
            'top1' => ['min', 0.90, 'Top-1 ≥90%'],
            'top3' => ['min', 0.97, 'Top-3 ≥97%'],
        ],
        'legacy_conversion' => [
            'exact_match' => ['min', 1.0, '100% exact match on reversible fixtures'],
            'silent_loss' => ['max', 0, 'Zero silent loss'],
        ],
        'suggestions' => [
            'recall_at_5' => ['min', 0.85, 'Recall@5 ≥85%'],
        ],
        'spelling' => [
            'precision'   => ['min', 0.95, 'Precision ≥95%'],
            'recall'      => ['min', 0.80, 'Recall ≥80%'],
            'false_alarm' => ['max', 0.05, 'Correct-sentence false alarm ≤5%'],
        ],
        'grammar' => [
            'precision'            => ['min', 0.90, 'Precision ≥90%'],
            'recall'               => ['min', 0.70, 'Recall ≥70%'],
            'meaning_preservation' => ['min', 0.98, 'Meaning preservation ≥98%'],
            'negative_edit_rate'   => ['max', 0.05, 'Edits on correct negatives ≤5%'],
        ],
        'translation' => [
            'adequacy_4plus' => ['min', 0.90, '≥90% segments adequacy ≥4/5'],
            'fluency_4plus'  => ['min', 0.85, '≥85% segments fluency ≥4/5'],
        ],
        // Vision models (Local models set). No vision suite yet, so these read "insufficient evidence" until one runs.
        'image_upscale' => [
            'psnr_db' => ['min', 24.0, 'PSNR ≥24 dB on the reference image set'],
        ],
        'frame_interpolation' => [
            'psnr_db' => ['min', 28.0, 'Interpolated-frame PSNR ≥28 dB'],
        ],
        'background_removal' => [
            'mask_iou' => ['min', 0.90, 'Mask IoU ≥0.90 on the portrait set'],
        ],
        'text' => [
            'placeholder_preservation' => ['min', 1.0, '100% protected placeholders preserved'],
            'valid_first_attempt'      => ['min', 0.99, '≥99% valid structured output first attempt'],
            'invalid_blocked'          => ['min', 1.0, '100% invalid outputs blocked'],
        ],
    ];

    /** Metrics where a regression vs baseline is measured (§17.3), with the "lower is better" flag. */
    private const REGRESSION = [
        'wer_clean' => true, 'cer_clean' => true, 'wer_noisy' => true,
        'precision' => false, 'recall' => false, 'top1' => false, 'top3' => false,
        'adequacy_4plus' => false, 'fluency_4plus' => false, 'intelligibility' => false,
    ];

    public static function tasks(): array
    {
        return array_keys(self::GATES);
    }

    /**
     * @param array $metrics          candidate metrics
     * @param array|null $baseline    active baseline metrics on the same suite
     * @param array $subgroups        [{name, n, metrics:{wer_clean|wer_noisy...}, baseline:{...}}]
     */
    public function evaluate(string $task, array $metrics, ?array $baseline = null, array $subgroups = []): array
    {
        $checks = [];
        $pass   = true;

        foreach (self::GATES[$task] ?? [] as $metric => [$op, $limit, $label]) {
            if (! array_key_exists($metric, $metrics) || ! is_numeric($metrics[$metric])) {
                $checks[] = ['metric' => $metric, 'label' => $label, 'status' => 'insufficient_evidence'];
                $pass = false;
                continue;
            }
            $v  = (float) $metrics[$metric];
            $ok = $op === 'max' ? $v <= $limit : $v >= $limit;
            $checks[] = ['metric' => $metric, 'label' => $label, 'value' => $v, 'status' => $ok ? 'pass' : 'fail'];
            $pass = $pass && $ok;
        }

        // §17.3: no metric may regress more than 2 points vs the active baseline.
        foreach ($baseline ?? [] as $metric => $base) {
            if (! isset(self::REGRESSION[$metric], $metrics[$metric]) || ! is_numeric($base)) continue;
            $delta = self::REGRESSION[$metric] ? $metrics[$metric] - $base : $base - $metrics[$metric];
            $ok    = $delta <= 0.02 + 1e-9;
            $checks[] = ['metric' => $metric, 'label' => 'Regression vs baseline ≤2 points', 'value' => round($delta, 4), 'status' => $ok ? 'pass' : 'fail'];
            $pass = $pass && $ok;
        }

        // §17.3: strata with ≥100 utterances may not regress WER by more than 5 points.
        foreach ($subgroups as $sg) {
            $n = (int) ($sg['n'] ?? 0);
            if ($n < 100) {
                $checks[] = ['metric' => 'subgroup:' . ($sg['name'] ?? '?'), 'label' => 'Stratum n<100', 'status' => 'insufficient_evidence'];
                continue;
            }
            foreach (['wer_clean', 'wer_noisy'] as $m) {
                if (! isset($sg['metrics'][$m], $sg['baseline'][$m])) continue;
                $delta = $sg['metrics'][$m] - $sg['baseline'][$m];
                $ok    = $delta <= 0.05 + 1e-9;
                $checks[] = ['metric' => 'subgroup:' . $sg['name'] . ':' . $m, 'label' => 'Stratum WER regression ≤5 points', 'value' => round($delta, 4), 'status' => $ok ? 'pass' : 'fail'];
                $pass = $pass && $ok;
            }
        }

        return ['task' => $task, 'pass' => $pass && $checks !== [], 'checks' => $checks];
    }
}
