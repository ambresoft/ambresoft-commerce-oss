<?php

namespace App\Geography\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

final class PruneRegionImports extends Command
{
    protected $signature = 'cms:region-import-prune';

    protected $description = 'Remove expired private region CSV uploads';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = time() - (int) config('region-import.ttl_seconds') - 600;
        foreach ($disk->files('region-imports') as $file) {
            $token = pathinfo($file, PATHINFO_FILENAME);
            if ($disk->lastModified($file) < $cutoff && ! Cache::has('region-import:'.$token)) {
                $disk->delete($file);
            }
        }

        return self::SUCCESS;
    }
}
