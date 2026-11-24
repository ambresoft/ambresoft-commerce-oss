<?php

namespace Ambresoft\Sitemap\Domain\Service;

use InvalidArgumentException;

/** Limits apply to the actual escaped UTF-8 output, including its envelope. */
final class SitemapXml
{
    public const MAX_URLS = 50000;

    public const MAX_BYTES = 52428800;

    private const OPEN = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

    private const CLOSE = '</urlset>'."\n";

    public function __construct(private int $maxUrls = self::MAX_URLS, private int $maxBytes = self::MAX_BYTES)
    {
        if ($maxUrls < 1 || $maxUrls > self::MAX_URLS || $maxBytes < 200 || $maxBytes > self::MAX_BYTES) {
            throw new InvalidArgumentException('Invalid sitemap limits.');
        }
    }

    /** @param iterable<array{url:string,lastmod:?string}> $urls
     * @return iterable<array{xml:string,count:int}> */
    public function parts(iterable $urls): iterable
    {
        $xml = self::OPEN;
        $count = 0;
        foreach ($urls as $url) {
            $item = '  <url><loc>'.$this->escape($url['url']).'</loc>'.($url['lastmod'] === null ? '' : '<lastmod>'.$this->escape($url['lastmod']).'</lastmod>')."</url>\n";
            if (strlen(self::OPEN.$item.self::CLOSE) > $this->maxBytes) {
                throw new InvalidArgumentException('One URL exceeds the sitemap byte limit.');
            }
            if ($count > 0 && ($count === $this->maxUrls || strlen($xml) + strlen($item) + strlen(self::CLOSE) > $this->maxBytes)) {
                yield ['xml' => $xml.self::CLOSE, 'count' => $count];
                $xml = self::OPEN;
                $count = 0;
            }
            $xml .= $item;
            $count++;
        }
        yield ['xml' => $xml.self::CLOSE, 'count' => $count];
    }

    /** @param list<string> $parts */
    public function index(array $parts, string $modified): string
    {
        if (count($parts) > self::MAX_URLS) {
            throw new InvalidArgumentException('Too many sitemap parts.');
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($parts as $part) {
            $xml .= '  <sitemap><loc>'.$this->escape($part).'</loc><lastmod>'.$this->escape($modified)."</lastmod></sitemap>\n";
        }
        $xml .= "</sitemapindex>\n";
        if (strlen($xml) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Sitemap index exceeds the byte limit.');
        }

        return $xml;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
