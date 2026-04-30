import React from 'react';

type FilterIconProps = {
    size?: number;
    color?: string;
    strokeWidth?: number;
    background?: string;
    opacity?: number;
    rotation?: number;
    shadow?: number;
    flipHorizontal?: boolean;
    flipVertical?: boolean;
    padding?: number;
};

const FilterIcon = ({
    size,
    color = '#000000',
    strokeWidth = 2,
    background = 'transparent',
    opacity = 1,
    rotation = 0,
    shadow = 0,
    flipHorizontal = false,
    flipVertical = false,
    padding = 0,
}: FilterIconProps) => {
    const transforms = [];
    if (rotation !== 0) transforms.push(`rotate(${rotation}deg)`);
    if (flipHorizontal) transforms.push('scaleX(-1)');
    if (flipVertical) transforms.push('scaleY(-1)');

    const viewBoxSize = 24 + (padding * 2);
    const viewBoxOffset = -padding;
    const viewBox = `${viewBoxOffset} ${viewBoxOffset} ${viewBoxSize} ${viewBoxSize}`;

    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox={viewBox}
            width={size}
            height={size}
            fill="none"
            stroke={color}
            strokeWidth={strokeWidth}
            strokeLinecap="round"
            strokeLinejoin="round"
            style={{
                opacity,
                transform: transforms.join(' ') || undefined,
                filter: shadow > 0 ? `drop-shadow(0 ${shadow}px ${shadow * 2}px rgba(0,0,0,0.3))` : undefined,
                backgroundColor: background !== 'transparent' ? background : undefined
            }}
        >
            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" strokeWidth={strokeWidth} d="M22 3H2l8 9.46V19l4 2v-8.54z"/>
        </svg>
    );
};

export default FilterIcon;
