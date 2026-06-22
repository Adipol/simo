import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Per-script color classes are assembled dynamically from a PHP $colorMap in
    // resources/views/components/scripts/config-card.blade.php (scraper=indigo,
    // pep=purple, gaceta=amber). Tailwind's content scanner can't reliably extract
    // classes built from a PHP array, so they get purged in some build toolchains
    // (e.g. the version pinned in package-lock dropped the gaceta amber toggle).
    // Safelisting guarantees they survive in every build environment (CI/VPS/local).
    safelist: [
        'bg-indigo-50', 'text-indigo-600', 'accent-indigo-600', 'focus:ring-indigo-500/30', 'focus:border-indigo-400', 'peer-checked:bg-indigo-500', 'peer-checked:bg-indigo-600', 'peer-checked:border-indigo-600', 'hover:border-indigo-300',
        'bg-purple-50', 'text-purple-600', 'accent-purple-600', 'focus:ring-purple-500/30', 'focus:border-purple-400', 'peer-checked:bg-purple-500', 'peer-checked:bg-purple-600', 'peer-checked:border-purple-600', 'hover:border-purple-300',
        'bg-amber-50', 'text-amber-600', 'accent-amber-600', 'focus:ring-amber-500/30', 'focus:border-amber-400', 'peer-checked:bg-amber-500', 'peer-checked:bg-amber-600', 'peer-checked:border-amber-600', 'hover:border-amber-300',
    ],

    theme: {
        extend: {
            fontFamily: {
                // app.css already imports Inter; keep it as primary sans font
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                'simo-dark': '#111111',
                // simo-bg: usar bg-zinc-100 directamente (body lo define en app.css)
            },
        },
    },

    plugins: [forms],
};
