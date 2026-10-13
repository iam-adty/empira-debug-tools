/*
 * The debug bar.
 *
 * Reads one JSON island the server injected into this page and renders a fixed
 * bar from it. The server owns the data; this owns the pixels.
 *
 * ## Why the data travels in the page rather than in a cookie
 *
 * A cookie was the obvious place for it, and Clockwork's own toolbar uses one.
 * `Response::cookie()` replaces the `Set-Cookie` header rather than appending,
 * so a module running last in the pipeline would overwrite whatever cookie the
 * application had set during dispatch — silently, and only on pages being
 * debugged. A `script type="application/json"` island costs one element, is read
 * before this file runs, and cannot collide with anything. It also means the bar
 * only ever appears on a page that carries its own numbers, which is the same
 * rule the injection already follows.
 *
 * No framework, no build step, and no network beyond the two files the module
 * serves: this ships inside a PHP package that otherwise has no JavaScript at
 * all, and an application that installs a profiler during development should not
 * acquire a toolchain to do it.
 */
(function () {
    'use strict';

    var ISLAND_ID = 'empira-debug';
    var ROOT = '/_clockwork';

    /* Status classes are coarse on purpose — four buckets, chosen by what you do
     * next rather than by what the number technically is. A 301 and a 404 want
     * different things from you, and a 503 does not need its own colour. */
    function statusClass(status) {
        if (status < 300) return 'ok';
        if (status < 400) return 'redirect';
        if (status < 500) return 'client';
        return 'server';
    }

    function el(name, className, text) {
        var node = document.createElement(name);

        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;

        return node;
    }

    function readData() {
        var island = document.getElementById(ISLAND_ID);

        if (!island) return null;

        try {
            return JSON.parse(island.textContent);
        } catch (e) {
            /* Nothing useful to do. A profiler that throws into the page it is
             * measuring is worse than no bar at all. */
            return null;
        }
    }

    function render(data) {
        var bar = el('div', 'empira-bar');

        var status = el('span', 'empira-bar__status');
        status.appendChild(el('span', 'empira-bar__dot empira-bar__dot--' + statusClass(data.status)));
        status.appendChild(el('span', null, String(data.status)));

        bar.appendChild(status);

        if (data.route) bar.appendChild(el('span', 'empira-bar__route', data.route));

        bar.appendChild(el('span', null, data.duration_ms + ' ms'));

        if (data.memory_mb) bar.appendChild(el('span', null, data.memory_mb + ' MB'));

        var link = el('a', 'empira-bar__link', 'Clockwork');
        link.href = ROOT;
        link.target = '_blank';
        /* Without this the bar is a navigation, and a page load during debugging
         * is the one interruption that loses what you were about to read. */
        link.rel = 'noopener';
        bar.appendChild(link);

        document.body.appendChild(bar);
    }

    function boot() {
        var data = readData();

        if (!data || typeof data.status !== 'number') return;

        render(data);
    }

    /* Served with `defer`, so `document.body` exists by now — but a response
     * with no body at all is possible, and appending to nothing is not. */
    if (document.body) {
        boot();
    } else {
        document.addEventListener('DOMContentLoaded', boot);
    }
})();