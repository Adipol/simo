import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
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
