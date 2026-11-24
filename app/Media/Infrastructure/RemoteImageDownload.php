<?php

namespace App\Media\Infrastructure;

use App\Shared\Application\Command\ImageUpload;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Remote images become ordinary validated private uploads. */
class RemoteImageDownload extends RemoteDownload
{
    public function __construct(private readonly PrivateImageStorage $images) {}

    /** @return array{mime_type:string,byte_size:int,width:int,height:int} */
    public function download(string $url, string $destination, float $timeout): array
    {
        try {
            $this->fetch($url, $destination, $timeout, 5 * 1024 * 1024);

            return $this->images->inspect(new ImageUpload($destination));
        } catch (Throwable) {
            throw ValidationException::withMessages(['image_url' => __('category_import.image_failed')]);
        }
    }
}
