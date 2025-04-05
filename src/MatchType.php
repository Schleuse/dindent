<?php

declare(strict_types=1);

namespace Gajus\Dindent;


enum MatchType
{
    case NoIndent;
    case IndentDecrease;
    case IndentIncrease;
    case Discard;


    public function asString(): string {
        return match($this) {
            MatchType::NoIndent => 'NO',
            MatchType::IndentDecrease => 'DECREASE',
            MatchType::IndentIncrease => 'INCREASE',
            MatchType::Discard => 'DISCARD'
        };
    }
}
