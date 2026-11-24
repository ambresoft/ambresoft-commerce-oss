<?php

namespace App\Order\Presentation\Console;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Infrastructure\OrderEmailTemplates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Sends every order email (one per status, plus the invoice) with sample data to one address, for review. */
final class SendOrderEmailTests extends Command
{
    protected $signature = 'cms:order-emails-test {recipient : Address receiving the test emails} {--from= : Sender address (default: Settings → General → Emails)} {--locale= : Language (default: the default language)}';

    protected $description = 'Send each order status email and the invoice email with sample data to one address';

    public function handle(OrderEmailTemplates $templates, LocaleCatalog $locales): int
    {
        $recipient = strtolower((string) $this->argument('recipient'));
        $from = $this->option('from') === null ? null : strtolower((string) $this->option('from'));
        foreach (array_filter([$recipient, $from]) as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->error('Invalid email address: '.$address);

                return self::FAILURE;
            }
        }
        $locale = (string) ($this->option('locale') ?? $locales->defaultCode());
        if (! $locales->active()->contains('code', $locale)) {
            $this->error('Inactive or unknown language: '.$locale);

            return self::FAILURE;
        }
        $failed = 0;
        $jobs = DB::table('order_statuses')->orderBy('position')->orderBy('id')->get(['id', 'code'])->map(fn (object $s): array => ['status', (string) $s->id, $s->code])->all();
        $jobs[] = ['invoice', null, 'invoice'];
        foreach ($jobs as [$kind, $status, $label]) {
            $result = $templates->test(null, $kind, $status, $locale, [], $recipient, $from);
            $this->line(sprintf('%-12s %s', $label, $result->accepted ? 'accepted' : 'failed: '.$result->error));
            $failed += $result->accepted ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
