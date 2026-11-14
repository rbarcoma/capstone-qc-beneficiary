<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case EnergyManager = 'energy_manager';
    case FacilitiesPersonnel = 'facilities_personnel';
    case BudgetOfficer = 'budget_officer';
    case Viewer = 'viewer';

    /**
     * Human-readable role label (used in the sidebar and admin UI).
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::EnergyManager => 'Energy Manager',
            self::FacilitiesPersonnel => 'Facilities Personnel',
            self::BudgetOfficer => 'Budget Officer',
            self::Viewer => 'Viewer / Executive',
        };
    }

    /**
     * All roles as values, for validation rules.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether holders of this role are restricted to assigned buildings
     * (proposed permission matrix: only Facilities Personnel is).
     */
    public function isBuildingRestricted(): bool
    {
        return $this === self::FacilitiesPersonnel;
    }
}
