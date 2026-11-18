/**
 * Formats an exact decimal USD string (e.g. "0.4673437500") for display without floating-point maths.
 *
 * Pixelforge costs are fractions of a cent, so small amounts keep up to `maxDecimals` places
 * (rounded half up); trailing zeros are dropped down to two places, e.g. "$0.467344" or "$0.30".
 */
export function formatUsd(value: string, maxDecimals = 6): string {
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(value.trim());

    if (!match) {
        return value;
    }

    const [, sign, whole, fraction = ''] = match;
    const padded = fraction.padEnd(maxDecimals + 1, '0');
    const scale = 10n ** BigInt(maxDecimals);

    let units =
        BigInt(whole) * scale + BigInt(padded.slice(0, maxDecimals) || '0');

    if (Number(padded[maxDecimals]) >= 5) {
        units += 1n;
    }

    const wholePart = (units / scale).toLocaleString('en-US');
    const fractionPart = (units % scale)
        .toString()
        .padStart(maxDecimals, '0')
        .replace(/0+$/, '')
        .padEnd(2, '0');

    return `${sign && units !== 0n ? '-' : ''}$${wholePart}.${fractionPart}`;
}
