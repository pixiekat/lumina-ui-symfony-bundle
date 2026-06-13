<?php

declare(strict_types=1);

namespace Pixiekat\LuminaUiBundle\Enum;

/**
 * Which matching software produced an evaluation.
 *
 * Backed by a short string so it stores cleanly in Postgres and round-trips
 * through Doctrine's `enumType` mapping. Add new engines here as they come
 * online (the EXACT container exists today; MatchMiner is planned).
 */
enum MatchingSoftware: string
{
    case Exact = 'exact';
    case MatchMiner = 'matchminer';

    /** Human-friendly label for templates / dropdowns. */
    public function label(): string
    {
        return match ($this) {
            self::Exact => 'EXACT',
            self::MatchMiner => 'MatchMiner',
        };
    }
}
