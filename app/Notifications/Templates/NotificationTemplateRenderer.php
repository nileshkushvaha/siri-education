<?php

declare(strict_types=1);

namespace App\Notifications\Templates;

use App\Models\NotificationTemplate;
use App\Notifications\Templates\Exceptions\UnknownTemplateVariableException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * GAP-039 requirement #3 — the ONE authoritative renderer. Loads an
 * active admin override if one exists, falls back to the code-owned
 * default otherwise, substitutes only allowlisted `{{variable}}`
 * placeholders (HTML-escaped, never Blade/PHP/JS/expression
 * evaluation — plain string search/replace only), and rejects any
 * other variable reference. An invalid override (references an
 * unknown variable) or an unreachable database never breaks delivery:
 * both fall back to the safe code-owned default (requirement #9).
 */
final class NotificationTemplateRenderer
{
    private const string CACHE_PREFIX = 'notification_template:';

    public static function cacheKey(NotificationTemplateKey $key, NotificationTemplateChannel $channel): string
    {
        return self::CACHE_PREFIX.$key->value.':'.$channel->value;
    }

    /**
     * @param  array<string, string|int|float>  $variables  every key MUST be in the template's variable allowlist — an unlisted key is a programmer error and throws immediately, never silently dropped.
     */
    public function render(NotificationTemplateKey $key, NotificationTemplateChannel $channel, array $variables): RenderedNotificationTemplate
    {
        $definition = NotificationTemplateRegistry::get($key);

        if (! $definition->supports($channel)) {
            throw new InvalidArgumentException(sprintf('Template "%s" does not support channel "%s".', $key->value, $channel->value));
        }

        $content = $definition->contentFor($channel);

        foreach (array_keys($variables) as $name) {
            if (! in_array($name, $content->variables, true)) {
                throw new InvalidArgumentException(sprintf('"%s" is not an allowed variable for template "%s" (%s).', $name, $key->value, $channel->value));
            }
        }

        $override = $this->activeOverride($key, $channel);

        if ($override !== null) {
            try {
                return $this->interpolate($override['subject'], $override['body'], $variables, $content->variables);
            } catch (UnknownTemplateVariableException) {
                // Requirement #9 — an invalid override never breaks delivery.
                report(new UnknownTemplateVariableException(sprintf('Invalid override for template "%s" (%s) ignored; using default.', $key->value, $channel->value)));
            }
        }

        return $this->interpolate($content->defaultSubject, $content->defaultBody, $variables, $content->variables);
    }

    /**
     * Cache::rememberForever() treats a cached `null` as "not cached"
     * and re-runs the callback every time (Laravel re-checks `is_null()`
     * on read) — so a real "no override exists" outcome is wrapped in
     * a one-element array before caching, or it would never actually
     * be cached at all.
     *
     * @return array{subject: string, body: string}|null
     */
    private function activeOverride(NotificationTemplateKey $key, NotificationTemplateChannel $channel): ?array
    {
        try {
            $cached = Cache::rememberForever(
                self::cacheKey($key, $channel),
                function () use ($key, $channel): array {
                    $template = NotificationTemplate::query()
                        ->where('template_key', $key->value)
                        ->where('channel', $channel->value)
                        ->where('is_active', true)
                        ->first();

                    if ($template === null || ($template->subject === null && $template->body === null)) {
                        return ['override' => null];
                    }

                    return ['override' => [
                        'subject' => $template->subject ?? NotificationTemplateRegistry::get($key)->contentFor($channel)->defaultSubject,
                        'body' => $template->body ?? NotificationTemplateRegistry::get($key)->contentFor($channel)->defaultBody,
                    ]];
                },
            );

            return $cached['override'];
        } catch (Throwable $e) {
            // Requirement #9 — database unavailable falls back to the default.
            report($e);

            return null;
        }
    }

    /** @param list<string> $allowed */
    private function interpolate(string $subject, string $body, array $variables, array $allowed): RenderedNotificationTemplate
    {
        $replace = fn (string $text): string => (string) preg_replace_callback(
            '/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/i',
            function (array $matches) use ($variables, $allowed): string {
                $name = $matches[1];

                if (! in_array($name, $allowed, true)) {
                    throw new UnknownTemplateVariableException(sprintf('Unknown template variable "%s".', $name));
                }

                return e((string) ($variables[$name] ?? ''));
            },
            $text,
        );

        $renderedSubject = $replace($subject);

        $lines = array_values(array_filter(
            array_map(fn (string $line): string => trim($replace($line)), explode("\n", $body)),
            fn (string $line): bool => $line !== '',
        ));

        return new RenderedNotificationTemplate($renderedSubject, $lines);
    }
}
