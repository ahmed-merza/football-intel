import { Head } from '@inertiajs/react';
import { BookOpenText } from 'lucide-react';
import { ComingSoonPanel } from '@/components/domain/coming-soon-panel';

export default function KnowledgeComingSoon() {
    return (
        <>
            <Head title="Knowledge base" />
            <ComingSoonPanel title="Knowledge base" icon={BookOpenText} />
        </>
    );
}

KnowledgeComingSoon.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Knowledge base', href: '/knowledge' },
    ],
};
