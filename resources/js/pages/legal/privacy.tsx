import { LegalDocument } from '@/components/legal/legal-document';
import type { LegalDetails } from '@/components/legal/legal-document';

export default function Privacy({ legal }: { legal: LegalDetails }) {
    return <LegalDocument doc="privacy" legal={legal} />;
}
