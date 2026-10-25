import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import plugin from 'tailwindcss/plugin';

/**
 * "Signal Desk" design tokens.
 *
 * Neutrals (`gray-*`) and the brand colour read CSS variables (see
 * resources/css/app.css), so dark mode is a variable swap on <html class="dark">
 * rather than a `dark:` variant on every element. Semantic hues are collapsed
 * into four families:
 *   brand  (green)  messaging, primary actions, success
 *   voice  (blue)   calls, AI drafts, information
 *   amber           needs attention
 *   red             missed, failed, destructive
 * and the old Tailwind hue names are aliased onto them, so existing markup
 * (emerald-*, indigo-*, violet-*, yellow-*, ...) lands on the right family.
 */

const cssVar = (name) => `rgb(var(--${name}) / <alpha-value>)`;

const neutral = Object.fromEntries(
    [50, 100, 200, 300, 400, 500, 600, 700, 800, 900].map((n) => [n, cssVar(`gray-${n}`)]),
);

const brandScale = {
    50: '#EEF8F2', 100: '#DDF3E8', 200: '#BDE6D1', 300: '#8FD3B1', 400: '#4FB985',
    500: '#1FA066', 600: '#0A7F50', 700: '#086B43', 800: '#06573A', 900: '#04432D',
};
const voiceScale = {
    50: '#EEF1FD', 100: '#E3E8FB', 200: '#C8D1F7', 300: '#A3B2F0', 400: '#6F86E4',
    500: '#4C66D6', 600: '#3452C9', 700: '#2A43A8', 800: '#223585', 900: '#1A2866',
};
const amberScale = {
    50: '#FDF6E8', 100: '#FBEBD3', 200: '#F6D5A3', 300: '#EEB766', 400: '#E09A3A',
    500: '#C2690F', 600: '#A8530B', 700: '#8A430A', 800: '#6F3609', 900: '#5A2D08',
};
const redScale = {
    50: '#FDF0EF', 100: '#F9E1E0', 200: '#F2C2C0', 300: '#E8918E', 400: '#D95F5B',
    500: '#C44541', 600: '#B3322F', 700: '#962825', 800: '#7A2220', 900: '#641E1C',
};

/** Old hue name -> token family. */
const HUE_FAMILIES = {
    green: brandScale, emerald: brandScale, teal: brandScale, lime: brandScale,
    blue: voiceScale, indigo: voiceScale, violet: voiceScale, purple: voiceScale, sky: voiceScale, cyan: voiceScale,
    amber: amberScale, yellow: amberScale, orange: amberScale,
    red: redScale, rose: redScale, pink: redScale,
};

const triplet = (hex) => {
    const h = hex.replace('#', '');
    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)).join(' ');
};

/**
 * Dark-mode remap for the tinted utilities (`bg-red-50`, `text-green-700`,
 * `border-amber-200`...). On a dark surface a pale tint reads as a glaring patch,
 * so tints become a low-alpha wash of the family colour and dark text shades
 * become the family's light shade. Generated, so every alias is covered.
 */
const darkHueRemap = plugin(({ addBase }) => {
    const rules = {};
    for (const [name, scale] of Object.entries({ ...HUE_FAMILIES, brand: brandScale, voice: voiceScale })) {
        const wash = triplet(scale[500]);
        const light = scale[300];
        const lighter = scale[200];

        for (const [shade, alpha] of [[50, 0.12], [100, 0.18], [200, 0.28]]) {
            rules[`.dark .bg-${name}-${shade}`] = { backgroundColor: `rgb(${wash} / ${alpha})` };
        }
        for (const shade of [50, 100]) {
            rules[`.dark .hover\\:bg-${name}-${shade}:hover`] = { backgroundColor: `rgb(${wash} / 0.22)` };
        }
        for (const shade of [400, 500, 600, 700, 800, 900]) {
            rules[`.dark .text-${name}-${shade}`] = { color: light };
            rules[`.dark .hover\\:text-${name}-${shade}:hover`] = { color: lighter };
        }
        for (const shade of [100, 200, 300]) {
            rules[`.dark .border-${name}-${shade}`] = { borderColor: `rgb(${wash} / 0.38)` };
        }
    }
    addBase(rules);
});

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Instrument Sans"', ...defaultTheme.fontFamily.sans],
                display: ['"Bricolage Grotesque"', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },

            colors: {
                gray: neutral,
                slate: neutral,
                zinc: neutral,
                neutral,
                stone: neutral,

                ...HUE_FAMILIES,

                // The brand green. Reads variables so dark mode can use the lighter
                // signal green with dark ink on it.
                brand: {
                    DEFAULT: cssVar('brand'),
                    hover: cssVar('brand-hover'),
                    soft: cssVar('brand-soft'),
                    text: cssVar('brand-text'),
                    ink: cssVar('brand-ink'),
                    ...brandScale,
                },
                voice: {
                    DEFAULT: cssVar('voice'),
                    soft: cssVar('voice-soft'),
                    ...voiceScale,
                },
            },

            // Four radii: 8 (inputs, small), 12 (buttons), 16 (inner panels), 20 (cards),
            // plus pill. Replaces the old mix of sm/md/lg/xl/2xl/3xl.
            borderRadius: {
                sm: '6px',
                DEFAULT: '8px',
                md: '8px',
                lg: '12px',
                xl: '16px',
                '2xl': '20px',
                '3xl': '24px',
            },

            boxShadow: {
                sm: 'var(--shadow-sm)',
                DEFAULT: 'var(--shadow-sm)',
                md: 'var(--shadow-md)',
                lg: 'var(--shadow-lg)',
                xl: 'var(--shadow-lg)',
                '2xl': 'var(--shadow-lg)',
            },
        },
    },

    plugins: [forms, darkHueRemap],
};
