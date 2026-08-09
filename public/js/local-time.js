/**
 * LocalTime — render stored UTC timestamps in the viewer's own time zone.
 *
 * Every timestamp the pipeline writes is UTC: ticket metadata, reply headers,
 * timeline entries, subtask stamps. Showing them raw meant everyone read the
 * same number regardless of where they sit, so a person in Warsaw and a person
 * in Dublin were looking at a time that was nobody's local time.
 *
 * The browser already knows the machine's zone, so the conversion needs no
 * setting, no profile and no server round-trip — and it follows the viewer
 * automatically across DST changes and travel.
 *
 * Two rules the rest of the dashboard relies on:
 *   * the rendered string always carries the zone abbreviation (CEST, IST,
 *     EDT…), so a screenshot pasted into a chat still says whose time it is;
 *   * the original UTC value is never lost — `utc()` produces the tooltip text
 *     shown on hover, which is what you compare against FreshService and logs.
 *
 * Loaded as a plain script before everything else so both the hand-written SPA
 * shell and the Vue panel share ONE implementation.
 */
(function (root) {
    'use strict';

    // Locales differ on whether they name a zone or fall back to a raw "GMT+2".
    // Try in order and keep the first real abbreviation; `en-GB` names most of
    // Europe, `en-IE` names Ireland, `en-US` names the Americas.
    var ABBR_LOCALES = ['en-GB', 'en-IE', 'en-US'];
    var GMT_OFFSET_RE = /^(GMT|UTC)([+\-–]\d|$)/i;

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    /**
     * Parse a stored timestamp into a Date. Accepts the shapes the pipeline
     * writes: "2026-08-07 09:55 UTC", "2026-08-07T09:55:03Z", and the same
     * without a zone marker — which is UTC too, and must NOT be left to the
     * engine to guess, because a bare "YYYY-MM-DD HH:MM" is read as LOCAL time
     * and would silently shift every such stamp by the viewer's offset.
     */
    function parse(value) {
        if (value === null || value === undefined) return null;
        if (value instanceof Date) return isNaN(value.getTime()) ? null : value;
        var s = String(value).trim();
        if (!s) return null;

        var iso = s.replace(/\s+(UTC|GMT)$/i, 'Z').replace(' ', 'T');
        // No zone designator at all → it is a UTC stamp missing its marker.
        if (!/(Z|[+\-]\d{2}:?\d{2})$/i.test(iso)) iso += 'Z';

        var d = new Date(iso);
        return isNaN(d.getTime()) ? null : d;
    }

    /** The viewer's zone abbreviation for this instant, e.g. "CEST". */
    function zoneAbbr(date) {
        for (var i = 0; i < ABBR_LOCALES.length; i++) {
            try {
                var parts = new Intl.DateTimeFormat(ABBR_LOCALES[i], {
                    timeZoneName: 'short',
                }).formatToParts(date);
                for (var j = 0; j < parts.length; j++) {
                    if (parts[j].type !== 'timeZoneName') continue;
                    var v = parts[j].value;
                    // A bare "GMT+2" is not a name — try the next locale, which
                    // may know this zone by an actual abbreviation.
                    if (!GMT_OFFSET_RE.test(v)) return v;
                }
            } catch (e) { /* locale unavailable — try the next */ }
        }
        // Nothing named it: fall back to the numeric offset, still unambiguous.
        try {
            var p = new Intl.DateTimeFormat('en-GB', { timeZoneName: 'short' })
                .formatToParts(date);
            for (var k = 0; k < p.length; k++) {
                if (p[k].type === 'timeZoneName') return p[k].value;
            }
        } catch (e) { /* fall through */ }
        return 'local';
    }

    /**
     * "2026-08-07 11:55 CEST" — the viewer's own time. Returns the input
     * unchanged when it isn't a timestamp, so it is safe to pipe any metadata
     * value through it.
     */
    function format(value) {
        var d = parse(value);
        if (d === null) return value;
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
            + ' ' + zoneAbbr(d);
    }

    /** "2026-08-07 09:55 UTC" — the stored value, for the hover tooltip. */
    function utc(value) {
        var d = parse(value);
        if (d === null) return typeof value === 'string' ? value : '';
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate())
            + ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ' UTC';
    }

    /** True when the value parses as a timestamp we can convert. */
    function isTimestamp(value) {
        return parse(value) !== null;
    }

    root.LocalTime = {
        parse: parse,
        format: format,
        utc: utc,
        zoneAbbr: zoneAbbr,
        isTimestamp: isTimestamp,
    };
}(typeof window !== 'undefined' ? window : globalThis));
