import { LineChart } from 'lucide-react';
import { MetricChart } from '@/components/domain/metric-chart';
import type { MetricPoint } from '@/components/domain/metric-chart';
import { Card, CardContent } from '@/components/ui/card';

type MetricSeries = Record<string, MetricPoint[]>;

/**
 * Chart layout for a player's canonical metrics. The order + grouping here
 * sets what the admin sees first on the Metrics tab. Adding a new metric
 * = add a row here; the chart handles missing-data + reference bands on
 * its own.
 */
const GROUPS: {
    title: string;
    metrics: { key: string; label: string; unit: string }[];
}[] = [
    {
        title: 'Blood — iron + vitamins',
        metrics: [
            { key: 'hb_g_dl', label: 'Haemoglobin', unit: 'g/dL' },
            { key: 'ferritin_ng_ml', label: 'Ferritin', unit: 'ng/mL' },
            { key: 'vit_d_ng_ml', label: 'Vitamin D (25-OH)', unit: 'ng/mL' },
            { key: 'vit_b12_pg_ml', label: 'Vitamin B12', unit: 'pg/mL' },
        ],
    },
    {
        title: 'Blood — electrolytes + muscle',
        metrics: [
            { key: 'sodium_mmol_l', label: 'Sodium', unit: 'mmol/L' },
            { key: 'potassium_mmol_l', label: 'Potassium', unit: 'mmol/L' },
            { key: 'cpk_u_l', label: 'CPK (creatine kinase)', unit: 'U/L' },
            { key: 'ldh_u_l', label: 'LDH', unit: 'U/L' },
        ],
    },
    {
        title: 'Body composition',
        metrics: [
            { key: 'weight_kg', label: 'Weight', unit: 'kg' },
            { key: 'body_fat_pct', label: 'Body fat %', unit: '%' },
            {
                key: 'skeletal_muscle_kg',
                label: 'Skeletal muscle mass',
                unit: 'kg',
            },
        ],
    },
];

export function PlayerMetrics({ metrics }: { metrics: MetricSeries }) {
    const hasAnyData = Object.values(metrics).some((s) => s.length > 0);

    if (!hasAnyData) {
        return (
            <Card>
                <CardContent className="flex flex-col items-start gap-3 py-8">
                    <div className="grid size-9 place-items-center rounded-md bg-muted text-muted-foreground">
                        <LineChart className="size-4" />
                    </div>
                    <h3 className="text-sm font-semibold">
                        No chartable data yet
                    </h3>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Charts populate automatically once a blood test or
                        body-composition report is processed — every lab value
                        fans out to a record_metrics row and shows up here.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="space-y-6">
            {GROUPS.map((group) => {
                const available = group.metrics.filter(
                    (m) => (metrics[m.key]?.length ?? 0) > 0,
                );

                if (available.length === 0) {
                    return null;
                }

                return (
                    <section key={group.title} className="space-y-2">
                        <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {group.title}
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3">
                            {available.map((m) => (
                                <MetricChart
                                    key={m.key}
                                    metricKey={m.key}
                                    title={m.label}
                                    unitHint={m.unit}
                                    points={metrics[m.key] ?? []}
                                />
                            ))}
                        </div>
                    </section>
                );
            })}
        </div>
    );
}
