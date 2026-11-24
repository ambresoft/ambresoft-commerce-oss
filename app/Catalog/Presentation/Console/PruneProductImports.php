<?php

namespace App\Catalog\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

final class PruneProductImports extends Command
{
    protected $signature = 'cms:product-import-prune';

    protected $description = 'Remove expired private product CSV uploads';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = time() - (int) config('product-import.ttl_seconds') - 600;
        foreach ($disk->files('product-imports') as $file) {
            $token = pathinfo($file, PATHINFO_FILENAME);
            if ($disk->lastModified($file) < $cutoff && ! Cache::has('product-import:'.$token)) {
                $disk->delete($file);
            }
        }

        return self::SUCCESS;
    }
}
