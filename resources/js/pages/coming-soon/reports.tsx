import { Head } from '@inertiajs/react';
import { FileBarChart2 } from 'lucide-react';
import { ComingSoonPanel } from '@/components/domain/coming-soon-panel';

export default function ReportsComingSoon() {
    return (
        <>
            <Head title="Reports" />
            <ComingSoonPanel title="Reports" icon={FileBarChart2} />
        </>
    );
}

ReportsComingSoon.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Reports', href: '/reports' },
    ],
};
