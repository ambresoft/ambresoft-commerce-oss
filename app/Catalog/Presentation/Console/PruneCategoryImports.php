<?php

namespace App\Catalog\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

final class PruneCategoryImports extends Command
{
    protected $signature = 'cms:category-import-prune';

    protected $description = 'Remove expired private category CSV uploads';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = time() - (int) config('category-import.ttl_seconds') - 600;
        foreach ($disk->files('category-imports') as $file) {
            $token = pathinfo($file, PATHINFO_FILENAME);
            if ($disk->lastModified($file) < $cutoff && ! Cache::has('category-import:'.$token)) {
                $disk->delete($file);
            }
        }

        return self::SUCCESS;
    }
}
