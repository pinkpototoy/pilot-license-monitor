<?php

namespace App\Domain\Compliance;

use App\Enums\ComplianceState;
use App\Enums\ValidityStatus;

/**
 * SRS 16 — a student is compliant only if every REQUIRED credential type is valid.
 * Precedence: Non-Compliant > Data Issue > At Risk > Compliant.
 */
final class ComplianceEvaluator
{
    /**
     * @param  list<int>|null  $requiredTypeIds  null = requirements unknown (no program assigned)
     * @param  array<int, ValidityStatus>  $statusByType  credential_type_id => validity of the current credential
     */
    public function evaluate(bool $monitored, ?array $requiredTypeIds, array $statusByType): ComplianceState
    {
        if (! $monitored) {
            return ComplianceState::NotMonitored;
        }

        // Without a program the system cannot know what is required (self-review #7).
        if ($requiredTypeIds === null) {
            return ComplianceState::DataIssue;
        }

        $required = array_map(fn (int $id) => $statusByType[$id] ?? null, $requiredTypeIds);

        foreach ($required as $status) {
            if ($status === null || $status === ValidityStatus::Expired) {
                return ComplianceState::NonCompliant;   // missing or expired
            }
        }

        foreach ($required as $status) {
            if (in_array($status, [ValidityStatus::IncompleteData, ValidityStatus::NotYetValid], true)) {
                return ComplianceState::DataIssue;
            }
        }

        foreach ($required as $status) {
            if ($status === ValidityStatus::ExpiringSoon) {
                return ComplianceState::AtRisk;
            }
        }

        return ComplianceState::Compliant;
    }
}
