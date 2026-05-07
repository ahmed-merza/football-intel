import type { SVGAttributes } from 'react';

/**
 * Football Intel mark — a minimalist shield with an "FI" monogram.
 * Uses currentColor so it adapts to sidebar/primary foreground tokens.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 32 32"
            xmlns="http://www.w3.org/2000/svg"
            fill="currentColor"
        >
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M16 1.5 3 5.2v9.5c0 7.9 5.5 13.3 13 15.8 7.5-2.5 13-7.9 13-15.8V5.2L16 1.5Zm-4.3 8.8H20v2.5h-5.8v2.9h5.3v2.5h-5.3v5.3h-2.5V10.3Z"
            />
        </svg>
    );
}
