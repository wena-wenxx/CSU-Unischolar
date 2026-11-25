<?php

namespace App\Console\Commands;

use App\Support\EligibilityEngine;
use Illuminate\Console\Command;

class RunRecommendations extends Command
{
    protected $signature = 'recommendations:run';

    protected $description = 'Recalculate the AI scholarship recommendations (rule-based eligibility scores).';

    public function handle(): int
    {
        $result = EligibilityEngine::recalculate();

        $this->info("Scored {$result['students']} students x {$result['scholarships']} scholarships: "
            ."{$result['rows']} recommendations, {$result['eligible']} eligible.");

        return self::SUCCESS;
    }
}
