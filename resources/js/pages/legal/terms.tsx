import { LegalDocument } from '@/components/legal/legal-document';
import type { LegalDetails } from '@/components/legal/legal-document';

export default function Terms({ legal }: { legal: LegalDetails }) {
    return <LegalDocument doc="terms" legal={legal} />;
}
