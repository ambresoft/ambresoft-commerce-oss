<?php

namespace App\Catalog\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

final class PruneBrandImports extends Command
{
    protected $signature = 'cms:brand-import-prune';

    protected $description = 'Remove expired private brand CSV uploads';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = time() - (int) config('brand-import.ttl_seconds') - 600;
        foreach ($disk->files('brand-imports') as $file) {
            $token = pathinfo($file, PATHINFO_FILENAME);
            if ($disk->lastModified($file) < $cutoff && ! Cache::has('brand-import:'.$token)) {
                $disk->delete($file);
            }
        }

        return self::SUCCESS;
    }
}
