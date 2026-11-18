<?php

namespace App\Services;

use App\Models\BillingEvidence;
use App\Models\BillingEvidenceComponent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class BillingEvidenceMath
{
    public const MONEY = ['energy_amount_php', 'export_credit_php', 'other_charges_php', 'applied_credits_php', 'current_charges_php', 'previous_balance_php', 'total_due_php'];

    public const CATEGORIES = ['energy', 'demand', 'fixed', 'tax', 'embedded_adjustment', 'export_credit', 'deposit', 'account_credit', 'unclassified'];

    public static function decimal(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }

    public static function rate(string $money, string $kwh): ?string
    {
        return self::decimal($kwh)->isPositive()
            ? (string) self::decimal($money)->dividedBy($kwh, 6, RoundingMode::HalfUp) : null;
    }

    public static function money(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::Unnecessary);
    }

    /** Re-read component rows; never trust a caller's preloaded relation. */
    public function fingerprint(BillingEvidence $evidence): string
    {
        return DataFingerprint::hash([
            'summary' => $evidence->except(['created_at', 'updated_at', 'verification_status', 'verified_by', 'verified_at', 'verified_fingerprint']),
            'components' => $evidence->components()->orderBy('position')->get()->map(fn (BillingEvidenceComponent $row) => $row->getAttributes())->all(),
        ]);
    }

    /** @return array{valid: bool, reason: string|null, currentResidual: string|null, statementResidual: string|null} */
    public function assess(BillingEvidence $evidence): array
    {
        $result = ['valid' => false, 'reason' => 'missing_financial_inputs', 'currentResidual' => null, 'statementResidual' => null];
        foreach (self::MONEY as $field) {
            if ($evidence->{$field} === null) {
                return $result;
            }
        }
        if ($evidence->import_kwh === null || $evidence->export_kwh === null) {
            return $result;
        }
        $expected = self::decimal($evidence->energy_amount_php)->plus($evidence->export_credit_php)->plus($evidence->other_charges_php)->plus($evidence->applied_credits_php);
        $current = self::decimal($evidence->current_charges_php)->minus($expected);
        $statement = self::decimal($evidence->total_due_php)->minus($evidence->previous_balance_php)->minus($evidence->current_charges_php);
        $result['currentResidual'] = self::money($current);
        $result['statementResidual'] = self::money($statement);
        if (! $current->isZero() || ! $statement->isZero()) {
            return [...$result, 'reason' => 'unreconciled_statement'];
        }
        if (self::decimal($evidence->export_credit_php)->isPositive() || self::decimal($evidence->applied_credits_php)->isPositive()
            || (self::decimal($evidence->export_kwh)->isZero() && ! self::decimal($evidence->export_credit_php)->isZero())) {
            return [...$result, 'reason' => 'invalid_credit_basis'];
        }
        $rows = $evidence->components()->orderBy('position')->get();
        if ($rows->isEmpty()) {
            return [...$result, 'reason' => 'component_evidence_missing'];
        }
        $groups = ['energy_amount_php' => BigDecimal::zero(), 'export_credit_php' => BigDecimal::zero(), 'other_charges_php' => BigDecimal::zero(), 'applied_credits_php' => BigDecimal::zero()];
        foreach ($rows as $row) {
            if (! in_array($row->category, self::CATEGORIES, true) || $row->category === 'unclassified') {
                return [...$result, 'reason' => 'unclassified_component'];
            }
            if (! in_array($row->source_page, $evidence->source_pages, true)) {
                return [...$result, 'reason' => 'component_source_missing'];
            }
            if ($row->parent_position !== null) {
                $parent = $rows->firstWhere('position', $row->parent_position);
                if (! $parent || ! $parent->is_subtotal || $parent->position >= $row->position) {
                    return [...$result, 'reason' => 'invalid_component_hierarchy'];
                }
            }
            if ($row->is_subtotal) {
                // Subtotals are evidence, never counted again with their children.
                $children = $rows->where('parent_position', $row->position);
                $sum = BigDecimal::zero();
                foreach ($children as $child) {
                    $sum = $sum->plus($child->amount_php);
                }
                if ($children->isEmpty() || ! $sum->isEqualTo($row->amount_php)) {
                    return [...$result, 'reason' => 'unreconciled_subtotal'];
                }

                continue;
            }
            $field = match ($row->category) {
                'export_credit' => 'export_credit_php', 'deposit' => 'other_charges_php', 'account_credit' => 'applied_credits_php', default => 'energy_amount_php',
            };
            $groups[$field] = $groups[$field]->plus($row->amount_php);
        }
        foreach ($groups as $field => $amount) {
            if (! $amount->isEqualTo($evidence->{$field})) {
                return [...$result, 'reason' => 'unreconciled_components'];
            }
        }

        return [...$result, 'valid' => true, 'reason' => null];
    }
}
