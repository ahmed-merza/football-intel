import { LineChart as LineChartChart } from 'echarts/charts';
import {
    GridComponent,
    MarkPointComponent,
    TooltipComponent,
} from 'echarts/components';
import * as echarts from 'echarts/core';
import { SVGRenderer } from 'echarts/renderers';
// ESM entrypoint. The CJS `lib/core` build caused a React error #130
// (element-type-invalid) — Vite's strict ESM interop wraps its
// default export in an object on production builds.
import ReactEChartsCore from 'echarts-for-react/esm/core';
import { useMemo } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

// Tree-shake: only register the chart types + components we actually use.
// Keeps the ECharts payload in the show bundle tiny vs. the full ~1 MB
// import of the convenience echarts-for-react entry.
echarts.use([
    LineChartChart,
    GridComponent,
    TooltipComponent,
    MarkPointComponent,
    SVGRenderer,
]);

export type MetricPoint = {
    date: string;
    value: number;
    unit: string | null;
    ref_low: number | null;
    ref_high: number | null;
    flag: 'low' | 'normal' | 'high' | 'critical' | string | null;
};

type MetricChartProps = {
    title: string;
    metricKey: string;
    unitHint?: string;
    points: MetricPoint[];
};

/**
 * Single-metric trend chart. Reads our theme CSS variables via
 * getComputedStyle so it picks up the current light/dark palette
 * automatically — no prop-drilling tokens through ECharts option configs.
 *
 * Reference-range band (ref_low / ref_high, when both present) renders as
 * a translucent green area. Flagged points (low / high / critical) render
 * as tone-matched dots overlaying the line.
 */
export function MetricChart({
    title,
    metricKey,
    unitHint,
    points,
}: MetricChartProps) {
    const option = useMemo(
        () => buildOption(points, unitHint),
        [points, unitHint],
    );

    if (points.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-sm">{title}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="flex h-32 items-center justify-center text-sm text-muted-foreground">
                        No data yet.
                    </div>
                </CardContent>
            </Card>
        );
    }

    const latest = points[points.length - 1];
    const unitLabel = latest.unit ?? unitHint ?? '';
    const flagTone: Record<string, string> = {
        low: 'text-warning',
        high: 'text-warning',
        critical: 'text-destructive',
        normal: 'text-success',
    };

    return (
        <Card>
            <CardHeader className="flex-row items-baseline justify-between gap-2 space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                <div className="flex items-baseline gap-1 text-xs text-muted-foreground">
                    <span
                        data-numeric
                        className={cn(
                            'text-base font-semibold text-foreground tabular-nums',
                            flagTone[latest.flag ?? 'normal'],
                        )}
                    >
                        {formatValue(latest.value)}
                    </span>
                    {unitLabel && <span>{unitLabel}</span>}
                </div>
            </CardHeader>
            <CardContent className="pb-4">
                <ReactEChartsCore
                    echarts={echarts}
                    option={option}
                    style={{ height: 160, width: '100%' }}
                    opts={{ renderer: 'svg' }}
                    notMerge
                    // Re-key on metric + points length so switching tabs or
                    // adding a new sample re-initialises cleanly.
                    key={`${metricKey}-${points.length}`}
                />
            </CardContent>
        </Card>
    );
}

function buildOption(points: MetricPoint[], unitHint?: string): unknown {
    const css = (name: string, fallback: string): string => {
        if (typeof window === 'undefined') {
            return fallback;
        }

        return (
            getComputedStyle(document.documentElement)
                .getPropertyValue(name)
                .trim() || fallback
        );
    };

    const primary = css('--primary', 'oklch(0.56 0.22 25.8)');
    const success = css('--success', 'oklch(0.66 0.16 150)');
    const warning = css('--warning', 'oklch(0.8 0.16 78)');
    const destructive = css('--destructive', 'oklch(0.6 0.23 27)');
    const muted = css('--muted-foreground', '#888');
    const border = css('--border', '#e5e5e5');

    const dates = points.map((p) => p.date);
    const values = points.map((p) => p.value);
    const unit = points[0]?.unit ?? unitHint ?? '';

    // Reference-range band — only drawn when both bounds are present + stable
    const refLow = points[0]?.ref_low ?? null;
    const refHigh = points[0]?.ref_high ?? null;
    const hasBand = refLow !== null && refHigh !== null;

    const series: unknown[] = [];

    if (hasBand) {
        series.push({
            name: 'Reference range',
            type: 'line',
            data: points.map(() => refHigh),
            lineStyle: { opacity: 0 },
            areaStyle: {
                color: success,
                opacity: 0.08,
                origin: refLow,
            },
            stack: 'band-top',
            symbol: 'none',
            silent: true,
            z: 0,
        });
    }

    series.push({
        name: 'Value',
        type: 'line',
        data: values,
        smooth: 0.25,
        lineStyle: { color: primary, width: 2 },
        itemStyle: { color: primary },
        symbol: 'circle',
        symbolSize: 6,
        emphasis: { focus: 'series' },
        markPoint: {
            symbol: 'circle',
            symbolSize: 8,
            data: points
                .map((p, i) => {
                    const color =
                        p.flag === 'critical'
                            ? destructive
                            : p.flag === 'low' || p.flag === 'high'
                              ? warning
                              : null;

                    if (color === null) {
                        return null;
                    }

                    return {
                        coord: [i, p.value],
                        itemStyle: { color, borderColor: color },
                        label: { show: false },
                    };
                })
                .filter((m) => m !== null),
        },
        z: 2,
    });

    return {
        animation: true,
        animationDuration: 200,
        grid: { left: 40, right: 12, top: 12, bottom: 24 },
        tooltip: {
            trigger: 'axis',
            backgroundColor: css('--popover', '#fff'),
            borderColor: border,
            textStyle: { color: css('--popover-foreground', '#111') },
            formatter: (params: unknown): string => {
                const arr = params as Array<{
                    axisValue: string;
                    data: number;
                }>;
                const head = arr[0];

                return `${head.axisValue}<br/><strong>${formatValue(head.data)}</strong> ${unit}`;
            },
        },
        xAxis: {
            type: 'category',
            data: dates,
            axisLine: { lineStyle: { color: border } },
            axisLabel: {
                color: muted,
                fontSize: 10,
                formatter: (v: string): string => v.slice(5), // MM-DD
            },
        },
        yAxis: {
            type: 'value',
            splitLine: { lineStyle: { color: border, type: 'dashed' } },
            axisLabel: { color: muted, fontSize: 10 },
        },
        series,
    };
}

function formatValue(v: number): string {
    if (Math.abs(v) >= 100) {
        return v.toFixed(0);
    }

    if (Math.abs(v) >= 10) {
        return v.toFixed(1);
    }

    return v.toFixed(2);
}
