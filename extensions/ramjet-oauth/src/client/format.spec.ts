import { describe, expect, it } from 'vitest';
import { absoluteTime, middleTruncate, relativeTime } from './format';

describe('middleTruncate', () => {
    it('keeps the start and the end of a UUID', () => {
        expect(middleTruncate('01a12dc9-cd2c-702a-afed-8ee180829693')).toBe('01a12dc9…829693');
    });

    it('leaves a short value alone', () => {
        expect(middleTruncate('abc')).toBe('abc');
    });
});

describe('relativeTime', () => {
    const now = Date.parse('2026-10-16T12:00:00Z');

    it('says just now within a minute', () => {
        expect(relativeTime('2026-10-16T11:59:30Z', now)).toBe('just now');
    });

    it('counts whole days, in the past', () => {
        expect(relativeTime('2026-10-13T12:00:00Z', now, 'en')).toBe('3 days ago');
    });

    it('picks the largest unit that fits', () => {
        expect(relativeTime('2026-10-16T10:00:00Z', now, 'en')).toBe('2 hours ago');
        expect(relativeTime('2026-09-16T12:00:00Z', now, 'en')).toBe('1 month ago');
    });

    it('returns the input for an unparseable date', () => {
        expect(relativeTime('not a date', now)).toBe('not a date');
    });
});

describe('absoluteTime', () => {
    it('returns the input for an unparseable date', () => {
        expect(absoluteTime('not a date')).toBe('not a date');
    });
});
