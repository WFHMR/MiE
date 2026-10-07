import { test } from '@playwright/test';

/**
 * Reports the contrast of every background/foreground pairing the themes use
 * on their status surfaces. Run it on demand to find unreadable combinations:
 *
 *   npx playwright test contrast-audit.spec.ts --project=chromium --no-deps
 */

const statuses = [ 'success', 'error', 'warning', 'info' ];
const levels = [ 'primary', 'secondary', 'tertiary' ];

/**
 * Each status surface is probed against the three candidates a rule could
 * realistically use: plain white, the theme font colour, and the dedicated
 * text colour of that status.
 *
 * Tokens are referenced directly rather than through utility classes, because
 * a token consumed only through @apply never produces a standalone utility.
 */
const pairs = statuses.flatMap( status =>
    levels.flatMap( level => [
        { label: `bg-${status}-${level} + white`, background: `--color-${status}-${level}`, color: null },
        { label: `bg-${status}-${level} + fontcolor`, background: `--color-${status}-${level}`, color: '--color-fontcolor' },
        { label: `bg-${status}-${level} + ${status}-fontcolor`, background: `--color-${status}-${level}`, color: `--color-${status}-fontcolor` },
    ] )
);

test('report contrast of the status surfaces', async ({ page }) => {
    await page.goto('/dashboard/pos');
    await page.waitForSelector('#pos-container', { state: 'visible', timeout: 30_000 });

    const theme = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));

    const readings = await page.evaluate((pairs: { label: string; background: string; color: string | null }[]) => {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        const context = canvas.getContext('2d')!;

        const toRgba = (value: string): number[] => {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = '#000000';
            context.fillStyle = value;
            context.fillRect(0, 0, 1, 1);
            const data = context.getImageData(0, 0, 1, 1).data;

            return [data[0], data[1], data[2], data[3] / 255];
        };

        const over = (top: number[], bottom: number[]): number[] => {
            const alpha = top[3];

            return [
                top[0] * alpha + bottom[0] * (1 - alpha),
                top[1] * alpha + bottom[1] * (1 - alpha),
                top[2] * alpha + bottom[2] * (1 - alpha),
                1,
            ];
        };

        const luminance = (rgb: number[]): number => {
            const channels = rgb.slice(0, 3).map(channel => {
                const ratio = channel / 255;

                return ratio <= 0.03928 ? ratio / 12.92 : Math.pow((ratio + 0.055) / 1.055, 2.4);
            });

            return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
        };

        const host = document.createElement('div');
        host.style.position = 'fixed';
        host.style.left = '-9999px';
        document.body.appendChild(host);

        const results: { pair: string; ratio: number; color: string; background: string }[] = [];

        for (const pair of pairs) {
            const probe = document.createElement('div');
            probe.style.backgroundColor = `var(${pair.background})`;
            probe.style.color = pair.color === null ? '#ffffff' : `var(${pair.color})`;
            probe.textContent = 'probe';
            host.appendChild(probe);

            const style = window.getComputedStyle(probe);
            const rawBackground = style.backgroundColor;
            const rawColor = style.color;

            probe.remove();

            // An undefined token leaves the property unresolved.
            if (rawBackground === '' || rawBackground === 'rgba(0, 0, 0, 0)' || rawColor === '') {
                results.push({ pair: pair.label, ratio: -1, color: 'unresolved', background: 'unresolved' });
                continue;
            }

            const bg = over(toRgba(rawBackground), [255, 255, 255, 1]);
            const fg = over(toRgba(rawColor), bg);

            const brightest = Math.max(luminance(fg), luminance(bg));
            const darkest = Math.min(luminance(fg), luminance(bg));

            results.push({
                pair: pair.label,
                ratio: Math.round(((brightest + 0.05) / (darkest + 0.05)) * 100) / 100,
                color: `rgb(${fg.slice(0, 3).map(Math.round).join(',')})`,
                background: `rgb(${bg.slice(0, 3).map(Math.round).join(',')})`,
            });
        }

        host.remove();

        return results;
    }, pairs);

    const failing = readings.filter(reading => reading.ratio < 4.5).sort((a, b) => a.ratio - b.ratio);

    console.log(`\n=== contrast audit on the "${theme}" theme ===`);
    console.log(`${failing.length} of ${readings.length} pairings fall below the 4.5 AA threshold\n`);

    for (const reading of failing) {
        console.log(`  ${reading.ratio.toFixed(2).padStart(5)}  ${reading.pair.padEnd(46)} ${reading.color} on ${reading.background}`);
    }

    console.log('');
});
