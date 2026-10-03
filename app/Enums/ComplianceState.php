<?php

namespace App\Enums;

/** SRS section 16 — evaluated per student. */
enum ComplianceState: string
{
    case Compliant = 'compliant';
    case AtRisk = 'at_risk';
    case NonCompliant = 'non_compliant';
    case DataIssue = 'data_issue';
    case NotMonitored = 'not_monitored';

    public function label(): string
    {
        return match ($this) {
            self::Compliant => 'Compliant',
            self::AtRisk => 'At Risk',
            self::NonCompliant => 'Non-Compliant',
            self::DataIssue => 'Data Issue',
            self::NotMonitored => 'Not Monitored',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Compliant => 'green',
            self::AtRisk => 'yellow',
            self::NonCompliant => 'red',
            self::DataIssue, self::NotMonitored => 'grey',
        };
    }
}
