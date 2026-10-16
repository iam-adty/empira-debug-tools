<?php

declare(strict_types=1);

namespace Empira\DebugTools;

use DebugBar\DebugBar;

/**
 * A {@see DebugBar} that reports *this* framework's request, not the superglobals.
 *
 * DebugBar answers "which request was this?" from `$_SERVER`, because that is all
 * a framework-agnostic tool can see. Empira has a `Request` object that knows more
 * than the superglobals do — and in a test there is no superglobal request at all,
 * the {@see Request} is constructed directly, so DebugBar's own metadata would
 * describe whatever the test runner happened to be doing.
 *
 * Overriding one method is the whole fix. Everything else DebugBar does — the
 * collectors, the storage, the request id — is exactly what is wanted here.
 */
final class EmpiraDebugBar extends DebugBar
{
    public function __construct(
        private readonly EmpiraCollector $collector,
    ) {
        // `DebugBar` declares no constructor, so there is nothing to chain to.
    }

    /**
     * The same metadata, with the request read from the collector.
     *
     * Merged over the parent's rather than replacing it, because the parent also
     * supplies `id`, `datetime` and `utime`, which are correct and are not
     * empira's to invent.
     */
    public function collectMetaData(): array
    {
        $meta = parent::collectMetaData();

        $method = $this->collector->method();
        $uri = $this->collector->uri();
        $ip = $this->collector->ip();

        if ($method !== null) {
            $meta['method'] = $method;
        }

        if ($uri !== null) {
            $meta['uri'] = $uri;
        }

        if ($ip !== null) {
            $meta['ip'] = $ip;
        }

        return $meta;
    }
}